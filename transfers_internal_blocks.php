<?php
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/security.php';
require_once __DIR__ . '/core/db.php';
start_session();
$env  = require __DIR__ . '/config/env.php';
$base = rtrim($env['app']['base_url'] ?? '', '/');
$pdo  = db();
$user = current_user();
if (!$user) { header('Location: ' . $base . '/index.php?msg=auth'); exit; }

$st = $pdo->prepare('SELECT role FROM users WHERE id=?');
$st->execute([$user['id']]);
$is_admin = ($st->fetch()['role'] ?? '') === 'admin';

$message = '';

if ($_SERVER['REQUEST_METHOD']==='POST' && $is_admin) {
  if (!csrf_check($_POST['csrf'] ?? '')) {
    $message = 'Token CSRF non valido.';
  } else {
    if (($_POST['action'] ?? '') === 'create') {
      $start = $_POST['start'] ?? '';
      $end   = $_POST['end'] ?? '';
      $note  = trim($_POST['note'] ?? '');
      $s = DateTime::createFromFormat('Y-m-d\TH:i', $start);
      $e = DateTime::createFromFormat('Y-m-d\TH:i', $end);
      if (!$s || !$e || $e <= $s) {
        $message = 'Intervallo non valido.';
      } else {
        $q = 'INSERT INTO transfers_internal_blocks (start_at, end_at, note, created_by) VALUES (?,?,?,?)';
        $pdo->prepare($q)->execute([$s->format('Y-m-d H:i:s'), $e->format('Y-m-d H:i:s'), $note, $user['id']]);
      }
    } elseif (($_POST['action'] ?? '') === 'delete') {
      $id = (int)($_POST['id'] ?? 0);
      $pdo->prepare('UPDATE transfers_internal_blocks SET deleted_at=NOW() WHERE id=? AND deleted_at IS NULL')->execute([$id]);
    } elseif (($_POST['action'] ?? '') === 'restore') {
      $id = (int)($_POST['id'] ?? 0);
      $pdo->prepare('UPDATE transfers_internal_blocks SET deleted_at=NULL WHERE id=? AND deleted_at IS NOT NULL')->execute([$id]);
    }
  }
}

$rows = $pdo->query('SELECT * FROM transfers_internal_blocks ORDER BY COALESCE(deleted_at, created_at) DESC')->fetchAll();

$title = 'Transfere — Periodi Bloccati';
include __DIR__ . '/partials/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h5 mb-0">Periodi Bloccati (Transfer Interni)</h1>
  <div><a class="btn btn-outline-secondary btn-sm" href="<?= e($base) ?>/transfers_internal.php">← Torna ai Transfer</a></div>
</div>

<div class="row g-4">
  <div class="col-12 col-lg-5">
    <div class="card shadow-sm">
      <div class="card-body">
        <h2 class="h6">Nuovo periodo bloccato</h2>
        <?php if(!$is_admin): ?>
          <div class="alert alert-warning">Solo admin possono creare/modificare i periodi.</div>
        <?php endif; ?>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="create">
          <div class="mb-3">
            <label class="form-label">Inizio</label>
            <input type="datetime-local" name="start" class="form-control" <?= $is_admin?'':'disabled' ?> required>
          </div>
          <div class="mb-3">
            <label class="form-label">Fine</label>
            <input type="datetime-local" name="end" class="form-control" <?= $is_admin?'':'disabled' ?> required>
          </div>
          <div class="mb-3">
            <label class="form-label">Nota (opzionale)</label>
            <input type="text" name="note" class="form-control" <?= $is_admin?'':'disabled' ?>>
          </div>
          <button class="btn btn-primary" <?= $is_admin?'':'disabled' ?>>Salva</button>
        </form>
      </div>
    </div>
  </div>
  <div class="col-12 col-lg-7">
    <div class="card shadow-sm">
      <div class="card-body">
        <h2 class="h6">Elenco periodi</h2>
        <div class="table-responsive">
          <table class="table table-sm align-middle">
            <thead><tr><th>Inizio</th><th>Fine</th><th>Nota</th><th>Stato</th><th></th></tr></thead>
            <tbody>
              <?php foreach($rows as $r): ?>
              <tr>
                <td><?php $s=new DateTime($r['start_at']); echo $s->format('d/m/y H:i'); ?></td>
                <td><?php $e=new DateTime($r['end_at']); echo $e->format('d/m/y H:i'); ?></td>
                <td><?= e($r['note'] ?? '') ?></td>
                <td><?= $r['deleted_at'] ? '<span class="badge bg-secondary">Cestinato</span>' : '<span class="badge bg-success">Attivo</span>' ?></td>
                <td class="text-nowrap">
                  <?php if($is_admin && !$r['deleted_at']): ?>
                    <form method="post" class="d-inline" data-confirm-message="Cestinare questo periodo?">
                      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                      <button class="btn btn-sm btn-outline-danger">Cestina</button>
                    </form>
                  <?php elseif($is_admin): ?>
                    <form method="post" class="d-inline">
                      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                      <input type="hidden" name="action" value="restore">
                      <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                      <button class="btn btn-sm btn-outline-secondary">Ripristina</button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/partials/footer.php'; ?>
