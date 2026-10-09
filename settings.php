<?php
// settings.php — configurazione impostazioni di sistema
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/security.php';
require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/roles.php';
require_once __DIR__ . '/core/settings.php';

start_session();
$env  = require __DIR__ . '/config/env.php';
$base = rtrim($env['app']['base_url'] ?? '', '/');
$user = current_user();

if (!$user) {
  header('Location: ' . $base . '/index.php?msg=auth');
  exit;
}

if (!user_is_admin($user)) {
  http_response_code(403);
  echo 'Accesso negato';
  exit;
}

$pdo = db();
ensure_system_settings_table($pdo);
$errors = [];
$messages = [];
$startValue = null;
$endValue = null;
$linenCostValues = null;
$pressureThresholdValue = null;

function save_departments_setting(PDO $pdo, array $departments): void {
  $departments = normalize_departments_list($departments);
  set_setting('departments', json_encode(array_values($departments), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $pdo);
}

function department_usage_counts(PDO $pdo, string $department): array {
  $needle = str_replace(' ', '', $department);
  $counts = ['users' => 0, 'tasks' => 0];

  try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE deleted_at IS NULL AND FIND_IN_SET(?, REPLACE(dipartimento, ' ', '')) > 0");
    $stmt->execute([$needle]);
    $counts['users'] = (int)$stmt->fetchColumn();
  } catch (Throwable $exception) {
    $counts['users'] = 0;
  }

  try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM tasks WHERE deleted_at IS NULL AND dipartimento = ?");
    $stmt->execute([$department]);
    $counts['tasks'] = (int)$stmt->fetchColumn();
  } catch (Throwable $exception) {
    $counts['tasks'] = 0;
  }

  return $counts;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $token = $_POST['csrf_token'] ?? '';
  $action = $_POST['action'] ?? 'season';
  if (!csrf_check($token)) {
    $errors[] = 'Token CSRF non valido, ricarica la pagina e riprova.';
  } elseif ($action === 'add_department') {
    $departmentName = trim((string)($_POST['department_name'] ?? ''));
    $departments = available_departments();

    if ($departmentName === '') {
      $errors[] = 'Inserisci il nome del dipartimento da creare.';
    } elseif ((function_exists('mb_strlen') ? mb_strlen($departmentName, 'UTF-8') : strlen($departmentName)) > 80) {
      $errors[] = 'Il nome del dipartimento può contenere al massimo 80 caratteri.';
    } elseif (preg_match('/[,;|]/', $departmentName)) {
      $errors[] = 'Il nome del dipartimento non può contenere virgole, punto e virgola o barre verticali.';
    } else {
      $exists = false;
      foreach ($departments as $existing) {
        if (strcasecmp($existing, $departmentName) === 0) {
          $exists = true;
          break;
        }
      }
      if ($exists) {
        $errors[] = 'Questo dipartimento è già presente.';
      } else {
        $departments[] = $departmentName;
        save_departments_setting($pdo, $departments);
        $messages[] = 'Dipartimento creato correttamente.';
      }
    }
  } elseif ($action === 'delete_department') {
    $departmentName = trim((string)($_POST['department_name'] ?? ''));
    $departments = available_departments();
    if (!in_array($departmentName, $departments, true)) {
      $errors[] = 'Dipartimento non trovato.';
    } elseif (count($departments) <= 1) {
      $errors[] = 'Non puoi eliminare l’ultimo dipartimento disponibile.';
    } else {
      $departments = array_values(array_filter($departments, function($department) use ($departmentName) {
        return $department !== $departmentName;
      }));
      save_departments_setting($pdo, $departments);
      $usage = department_usage_counts($pdo, $departmentName);
      $messages[] = 'Dipartimento eliminato dalla lista selezionabile.';
      if ($usage['users'] > 0 || $usage['tasks'] > 0) {
        $messages[] = 'Nota: esistono ancora ' . $usage['users'] . ' utenti e ' . $usage['tasks'] . ' task collegati a questo dipartimento nei dati storici.';
      }
    }
  } elseif ($action === 'pressure_alert_threshold') {
    $pressureThresholdValue = trim((string)($_POST['atmospheric_pressure_alert_threshold'] ?? ''));
    try {
      $threshold = parse_atmospheric_pressure_alert_threshold($pressureThresholdValue);
      set_setting('atmospheric_pressure_alert_threshold', (string)$threshold, $pdo);
      $pressureThresholdValue = (string)$threshold;
      $messages[] = 'Soglia di allerta della pressione atmosferica salvata correttamente.';
    } catch (InvalidArgumentException $exception) {
      $errors[] = $exception->getMessage();
    }
  } elseif ($action === 'linen_costs') {
    $linenCostValues = [];
    $linenCostFields = [
      'matrimoniale' => 'lenzuolo matrimoniale',
      'singola' => 'lenzuolo singolo',
      'set_bagno' => 'set da bagno',
    ];

    foreach ($linenCostFields as $key => $label) {
      $rawValue = str_replace(',', '.', trim((string)($_POST['cost_' . $key] ?? '')));
      if ($rawValue === '' || !is_numeric($rawValue) || !is_finite((float)$rawValue) || (float)$rawValue < 0) {
        $errors[] = 'Inserisci un costo valido e non negativo per ' . $label . '.';
        continue;
      }
      $linenCostValues[$key] = (float)$rawValue;
    }

    if (!$errors) {
      foreach ($linenCostValues as $key => $value) {
        set_setting('riassetti_cost_' . $key, number_format($value, 2, '.', ''), $pdo);
      }
      $messages[] = 'Costi della biancheria salvati correttamente.';
    }
  } else {
    $summerStart = trim($_POST['summer_season_start'] ?? '');
    $summerEnd   = trim($_POST['summer_season_end'] ?? '');
    $setpointRaw = str_replace(',', '.', trim((string)($_POST['hot_water_temperature_setpoint'] ?? '')));

    $startValue = $summerStart !== '' ? $summerStart : null;
    $endValue   = $summerEnd !== '' ? $summerEnd : null;

    $timezone = new DateTimeZone('Europe/Rome');
    $startDate = $startValue ? DateTimeImmutable::createFromFormat('Y-m-d', $startValue, $timezone) : null;
    $endDate   = $endValue ? DateTimeImmutable::createFromFormat('Y-m-d', $endValue, $timezone) : null;

    if ($startValue && !$startDate) {
      $errors[] = 'La data di apertura non è valida. Usa il formato YYYY-MM-DD.';
    }

    if ($endValue && !$endDate) {
      $errors[] = 'La data di chiusura non è valida. Usa il formato YYYY-MM-DD.';
    }

    if (!$errors && $startDate && $endDate && $startDate > $endDate) {
      $errors[] = 'La data di apertura non può essere successiva alla data di chiusura.';
    }

    if ($setpointRaw !== '' && (!is_numeric($setpointRaw) || !is_finite((float)$setpointRaw) || (float)$setpointRaw < 0 || (float)$setpointRaw > 100)) {
      $errors[] = 'Il setpoint acqua calda deve essere compreso tra 0 e 100 °C.';
    }

    if (!$errors) {
      set_setting('summer_season_start', $startValue, $pdo);
      set_setting('summer_season_end', $endValue, $pdo);
      set_setting('hot_water_temperature_setpoint', $setpointRaw === '' ? null : number_format((float)$setpointRaw, 1, '.', ''), $pdo);
      $messages[] = 'Impostazioni salvate correttamente.';
    }
  }
}

$currentSettings = get_summer_season_range($pdo);
$currentStart = $currentSettings['start'];
$currentEnd   = $currentSettings['end'];
$hotWaterSetpoint = get_hot_water_setpoint($pdo);
$departments = available_departments();
$linenCosts = get_riassetti_linen_costs($pdo);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $errors && (($_POST['action'] ?? '') === 'linen_costs')) {
  $linenCosts = array_merge($linenCosts, $linenCostValues ?? []);
}
$departmentUsages = [];
foreach ($departments as $department) {
  $departmentUsages[$department] = department_usage_counts($pdo, $department);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $errors && (($_POST['action'] ?? 'season') === 'season')) {
  $currentStart = $startValue ?? $currentStart;
  $currentEnd   = $endValue ?? $currentEnd;
  if (isset($setpointRaw) && $setpointRaw !== '' && is_numeric($setpointRaw)) $hotWaterSetpoint = (float)$setpointRaw;
}

$seasonActive = is_today_within_summer_season($pdo);
$pressureThresholdValue ??= (string)get_atmospheric_pressure_alert_threshold($pdo);

$title = 'Impostazioni di sistema';
include __DIR__ . '/partials/header.php';
?>
<div class="row justify-content-center g-3">
  <div class="col-12 col-xl-10">
    <div class="card shadow-sm mb-3">
      <div class="card-body">
        <h1 class="h4 mb-3">Impostazioni di sistema</h1>
        <p class="text-muted">Definisci il periodo di apertura della stagione estiva e gestisci i dipartimenti disponibili nelle schede utenti, task e invio SMS.</p>

        <?php if ($messages): ?>
          <div class="alert alert-success">
            <?php foreach ($messages as $msg): ?>
              <div><?= e($msg) ?></div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <?php if ($errors): ?>
          <div class="alert alert-danger">
            <?php foreach ($errors as $err): ?>
              <div><?= e($err) ?></div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-5">
    <div class="card shadow-sm h-100">
      <div class="card-body">
        <h2 class="h5 mb-3">Stagione estiva</h2>
        <dl class="row small text-muted mb-4">
          <dt class="col-sm-6">Intervallo attuale</dt>
          <dd class="col-sm-6">
            <?php
              $startDisplay = $currentStart ? DateTimeImmutable::createFromFormat('Y-m-d', $currentStart) : false;
              $endDisplay   = $currentEnd ? DateTimeImmutable::createFromFormat('Y-m-d', $currentEnd) : false;
            ?>
            <?php if ($startDisplay && $endDisplay): ?>
              <?= e($startDisplay->format('d/m/Y')) ?> – <?= e($endDisplay->format('d/m/Y')) ?>
            <?php else: ?>
              Non configurato
            <?php endif; ?>
          </dd>
          <dt class="col-sm-6">Stato oggi</dt>
          <dd class="col-sm-6">
            <?php if ($currentStart && $currentEnd): ?>
              <?php if ($seasonActive): ?>
                <span class="badge bg-success">Stagione attiva</span>
              <?php else: ?>
                <span class="badge bg-secondary">Stagione non attiva</span>
              <?php endif; ?>
            <?php else: ?>
              <span class="badge bg-info text-dark">Controllo disattivato</span>
            <?php endif; ?>
          </dd>
        </dl>

        <form method="post" novalidate>
          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="season">
          <div class="mb-3">
            <label for="summer_season_start" class="form-label">Data apertura stagione estiva</label>
            <input type="date" class="form-control" id="summer_season_start" name="summer_season_start" value="<?= e($currentStart ?? '') ?>">
            <div class="form-text">Lascia vuoto per disattivare il controllo.</div>
          </div>
          <div class="mb-3">
            <label for="summer_season_end" class="form-label">Data chiusura stagione estiva</label>
            <input type="date" class="form-control" id="summer_season_end" name="summer_season_end" value="<?= e($currentEnd ?? '') ?>">
          </div>
          <div class="mb-3">
            <label for="hot_water_temperature_setpoint" class="form-label">Setpoint temperatura acqua calda</label>
            <div class="input-group"><input type="number" min="0" max="100" step="0.1" class="form-control" id="hot_water_temperature_setpoint" name="hot_water_temperature_setpoint" value="<?= e($hotWaterSetpoint !== null ? number_format($hotWaterSetpoint, 1, '.', '') : '') ?>"><span class="input-group-text">°C</span></div>
            <div class="form-text">Lascia vuoto per disattivare gli alert caldaie.</div>
          </div>
          <button type="submit" class="btn btn-primary">Salva impostazioni</button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-7">
    <div class="card shadow-sm h-100">
      <div class="card-body">
        <div class="d-flex flex-column flex-md-row justify-content-between gap-2 mb-3">
          <div>
            <h2 class="h5 mb-1">Dipartimenti</h2>
            <div class="text-muted small">Crea o rimuovi i dipartimenti selezionabili nel sistema.</div>
          </div>
          <span class="badge text-bg-light align-self-start"><?= count($departments) ?> dipartimenti</span>
        </div>

        <form method="post" class="row g-2 mb-3">
          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="add_department">
          <div class="col-12 col-md">
            <label class="visually-hidden" for="department_name">Nuovo dipartimento</label>
            <input type="text" class="form-control" id="department_name" name="department_name" maxlength="80" placeholder="Nome nuovo dipartimento">
          </div>
          <div class="col-12 col-md-auto">
            <button type="submit" class="btn btn-success w-100"><i class="bi bi-plus-circle me-1"></i>Aggiungi</button>
          </div>
        </form>

        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead>
              <tr>
                <th>Dipartimento</th>
                <th class="text-center">Utenti</th>
                <th class="text-center">Task</th>
                <th class="text-end">Azioni</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($departments as $department): $usage = $departmentUsages[$department] ?? ['users'=>0,'tasks'=>0]; ?>
                <tr>
                  <td><span class="badge bg-light text-dark border"><?= e($department) ?></span></td>
                  <td class="text-center"><?= (int)$usage['users'] ?></td>
                  <td class="text-center"><?= (int)$usage['tasks'] ?></td>
                  <td class="text-end">
                    <form method="post" class="d-inline" data-confirm-message="Eliminare il dipartimento ‘<?= e($department) ?>’ dalla lista selezionabile? I dati storici resteranno invariati.">
                      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                      <input type="hidden" name="action" value="delete_department">
                      <input type="hidden" name="department_name" value="<?= e($department) ?>">
                      <button type="submit" class="btn btn-sm btn-outline-danger" <?= count($departments) <= 1 ? 'disabled' : '' ?>><i class="bi bi-trash"></i></button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <div class="form-text mt-2">L’eliminazione rimuove il dipartimento dalle nuove selezioni. Eventuali utenti o task già collegati mantengono il valore storico finché non vengono modificati.</div>
      </div>
    </div>
  </div>

  <div class="col-12 col-xl-10">
    <div class="card shadow-sm">
      <div class="card-body">
        <h2 class="h5 mb-1">Costi biancheria riassetti</h2>
        <p class="text-muted small mb-3">Imposta i costi unitari utilizzati nella pagina Statistiche dei riassetti.</p>
        <form method="post" class="row g-3" novalidate>
          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="linen_costs">
          <div class="col-12 col-md-4">
            <label for="cost_matrimoniale" class="form-label">Lenzuolo matrimoniale</label>
            <div class="input-group">
              <span class="input-group-text">€</span>
              <input type="number" class="form-control" id="cost_matrimoniale" name="cost_matrimoniale" min="0" step="0.01" value="<?= e(number_format($linenCosts['matrimoniale'], 2, '.', '')) ?>" required>
            </div>
          </div>
          <div class="col-12 col-md-4">
            <label for="cost_singola" class="form-label">Lenzuolo singolo</label>
            <div class="input-group">
              <span class="input-group-text">€</span>
              <input type="number" class="form-control" id="cost_singola" name="cost_singola" min="0" step="0.01" value="<?= e(number_format($linenCosts['singola'], 2, '.', '')) ?>" required>
            </div>
          </div>
          <div class="col-12 col-md-4">
            <label for="cost_set_bagno" class="form-label">Set da bagno</label>
            <div class="input-group">
              <span class="input-group-text">€</span>
              <input type="number" class="form-control" id="cost_set_bagno" name="cost_set_bagno" min="0" step="0.01" value="<?= e(number_format($linenCosts['set_bagno'], 2, '.', '')) ?>" required>
            </div>
          </div>
          <div class="col-12">
            <button type="submit" class="btn btn-primary">Salva costi</button>
          </div>
        </form>
      </div>
    </div>
  </div>
  <div class="col-12 col-xl-10">
    <div class="card shadow-sm"><div class="card-body">
      <h2 class="h5 mb-3">Alert pressione atmosferica</h2>
      <p class="text-muted">L’allerta in dashboard e gli SMS meteo si attivano quando la pressione è pari o inferiore alla soglia. Gli SMS mantengono le regole di invio durante la stagione estiva e di prevenzione degli avvisi ripetuti. Valore predefinito: 1013 hPa.</p>
      <form method="post" class="row g-3 align-items-end">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="pressure_alert_threshold">
        <div class="col-12 col-md-6"><label for="pressureAlertThreshold" class="form-label">Soglia pressione atmosferica</label><div class="input-group"><input type="number" class="form-control" id="pressureAlertThreshold" name="atmospheric_pressure_alert_threshold" step="any" value="<?= e(str_replace(',', '.', $pressureThresholdValue)) ?>" required><span class="input-group-text">hPa</span></div><div class="form-text">Inserisci un valore maggiore di zero.</div></div>
        <div class="col-12 col-md-auto"><button class="btn btn-primary">Salva soglia pressione</button></div>
      </form>
    </div></div>
  </div>
</div>
<?php include __DIR__ . '/partials/footer.php'; ?>
