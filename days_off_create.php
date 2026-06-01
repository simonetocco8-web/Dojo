<?php
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/security.php';
require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/google/gcal_days_off.php';

start_session();
$env  = require __DIR__ . '/config/env.php';
$base = rtrim($env['app']['base_url'] ?? '', '/');
$pdo  = db();
$user = current_user();

if (!$user || !(is_admin() || user_has_department($user, 'Amministrazione'))) {
  http_response_code(403); exit('Permesso negato.');
}

$message = '';
$conflictRows = [];
$formData = [
  'user_id' => '',
  'mode' => 'specific',
  'day' => '',
  'weekday' => (string)(int)(new DateTimeImmutable('next monday'))->format('N'),
  'until' => (new DateTimeImmutable('+3 months'))->format('Y-m-d'),
  'note' => '',
];
$weekdays = [
  1 => 'Lunedì',
  2 => 'Martedì',
  3 => 'Mercoledì',
  4 => 'Giovedì',
  5 => 'Venerdì',
  6 => 'Sabato',
  7 => 'Domenica',
];

function normalize_days_off_date(string $day): string {
  $day = trim($day);
  if (preg_match('#^\d{2}/\d{2}/\d{4}$#', $day)) {
    [$d,$m,$y] = explode('/', $day);
    return sprintf('%04d-%02d-%02d', $y, $m, $d);
  }
  return $day;
}

function days_off_future_rows(PDO $pdo, int $uid): array {
  $st = $pdo->prepare("SELECT id, day, note FROM days_off WHERE user_id = ? AND deleted_at IS NULL AND day >= CURDATE() ORDER BY day ASC, id ASC");
  $st->execute([$uid]);
  return $st->fetchAll(PDO::FETCH_ASSOC);
}

function days_off_sync_create(PDO $pdo, int $daysOffId): void {
  try { gcal_days_off_create($pdo, $daysOffId); } catch (Throwable $e) { error_log('GCAL days_off create failed: '.$e->getMessage()); }
}

function days_off_sync_recreate(PDO $pdo, int $daysOffId): void {
  try { gcal_days_off_delete($pdo, $daysOffId); } catch (Throwable $e) { error_log('GCAL days_off delete failed: '.$e->getMessage()); }
  $pdo->prepare('UPDATE days_off SET google_event_id = NULL WHERE id = ?')->execute([$daysOffId]);
  days_off_sync_create($pdo, $daysOffId);
}

function next_date_for_weekday(DateTimeImmutable $from, int $weekday): DateTimeImmutable {
  $currentWeekday = (int)$from->format('N');
  $daysToAdd = ($weekday - $currentWeekday + 7) % 7;
  return $from->modify('+' . $daysToAdd . ' days');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $formData['user_id'] = $_POST['user_id'] ?? '';
  $formData['mode'] = $_POST['mode'] ?? 'specific';
  $formData['day'] = $_POST['day'] ?? '';
  $formData['weekday'] = $_POST['weekday'] ?? $formData['weekday'];
  $formData['until'] = $_POST['until'] ?? $formData['until'];
  $formData['note'] = trim($_POST['note'] ?? '');

  if (!csrf_check($_POST['csrf'] ?? '')) {
    $message = 'Token CSRF non valido.';
  } else {
    $uid  = (int)$formData['user_id'];
    $mode = in_array($formData['mode'], ['specific','weekly'], true) ? $formData['mode'] : 'specific';
    $note = $formData['note'];

    if ($uid <= 0) {
      $message = 'Seleziona un utente.';
    } elseif ($mode === 'specific') {
      $day = normalize_days_off_date((string)$formData['day']);
      $formData['day'] = $day;

      if (!preg_match('#^\d{4}-\d{2}-\d{2}$#', $day)) {
        $message = 'Seleziona una data valida.';
      } else {
        $futureRows = days_off_future_rows($pdo, $uid);
        $conflictAction = $_POST['conflict_action'] ?? '';

        if ($futureRows && !in_array($conflictAction, ['replace_next','update_all'], true)) {
          $conflictRows = $futureRows;
          $message = 'Sono già presenti giorni liberi futuri per questo utente. Scegli come applicare la nuova data.';
        } else {
          try {
            if ($futureRows && $conflictAction === 'replace_next') {
              $target = $futureRows[0];
              $upd = $pdo->prepare('UPDATE days_off SET day = ?, note = ?, deleted_at = NULL WHERE id = ?');
              $upd->execute([$day, $note ?: null, (int)$target['id']]);
              days_off_sync_recreate($pdo, (int)$target['id']);
              header('Location: days_off_list.php?msg=updated');
              exit;
            }

            if ($futureRows && $conflictAction === 'update_all') {
              $start = new DateTimeImmutable($day);
              $weekday = (int)$start->format('N');
              $next = $start;
              $upd = $pdo->prepare('UPDATE days_off SET day = ?, note = ?, deleted_at = NULL WHERE id = ?');
              foreach ($futureRows as $idx => $row) {
                $newDay = $idx === 0 ? $next : $next->modify('+' . $idx . ' weeks');
                $upd->execute([$newDay->format('Y-m-d'), $note ?: null, (int)$row['id']]);
                days_off_sync_recreate($pdo, (int)$row['id']);
              }
              header('Location: days_off_list.php?msg=updated_all&weekday=' . $weekday);
              exit;
            }

            $ins = $pdo->prepare('INSERT INTO days_off (user_id, day, note, created_by) VALUES (?,?,?,?)');
            $ins->execute([$uid, $day, $note ?: null, $user['id'] ?? null]);
            $daysOffId = (int)$pdo->lastInsertId();
            days_off_sync_create($pdo, $daysOffId);

            header('Location: days_off_list.php?msg=created');
            exit;
          } catch (Throwable $e) {
            $message = 'Errore salvataggio: '.htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
          }
        }
      }
    } else {
      $weekday = (int)$formData['weekday'];
      $until = normalize_days_off_date((string)$formData['until']);
      $formData['until'] = $until;

      if (!isset($weekdays[$weekday]) || !preg_match('#^\d{4}-\d{2}-\d{2}$#', $until)) {
        $message = 'Seleziona giorno della settimana e data finale validi.';
      } else {
        try {
          $today = new DateTimeImmutable('today');
          $end = new DateTimeImmutable($until);
          $first = next_date_for_weekday($today, $weekday);
          if ($end < $first) {
            $message = 'La data finale deve includere almeno una ricorrenza futura.';
          } else {
            $createdIds = [];
            $ins = $pdo->prepare('INSERT INTO days_off (user_id, day, note, created_by) VALUES (?,?,?,?)');
            $exists = $pdo->prepare('SELECT id FROM days_off WHERE user_id = ? AND day = ? AND deleted_at IS NULL LIMIT 1');
            for ($date = $first; $date <= $end; $date = $date->modify('+1 week')) {
              $day = $date->format('Y-m-d');
              $exists->execute([$uid, $day]);
              if ($exists->fetchColumn()) continue;
              $ins->execute([$uid, $day, $note ?: null, $user['id'] ?? null]);
              $createdIds[] = (int)$pdo->lastInsertId();
            }
            foreach ($createdIds as $daysOffId) {
              days_off_sync_create($pdo, $daysOffId);
            }
            header('Location: days_off_list.php?msg=created_repeated&count=' . count($createdIds));
            exit;
          }
        } catch (Throwable $e) {
          $message = 'Errore salvataggio ricorrenze: '.htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        }
      }
    }
  }
}

$users = $pdo->query("SELECT id, nome, cognome, dipartimento FROM users WHERE deleted_at IS NULL ORDER BY cognome, nome")->fetchAll(PDO::FETCH_ASSOC);

$title = 'Nuovo Giorno Libero';
include __DIR__ . '/partials/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h5 mb-0">Nuovo Giorno Libero</h1>
  <a href="days_off_list.php" class="btn btn-outline-secondary btn-sm">Torna alla lista</a>
</div>

<?php if($message): ?><div class="alert alert-warning"><?= e($message) ?></div><?php endif; ?>

<?php if ($conflictRows): ?>
  <div class="card border-warning mb-3">
    <div class="card-body">
      <h2 class="h6">Giorni liberi futuri già presenti</h2>
      <p class="mb-2">Per questo utente esistono già <?= count($conflictRows) ?> giorni liberi futuri. Puoi sostituire solo il prossimo giorno libero oppure spostare tutti i futuri sulla nuova ricorrenza settimanale.</p>
      <ul class="small mb-3">
        <?php foreach (array_slice($conflictRows, 0, 5) as $row): ?>
          <li><?= e((new DateTimeImmutable($row['day']))->format('d/m/Y')) ?><?= $row['note'] ? ' — '.e($row['note']) : '' ?></li>
        <?php endforeach; ?>
        <?php if (count($conflictRows) > 5): ?><li>… altri <?= count($conflictRows) - 5 ?></li><?php endif; ?>
      </ul>
      <div class="d-flex flex-wrap gap-2">
        <form method="post">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="user_id" value="<?= e($formData['user_id']) ?>">
          <input type="hidden" name="mode" value="specific">
          <input type="hidden" name="day" value="<?= e($formData['day']) ?>">
          <input type="hidden" name="note" value="<?= e($formData['note']) ?>">
          <input type="hidden" name="conflict_action" value="replace_next">
          <button class="btn btn-warning">Sostituisci il prossimo giorno libero</button>
        </form>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="user_id" value="<?= e($formData['user_id']) ?>">
          <input type="hidden" name="mode" value="specific">
          <input type="hidden" name="day" value="<?= e($formData['day']) ?>">
          <input type="hidden" name="note" value="<?= e($formData['note']) ?>">
          <input type="hidden" name="conflict_action" value="update_all">
          <button class="btn btn-outline-warning">Modifica tutti i futuri</button>
        </form>
        <a class="btn btn-outline-secondary" href="days_off_create.php">Annulla</a>
      </div>
    </div>
  </div>
<?php endif; ?>

<div class="card">
  <div class="card-body">
    <form method="post">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

      <div class="mb-3">
        <label class="form-label">Utente</label>
        <select name="user_id" class="form-select" required>
          <option value="">— Seleziona —</option>
          <?php foreach ($users as $u): ?>
            <option value="<?= (int)$u['id'] ?>" <?= (int)$formData['user_id'] === (int)$u['id'] ? 'selected' : '' ?>>
              <?= e(trim(($u['cognome'] ?? '').' '.($u['nome'] ?? ''))) ?> (<?= e(departments_label($u['dipartimento'])) ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="mb-3">
        <label class="form-label">Tipo inserimento</label>
        <div class="form-check">
          <input class="form-check-input" type="radio" name="mode" value="specific" id="mode_specific" <?= $formData['mode'] !== 'weekly' ? 'checked' : '' ?>>
          <label class="form-check-label" for="mode_specific">Giorno specifico</label>
        </div>
        <div class="form-check">
          <input class="form-check-input" type="radio" name="mode" value="weekly" id="mode_weekly" <?= $formData['mode'] === 'weekly' ? 'checked' : '' ?>>
          <label class="form-check-label" for="mode_weekly">Ripetuto per giorno della settimana</label>
        </div>
      </div>

      <div class="row g-3">
        <div class="col-md-4">
          <label class="form-label">Data specifica</label>
          <input type="date" name="day" class="form-control" value="<?= e($formData['day']) ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">Giorno della settimana</label>
          <select name="weekday" class="form-select">
            <?php foreach ($weekdays as $num => $label): ?>
              <option value="<?= (int)$num ?>" <?= (int)$formData['weekday'] === (int)$num ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label">Ripeti fino al</label>
          <input type="date" name="until" class="form-control" value="<?= e($formData['until']) ?>">
        </div>
      </div>

      <div class="mt-3 mb-3">
        <label class="form-label">Note (facoltative)</label>
        <input type="text" name="note" class="form-control" maxlength="255" value="<?= e($formData['note']) ?>" placeholder="es. permesso, cambio turno...">
      </div>

      <div class="d-flex gap-2">
        <button class="btn btn-primary">Salva</button>
        <a href="days_off_list.php" class="btn btn-outline-secondary">Annulla</a>
      </div>
    </form>
  </div>
</div>

<?php include __DIR__ . '/partials/footer.php'; ?>
