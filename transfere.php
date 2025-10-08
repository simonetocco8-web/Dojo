<?php
// transfere.php — pagina indice per scegliere tra Interni/Esterni
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/security.php';
require_once __DIR__ . '/core/db.php';
start_session();
$env  = require __DIR__ . '/config/env.php';
$base = rtrim($env['app']['base_url'] ?? '', '/');
$user = current_user();
if (!$user) { header('Location: ' . $base . '/index.php?msg=auth'); exit; }

$title = 'Transfere';
include __DIR__ . '/partials/header.php';
?>

<div class="container my-5">
  <div class="row justify-content-center text-center g-4">
    <div class="col-12 col-md-6">
      <a href="<?= e($base) ?>/transfers_internal.php" class="btn btn-lg btn-outline-primary w-100 py-5 shadow-sm">
        <i class="bi bi-building-check" style="font-size:2rem;"></i><br>
        <span class="fs-4">Transfer Interni</span>
      </a>
    </div>
    <div class="col-12 col-md-6">
      <a href="<?= e($base) ?>/transfers_external.php" class="btn btn-lg btn-outline-success w-100 py-5 shadow-sm">
        <i class="bi bi-bus-front" style="font-size:2rem;"></i><br>
        <span class="fs-4">Transfer Esterni</span>
      </a>
    </div>
  </div>
</div>

<?php include __DIR__ . '/partials/footer.php'; ?>
