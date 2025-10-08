<?php
require_once __DIR__ . '/_role_guard.php';
if (!$user) { header('Location: ../login.php?msg=auth'); exit; }

require_once '../dompdf/vendor/autoload.php'; 
// adegua il path se necessario

$data = $_SESSION['last_scarico_pdf'] ?? null;
if (!$data) { http_response_code(400); echo 'Nessun dato scarico disponibile.'; exit; }

$when  = $data['when'] ?? (new DateTime('now', new DateTimeZone('Europe/Rome')))->format('d/m/Y H:i');
$wh    = $data['warehouse'] ?? '';
$rows  = $data['rows'] ?? [];
$warns = $data['warnings'] ?? [];

// nota sotto soglia per righe scaricate
$lowList = [];
foreach ($rows as $r) {
  if (($r['now'] ?? 0) < ($r['min_qty'] ?? 0)) $lowList[] = $r['title'] ?? ('#'.$r['product_id']);
}

ob_start(); ?>
<!doctype html>
<html lang="it"><head><meta charset="utf-8">
<style>
body{font-family:DejaVu Sans,Arial,Helvetica,sans-serif;font-size:12px;color:#222}
h1{font-size:18px;margin:0 0 6px}
.meta{margin:0 0 12px}.meta div{margin:3px 0}
table{width:100%;border-collapse:collapse}
th,td{border:1px solid #888;padding:6px 8px} th{background:#f2f2f2;text-align:left}
.section{margin-top:14px}.note{margin-top:10px;font-size:11px}
</style></head><body>
  <h1>Bolla di Consegna</h1>
  <div class="meta">
    <div><strong>Data/Ora:</strong> <?= htmlspecialchars($when) ?></div>
    <div><strong>Magazzino:</strong> <?= htmlspecialchars($wh) ?></div>
  </div>

  <?php if (!empty($rows)): ?>
  <div class="section">
    <table>
      <thead><tr><th>#</th><th>Prodotto</th><th>Q.tà scaricata</th><th>Giacenza precedente</th><th>Giacenza residua</th></tr></thead>
      <tbody>
        <?php $i=0; foreach($rows as $r): $i++; ?>
        <tr>
          <td><?= $i ?></td>
          <td><?= htmlspecialchars($r['title'] ?? ('#'.$r['product_id'])) ?></td>
          <td><?= (float)$r['qty'] ?></td>
          <td><?= (float)$r['prev'] ?></td>
          <td><?= (float)$r['now'] ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <?php if (!empty($warns)): ?>
  <div class="section">
    <h2 style="font-size:14px;margin:10px 0 6px;">Articoli da reperire da altro magazzino</h2>
    <table>
      <thead><tr><th>#</th><th>Prodotto</th><th>Q.tà richiesta</th><th>Magazzino suggerito</th><th>Disponibile</th></tr></thead>
      <tbody>
        <?php $j=0; foreach($warns as $w): $j++; ?>
        <tr>
          <td><?= $j ?></td>
          <td><?= htmlspecialchars($w['title'] ?? ('#'.$w['product_id'])) ?></td>
          <td><?= (float)($w['requested'] ?? 0) ?></td>
          <td><?= htmlspecialchars($w['suggest_wh'] ?? '') ?></td>
          <td><?= (float)($w['available'] ?? 0) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <?php if (!empty($lowList)): ?>
    <div class="note"><strong>Nota:</strong> prodotti sotto soglia: <?= htmlspecialchars(implode(', ', $lowList)) ?>.</div>
  <?php endif; ?>
</body></html>
<?php
$html = ob_get_clean();

if (!class_exists(\Dompdf\Dompdf::class)) { echo $html; exit; }

$options = new \Dompdf\Options();
$options->set('isRemoteEnabled', false);
$options->set('isHtml5ParserEnabled', true);
$dompdf = new \Dompdf\Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();
$fname = 'bolla_scarico_'.(new DateTime('now', new DateTimeZone('Europe/Rome')))->format('Ymd_His').'.pdf';
$dompdf->stream($fname, ['Attachment'=>true]);
exit;
