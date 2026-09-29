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

$inspectionId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$inspectionId || $inspectionId < 1) {
    http_response_code(400);
    exit('Procedura non valida.');
}

$pdo = db();
ensure_autocontrollo_electrical_inspections_tables($pdo);
$stmt = $pdo->prepare('SELECT i.*, u.email AS operator_email
    FROM autocontrollo_electrical_inspections i
    LEFT JOIN users u ON u.id = i.started_by
    WHERE i.id = ?');
$stmt->execute([$inspectionId]);
$inspection = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$inspection) {
    http_response_code(404);
    exit('Procedura non trovata.');
}

$stmt = $pdo->prepare('SELECT r.*, u.email AS resolver_email
    FROM autocontrollo_electrical_inspection_results r
    LEFT JOIN users u ON u.id = r.anomaly_resolved_by
    WHERE r.inspection_id = ?
    ORDER BY r.sort_order');
$stmt->execute([$inspectionId]);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

$dateTime = static function (?string $value): string {
    return $value ? (new DateTimeImmutable($value))->format('d/m/Y H:i') : '—';
};
$date = static function (?string $value): string {
    return $value ? (new DateTimeImmutable($value))->format('d/m/Y') : '—';
};
$escape = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$typeLabel = $inspection['inspection_type'] === 'pre_apertura' ? 'Pre-apertura' : 'Post-chiusura';
$anomalyCount = 0;
$unresolvedCount = 0;
foreach ($results as $result) {
    if ((int)$result['differentials_ok'] === 0) {
        $anomalyCount++;
        if (empty($result['anomaly_resolved_date'])) $unresolvedCount++;
    }
}

ob_start();
?>
<!doctype html><html lang="it"><head><meta charset="utf-8"><style>
body{font-family:DejaVu Sans,sans-serif;color:#1f2937;font-size:10px}h1{font-size:22px;color:#0d6efd;margin:0 0 5px}.meta{width:100%;border-collapse:collapse;margin:18px 0}.meta td{border:1px solid #d1d5db;padding:7px}.label{color:#6b7280;font-size:8px;text-transform:uppercase}.results{width:100%;border-collapse:collapse}.results th{background:#e9ecef;text-align:left}.results th,.results td{border:1px solid #adb5bd;padding:6px;vertical-align:top}.ok{color:#146c43;font-weight:bold}.bad{color:#b02a37;font-weight:bold}.pending{color:#b02a37}.footer{margin-top:20px;color:#6b7280;text-align:right;font-size:8px}
</style></head><body>
<h1>Autocontrollo Impianto Elettrico</h1>
<div>Procedura #<?= (int)$inspection['id'] ?> · <?= $escape($typeLabel) ?></div>
<table class="meta"><tr><td><div class="label">Data prevista</div><strong><?= $escape($date($inspection['scheduled_date'])) ?></strong></td><td><div class="label">Avviata</div><strong><?= $escape($dateTime($inspection['started_at'])) ?></strong></td><td><div class="label">Completata</div><strong><?= $escape($dateTime($inspection['completed_at'])) ?></strong></td></tr><tr><td colspan="2"><div class="label">Operatore</div><?= $escape($inspection['operator_email'] ?: '—') ?></td><td><div class="label">Esito</div><?php if ($anomalyCount === 0): ?><span class="ok">Regolare</span><?php elseif ($unresolvedCount === 0): ?><span class="ok">Anomalie risolte</span><?php else: ?><span class="bad"><?= $unresolvedCount ?> anomalie da risolvere</span><?php endif; ?></td></tr></table>
<table class="results"><thead><tr><th>#</th><th>Quadro</th><th>Stato esterno</th><th>Test differenziali</th><th>Anomalia / risoluzione</th><th>Verificato</th></tr></thead><tbody>
<?php foreach ($results as $index => $result): ?><tr><td><?= $index + 1 ?></td><td><?= $escape($result['panel_location']) ?></td><td><?= $result['external_check'] === null ? '—' : 'Verificato' ?></td><td><?php if ($result['differentials_ok'] === null): ?>—<?php elseif ((int)$result['differentials_ok'] === 1): ?><span class="ok">Regolare</span><?php else: ?><span class="bad">Anomalia</span><?php endif; ?></td><td><?= $escape($result['anomaly'] ?: '—') ?><?php if ($result['anomaly_resolved_date']): ?><br><span class="ok">Risolta il <?= $escape($date($result['anomaly_resolved_date'])) ?></span><?php if ($result['resolver_email']): ?><br>da <?= $escape($result['resolver_email']) ?><?php endif; ?><?php elseif ((int)$result['differentials_ok'] === 0): ?><br><span class="pending">Da risolvere</span><?php endif; ?></td><td><?= $escape($dateTime($result['checked_at'])) ?></td></tr><?php endforeach; ?>
</tbody></table>
<div class="footer">Documento esportato il <?= date('d/m/Y H:i') ?> da Dojo</div>
</body></html>
<?php
$html = ob_get_clean();
$options = new Options();
$options->set('isRemoteEnabled', false);
$pdf = new Dompdf($options);
$pdf->loadHtml($html, 'UTF-8');
$pdf->setPaper('A4', 'landscape');
$pdf->render();
$pdf->stream('autocontrollo-impianto-elettrico-' . (int)$inspection['id'] . '.pdf', ['Attachment' => true]);
