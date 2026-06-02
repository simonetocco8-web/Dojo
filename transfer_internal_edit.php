<?php
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/security.php';
require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/roles.php';
require_once __DIR__ . '/core/sms.php';
require_once __DIR__ . '/google/calendar_transfers.php';
start_session();
$env  = require __DIR__ . '/config/env.php';
$base = rtrim($env['app']['base_url'] ?? '', '/');
$pdo  = db();
$user = current_user();
if (!$user) { header('Location: ' . $base . '/index.php?msg=auth'); exit; }
if (!user_is_reception_or_amministrazione($user)) { http_response_code(403); exit('Permesso negato.'); }

$predef = ['Coop','Stazione Ricadi','Ristorante La Notte','Ristorante Europa','Ristorante Campagnola','Ristorante da Mimma'];
$message = '';
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) { header('Location: ' . $base . '/transfers_internal.php'); exit; }

$stmt = $pdo->prepare('SELECT * FROM transfers_internal WHERE id = ? AND deleted_at IS NULL LIMIT 1');
$stmt->execute([$id]);
$transfer = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$transfer) { header('Location: ' . $base . '/transfers_internal.php?msg=' . rawurlencode('Transfer non trovato.')); exit; }

$form = [
  'room_number' => $transfer['room_number'],
  'direction' => $transfer['direction'],
  'date' => (new DateTime($transfer['when_at']))->format('Y-m-d'),
  'time' => (new DateTime($transfer['when_at']))->format('H:i'),
  'location_predef' => in_array($transfer['location'], $predef, true) ? $transfer['location'] : $predef[0],
  'location_custom' => in_array($transfer['location'], $predef, true) ? '' : $transfer['location'],
  'loc_mode' => in_array($transfer['location'], $predef, true) ? 'predef' : 'custom',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $form['room_number'] = trim($_POST['room_number'] ?? '');
  $form['direction'] = $_POST['direction'] ?? 'da';
  $form['loc_mode'] = $_POST['loc_mode'] ?? 'predef';
  $form['location_predef'] = $_POST['location_predef'] ?? $predef[0];
  $form['location_custom'] = trim($_POST['location_custom'] ?? '');
  $form['date'] = $_POST['date'] ?? '';
  $form['time'] = $_POST['time'] ?? '';

  if (!csrf_check($_POST['csrf'] ?? '')) {
    $message = 'Token CSRF non valido.';
  } else {
    $room = $form['room_number'];
    $direction = $form['direction'];
    $location = '';
    if ($form['loc_mode'] === 'predef') {
      if (!in_array($form['location_predef'], $predef, true)) { $message = 'Località predefinita non valida.'; }
      else { $location = $form['location_predef']; }
    } else {
      $location = $form['location_custom'];
      if ($location === '') { $message = 'Inserire una località.'; }
    }

    if (!$message && $room && in_array($direction, ['da','per'], true) && $form['date'] && $form['time']) {
      $whenAt = DateTime::createFromFormat('Y-m-d H:i', $form['date'].' '.$form['time']);
      if (!$whenAt) {
        $message = 'Data/Ora non valida.';
      } else {
        $q = 'SELECT id FROM transfers_internal_blocks WHERE deleted_at IS NULL AND start_at <= ? AND end_at >= ? LIMIT 1';
        $blockStmt = $pdo->prepare($q);
        $blockStmt->execute([$whenAt->format('Y-m-d H:i:s'), $whenAt->format('Y-m-d H:i:s')]);
        if ($blockStmt->fetch()) {
          $message = 'Periodo bloccato: impossibile inserire il transfer a questa data/ora.';
        } else {
          $upd = $pdo->prepare('UPDATE transfers_internal SET room_number = ?, direction = ?, location = ?, when_at = ? WHERE id = ? AND deleted_at IS NULL');
          $upd->execute([$room, $direction, $location, $whenAt->format('Y-m-d H:i:s'), $id]);
          ensure_transfer_internal_sms_reminders_table($pdo);
          $pdo->prepare('DELETE FROM transfer_internal_sms_reminders WHERE transfer_id = ?')->execute([$id]);

          $messages = [];
          try {
            gcal_recreate_event_for_internal_transfer($pdo, $id);
          } catch (Throwable $e) {
            error_log('Google Calendar update failed: '.$e->getMessage());
            $messages[] = 'calendar_error: ' . $e->getMessage();
          }

          try {
            $navStmt = $pdo->prepare("\n              SELECT telefono\n              FROM users\n              WHERE deleted_at IS NULL\n                AND is_active = 1\n                AND telefono <> ''\n                AND FIND_IN_SET('Navettista', REPLACE(dipartimento, ' ', '')) > 0\n            ");
            $navStmt->execute();
            $navettistaPhones = $navStmt->fetchAll(PDO::FETCH_COLUMN);

            sms_send_internal_transfer($env, [
              'label' => 'MODIFICA',
              'room_number' => $room,
              'direction' => $direction,
              'location' => $location,
              'date' => $whenAt->format('d/m/Y'),
              'time' => $form['time'],
              'recipients' => $navettistaPhones,
            ]);
          } catch (Throwable $e) {
            error_log('SMS transfer update failed: ' . $e->getMessage());
            $messages[] = 'sms_error: ' . $e->getMessage();
          }

          $redir = $base . '/transfers_internal.php?msg=' . rawurlencode($messages ? ('updated | ' . implode(' | ', $messages)) : 'updated');
          header('Location: ' . $redir);
          exit;
        }
      }
    } elseif (!$message) {
      $message = 'Compila tutti i campi obbligatori.';
    }
  }
}

$title = 'Modifica Transfer Interno';
include __DIR__ . '/partials/header.php';
?>
<div class="row justify-content-center">
  <div class="col-12 col-lg-7 col-xl-6">
    <div class="card shadow-sm">
      <div class="card-body">
        <h1 class="h5 mb-3">Modifica Transfer Interno #<?= (int)$id ?></h1>
        <?php if($message): ?><div class="alert alert-info"><?= e($message) ?></div><?php endif; ?>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="id" value="<?= (int)$id ?>">
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label">Camera</label>
              <input type="text" name="room_number" class="form-control" value="<?= e($form['room_number']) ?>" required>
            </div>
            <div class="col-md-4">
              <label class="form-label">Verso</label>
              <select name="direction" class="form-select">
                <option value="da" <?= $form['direction']==='da' ? 'selected' : '' ?>>da</option>
                <option value="per" <?= $form['direction']==='per' ? 'selected' : '' ?>>per</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Data</label>
              <input type="date" name="date" class="form-control" value="<?= e($form['date']) ?>" required>
            </div>
            <div class="col-md-4">
              <label class="form-label">Ora</label>
              <input type="time" name="time" class="form-control" value="<?= e($form['time']) ?>" required>
            </div>
            <div class="col-md-8">
              <label class="form-label">Località</label>
              <div class="d-flex gap-2">
                <select name="location_predef" class="form-select" id="loc_predef">
                  <?php foreach($predef as $p): ?>
                    <option value="<?= e($p) ?>" <?= $form['location_predef']===$p ? 'selected' : '' ?>><?= e($p) ?></option>
                  <?php endforeach; ?>
                </select>
                <div class="form-check d-flex align-items-center">
                  <input class="form-check-input" type="radio" name="loc_mode" value="predef" id="loc_mode_predef" <?= $form['loc_mode']==='predef' ? 'checked' : '' ?>>
                  <label class="form-check-label ms-1" for="loc_mode_predef">Predef.</label>
                </div>
              </div>
              <div class="d-flex gap-2 mt-2">
                <input type="text" name="location_custom" class="form-control" value="<?= e($form['location_custom']) ?>" placeholder="Oppure inserisci manualmente...">
                <div class="form-check d-flex align-items-center">
                  <input class="form-check-input" type="radio" name="loc_mode" value="custom" id="loc_mode_custom" <?= $form['loc_mode']==='custom' ? 'checked' : '' ?>>
                  <label class="form-check-label ms-1" for="loc_mode_custom">Manuale</label>
                </div>
              </div>
            </div>
          </div>
          <div class="mt-3 d-flex gap-2">
            <button class="btn btn-primary">Salva modifiche</button>
            <a class="btn btn-outline-secondary" href="<?= e($base) ?>/transfers_internal.php">Annulla</a>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/partials/footer.php'; ?>
