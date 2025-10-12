<?php
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/security.php';
require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/roles.php';
require_once __DIR__ . '/dompdf/vendor/autoload.php';
start_session();

$pdo  = db();
$user = current_user();
if (!$user) { header('Location: index.php?msg=auth'); exit; }

if (!(user_is_reception_or_amministrazione($user) || user_is_housekeeping($user))) {
  http_response_code(403);
  echo 'Accesso negato.';
  exit;
}

$date = trim($_GET['date'] ?? '');
if ($date === '' || !DateTime::createFromFormat('Y-m-d', $date)) {
  http_response_code(400);
  echo 'Data non valida.';
  exit;
}

$stmt = $pdo->prepare('SELECT * FROM riassetti WHERE data_riassetto = ? ORDER BY room ASC, id ASC');
$stmt->execute([$date]);
$rows = $stmt->fetchAll();

$displayDate = DateTime::createFromFormat('Y-m-d', $date)->format('d/m/Y');

function riassetto_linen_summary(array $row): string {
  $parts = [];
  if (!empty($row['qty_matrimoniale'])) $parts[] = $row['qty_matrimoniale'] . ' Matrimoniale';
  if (!empty($row['qty_singola'])) $parts[] = $row['qty_singola'] . ' Singola';
  if (!empty($row['qty_set_bagno'])) $parts[] = $row['qty_set_bagno'] . ' Set Bagno';
  return $parts ? implode(', ', $parts) : '—';
}

ob_start();
?>
<!doctype html>
<html lang="it">
<head>
  <meta charset="utf-8">
  <style>
    body { font-family: DejaVu Sans, Arial, Helvetica, sans-serif; font-size: 12px; color: #222; }
    h1 { font-size: 20px; margin: 0 0 12px; }
    table { width: 100%; border-collapse: collapse; }
    th, td { border: 1px solid #666; padding: 6px 8px; vertical-align: top; }
    th { background: #f0f0f0; text-align: left; }
    .note { font-size: 11px; color: #555; }
  </style>
</head>
<body>
  <h1>Riassetti del <?= e($displayDate) ?></h1>
  <?php if ($rows): ?>
    <table>
      <thead>
        <tr>
          <th style="width: 12%;">Camera</th>
          <th style="width: 30%;">Biancheria</th>
          <th style="width: 15%;">Pulizia extra</th>
          <th>Note</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $row): ?>
        <tr>
          <td><?= e($row['room']) ?></td>
          <td><?= e(riassetto_linen_summary($row)) ?></td>
          <td><?= !empty($row['pulizia_extra']) ? 'Sì' : 'No' ?></td>
          <td class="note"><?= nl2br(e($row['note'] ?? '')) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php else: ?>
    <p>Nessun riassetto programmato per questa data.</p>
  <?php endif; ?>
</body>
</html>
<?php
$html = ob_get_clean();

if (!class_exists(\Dompdf\Dompdf::class)) {
  echo $html;
  exit;
}

$options = new \Dompdf\Options();
$options->set('isRemoteEnabled', false);
$options->set('isHtml5ParserEnabled', true);
$dompdf = new \Dompdf\Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();
$dompdf->stream('riassetti_' . DateTime::createFromFormat('Y-m-d', $date)->format('Ymd') . '.pdf', ['Attachment' => true]);
exit;
