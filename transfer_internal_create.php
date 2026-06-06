<?php
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/security.php';
require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/sms.php';
require_once __DIR__ . '/google/calendar_transfers.php';
start_session();
$env  = require __DIR__ . '/config/env.php';
$base = rtrim($env['app']['base_url'] ?? '', '/');
$pdo  = db();
ensure_transfer_internal_details_columns($pdo);
$user = current_user();
if (!$user) { header('Location: ' . $base . '/index.php?msg=auth'); exit; }

$predef = ['Coop','Stazione Ricadi','Ristorante La Notte','Ristorante Europa','Ristorante Campagnola','Ristorante da Mimma'];
$message = '';

if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (!csrf_check($_POST['csrf'] ?? '')) {
    $message = 'Token CSRF non valido.';
  } else {
    $room = trim($_POST['room_number'] ?? '');
    $direction = $_POST['direction'] ?? 'da';
    $use_predef = $_POST['loc_mode'] ?? 'predef';
    $location = '';
    if ($use_predef === 'predef') {
      $lp = $_POST['location_predef'] ?? '';
      if (!in_array($lp, $predef, true)) { $message = 'Località predefinita non valida.'; }
      else { $location = $lp; }
    } else {
      $location = trim($_POST['location_custom'] ?? '');
      if ($location === '') { $message = 'Inserire una località.'; }
    }
    $date = $_POST['date'] ?? '';
    $time = $_POST['time'] ?? '';
    $peopleCount = (int)($_POST['people_count'] ?? 1);
    $note = trim(preg_replace('/\s+/u', ' ', (string)($_POST['note'] ?? '')));
    if (function_exists('mb_substr')) {
      $note = mb_substr($note, 0, 255, 'UTF-8');
    } else {
      $note = substr($note, 0, 255);
    }
    if (!$message && $peopleCount <= 0) {
      $message = 'Inserire un numero di persone valido.';
    }
    if (!$message && $room && in_array($direction, ['da','per'], true) && $date && $time) {
      $when_at = DateTime::createFromFormat('Y-m-d H:i', $date.' '.$time);
      if (!$when_at) {
        $message = 'Data/Ora non valida.';
      } else {
        $q = 'SELECT id FROM transfers_internal_blocks WHERE deleted_at IS NULL AND start_at <= ? AND end_at >= ? LIMIT 1';
        $stmt = $pdo->prepare($q);
        $stmt->execute([$when_at->format('Y-m-d H:i:s'), $when_at->format('Y-m-d H:i:s')]);
        $blk = $stmt->fetch();
        if ($blk) {
          $message = 'Periodo bloccato: impossibile inserire il transfer a questa data/ora.';
        } else {
          $ins = $pdo->prepare('INSERT INTO transfers_internal (room_number, direction, location, people_count, note, when_at, created_by) VALUES (?,?,?,?,?,?,?)');
          $ins->execute([$room, $direction, $location, $peopleCount, $note !== '' ? $note : null, $when_at->format('Y-m-d H:i:s'), $user['id']]);

          $transferId = (int)$pdo->lastInsertId();

          $messages = [];
          try {
            gcal_create_event_for_internal_transfer($pdo, $transferId);
          } catch (Throwable $e) {
            error_log('Google Calendar create failed: '.$e->getMessage());
            $messages[] = 'calendar_error: ' . $e->getMessage();
          }

          try {
            $navStmt = $pdo->prepare("
              SELECT telefono
              FROM users
              WHERE deleted_at IS NULL
                AND is_active = 1
                AND telefono <> ''
                AND FIND_IN_SET('Navettista', REPLACE(dipartimento, ' ', '')) > 0
            ");
            $navStmt->execute();
            $navettistaPhones = $navStmt->fetchAll(PDO::FETCH_COLUMN);

            sms_send_internal_transfer($env, [
              'room_number' => $room,
              'direction' => $direction,
              'location' => $location,
              'date' => $when_at->format('d/m/Y'),
              'time' => $time,
              'people_count' => $peopleCount,
              'note' => $note,
              'recipients' => $navettistaPhones,
            ]);
          } catch (Throwable $e) {
            error_log('SMS send failed: ' . $e->getMessage());
            $messages[] = 'sms_error: ' . $e->getMessage();
          }

          $redir = $base . '/transfers_internal.php';
          if (!empty($messages)) {
            $redir .= '?msg=' . rawurlencode(implode(' | ', $messages));
          }
          header('Location: ' . $redir);
          exit;
        }
      }
    } else if(!$message) {
      $message = 'Compila tutti i campi obbligatori.';
    }
  }
}

$title = 'Nuovo Transfer Interno';
include __DIR__ . '/partials/header.php';
?>
<div class="row justify-content-center">
  <div class="col-12 col-lg-7 col-xl-6">
    <div class="card shadow-sm">
      <div class="card-body">
        <h1 class="h5 mb-3">Nuovo Transfer Interno</h1>
        <?php if($message): ?><div class="alert alert-info"><?= e($message) ?></div><?php endif; ?>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label">Camera</label>
              <input type="text" name="room_number" class="form-control" required>
            </div>
            <div class="col-md-4">
              <label class="form-label">Verso</label>
              <select name="direction" class="form-select">
                <option value="da">da</option>
                <option value="per">per</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Data</label>
              <input type="date" name="date" class="form-control" required>
            </div>
            <div class="col-md-4">
              <label class="form-label">Ora</label>
              <input type="time" name="time" class="form-control" required>
            </div>
            <div class="col-md-4">
              <label class="form-label">Persone</label>
              <input type="number" name="people_count" class="form-control" min="1" step="1" value="1" required>
            </div>
            <div class="col-md-8">
              <label class="form-label">Località</label>
              <div class="d-flex gap-2">
                <select name="location_predef" class="form-select" id="loc_predef">
                  <?php foreach($predef as $p): ?>
                    <option value="<?= e($p) ?>"><?= e($p) ?></option>
                  <?php endforeach; ?>
                </select>
                <div class="form-check d-flex align-items-center">
                  <input class="form-check-input" type="radio" name="loc_mode" value="predef" id="loc_mode_predef" checked>
                  <label class="form-check-label ms-1" for="loc_mode_predef">Predef.</label>
                </div>
              </div>
              <div class="d-flex gap-2 mt-2">
                <input type="text" name="location_custom" class="form-control" placeholder="Oppure inserisci manualmente...">
                <div class="form-check d-flex align-items-center">
                  <input class="form-check-input" type="radio" name="loc_mode" value="custom" id="loc_mode_custom">
                  <label class="form-check-label ms-1" for="loc_mode_custom">Manuale</label>
                </div>
              </div>
            </div>
            <div class="col-12">
              <label class="form-label">Note</label>
              <input type="text" name="note" class="form-control" maxlength="255" placeholder="Nota opzionale su singola riga">
            </div>
          </div>
          <div class="mt-3 d-flex gap-2">
            <button class="btn btn-primary">Crea transfer</button>
            <a class="btn btn-outline-secondary" href="<?= e($base) ?>/transfers_internal.php">Annulla</a>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/partials/footer.php'; ?>
