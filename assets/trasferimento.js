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
  document.getElementById('stock_'+id).innerText = 'Giacenza attuale (tot): '+stock;
    const inp = document.getElementById('input_'+id) || document.getElementById('prod_input_'+id);
  if (inp) { inp.value = title; inp.focus(); }
}

window.invAddRow = invAddRow;
window.invAutocomplete = invAutocomplete;
window.invPick = invPick;

invAddRow();
