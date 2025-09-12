<?php
require_once __DIR__ . '/_role_guard.php';
if (!$user) { header('Location: ../login.php?msg=auth'); exit; }
?>
<?php
if (!is_amministrazione()) { header($_SERVER['SERVER_PROTOCOL'].' 403 Forbidden'); echo 'Solo Amministrazione.'; exit; }

$message = '';
$warehouses = ['Tizzo','Tramonto'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_check($_POST['csrf'] ?? '')) {
    $message = 'Token CSRF non valido.';
  } else {
    $wh = $_POST['warehouse'] ?? 'Tizzo';
    $warehouses = ['Tizzo','Tramonto'];
    if (!in_array($wh, $warehouses, true)) $wh = 'Tizzo';

    $items = $_POST['items'] ?? [];
    // Filtro righe valide
    $valid = [];
    foreach ($items as $it) {
      $pid = (int)($it['product_id'] ?? 0);
      $qty = (float)($it['qty'] ?? 0);
      if ($pid > 0 && $qty > 0) {
        $valid[] = ['pid' => $pid, 'qty' => $qty];
      }
    }

    if (empty($valid)) {
      $message = 'Nessuna riga valida: seleziona un prodotto dai suggerimenti e una quantità > 0.';
    } elseif (empty($user['id'])) {
      $message = 'Sessione non valida: utente non rilevato (created_by).';
    } else {
      $pdo->beginTransaction();
      try {
        // Preparo gli statement una sola volta
        $stmtLvlUp = $pdo->prepare("
          INSERT INTO stock_levels (product_id, warehouse, qty)
          VALUES (?,?,?)
          ON DUPLICATE KEY UPDATE qty = qty + VALUES(qty)
        ");
        $stmtMov = $pdo->prepare("
          INSERT INTO stock_movements (product_id, warehouse, type, qty_delta, ref, created_by)
          VALUES (?,?,?,?,?,?)
        ");

        foreach ($valid as $v) {
          $stmtLvlUp->execute([$v['pid'], $wh, $v['qty']]);
          $stmtMov->execute([$v['pid'], $wh, 'carico', $v['qty'], '', $user['id']]);
        }

        $pdo->commit();
        $message = 'Carico registrato.';
      } catch (PDOException $e) {
        $pdo->rollBack();
        // LOG dettagliato per capire la causa reale
        error_log('CARICO-ERR PDO: ' . $e->getMessage() . ' | info=' . json_encode($e->errorInfo));
        // Messaggio chiaro per debug (puoi renderlo generico in produzione)
        $msg = $e->getMessage();
        if (isset($e->errorInfo[1]) && (int)$e->errorInfo[1] === 1062) {
          $message = 'Errore: violazione chiave unica (controlla vincoli su stock_levels).';
        } else {
          $message = 'Errore DB durante il carico: ' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8');
        }
      } catch (Exception $e) {
        $pdo->rollBack();
        error_log('CARICO-ERR GEN: ' . $e->getMessage());
        $message = 'Errore durante il carico: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
      }
    }
  }
}

$title = 'Carico Magazzino';
include __DIR__ . '/../partials/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h5 mb-0">Carico</h1>
</div>
<?php if($message): ?><div class="alert alert-info"><?= e($message) ?></div><?php endif; ?>

<div class="card shadow-sm">
  <div class="card-body">
    <form method="post" id="caricoForm">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <div class="row g-3 align-items-end">
        <div class="col-md-3">
          <label class="form-label">Magazzino</label>
          <select name="warehouse" class="form-select">
            <option value="Tizzo">Tizzo</option>
            <option value="Tramonto">Tramonto</option>
          </select>
        </div>
      </div>
      <hr>
      <div id="items"></div>
      <button type="button" class="btn btn-outline-secondary btn-sm" onclick="window.invAddRow()">+ Prodotto</button>
      <div class="mt-3">
        <button class="btn btn-primary">Registra Carico</button>
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
  if (!q || q.length<2) { box.innerHTML=''; return; }
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
  const inp = document.getElementById('input_'+id) || document.getElementById('prod_input_'+id);
  if (inp) { inp.value = title; inp.focus(); }
}

window.invAddRow = invAddRow;
window.invAutocomplete = invAutocomplete;
window.invPick = invPick;

invAddRow();
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
