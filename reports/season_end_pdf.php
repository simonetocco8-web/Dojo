<?php
use Dompdf\Dompdf;
use Dompdf\Options;

require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/roles.php';
require_once __DIR__ . '/../core/season_end_report.php';
require_once __DIR__ . '/../dompdf/vendor/autoload.php';
require_login();
$user = current_user();
if (!user_is_admin($user) && !user_is_amministrazione($user)) { http_response_code(403); exit('Accesso negato'); }

$data = season_end_report_data(db());
$n = static fn($value): string => number_format((float)$value, 0, ',', '.');
$d = static fn($value): string => number_format((float)$value, 2, ',', '.');
$money = static fn($value): string => '€ ' . number_format((float)$value, 2, ',', '.');
$t = $data['tramontoday']; $i = $data['internal']; $x = $data['external']; $r = $data['riassetti'];
ob_start();
?>
<!doctype html><html lang="it"><head><meta charset="utf-8"><style>
body{font-family:DejaVu Sans,sans-serif;color:#1f2937;font-size:11px}h1{font-size:23px;color:#0d6efd;margin-bottom:4px}h2{font-size:17px;border-bottom:2px solid #0d6efd;padding-bottom:5px;margin-top:24px}.period{color:#6b7280}.grid{width:100%;border-collapse:separate;border-spacing:8px}.box{border:1px solid #d1d5db;border-radius:6px;padding:10px}.label{color:#6b7280;font-size:9px;text-transform:uppercase}.value{font-size:18px;font-weight:bold;margin-top:4px}.footer{margin-top:28px;color:#6b7280;font-size:9px;text-align:right}
</style></head><body>
<h1>Report Fine Stagione</h1><div class="period">Periodo <?= $data['start']->format('d/m/Y') ?> - <?= $data['end']->format('d/m/Y') ?></div>
<h2>Report TramontoDay</h2><table class="grid"><tr>
<td class="box"><div class="label">Accessi</div><div class="value"><?= $n($t['accesses'] ?? 0) ?></div></td>
<td class="box"><div class="label">Postazioni vendute</div><div class="value"><?= $n($t['stations'] ?? 0) ?></div></td>
<td class="box"><div class="label">Ricavi</div><div class="value"><?= $money($t['revenue'] ?? 0) ?></div></td></tr><tr>
<td class="box">Adulti: <b><?= $n($t['adults'] ?? 0) ?></b><br>Bambini: <b><?= $n($t['children'] ?? 0) ?></b><br>Infant: <b><?= $n($t['infants'] ?? 0) ?></b></td>
<td class="box">Sdraio aggiuntive: <b><?= $n($t['extra_sunbeds'] ?? 0) ?></b></td>
<td class="box">Annullate: <b><?= $n($t['cancelled'] ?? 0) ?></b><br>No-show: <b><?= $n($t['no_show'] ?? 0) ?></b></td></tr></table>
<h2>Statistiche Trasporti</h2><table class="grid"><tr>
<td class="box"><div class="label">Transfer interni eseguiti</div><div class="value"><?= $n($i['total'] ?? 0) ?></div>Km complessivi: <?= $d($i['km'] ?? 0) ?></td>
<td class="box"><div class="label">Transfer esterni eseguiti</div><div class="value"><?= $n($x['total'] ?? 0) ?></div></td>
<td class="box">Prezzo clienti: <b><?= $money($x['customer_total'] ?? 0) ?></b><br>Prezzo fornitori: <b><?= $money($x['supplier_total'] ?? 0) ?></b><br>Margine: <b><?= $money(($x['customer_total'] ?? 0)-($x['supplier_total'] ?? 0)) ?></b></td></tr></table>
<h2>Statistiche Riassetti</h2><table class="grid"><tr>
<td class="box"><div class="label">Riassetti</div><div class="value"><?= $n($r['total'] ?? 0) ?></div>Extra: <?= $n($r['extra'] ?? 0) ?></td>
<td class="box">Matrimoniale: <b><?= $n($r['matrimoniale'] ?? 0) ?></b><br>Singola: <b><?= $n($r['singola'] ?? 0) ?></b><br>Set bagno: <b><?= $n($r['set_bagno'] ?? 0) ?></b></td>
<td class="box"><div class="label">Costo biancheria</div><div class="value"><?= $money($r['cost'] ?? 0) ?></div></td></tr></table>
<div class="footer">Generato il <?= date('d/m/Y H:i') ?> da Dojo</div></body></html>
<?php
$html = ob_get_clean();
$options = new Options(); $options->set('isRemoteEnabled', false);
$pdf = new Dompdf($options); $pdf->loadHtml($html, 'UTF-8'); $pdf->setPaper('A4', 'portrait'); $pdf->render();
$pdf->stream('report-fine-stagione-' . $data['start']->format('Y') . '.pdf', ['Attachment' => true]);
