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
$errors = [];
$messages = [];
$startValue = null;
$endValue = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $token = $_POST['csrf_token'] ?? '';
  if (!csrf_check($token)) {
    $errors[] = 'Token CSRF non valido, ricarica la pagina e riprova.';
  } else {
    $summerStart = trim($_POST['summer_season_start'] ?? '');
    $summerEnd   = trim($_POST['summer_season_end'] ?? '');

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

    if (!$errors) {
      set_setting('summer_season_start', $startValue, $pdo);
      set_setting('summer_season_end', $endValue, $pdo);
      $messages[] = 'Impostazioni salvate correttamente.';
    }
  }
}

$currentSettings = get_summer_season_range($pdo);
$currentStart = $currentSettings['start'];
$currentEnd   = $currentSettings['end'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $errors) {
  $currentStart = $startValue ?? $currentStart;
  $currentEnd   = $endValue ?? $currentEnd;
}

$seasonActive = is_today_within_summer_season($pdo);

$title = 'Impostazioni di sistema';
include __DIR__ . '/partials/header.php';
?>
<div class="row justify-content-center">
  <div class="col-12 col-lg-6">
    <div class="card shadow-sm">
      <div class="card-body">
        <h1 class="h4 mb-3">Impostazioni di sistema</h1>
        <p class="text-muted">Definisci il periodo di apertura della stagione estiva. Al di fuori di questo intervallo la dashboard e il report giornaliero mostreranno solo i task.</p>

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
          <div class="mb-3">
            <label for="summer_season_start" class="form-label">Data apertura stagione estiva</label>
            <input type="date" class="form-control" id="summer_season_start" name="summer_season_start" value="<?= e($currentStart ?? '') ?>">
            <div class="form-text">Lascia vuoto per disattivare il controllo.</div>
          </div>
          <div class="mb-3">
            <label for="summer_season_end" class="form-label">Data chiusura stagione estiva</label>
            <input type="date" class="form-control" id="summer_season_end" name="summer_season_end" value="<?= e($currentEnd ?? '') ?>">
          </div>
          <button type="submit" class="btn btn-primary">Salva impostazioni</button>
        </form>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/partials/footer.php'; ?>
