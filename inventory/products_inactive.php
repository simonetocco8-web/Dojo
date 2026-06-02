<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/roles.php';

start_session();
$csrf = csrf_token();
$env = require __DIR__ . '/../config/env.php';
$base = rtrim($env['app']['base_url'] ?? '', '/');
$pdo = db();
ensure_products_active_column($pdo);
$user = current_user();

if (!$user || !user_is_bar_or_amministrazione($user)) {
  http_response_code(403);
  exit('Permesso negato.');
}

$msg = (string)($_GET['msg'] ?? '');

$stmt = $pdo->query(""
  . "SELECT p.id, p.title, p.ean13, p.category, p.unit, p.min_qty, p.max_qty, s.name AS supplier_name, "
  . "COALESCE(SUM(sl.qty), 0) AS total_qty "
  . "FROM products p "
  . "LEFT JOIN suppliers s ON s.id = p.supplier_id "
  . "LEFT JOIN stock_levels sl ON sl.product_id = p.id "
  . "WHERE p.is_active = 0 "
  . "GROUP BY p.id, p.title, p.ean13, p.category, p.unit, p.min_qty, p.max_qty, s.name "
  . "ORDER BY p.title ASC"
);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$title = 'Prodotti non attivi';
include __DIR__ . '/../partials/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h5 mb-0">Prodotti non attivi</h1>
  <a class="btn btn-outline-secondary btn-sm" href="<?= e($base) ?>/inventory/products.php">← Torna ai prodotti</a>
</div>

<?php if ($msg === 'activated'): ?>
  <div class="alert alert-success">Prodotto riattivato.</div>
<?php elseif ($msg === 'deleted'): ?>
  <div class="alert alert-success">Prodotto eliminato definitivamente.</div>
<?php elseif ($msg === 'error'): ?>
  <div class="alert alert-danger">Operazione non completata. Controlla il log errori.</div>
<?php endif; ?>

<div class="card shadow-sm">
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <thead>
        <tr>
          <th>Prodotto</th>
          <th style="width:140px;">Categoria</th>
          <th style="width:90px;">UM</th>
          <th class="text-center" style="width:90px;">Tot</th>
          <th style="width:180px;">Fornitore</th>
          <th class="text-end" style="width:150px;">Azioni</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td class="fw-semibold"><?= e($r['title']) ?></td>
            <td><?= e($r['category'] ?? '') ?></td>
            <td><?= e($r['unit'] ?? '') ?></td>
            <td class="text-center"><?= (float)$r['total_qty'] ?></td>
            <td><?= $r['supplier_name'] ? e($r['supplier_name']) : '<span class="text-muted">—</span>' ?></td>
            <td class="text-end text-nowrap">
              <form method="post" action="<?= e($base) ?>/inventory/product_action.php" class="d-inline" data-confirm-message="Riattivare questo prodotto?">
                <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <input type="hidden" name="action" value="activate">
                <input type="hidden" name="return" value="inactive">
                <button class="btn btn-link btn-sm text-success" title="Riattiva" aria-label="Riattiva"><i class="bi bi-arrow-counterclockwise"></i></button>
              </form>
              <form method="post" action="<?= e($base) ?>/inventory/product_action.php" class="d-inline" data-confirm-message="Eliminare definitivamente questo prodotto dal sistema?">
                <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="return" value="inactive">
                <button class="btn btn-link btn-sm text-danger" title="Elimina definitivamente" aria-label="Elimina definitivamente"><i class="bi bi-trash"></i></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
          <tr><td colspan="6" class="text-center text-muted py-3">Nessun prodotto non attivo.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php include __DIR__ . '/../partials/footer.php'; ?>
