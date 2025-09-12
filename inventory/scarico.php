<?php
require_once __DIR__ . '/_role_guard.php';
require_once '../dompdf/autoload.inc.php';
if (!$user) { header('Location: ../login.php?msg=auth'); exit; }
if (!is_bar_or_amministrazione()) { header($_SERVER['SERVER_PROTOCOL'].' 403 Forbidden'); echo 'Solo Bar o Amministrazione.'; exit; }

$message = '';
$summary = [];
$warnings = []; // <--- avvisi “reperire dall’altro magazzino”
$warehouses = ['Tizzo','Tramonto'];

$message = '';
$summary = [];
$warnings = [];
$warehouses = ['Tizzo','Tramonto'];

if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (!csrf_check($_POST['csrf'] ?? '')) {
    $message = 'Token CSRF non valido.';
  } else {
    $wh = $_POST['warehouse'] ?? 'Tizzo';
    if (!in_array($wh,$warehouses,true)) $wh='Tizzo';
    $otherWh = ($wh === 'Tizzo') ? 'Tramonto' : 'Tizzo';

    // Filtra righe valide
    $valid = [];
    foreach (($_POST['items'] ?? []) as $it) {
      $pid = (int)($it['product_id'] ?? 0);
      $qty = (float)($it['qty'] ?? 0);
      if ($pid > 0 && $qty > 0) $valid[] = ['pid'=>$pid,'qty'=>$qty];
    }

    if (empty($valid)) {
      $message = 'Nessuna riga valida: seleziona un prodotto dai suggerimenti e una quantità > 0.';
    } else {
      // Statement riutilizzabili
      $stGetQty = $pdo->prepare("SELECT qty FROM stock_levels WHERE product_id=? AND warehouse=?");
      $stSetQty = $pdo->prepare("
         INSERT INTO stock_levels (product_id, warehouse, qty)
         VALUES (?,?,?)
         ON DUPLICATE KEY UPDATE qty = VALUES(qty)
      ");
      $stLog = $pdo->prepare("
         INSERT INTO stock_movements (product_id, warehouse, type, qty_delta, created_by)
         VALUES (?,?,?,?,?)
      ");
      $stTitle = $pdo->prepare("SELECT title, min_qty FROM products WHERE id=?");

      $pdo->beginTransaction();
      try {
        foreach ($valid as $v) {
          $pid = $v['pid']; $qty = $v['qty'];

          // Giacenza nel magazzino scelto e nell'altro
          $stGetQty->execute([$pid, $wh]);
          $curWh = (float)($stGetQty->fetchColumn() ?: 0);

          $stGetQty->execute([$pid, $otherWh]);
          $curOther = (float)($stGetQty->fetchColumn() ?: 0);

          // Info prodotto
          $stTitle->execute([$pid]);
          $info = $stTitle->fetch(PDO::FETCH_ASSOC) ?: ['title'=>'Prodotto #'.$pid, 'min_qty'=>0];

          if ($curWh <= 0 && $curOther > 0) {
            // NON scarico: segnalo reperimento da altro magazzino
            $warnings[] = [
              'product_id' => $pid,
              'title'      => $info['title'],
              'suggest_wh' => $otherWh,
              'available'  => $curOther,
              'requested'  => $qty,
            ];
            continue;
          }

          // Scarico dal magazzino scelto (clamp a 0)
          $new = max($curWh - $qty, 0);
          $stSetQty->execute([$pid, $wh, $new]);
          $stLog->execute([$pid, $wh, 'scarico', -abs($qty), $user['id']]);

          $summary[] = [
            'product_id' => $pid,
            'title'      => $info['title'],
            'qty'        => $qty,
            'prev'       => $curWh,
            'now'        => $new,
            'min_qty'    => (float)$info['min_qty'],
          ];
        }

        $pdo->commit();
        // Se ci sono avvisi, mostriamo alert in pagina
        if (!empty($warnings)) {
          $message = trim(($message ? $message.' ' : '') . 'Alcuni articoli vanno reperiti dall’altro magazzino (vedi avvisi).');
        }
      } catch (Exception $e) {
        $pdo->rollBack();
        error_log('SCARICO-ERR: '.$e->getMessage());
        $message = 'Errore durante lo scarico: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
      }
    }

    // --- PREPARA DATI PER PDF (sempre) ---
    $_SESSION['last_scarico_pdf'] = [
      'when'     => (new DateTime('now', new DateTimeZone('Europe/Rome')))->format('d/m/Y H:i'),
      'warehouse'=> $wh,
      'rows'     => $summary,   // righe scaricate (possono essere 0)
      'warnings' => $warnings,  // righe da reperire (possono essere 0+)
    ];

    // --- GENERA PDF subito se c'è almeno UNA riga scaricata ---
    if (!empty($summary)) {
      // costruiamo HTML e PDF immediatamente
      $nowIt = $_SESSION['last_scarico_pdf']['when'];
      $rows  = $summary;
      $warns = $warnings;

      // nota sotto soglia
      $lowList = [];
      foreach ($rows as $r) {
        if ($r['now'] < ($r['min_qty'] ?? 0)) $lowList[] = $r['title'];
      }

      ob_start(); ?>
<!doctype html>
<html lang="it"><head><meta charset="utf-8">
<style>
body{font-family:DejaVu Sans,Arial,Helvetica,sans-serif;font-size:12px;color:#222}
h1{font-size:18px;margin:0 0 6px}
.meta{margin:0 0 12px}
.meta div{margin:3px 0}
table{width:100%;border-collapse:collapse}
th,td{border:1px solid #888;padding:6px 8px} th{background:#f2f2f2;text-align:left}
.section{margin-top:14px}
.note{margin-top:10px;font-size:11px}
</style></head><body>
  <h1>Bolla di Consegna</h1>
  <div class="meta">
    <div><strong>Data/Ora:</strong> <?= htmlspecialchars($nowIt) ?></div>
    <div><strong>Magazzino:</strong> <?= htmlspecialchars($wh) ?></div>
  </div>

  <div class="section">
    <table>
      <thead><tr><th>#</th><th>Prodotto</th><th>Q.tà scaricata</th><th>Giacenza precedente</th><th>Giacenza residua</th></tr></thead>
      <tbody>
        <?php $i=0; foreach($rows as $r): $i++; ?>
        <tr>
          <td><?= $i ?></td>
          <td><?= htmlspecialchars($r['title']) ?></td>
          <td><?= (float)$r['qty'] ?></td>
          <td><?= (float)$r['prev'] ?></td>
          <td><?= (float)$r['now'] ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if (!empty($warns)): ?>
  <div class="section">
    <h2 style="font-size:14px;margin:10px 0 6px;">Articoli da reperire da altro magazzino</h2>
    <table>
      <thead><tr><th>#</th><th>Prodotto</th><th>Q.tà richiesta</th><th>Magazzino suggerito</th><th>Disponibile</th></tr></thead>
      <tbody>
        <?php $j=0; foreach($warns as $w): $j++; ?>
        <tr>
          <td><?= $j ?></td>
          <td><?= htmlspecialchars($w['title']) ?></td>
          <td><?= (float)$w['requested'] ?></td>
          <td><?= htmlspecialchars($w['suggest_wh']) ?></td>
          <td><?= (float)$w['available'] ?></td>
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
    }
    // Se arrivi qui: niente righe scaricate. Mostriamo pagina con alert + bottone per bolla “da reperire”.
  }
}


$title = 'Scarico Magazzino';
include __DIR__ . '/../partials/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h5 mb-0">Scarico</h1>
  <?php if($summary): ?><button class="btn btn-sm btn-outline-secondary" onclick="window.print()">Stampa riepilogo</button><?php endif; ?>
</div>
<?php if($message): ?><div class="alert alert-info"><?= e($message) ?></div><?php endif; ?>

<?php if (!empty($warnings)): ?>
<!--  <div class="alert alert-warning">
    <div class="fw-bold mb-1">Avvisi di reperimento</div>
    <ul class="mb-2">
      <?php foreach ($warnings as $w): ?>
        <li><strong><?= e($w['title']) ?></strong> — richiesti <?= e($w['requested']) ?>.
            Non disponibile in <em><?= e($wh) ?></em>, disponibile in
            <em><?= e($w['suggest_wh']) ?></em>: <?= e($w['available']) ?>.</li>
      <?php endforeach; ?>
    </ul>
   <a class="btn btn-sm btn-outline-primary" href="scarico_bolla.php" target="_blank">Scarica bolla (articoli da reperire)</a>
  </div> -->
<?php endif; ?>



<?php if (!empty($warnings)): ?>
  <div class="alert alert-warning">
    <div class="fw-bold mb-1">Avvisi di reperimento</div>
    <ul class="mb-0">
      <?php foreach ($warnings as $w): ?>
        <li>
          <strong><?= e($w['title']) ?></strong> — richiesti <?= e($w['requested']) ?>.
          Non disponibile in <em><?= e($wh) ?></em>, disponibile in
          <em><?= e($w['suggest_wh']) ?></em>: <?= e($w['available']) ?>.
          <span class="text-muted">Suggerimento: preleva dall’altro magazzino o usa la funzione “Trasferimento”.</span>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<div class="card shadow-sm no-print">
  <div class="card-body">
    <form method="post" id="scaricoForm">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <div class="row g-3 align-items-end">
        <div class="col-md-3">
          <label class="form-label">Magazzino</label>
          <select name="warehouse" class="form-select">
            <option value="Tizzo" <?= (($_POST['warehouse'] ?? '')==='Tizzo')?'selected':''; ?>>Tizzo</option>
            <option value="Tramonto" <?= (($_POST['warehouse'] ?? '')==='Tramonto')?'selected':''; ?>>Tramonto</option>
          </select>
        </div>
      </div>
      <hr>
      <div id="items"></div>
      <button type="button" class="btn btn-outline-secondary btn-sm" onclick="window.invAddRow()">+ Prodotto</button>
      <div class="mt-3">
        <button class="btn btn-success">Registra Scarico</button>
      </div>
    </form>
  </div>
</div>

<?php if($summary): ?>
<div class="card shadow-sm mt-3">
  <div class="card-body">
    <h2 class="h6">Riepilogo Scarico</h2>
    <div class="table-responsive">
      <table class="table table-sm">
        <thead><tr><th>Prodotto</th><th>Q.tà Scarico</th><th>Giacenza Precedente</th><th>Giacenza Residua</th></tr></thead>
        <tbody>
          <?php foreach($summary as $s):
            $p = $pdo->prepare('SELECT title FROM products WHERE id=?'); $p->execute([$s['product_id']]); $ttl = $p->fetchColumn();
          ?>
          <tr>
            <td><?= e($ttl) ?></td>
            <td><?= e((float)$s['qty']) ?></td>
            <td><?= e((float)$s['prev']) ?></td>
            <td><?= e((float)$s['now']) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
function escAttr(s){
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
                  .replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}
let rowCounter = 0;
function invAddRow(){
  const id = ++rowCounter;
  const wrap = document.getElementById('items');
  const div = document.createElement('div');
  div.className = 'row g-2 align-items-end mb-2 position-relative';
  div.innerHTML = `
    <div class="col-md-6">
      <label class="form-label">Prodotto (Titolo o EAN)</label>
      <input id="input_${id}" type="text" class="form-control" placeholder="Cerca titolo o inserisci EAN">
      <input type="hidden" name="items[${id}][product_id]" id="pid_${id}">
      <div class="small text-muted" id="stock_${id}"></div>
      <div class="list-group position-absolute w-75 z-3 bg-white border" id="ac_${id}" style="max-height:200px; overflow:auto;"></div>
    </div>
    <div class="col-md-2">
      <label class="form-label">Q.tà</label>
      <input type="number" step="0.001" name="items[${id}][qty]" class="form-control" required>
    </div>
  `;
  wrap.appendChild(div);

  const input = div.querySelector('input.form-control');
  input.addEventListener('input', (e)=> invAutocomplete(id, e.target.value));

  const box = document.getElementById('ac_'+id);
  box.addEventListener('click', (ev)=>{
    const btn = ev.target.closest('button[data-pid]');
    if (!btn) return;
    invPick(id, parseInt(btn.dataset.pid), btn.dataset.title, parseFloat(btn.dataset.stock)||0);
  });
}
async function invAutocomplete(id, q){
  const box = document.getElementById('ac_'+id);
  if (!q || q.length<2){ box.innerHTML=''; return; }
  try {
    const res = await fetch('product_search.php?q='+encodeURIComponent(q), {credentials:'same-origin'});
    if (!res.ok){ box.innerHTML='<div class="small text-danger p-2">Errore '+res.status+'</div>'; return; }
    const data = await res.json();
    if (!Array.isArray(data) || data.length===0){ box.innerHTML='<div class="list-group-item small text-muted">Nessun risultato</div>'; return; }
    box.innerHTML = data.map(r => `
      <button type="button" class="list-group-item list-group-item-action"
        data-pid="${r.id}" data-title="${r.title || ''}" data-stock="${r.stock ?? 0}">
        ${escAttr(r.title)} <span class="badge bg-light text-dark">Giacenza tot: ${r.stock ?? 0}</span>
      </button>
    `).join('');
  } catch(e){
    box.innerHTML='<div class="small text-danger p-2">Errore rete</div>';
    console.error(e);
  }
}
function invPick(id, pid, title, stock){
  document.getElementById('pid_'+id).value = pid;
  document.getElementById('ac_'+id).innerHTML='';
  document.getElementById('stock_'+id).innerText = 'Giacenza attuale: '+stock;
  const inp = document.getElementById('input_'+id);
  if (inp) { inp.value = title; inp.focus(); }
}
window.invAddRow = invAddRow;
window.invAutocomplete = invAutocomplete;
window.invPick = invPick;
invAddRow();
</script>

<style>
@media print { .no-print { display: none; } }
</style>

<?php include __DIR__ . '/../partials/footer.php'; ?>
