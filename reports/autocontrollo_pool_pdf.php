<?php

use Dompdf\Dompdf;
use Dompdf\Options;

require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../dompdf/vendor/autoload.php';

require_login();
$user = current_user();
if (!$user || !user_has_department($user, 'Amministrazione')) {
    http_response_code(403);
    exit('Accesso negato.');
}

$timezone = new DateTimeZone('Europe/Rome');
$fromRaw = trim((string)($_GET['date_from'] ?? ''));
$toRaw = trim((string)($_GET['date_to'] ?? ''));
$from = DateTimeImmutable::createFromFormat('!Y-m-d', $fromRaw, $timezone);
$to = DateTimeImmutable::createFromFormat('!Y-m-d', $toRaw, $timezone);
if (!$from || !$to || $from->format('Y-m-d') !== $fromRaw || $to->format('Y-m-d') !== $toRaw || $from > $to) {
    http_response_code(400);
    exit('Intervallo di date non valido.');
}

$pdo = db();
ensure_autocontrollo_pool_inspections_tables($pdo);
$stmt = $pdo->prepare("SELECT i.*, u.email AS operator_email,
    TRIM(CONCAT_WS(' ', NULLIF(u.nome,''), NULLIF(u.cognome,''))) AS operator_name,
    (SELECT GROUP_CONCAT(CONCAT(p.product_description, ' — ', FORMAT(p.quantity_kg, 3), ' kg') ORDER BY p.id SEPARATOR ' | ')
     FROM autocontrollo_pool_inspection_products p WHERE p.inspection_id=i.id AND p.quantity_kg > 0) AS products
    FROM autocontrollo_pool_inspections i
    LEFT JOIN users u ON u.id=i.operator_id
    WHERE i.inspection_date BETWEEN ? AND ?
    ORDER BY i.inspection_date, i.inspection_time");
$stmt->execute([$fromRaw, $toRaw]);
$inspections = $stmt->fetchAll(PDO::FETCH_ASSOC);
$escape = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$number = static fn($value, int $decimals = 2): string => number_format((float)$value, $decimals, ',', '');

ob_start();
?>
<!doctype html><html lang="it"><head><meta charset="utf-8"><style>
body{font-family:DejaVu Sans,sans-serif;color:#1f2937;font-size:8px}h1{font-size:20px;color:#0d6efd;margin:0 0 4px}.period{color:#6b7280;margin-bottom:16px}.results{width:100%;border-collapse:collapse}.results th{background:#e9ecef;text-align:left}.results th,.results td{border:1px solid #adb5bd;padding:5px;vertical-align:top}.empty{text-align:center;color:#6b7280;padding:18px}.footer{margin-top:18px;color:#6b7280;text-align:right;font-size:8px}
</style></head><body>
<h1>Autocontrollo Piscina</h1><div class="period">Controlli dal <?= $escape($from->format('d/m/Y')) ?> al <?= $escape($to->format('d/m/Y')) ?></div>
<table class="results"><thead><tr><th>ID</th><th>Data e ora</th><th>Operatore</th><th>Cloro</th><th>Temp.</th><th>pH</th><th>Persone</th><th>Controlavaggio</th><th>Prelievo</th><th>Prodotti</th></tr></thead><tbody>
<?php if (!$inspections): ?><tr><td colspan="10" class="empty">Nessun controllo presente nell’intervallo selezionato.</td></tr><?php endif; ?>
<?php foreach ($inspections as $inspection): ?><tr><td>#<?= (int)$inspection['id'] ?></td><td><?= $escape((new DateTimeImmutable($inspection['inspection_date']))->format('d/m/Y')) ?> <?= $escape(substr($inspection['inspection_time'], 0, 5)) ?></td><td><?= $escape($inspection['operator_name'] ?: ($inspection['operator_email'] ?? '—')) ?></td><td><?= $escape($number($inspection['chlorine'])) ?></td><td><?= $escape($number($inspection['water_temperature'], 1)) ?> °C</td><td><?= $escape($number($inspection['ph_value'])) ?></td><td><?= (int)$inspection['people_in_pool'] ?></td><td><?= (int)($inspection['backwash_minutes'] ?? 0) > 0 ? (int)$inspection['backwash_minutes'] . ' min' : '' ?></td><td><?= $inspection['sample_location'] === 'interno' ? 'Interno' : 'Esterno' ?></td><td><?= $escape($inspection['products'] ?: '') ?></td></tr><?php endforeach; ?>
</tbody></table><div class="footer">Documento esportato il <?= date('d/m/Y H:i') ?> da Dojo</div></body></html>
<?php
$html = ob_get_clean();
$options = new Options();
$options->set('isRemoteEnabled', false);
$pdf = new Dompdf($options);
$pdf->loadHtml($html, 'UTF-8');
$pdf->setPaper('A4', 'landscape');
$pdf->render();
$pdf->stream('autocontrollo-piscina-' . $fromRaw . '-' . $toRaw . '.pdf', ['Attachment' => true]);
