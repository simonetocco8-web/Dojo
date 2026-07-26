<?php
declare(strict_types=1);

require_once __DIR__ . '/_role_guard.php';
require_once __DIR__ . '/orders_common.php';

if (!$user) { header('Location: ../login.php?msg=auth'); exit; }
if (!is_bar_or_amministrazione()) { http_response_code(403); exit('Permesso negato.'); }

ensure_inventory_orders_tables($pdo);
$statuses = inventory_order_status_labels();
$warehouses = inventory_order_warehouses();
$message = isset($_GET['created'])
  ? 'Ordine #' . (int)$_GET['created'] . ' generato correttamente.'
  : (isset($_GET['varied']) ? 'Variazioni dell’ordine #' . (int)$_GET['varied'] . ' salvate. Ora puoi registrare la consegna.' : '');
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_check($_POST['csrf'] ?? '')) $errors[] = 'Token CSRF non valido.';
  $orderId = (int)($_POST['order_id'] ?? 0);
  $status = (string)($_POST['status'] ?? '');
  $warehouse = (string)($_POST['warehouse'] ?? '');
  if ($orderId <= 0 || !isset($statuses[$status])) $errors[] = 'Ordine o stato non valido.';
  if ($status === 'consegnato' && !in_array($warehouse, $warehouses, true)) $errors[] = 'Seleziona il magazzino di consegna.';

  if (!$errors && $status === 'variato') {
    header('Location: ' . $base . '/inventory/order_edit.php?id=' . $orderId);
    exit;
  }

  if (!$errors) {
    $pdo->beginTransaction();
    $lockStmt = $pdo->prepare('SELECT status, delivered_at FROM inventory_orders WHERE id = ? FOR UPDATE');
    $lockStmt->execute([$orderId]);
    $order = $lockStmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
      $errors[] = 'Ordine non trovato.';
    } elseif ($status === 'consegnato' && !empty($order['delivered_at'])) {
      $errors[] = 'Questo ordine è già stato caricato in magazzino.';
    }

    if (!$errors && $status === 'consegnato') {
      $itemStmt = $pdo->prepare('SELECT product_id, quantity, product_name FROM inventory_order_items WHERE order_id = ?');
      $itemStmt->execute([$orderId]);
      $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
      if (!$items) {
        $errors[] = 'L’ordine non contiene prodotti.';
      } else {
        $levelStmt = $pdo->prepare('INSERT INTO stock_levels (product_id, warehouse, qty) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE qty = qty + VALUES(qty)');
        $movementStmt = $pdo->prepare("INSERT INTO stock_movements (product_id, warehouse, type, qty_delta, ref, created_by) VALUES (?, ?, 'carico', ?, ?, ?)");
        foreach ($items as $item) {
          $levelStmt->execute([(int)$item['product_id'], $warehouse, (float)$item['quantity']]);
          $movementStmt->execute([(int)$item['product_id'], $warehouse, (float)$item['quantity'], 'Ordine #' . $orderId, $user['id'] ?? null]);
        }
        $updateStmt = $pdo->prepare("UPDATE inventory_orders SET status = 'consegnato', delivery_warehouse = ?, delivered_at = NOW(), updated_at = NOW() WHERE id = ?");
        $updateStmt->execute([$warehouse, $orderId]);
      }
    } elseif (!$errors) {
      $updateStmt = $pdo->prepare('UPDATE inventory_orders SET status = ?, updated_at = NOW() WHERE id = ?');
      $updateStmt->execute([$status, $orderId]);
    }

    if ($errors) $pdo->rollBack(); else { $pdo->commit(); $message = $status === 'consegnato' ? 'Ordine consegnato e disponibilità aggiornate nel magazzino ' . $warehouse . '.' : 'Stato dell’ordine aggiornato.'; }
  }
}

$rows = $pdo->query("SELECT o.id, o.status, o.delivery_warehouse, o.delivered_at, o.created_at, s.name AS supplier_name,
  COUNT(oi.id) AS items_count, COALESCE(SUM(oi.quantity), 0) AS total_quantity
  FROM inventory_orders o
  JOIN suppliers s ON s.id = o.supplier_id
  LEFT JOIN inventory_order_items oi ON oi.order_id = o.id
  GROUP BY o.id, o.status, o.delivery_warehouse, o.delivered_at, o.created_at, s.name
  ORDER BY o.id DESC LIMIT 300")->fetchAll(PDO::FETCH_ASSOC);

$title = 'Ordini';
include __DIR__ . '/../partials/header.php';
?>
<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 mb-3">
  <div><h1 class="h4 mb-1"><i class="bi bi-clipboard-check me-1"></i>Ordini</h1><div class="text-muted">Ordini generati e stato delle consegne.</div></div>
  <a class="btn btn-primary" href="<?= e($base) ?>/inventory/order_create.php"><i class="bi bi-cart-plus me-1"></i>Nuovo ordine</a>
</div>
<?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>
<?php if ($errors): ?><div class="alert alert-danger"><?php foreach ($errors as $error): ?><div><?= e($error) ?></div><?php endforeach; ?></div><?php endif; ?>

<div class="card shadow-sm"><div class="table-responsive"><table class="table table-sm align-middle mb-0">
  <thead><tr><th>Ordine</th><th>Fornitore</th><th>Prodotti</th><th>Stato</th><th>Consegna</th><th class="text-end">Azioni</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $row): ?>
    <tr>
      <td><strong>#<?= (int)$row['id'] ?></strong><div class="small text-muted"><?= e((new DateTime($row['created_at']))->format('d/m/Y H:i')) ?></div></td>
      <td><?= e($row['supplier_name']) ?></td>
      <td><?= (int)$row['items_count'] ?> <span class="small text-muted">(<?= e(inventory_order_format_quantity($row['total_quantity'])) ?> unità)</span></td>
      <td><span class="badge text-bg-<?= $row['status'] === 'consegnato' ? 'success' : ($row['status'] === 'annullato' ? 'danger' : 'secondary') ?>"><?= e($statuses[$row['status']] ?? $row['status']) ?></span></td>
      <td><?php if ($row['delivered_at']): ?><?= e($row['delivery_warehouse']) ?><div class="small text-muted"><?= e((new DateTime($row['delivered_at']))->format('d/m/Y H:i')) ?></div><?php else: ?>—<?php endif; ?></td>
      <td class="text-end">
        <div class="d-flex flex-wrap justify-content-end gap-1 mb-1"><a class="btn btn-sm btn-outline-dark" target="_blank" href="<?= e($base) ?>/inventory/order_pdf.php?id=<?= (int)$row['id'] ?>"><i class="bi bi-file-earmark-pdf"></i></a><a class="btn btn-sm btn-outline-secondary" href="<?= e($base) ?>/inventory/order_edit.php?id=<?= (int)$row['id'] ?>"><i class="bi bi-pencil"></i></a></div>
        <form method="post" class="d-flex flex-wrap justify-content-end gap-1">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="order_id" value="<?= (int)$row['id'] ?>">
          <select name="status" class="form-select form-select-sm w-auto" aria-label="Nuovo stato ordine #<?= (int)$row['id'] ?>"><?php foreach ($statuses as $value => $label): ?><option value="<?= e($value) ?>" <?= $row['status'] === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select>
          <select name="warehouse" class="form-select form-select-sm w-auto" aria-label="Magazzino di consegna"><option value="">Magazzino…</option><?php foreach ($warehouses as $wh): ?><option value="<?= e($wh) ?>"><?= e($wh) ?></option><?php endforeach; ?></select>
          <button class="btn btn-sm btn-outline-primary">Salva</button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="6" class="text-center text-muted py-4">Nessun ordine creato.</td></tr><?php endif; ?>
  </tbody>
</table></div></div>
<?php include __DIR__ . '/../partials/footer.php'; ?>
