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

$title = 'Transfere — Interni';
include __DIR__ . '/partials/header.php';

$rows = $pdo->query('SELECT t.*, u.email AS created_by_email
                     FROM transfers_internal t
                     JOIN users u ON u.id = t.created_by
                     WHERE t.deleted_at IS NULL
                     ORDER BY t.when_at ASC, t.id DESC')->fetchAll();
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h5 mb-0">Transfer Interni</h1>
  <div class="d-flex gap-2">
    <a class="btn btn-outline-secondary btn-sm" href="<?= e($base) ?>/transfers_internal_blocks.php">Periodi Bloccati</a>
    <a class="btn btn-primary btn-sm" href="<?= e($base) ?>/transfer_internal_create.php">+ Nuovo transfer</a>
  </div>
</div>

<div class="card shadow-sm">
  <div class="card-body">
    <div class="table-responsive">
      <table class="table table-sm align-middle">
        <thead>
          <tr>
            <th>Data</th>
            <th>Ora</th>
            <th>Camera</th>
            <th>Verso</th>
            <th>Località</th>
            <th>Creato da</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($rows as $r): ?>
          <tr>
            <td><?php $dt=new DateTime($r['when_at']); echo $dt->format('d/m/y'); ?></td>
            <td><?php $dt=new DateTime($r['when_at']); echo $dt->format('H:i'); ?></td>
            <td><?= e($r['room_number']) ?></td>
            <td><?= e(strtoupper($r['direction'])) ?></td>
            <td><?= e($r['location']) ?></td>
            <td class="small text-muted"><?= e($r['created_by_email']) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php include __DIR__ . '/partials/footer.php'; ?>
