<?php

use Dompdf\Dompdf;
use Dompdf\Options;

require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/settings.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../dompdf/vendor/autoload.php';

require_login();
$user = current_user();
if (!$user || !user_has_department($user, 'Amministrazione')) { http_response_code(403); exit('Accesso negato.'); }

$pdo = db();
ensure_autocontrollo_fire_inspections_tables($pdo);
$inspectionId = (int)($_GET['id'] ?? 0);
$seasonExport = (string)($_GET['season'] ?? '') === '1';
if ($inspectionId <= 0 && !$seasonExport) { http_response_code(400); exit('Richiesta PDF non valida.'); }

$params = [];
$where = '';
if ($inspectionId > 0) {
    $where = 'WHERE i.id=?';
    $params[] = $inspectionId;
} else {
    $range = get_summer_season_range($pdo);
    if (empty($range['start']) || empty($range['end'])) { http_response_code(400); exit('Stagione non configurata.'); }
    $where = 'WHERE i.season_start=? AND i.season_end=?';
    $params = [$range['start'], $range['end']];
}
$stmt = $pdo->prepare("SELECT i.*, u.email operator_email, TRIM(CONCAT_WS(' ',NULLIF(u.nome,''),NULLIF(u.cognome,''))) operator_name FROM autocontrollo_fire_inspections i LEFT JOIN users u ON u.id=i.operator_id $where ORDER BY i.scheduled_date, i.started_at");
$stmt->execute($params);
$inspections = $stmt->fetchAll(PDO::FETCH_ASSOC);
if ($inspectionId > 0 && !$inspections) { http_response_code(404); exit('Rilievo non trovato.'); }

$resultStmt = $pdo->prepare('SELECT * FROM autocontrollo_fire_inspection_results WHERE inspection_id=? ORDER BY sort_order');
$escape = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
ob_start();
?>
<!doctype html><html lang="it"><head><meta charset="utf-8"><style>
body{font-family:DejaVu Sans,sans-serif;color:#1f2937;font-size:9px}h1{font-size:20px;color:#dc3545;margin:0 0 4px}.period{color:#6b7280;margin-bottom:16px}.inspection{margin:0 0 18px;page-break-inside:avoid}.meta{margin-bottom:7px}.results{width:100%;border-collapse:collapse}.results th{background:#e9ecef;text-align:left}.results th,.results td{border:1px solid #adb5bd;padding:5px}.anomaly{background:#f8d7da;color:#842029;font-weight:bold}.empty{text-align:center;color:#6b7280;padding:18px}.footer{margin-top:18px;color:#6b7280;text-align:right;font-size:8px}
</style></head><body><h1>Autocontrollo Antincendio</h1><div class="period"><?= $seasonExport ? 'Report cumulativo della stagione' : 'Rilievo #' . (int)$inspectionId ?></div>
<?php if (!$inspections): ?><div class="empty">Nessuna procedura presente nella stagione.</div><?php endif; ?>
<?php foreach ($inspections as $inspection): $resultStmt->execute([$inspection['id']]); $results=$resultStmt->fetchAll(PDO::FETCH_ASSOC); ?>
<div class="inspection"><div class="meta"><strong>Scadenza:</strong> <?= $escape((new DateTimeImmutable($inspection['scheduled_date']))->format('d/m/Y')) ?> · <strong>Eseguito:</strong> <?= $escape((new DateTimeImmutable($inspection['started_at']))->format('d/m/Y H:i')) ?> · <strong>Operatore:</strong> <?= $escape($inspection['operator_name'] ?: ($inspection['operator_email'] ?? '—')) ?> · <strong>Stato:</strong> <?= $escape($inspection['status']) ?></div>
<table class="results"><thead><tr><th>ID Estintore</th><th>Tipologia</th><th>Capacità</th><th>Verifica positiva</th></tr></thead><tbody><?php foreach($results as $row):$anomaly=$row['is_suitable']!==null&&!(int)$row['is_suitable'];?><tr class="<?=$anomaly?'anomaly':''?>"><td><?=$escape($row['extinguisher_label'])?></td><td><?=$escape(ucfirst($row['extinguisher_type']))?></td><td><?=$escape($row['capacity_kg'])?> Kg</td><td><?=$row['is_suitable']===null?'—':((int)$row['is_suitable']?'Sì':'NO — ANOMALIA')?></td></tr><?php endforeach;?></tbody></table></div>
<?php endforeach; ?><div class="footer">Documento esportato il <?= date('d/m/Y H:i') ?> da Dojo</div></body></html>
<?php
$html = ob_get_clean();
$options = new Options(); $options->set('isRemoteEnabled', false);
$pdf = new Dompdf($options); $pdf->loadHtml($html, 'UTF-8'); $pdf->setPaper('A4', 'portrait'); $pdf->render();
$filename = $seasonExport ? 'autocontrollo-antincendio-stagione.pdf' : 'autocontrollo-antincendio-' . $inspectionId . '.pdf';
$pdf->stream($filename, ['Attachment' => true]);
