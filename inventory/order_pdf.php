<?php
declare(strict_types=1);

use Dompdf\Dompdf;
use Dompdf\Options;

require_once __DIR__ . '/_role_guard.php';
require_once __DIR__ . '/orders_common.php';
require_once __DIR__ . '/../dompdf/vendor/autoload.php';

if (!$user) { header('Location: ../login.php?msg=auth'); exit; }
if (!is_bar_or_amministrazione()) { http_response_code(403); exit('Permesso negato.'); }
ensure_inventory_orders_tables($pdo);

$orderId = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT o.*, s.name AS supplier_name, s.phone AS supplier_phone, s.email AS supplier_email FROM inventory_orders o JOIN suppliers s ON s.id = o.supplier_id WHERE o.id = ?');
$stmt->execute([$orderId]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$order) { http_response_code(404); exit('Ordine non trovato.'); }
$stmt = $pdo->prepare('SELECT product_name, product_unit, quantity FROM inventory_order_items WHERE order_id = ? ORDER BY product_name');
$stmt->execute([$orderId]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);
$statusLabels = inventory_order_status_labels();

ob_start(); ?>
<!doctype html><html lang="it"><head><meta charset="utf-8"><style>body{font-family:DejaVu Sans,Arial,sans-serif;font-size:12px;color:#222}h1{font-size:22px}table{width:100%;border-collapse:collapse;margin-top:20px}th,td{border:1px solid #aaa;padding:8px}th{background:#eee;text-align:left}.meta{margin:4px 0}.right{text-align:right}</style></head><body>
<h1>Ordine #<?= $orderId ?></h1>
<p class="meta"><strong>Fornitore:</strong> <?= e($order['supplier_name']) ?></p>
<p class="meta"><strong>Contatti:</strong> <?= e(trim(($order['supplier_phone'] ?? '') . ' ' . ($order['supplier_email'] ?? ''))) ?></p>
<p class="meta"><strong>Data:</strong> <?= e((new DateTime($order['created_at']))->format('d/m/Y H:i')) ?> &nbsp; <strong>Stato:</strong> <?= e($statusLabels[$order['status']] ?? $order['status']) ?></p>
<table><thead><tr><th>Prodotto</th><th>Unità di misura</th><th class="right">Quantità</th></tr></thead><tbody><?php foreach ($items as $item): ?><tr><td><?= e($item['product_name']) ?></td><td><?= e($item['product_unit'] ?? '') ?></td><td class="right"><?= e(inventory_order_format_quantity($item['quantity'])) ?></td></tr><?php endforeach; ?></tbody></table>
</body></html>
<?php $html = ob_get_clean();
$options = new Options();
$options->set('isRemoteEnabled', false);
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();
$dompdf->stream('ordine_' . $orderId . '.pdf', ['Attachment' => false]);
