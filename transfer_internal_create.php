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
ensure_transfer_locations_table($pdo);
$user = current_user();
if (!$user) { header('Location: ' . $base . '/index.php?msg=auth'); exit; }

$locationRows = $pdo->query('SELECT name, distance_km FROM transfer_locations WHERE is_active = 1 ORDER BY name ASC')->fetchAll();
$predef = array_column($locationRows, 'name');
$hasPredefinedLocations = !empty($predef);
$message = '';

if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (!csrf_check($_POST['csrf'] ?? '')) {
    $message = 'Token CSRF non valido.';
  } else {
    $room = trim($_POST['room_number'] ?? '');
    $direction = $_POST['direction'] ?? 'da';
    $use_predef = $_POST['loc_mode'] ?? ($hasPredefinedLocations ? 'predef' : 'custom');
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
  <div class="col-12 col-lg-9 col-xl-8">
    <div class="card shadow-sm">
      <div class="card-body p-3 p-md-4">
        <div class="text-muted small text-uppercase fw-semibold mb-1">Transfer interni</div>
        <h1 class="h4 mb-2">Nuovo transfer interno</h1>
        <p class="text-muted mb-4">Indica camera, data e orario, poi scegli persone, direzione e località.</p>
        <?php if($message): ?><div class="alert alert-danger" role="alert"><?= e($message) ?></div><?php endif; ?>
        <form method="post" id="internalTransferCreateForm" data-wait-feedback="Creazione transfer e invio SMS in corso...">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <div class="row g-3">
            <div class="col-12"><h2 class="h6 border-bottom pb-2 mb-0">Quando e per chi</h2></div>
            <div class="col-md-4">
              <label class="form-label" for="transfer_room_number">Camera</label>
              <input type="text" name="room_number" id="transfer_room_number" class="form-control" required>
            </div>
            <div class="col-md-4">
              <label class="form-label" for="transfer_date">Data</label>
              <input type="date" name="date" id="transfer_date" class="form-control" required>
            </div>
            <div class="col-md-4">
              <label class="form-label" for="transfer_time">Ora</label>
              <input type="time" name="time" id="transfer_time" class="form-control" required>
            </div>
            <div class="col-12"><h2 class="h6 border-bottom pb-2 mb-0 mt-2">Dettagli del tragitto</h2></div>
            <div class="col-md-4">
              <label class="form-label" for="transfer_people_count">Persone</label>
              <input type="number" name="people_count" id="transfer_people_count" class="form-control" min="1" step="1" value="1" required>
            </div>
            <div class="col-md-4">
              <label class="form-label" for="transfer_direction">Verso</label>
              <select name="direction" id="transfer_direction" class="form-select">
                <option value="da">Da località alla struttura</option>
                <option value="per">Dalla struttura alla località</option>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label" for="loc_predef">Località predefinita</label>
              <div class="d-flex flex-column flex-sm-row gap-2">
                <select name="location_predef" class="form-select" id="loc_predef" <?= !$hasPredefinedLocations ? 'disabled' : '' ?>>
                  <?php foreach($predef as $p): ?>
                    <?php $locationRow = $locationRows[array_search($p, $predef, true)] ?? null; ?>
                    <option value="<?= e($p) ?>"><?= e($p) ?><?= $locationRow ? ' · ' . e(number_format((float)$locationRow['distance_km'], 2, ',', '.')) . ' km' : '' ?></option>
                  <?php endforeach; ?>
                </select>
                <div class="form-check d-flex align-items-center">
                  <input class="form-check-input" type="radio" name="loc_mode" value="predef" id="loc_mode_predef" <?= $hasPredefinedLocations ? 'checked' : 'disabled' ?>>
                  <label class="form-check-label ms-1" for="loc_mode_predef">Predefinita</label>
                </div>
              </div>
              <label class="form-label mt-3" for="location_custom">Località manuale</label>
              <div class="d-flex flex-column flex-sm-row gap-2">
                <input type="text" name="location_custom" id="location_custom" class="form-control" placeholder="Oppure inserisci manualmente...">
                <div class="form-check d-flex align-items-center">
                  <input class="form-check-input" type="radio" name="loc_mode" value="custom" id="loc_mode_custom" <?= !$hasPredefinedLocations ? 'checked' : '' ?>>
                  <label class="form-check-label ms-1" for="loc_mode_custom">Manuale</label>
                </div>
              </div>
            </div>
            <div class="col-12">
              <label class="form-label" for="transfer_note">Note</label>
              <input type="text" name="note" id="transfer_note" class="form-control" maxlength="255" placeholder="Nota opzionale su singola riga">
            </div>
          </div>
          <div class="mt-4 pt-3 border-top d-flex flex-column flex-sm-row gap-2">
            <button class="btn btn-primary"><i class="bi bi-check2-circle me-1"></i>Crea transfer</button>
            <a class="btn btn-outline-secondary" href="<?= e($base) ?>/transfers_internal.php">Annulla</a>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>
<div class="modal fade" id="coopOpeningModal" tabindex="-1" aria-labelledby="coopOpeningModalTitle" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
  <div class="modal-dialog modal-dialog-centered"><div class="modal-content border-0 shadow">
    <div class="modal-header border-0 pb-0"><div class="d-flex align-items-center gap-3"><span class="d-inline-flex align-items-center justify-content-center rounded-circle bg-warning-subtle text-warning-emphasis" style="width:44px;height:44px"><i class="bi bi-shop fs-4"></i></span><div><div class="text-muted small">Verifica apertura</div><h2 class="modal-title h5 mb-0" id="coopOpeningModalTitle">Conferma transfer Coop</h2></div></div><button type="button" class="btn-close" id="coopOpeningClose" aria-label="Chiudi"></button></div>
    <div class="modal-body py-4"><p class="mb-0" id="coopOpeningModalMessage"></p></div>
    <div class="modal-footer border-0 pt-0"><button type="button" class="btn btn-outline-secondary" id="coopOpeningChange"><i class="bi bi-pencil me-1"></i><span>Modifica</span></button><button type="button" class="btn btn-primary" id="coopOpeningConfirm"><i class="bi bi-check2-circle me-1"></i><span>Conferma</span></button></div>
  </div></div>
</div>
<?php $pageScripts = ['assets/transfer-internal-create.js']; ?>
<?php include __DIR__ . '/partials/footer.php'; ?>
