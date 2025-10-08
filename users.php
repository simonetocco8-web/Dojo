<?php
// users.php — solo LISTA (full width) + soft delete + cestino
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/security.php';
require_once __DIR__ . '/core/db.php';
require_login();      // se vuoi che sia almeno loggato
require_admin();      // e in più admin

$env  = require __DIR__ . '/config/env.php';
$base = rtrim($env['app']['base_url'] ?? '', '/');
$pdo  = db();

$show_trash = isset($_GET['trash']);
$msg = $_GET['msg'] ?? '';

if ($show_trash) {
  $users = $pdo->query('SELECT id, nome, cognome, email, telefono, dipartimento, role, is_active, created_at, deleted_at
                        FROM users WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC')->fetchAll();
  $title = 'Cestino utenti';
} else {
  $users = $pdo->query('SELECT id, nome, cognome, email, telefono, dipartimento, role, is_active, created_at
                        FROM users WHERE deleted_at IS NULL ORDER BY id DESC')->fetchAll();
  $title = 'Utenti';
}

include __DIR__ . '/partials/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h5 mb-0"><?= e($title) ?></h1>
  <div class="d-flex gap-2">
    <?php if(!$show_trash): ?>
      <a class="btn btn-primary btn-sm" href="<?= e($base) ?>/user_create.php">+ Nuovo utente</a>
      <a class="btn btn-outline-secondary btn-sm" href="<?= e($base) ?>/users.php?trash=1">Cestino</a>
    <?php else: ?>
      <a class="btn btn-outline-secondary btn-sm" href="<?= e($base) ?>/users.php">← Torna alla lista</a>
    <?php endif; ?>
  </div>
</div>

<?php if ($msg === 'created'): ?>
  <div class="alert alert-success">Utente creato con successo.</div>
<?php endif; ?>

<div class="card shadow-sm">
  <div class="card-body">
    <div class="table-responsive">
      <table class="table table-sm align-middle">
        <thead>
          <tr>
            <th>ID</th><th>Nome</th><th>Cognome</th><th>Email</th><th>Telefono</th><th>Dipart.</th><th>Ruolo</th><th>Attivo</th><?= $show_trash ? '<th>Eliminato</th>' : '<th>Creato</th>' ?><th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($users as $u): ?>
          <tr>
            <td><?= (int)$u['id'] ?></td>
            <td><?= e($u['nome'] ?? '') ?></td>
            <td><?= e($u['cognome'] ?? '') ?></td>
            <td><?= e($u['email']) ?></td>
            <td><?= e($u['telefono'] ?? '') ?></td>
            <td><?= e($u['dipartimento'] ?? '') ?></td>
            <td><span class="badge bg-secondary"><?= e($u['role']) ?></span></td>
            <td><?= !empty($u['is_active']) ? '✔' : '—' ?></td>
            <?php if($show_trash): ?>
              <td><?= e($u['deleted_at']) ?></td>
            <?php else: ?>
              <td><?= e($u['created_at']) ?></td>
            <?php endif; ?>
            <td class="text-nowrap d-flex gap-2">
              <?php if(!$show_trash): ?>
                <a class="btn btn-sm btn-outline-primary" href="<?= e($base) ?>/user_edit.php?id=<?= (int)$u['id'] ?>">Modifica</a>
                <form method="post" action="<?= e($base) ?>/user_delete.php" onsubmit="return confirm('Confermi di voler eliminare questo utente?');" class="d-inline">
                  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                  <button class="btn btn-sm btn-outline-danger">Elimina</button>
                </form>
              <?php else: ?>
                <form method="post" action="<?= e($base) ?>/user_restore.php" class="d-inline">
                  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                  <button class="btn btn-sm btn-outline-success">Ripristina</button>
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
<?php include __DIR__ . '/partials/footer.php'; ?>
