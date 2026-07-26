<?php
declare(strict_types=1);

require_once __DIR__ . '/_role_guard.php';
require_once __DIR__ . '/orders_common.php';

if (!$user) { header('Location: ../login.php?msg=auth'); exit; }
if (!is_bar_or_amministrazione()) { http_response_code(403); exit('Permesso negato.'); }

ensure_products_active_column($pdo);
ensure_suppliers_active_column($pdo);
ensure_inventory_orders_tables($pdo);

$suppliers = $pdo->query('SELECT id, name FROM suppliers WHERE COALESCE(is_active, 1) = 1 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
$supplierIds = array_map('intval', array_column($suppliers, 'id'));
$supplierId = (int)($_GET['supplier_id'] ?? $_POST['supplier_id'] ?? 0);
if (!in_array($supplierId, $supplierIds, true)) $supplierId = 0;
$errors = [];

$products = [];
if ($supplierId > 0) {
  $stmt = $pdo->query("SELECT p.id, p.title, p.unit, p.min_qty, p.max_qty, p.supplier_id,
    COALESCE(SUM(sl.qty), 0) AS stock_qty, s.name AS supplier_name
    FROM products p
    LEFT JOIN stock_levels sl ON sl.product_id = p.id
    LEFT JOIN suppliers s ON s.id = p.supplier_id
    WHERE COALESCE(p.is_active, 1) = 1
    GROUP BY p.id, p.title, p.unit, p.min_qty, p.max_qty, p.supplier_id, s.name
    ORDER BY p.title");
  $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_check($_POST['csrf'] ?? '')) $errors[] = 'Token CSRF non valido.';
  if ($supplierId <= 0) $errors[] = 'Seleziona un fornitore valido.';
  $items = inventory_order_items_from_post($pdo, (array)($_POST['quantity'] ?? []));
  if (!$items) $errors[] = 'Inserisci una quantità maggiore di zero per almeno un prodotto.';

  if (!$errors) {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("INSERT INTO inventory_orders (supplier_id, status, created_by) VALUES (?, 'generato', ?)");
    $stmt->execute([$supplierId, $user['id'] ?? null]);
    $orderId = (int)$pdo->lastInsertId();
    inventory_order_insert_items($pdo, $orderId, $items);
    $pdo->commit();
    header('Location: ' . $base . '/inventory/orders.php?created=' . $orderId);
    exit;
  }
}

$selectedSupplier = null;
foreach ($suppliers as $supplier) if ((int)$supplier['id'] === $supplierId) $selectedSupplier = $supplier;
$lowStock = array_values(array_filter($products, static fn(array $p): bool => (int)$p['supplier_id'] === $supplierId && (float)$p['stock_qty'] < (float)$p['min_qty']));
$sameSupplier = array_values(array_filter($products, static fn(array $p): bool => (int)$p['supplier_id'] === $supplierId && (float)$p['stock_qty'] >= (float)$p['min_qty']));
$otherProducts = array_values(array_filter($products, static fn(array $p): bool => (int)$p['supplier_id'] !== $supplierId));

$title = 'Nuovo ordine';
include __DIR__ . '/../partials/header.php';

function render_order_product_rows(array $rows, bool $suggestQuantity = false): void {
  foreach ($rows as $product) {
    $suggested = $suggestQuantity ? max(0, (float)$product['max_qty'] - (float)$product['stock_qty']) : 0;
    ?>
    <tr>
      <td><div class="fw-semibold"><?= e($product['title']) ?></div><div class="small text-muted"><?= e($product['supplier_name'] ?: 'Senza fornitore') ?></div></td>
      <td class="text-center"><?= e(inventory_order_format_quantity($product['stock_qty'])) ?> <?= e($product['unit'] ?? '') ?></td>
      <td class="text-center"><?= e(inventory_order_format_quantity($product['min_qty'])) ?> / <?= e(inventory_order_format_quantity($product['max_qty'])) ?></td>
      <td style="width:170px"><input type="number" class="form-control" min="0" step="0.001" name="quantity[<?= (int)$product['id'] ?>]" value="<?= $suggested > 0 ? e((string)$suggested) : '' ?>" placeholder="0"></td>
    </tr>
    <?php
  }
}
?>

<div class="d-flex justify-content-between align-items-start gap-3 mb-3">
  <div><h1 class="h4 mb-1"><i class="bi bi-cart-plus me-1"></i>Nuovo ordine</h1><div class="text-muted">Procedura guidata per preparare un ordine fornitore.</div></div>
  <a class="btn btn-outline-secondary" href="<?= e($base) ?>/inventory/orders.php">Ordini</a>
</div>
<?php if ($errors): ?><div class="alert alert-danger"><?php foreach ($errors as $error): ?><div><?= e($error) ?></div><?php endforeach; ?></div><?php endif; ?>

<div class="card shadow-sm mb-3">
  <div class="card-body">
    <div class="d-flex gap-2 align-items-center mb-3"><span class="badge rounded-pill text-bg-primary">1</span><h2 class="h5 mb-0">Scegli il fornitore</h2></div>
    <form method="get" class="row g-2">
      <div class="col-12 col-md-9"><select class="form-select" name="supplier_id" required><option value="">Seleziona un fornitore…</option><?php foreach ($suppliers as $supplier): ?><option value="<?= (int)$supplier['id'] ?>" <?= $supplierId === (int)$supplier['id'] ? 'selected' : '' ?>><?= e($supplier['name']) ?></option><?php endforeach; ?></select></div>
      <div class="col-12 col-md-3 d-grid"><button class="btn btn-primary">Continua</button></div>
    </form>
  </div>
</div>

<?php if ($selectedSupplier): ?>
<form method="post">
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="supplier_id" value="<?= $supplierId ?>">
  <div class="card shadow-sm mb-3">
    <div class="card-body">
      <div class="d-flex gap-2 align-items-center mb-3"><span class="badge rounded-pill text-bg-primary">2</span><div><h2 class="h5 mb-0">Prodotti sottoscorta</h2><div class="small text-muted">Fornitore: <?= e($selectedSupplier['name']) ?>. Le quantità proposte ripristinano la scorta massima.</div></div></div>
      <?php if ($lowStock): ?><div class="table-responsive"><table class="table table-sm align-middle"><thead><tr><th>Prodotto</th><th class="text-center">Disponibile</th><th class="text-center">Min / Max</th><th>Da ordinare</th></tr></thead><tbody><?php render_order_product_rows($lowStock, true); ?></tbody></table></div><?php else: ?><div class="alert alert-success mb-0">Nessun prodotto di questo fornitore è sottoscorta.</div><?php endif; ?>
    </div>
  </div>
  <div class="card shadow-sm mb-3">
    <div class="card-body">
      <div class="d-flex gap-2 align-items-center mb-3"><span class="badge rounded-pill text-bg-primary">3</span><div><h2 class="h5 mb-0">Aggiungi altri prodotti</h2><div class="small text-muted">Facoltativo: completa l'ordine con prodotti non sottoscorta o di altri fornitori.</div></div></div>
      <h3 class="h6">Altri prodotti dello stesso fornitore</h3>
      <?php if ($sameSupplier): ?><div class="table-responsive mb-3"><table class="table table-sm align-middle"><tbody><?php render_order_product_rows($sameSupplier); ?></tbody></table></div><?php else: ?><p class="text-muted">Nessun altro prodotto associato.</p><?php endif; ?>
      <details><summary class="btn btn-outline-secondary mb-3">Mostra prodotti di altri fornitori</summary><div class="table-responsive"><table class="table table-sm align-middle"><tbody><?php render_order_product_rows($otherProducts); ?></tbody></table></div></details>
    </div>
  </div>
  <div class="d-grid d-md-flex justify-content-md-end mb-4"><button class="btn btn-success btn-lg"><i class="bi bi-check2-circle me-1"></i>Genera ordine</button></div>
</form>
<?php endif; ?>
<?php include __DIR__ . '/../partials/footer.php'; ?>
