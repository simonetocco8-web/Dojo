<?php
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/security.php';
require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/roles.php';
require_once __DIR__ . '/google/calendar_riassetti.php';
start_session();

$env  = require __DIR__ . '/config/env.php';
$base = rtrim($env['app']['base_url'] ?? '', '/');
$pdo  = db();
$user = current_user();
if (!$user) { header('Location: ' . $base . '/index.php?msg=auth'); exit; }

if (!user_is_reception_or_amministrazione($user)) {
  http_response_code(403);
  echo '<h1>403</h1><p>Accesso riservato ad Amministrazione e Reception.</p>';
  exit;
}

$isEdit = false;
$riassetto = [
  'id' => null,
  'data_riassetto' => '',
  'room' => '',
  'qty_matrimoniale' => 0,
  'qty_singola' => 0,
  'qty_set_bagno' => 0,
  'pulizia_extra' => 0,
  'note' => '',
];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
  if ($id > 0) {
    $stmt = $pdo->prepare('SELECT * FROM riassetti WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
      http_response_code(404);
      echo '<h1>404</h1><p>Riassetto non trovato.</p>';
      exit;
    }
    $riassetto = $row;
    $isEdit = true;
  }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_check($_POST['csrf'] ?? '')) {
    http_response_code(400);
    echo '<h1>400</h1><p>Token CSRF non valido.</p>';
    exit;
  }

  $riassetto['id'] = isset($_POST['id']) ? (int)$_POST['id'] : null;
  $isEdit = $riassetto['id'] !== null && $riassetto['id'] > 0;
  $riassetto['data_riassetto'] = trim($_POST['data_riassetto'] ?? '');
  $riassetto['room'] = trim($_POST['room'] ?? '');
  $riassetto['qty_matrimoniale'] = max(0, (int)($_POST['qty_matrimoniale'] ?? 0));
  $riassetto['qty_singola'] = max(0, (int)($_POST['qty_singola'] ?? 0));
  $riassetto['qty_set_bagno'] = max(0, (int)($_POST['qty_set_bagno'] ?? 0));
  $riassetto['pulizia_extra'] = isset($_POST['pulizia_extra']) ? 1 : 0;
  $riassetto['note'] = trim($_POST['note'] ?? '');

  if ($riassetto['data_riassetto'] === '') {
    $errors[] = 'La data è obbligatoria.';
  } elseif (!DateTime::createFromFormat('Y-m-d', $riassetto['data_riassetto'])) {
    $errors[] = 'La data non è valida.';
  }
  if ($riassetto['room'] === '') {
    $errors[] = 'La camera è obbligatoria.';
  }

  if ($isEdit) {
    $stmt = $pdo->prepare('SELECT * FROM riassetti WHERE id = ? LIMIT 1');
    $stmt->execute([$riassetto['id']]);
    $row = $stmt->fetch();
    if (!$row) {
      $errors[] = 'Riassetto non trovato.';
    } else {
      $riassetto['google_event_id'] = $row['google_event_id'];
    }
  }

  if (!$errors) {
    if ($isEdit) {
      $stmt = $pdo->prepare('UPDATE riassetti
        SET data_riassetto = ?, room = ?, qty_matrimoniale = ?, qty_singola = ?, qty_set_bagno = ?, pulizia_extra = ?, note = ?, updated_by = ?, updated_at = NOW()
        WHERE id = ?');
      $stmt->execute([
        $riassetto['data_riassetto'],
        $riassetto['room'],
        $riassetto['qty_matrimoniale'],
        $riassetto['qty_singola'],
        $riassetto['qty_set_bagno'],
        $riassetto['pulizia_extra'],
        $riassetto['note'],
        $user['id'],
        $riassetto['id'],
      ]);
      $id = $riassetto['id'];
    } else {
      $stmt = $pdo->prepare('INSERT INTO riassetti
        (data_riassetto, room, qty_matrimoniale, qty_singola, qty_set_bagno, pulizia_extra, note, created_by, updated_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
      $stmt->execute([
        $riassetto['data_riassetto'],
        $riassetto['room'],
        $riassetto['qty_matrimoniale'],
        $riassetto['qty_singola'],
        $riassetto['qty_set_bagno'],
        $riassetto['pulizia_extra'],
        $riassetto['note'],
        $user['id'],
        $user['id'],
      ]);
      $id = (int)$pdo->lastInsertId();
    }

    $calendarWarn = false;
    try {
      $eventId = gcal_sync_riassetto($pdo, $id);
      if ($eventId === null) {
        $calendarWarn = true;
      }
    } catch (Exception $e) {
      error_log('Errore salvataggio riassetto: ' . $e->getMessage());
      $calendarWarn = true;
    }

    $query = 'msg=saved';
    if ($calendarWarn) {
      $query .= '&warn=calendar';
    }
    header('Location: ' . $base . '/riassetti.php?' . $query);
    exit;
  }
}

$title = $isEdit ? 'Modifica riassetto' : 'Nuovo riassetto';
include __DIR__ . '/partials/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h5 mb-0"><?= e($title) ?></h1>
  <div class="d-flex gap-2">
    <a class="btn btn-sm btn-outline-secondary" href="<?= e($base) ?>/riassetti.php">&larr; Torna ai riassetti</a>
  </div>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger">
  <ul class="mb-0">
    <?php foreach ($errors as $err): ?>
      <li><?= e($err) ?></li>
    <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<div class="card shadow-sm">
  <div class="card-body">
    <form method="post" class="row g-3">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <?php if ($isEdit): ?>
        <input type="hidden" name="id" value="<?= (int)$riassetto['id'] ?>">
      <?php endif; ?>
      <div class="col-12 col-md-4">
        <label class="form-label">Data *</label>
        <input type="date" name="data_riassetto" class="form-control" value="<?= e($riassetto['data_riassetto']) ?>" required>
      </div>
      <div class="col-12 col-md-4">
        <label class="form-label">Camera *</label>
        <input type="text" name="room" class="form-control" value="<?= e($riassetto['room']) ?>" required>
      </div>
      <div class="col-12 col-md-4">
        <label class="form-label">Pulizia extra</label>
        <div class="form-check">
          <input class="form-check-input" type="checkbox" name="pulizia_extra" id="pulizia_extra" value="1" <?= !empty($riassetto['pulizia_extra']) ? 'checked' : '' ?>>
          <label class="form-check-label" for="pulizia_extra">Richiede pulizia aggiuntiva</label>
        </div>
      </div>
      <div class="col-12 col-md-4">
        <label class="form-label">Biancheria Matrimoniale</label>
        <input type="number" name="qty_matrimoniale" class="form-control" min="0" value="<?= (int)$riassetto['qty_matrimoniale'] ?>">
      </div>
      <div class="col-12 col-md-4">
        <label class="form-label">Biancheria Singola</label>
        <input type="number" name="qty_singola" class="form-control" min="0" value="<?= (int)$riassetto['qty_singola'] ?>">
      </div>
      <div class="col-12 col-md-4">
        <label class="form-label">Set Bagno</label>
        <input type="number" name="qty_set_bagno" class="form-control" min="0" value="<?= (int)$riassetto['qty_set_bagno'] ?>">
      </div>
      <div class="col-12">
        <label class="form-label">Note</label>
        <textarea name="note" class="form-control" rows="4" placeholder="Eventuali note aggiuntive..."><?= e($riassetto['note']) ?></textarea>
      </div>
      <div class="col-12">
        <button type="submit" class="btn btn-primary">Salva</button>
        <a class="btn btn-outline-secondary" href="<?= e($base) ?>/riassetti.php">Annulla</a>
      </div>
    </form>
  </div>
</div>

<?php include __DIR__ . '/partials/footer.php'; ?>
