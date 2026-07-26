<?php
declare(strict_types=1);

require_once __DIR__ . '/_role_guard.php';
require_once __DIR__ . '/orders_common.php';

if (!$user) { header('Location: ../login.php?msg=auth'); exit; }
if (!is_bar_or_amministrazione()) { http_response_code(403); exit('Permesso negato.'); }
ensure_products_active_column($pdo);
ensure_inventory_orders_tables($pdo);

$orderId = (int)($_GET['id'] ?? $_POST['order_id'] ?? 0);
$stmt = $pdo->prepare('SELECT o.*, s.name AS supplier_name FROM inventory_orders o JOIN suppliers s ON s.id = o.supplier_id WHERE o.id = ?');
$stmt->execute([$orderId]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$order) { http_response_code(404); exit('Ordine non trovato.'); }

$itemStmt = $pdo->prepare('SELECT product_id, quantity, product_name, product_unit, product_supplier_id FROM inventory_order_items WHERE order_id = ? ORDER BY product_name');
$itemStmt->execute([$orderId]);
$currentItems = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
$currentQuantities = [];
foreach ($currentItems as $item) $currentQuantities[(int)$item['product_id']] = (float)$item['quantity'];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_check($_POST['csrf'] ?? '')) $errors[] = 'Token CSRF non valido.';
  if ($order['status'] === 'consegnato') $errors[] = 'Un ordine già consegnato non può essere variato.';
  $revisedItems = inventory_order_items_from_post($pdo, (array)($_POST['quantity'] ?? []));
  if (!$revisedItems) $errors[] = 'L’ordine deve contenere almeno un prodotto.';
  if (!$errors) {
    $pdo->beginTransaction();
    $revisionStmt = $pdo->prepare('INSERT INTO inventory_order_revisions (order_id, previous_items, revised_items, revised_by) VALUES (?, ?, ?, ?)');
    $revisionStmt->execute([$orderId, json_encode($currentItems, JSON_UNESCAPED_UNICODE), json_encode($revisedItems, JSON_UNESCAPED_UNICODE), $user['id'] ?? null]);
    $pdo->prepare('DELETE FROM inventory_order_items WHERE order_id = ?')->execute([$orderId]);
    inventory_order_insert_items($pdo, $orderId, $revisedItems);
    $pdo->prepare("UPDATE inventory_orders SET status = 'variato', updated_at = NOW() WHERE id = ?")->execute([$orderId]);
    $pdo->commit();
    header('Location: ' . $base . '/inventory/orders.php?varied=' . $orderId);
    exit;
  }
}

$products = $pdo->query("SELECT p.id, p.title, p.unit, p.supplier_id, s.name AS supplier_name FROM products p LEFT JOIN suppliers s ON s.id = p.supplier_id WHERE COALESCE(p.is_active, 1) = 1 ORDER BY CASE WHEN p.supplier_id = " . (int)$order['supplier_id'] . " THEN 0 ELSE 1 END, p.title")->fetchAll(PDO::FETCH_ASSOC);
$title = 'Varia ordine #' . $orderId;
include __DIR__ . '/../partials/header.php';
?>
<div class="d-flex justify-content-between align-items-start gap-3 mb-3"><div><h1 class="h4 mb-1">Varia ordine #<?= $orderId ?></h1><div class="text-muted">Fornitore principale: <?= e($order['supplier_name']) ?>. Quantità zero elimina il prodotto.</div></div><a class="btn btn-outline-secondary" href="<?= e($base) ?>/inventory/orders.php">Indietro</a></div>
<?php if ($order['status'] === 'consegnato'): ?><div class="alert alert-warning">L’ordine è già stato consegnato e non può essere modificato.</div><?php endif; ?>
<?php if ($errors): ?><div class="alert alert-danger"><?php foreach ($errors as $error): ?><div><?= e($error) ?></div><?php endforeach; ?></div><?php endif; ?>
<form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="order_id" value="<?= $orderId ?>">
  <div class="card shadow-sm mb-3"><div class="card-body"><h2 class="h5">Riepilogo e variazioni</h2><p class="text-muted small">Puoi cambiare tutte le quantità, eliminare prodotti o aggiungerne di nuovi.</p><div class="table-responsive"><table class="table table-sm align-middle"><thead><tr><th>Prodotto</th><th>Fornitore</th><th style="width:180px">Quantità</th></tr></thead><tbody>
  <?php foreach ($products as $product): $quantity = $currentQuantities[(int)$product['id']] ?? 0; ?><tr class="<?= $quantity > 0 ? 'table-primary' : '' ?>"><td class="fw-semibold"><?= e($product['title']) ?><div class="small text-muted"><?= e($product['unit'] ?? '') ?></div></td><td><?= e($product['supplier_name'] ?: 'Senza fornitore') ?></td><td><input type="number" min="0" step="0.001" class="form-control" name="quantity[<?= (int)$product['id'] ?>]" value="<?= $quantity > 0 ? e((string)$quantity) : '' ?>" placeholder="0" <?= $order['status'] === 'consegnato' ? 'disabled' : '' ?>></td></tr><?php endforeach; ?>
  </tbody></table></div></div></div>
  <?php if ($order['status'] !== 'consegnato'): ?><div class="d-flex justify-content-end mb-4"><button class="btn btn-warning"><i class="bi bi-pencil-square me-1"></i>Salva variazioni</button></div><?php endif; ?>
</form>
<?php include __DIR__ . '/../partials/footer.php'; ?>
