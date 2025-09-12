<?php
require_once __DIR__ . '/_role_guard.php';
if (!$user) { header('Location: ../login.php?msg=auth'); exit; }
?>
<?php
if (!is_amministrazione()) { header($_SERVER['SERVER_PROTOCOL'].' 403 Forbidden'); echo 'Solo Amministrazione.'; exit; }

$message = '';
$warehouses = ['Tizzo','Tramonto'];

if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (!csrf_check($_POST['csrf'] ?? '')) {
    $message = 'Token CSRF non valido.';
  } else {
    $wh = $_POST['warehouse'] ?? 'Tizzo';
    if (!in_array($wh,$warehouses,true)) $wh='Tizzo';
    $pid = (int)($_POST['product_id'] ?? 0);
    $qty = (float)($_POST['qty'] ?? 0);
    if ($pid>0) {
      $pdo->beginTransaction();
      try {
        $st = $pdo->prepare("SELECT qty FROM stock_levels WHERE product_id=? AND warehouse=?");
        $st->execute([$pid,$wh]);
        $cur = (float)($st->fetchColumn() ?: 0);
        $pdo->prepare("INSERT INTO stock_levels (product_id, warehouse, qty) VALUES (?,?,?)
                       ON DUPLICATE KEY UPDATE qty = VALUES(qty)")->execute([$pid,$wh,$qty]);
        $delta = $qty - $cur;
        if ($delta != 0) {
          $pdo->prepare("INSERT INTO stock_movements (product_id, warehouse, type, qty_delta, ref, created_by) VALUES (?,?,?,?,?,?)")
              ->execute([$pid,$wh,'allineamento',$delta,'', $user['id']]);
        }
        $pdo->commit();
        $message = 'Allineamento salvato.';
      } catch (Exception $e) {
        $pdo->rollBack();
        $message = 'Errore allineamento.';
      }
    } else {
      $message = 'Seleziona un prodotto.';
    }
  }
}

$title = 'Allineamento Magazzino';
include __DIR__ . '/../partials/header.php';
?>
<h1 class="h5 mb-3">Allineamento Quantità</h1>
<?php if($message): ?><div class="alert alert-info"><?= e($message) ?></div><?php endif; ?>

<div class="card shadow-sm">
  <div class="card-body">
    <form method="post">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <div class="row g-3 align-items-end">
        <div class="col-md-3">
          <label class="form-label">Magazzino</label>
          <select name="warehouse" class="form-select">
            <option value="Tizzo">Tizzo</option>
            <option value="Tramonto">Tramonto</option>
          </select>
        </div>
        <div class="col-md-6 position-relative">
          <label class="form-label">Prodotto</label>
          <input type="text" class="form-control" id="input_1" placeholder="Titolo o EAN">
          <input type="hidden" name="product_id" id="pid_1">
          <div class="small text-muted" id="stock_1"></div>
          <div class="list-group position-absolute w-75 z-3 bg-white border" id="ac_1" style="max-height:200px; overflow:auto;"></div>
        </div>
        <div class="col-md-3">
          <label class="form-label">Q.tà da Impostare</label>
          <input type="number" step="0.001" name="qty" class="form-control" required>
        </div>
      </div>
      <div class="mt-3">
        <button class="btn btn-warning">Allinea</button>
      </div>
    </form>
  </div>
</div>

<script>
function escAttr(s){
  return String(s)
    .replace(/&/g,'&amp;')
    .replace(/</g,'&lt;')
    .replace(/>/g,'&gt;')
    .replace(/"/g,'&quot;')
    .replace(/'/g,'&#39;');
}
const input1 = document.getElementById('prod_input_1');
input1.addEventListener('input', (e)=> invAutocomplete(1, e.target.value));

const box1 = document.getElementById('ac_1');
box1.addEventListener('click', (ev)=>{
  const btn = ev.target.closest('button[data-pid]');
  if (!btn) return;
  invPick(1, parseInt(btn.dataset.pid), btn.dataset.title, parseFloat(btn.dataset.stock)||0);
});

async function invAutocomplete(id, q){
  const box = document.getElementById('ac_'+id);
  if (!q || q.length<2) { box.innerHTML=''; return; }
  try {
    const res = await fetch('product_search.php?q='+encodeURIComponent(q), {credentials:'same-origin'});
    if (!res.ok){ box.innerHTML='<div class="small text-danger p-2">Errore '+res.status+'</div>'; return; }
    const data = await res.json();
    if (!Array.isArray(data) || data.length===0){ box.innerHTML='<div class="list-group-item small text-muted">Nessun risultato</div>'; return; }
    box.innerHTML = data.map(r => `
      <button type="button" class="list-group-item list-group-item-action"
        data-pid="${r.id}" data-title="${escAttr(r.title||'')}" data-stock="${r.stock ?? 0}">
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
  document.getElementById('stock_'+id).innerText = 'Giacenza attuale (tot): '+stock;
}

window.invAutocomplete = invAutocomplete;
window.invPick = invPick;
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
