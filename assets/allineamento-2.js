(function () {
  const SEARCH_URL = 'product_search_ajax.php'; // cambia se diverso

  function escAttr(s){
    return String(s)
      .replace(/&/g,'&amp;')
      .replace(/</g,'&lt;')
      .replace(/>/g,'&gt;')
      .replace(/"/g,'&quot;')
      .replace(/'/g,'&#39;');
  }
  function ready(fn){
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', fn, {once:true});
    } else { fn(); }
  }

  // ---- Autocomplete e pick ----
  async function doAutocomplete(row, q){
    const box = document.querySelector('.ac-box[data-row="'+row+'"]');
    if (!box) return;
    if (!q || q.length<2) { box.innerHTML=''; return; }

    try {
      const res = await fetch(SEARCH_URL + '?q=' + encodeURIComponent(q), { credentials:'same-origin' });
      if (!res.ok){ box.innerHTML = '<div class="small text-danger p-2">Errore '+res.status+'</div>'; return; }
      const data = await res.json();
      if (!Array.isArray(data) || data.length===0){
        box.innerHTML = '<div class="list-group-item small text-muted">Nessun risultato</div>'; return;
      }
      box.innerHTML = data.map(r => `
        <button type="button" class="list-group-item list-group-item-action"
          data-pid="${r.id}" data-title="${escAttr(r.title||'')}" data-stock="${r.stock ?? 0}">
          ${escAttr(r.title)} <span class="badge bg-light text-dark">Giacenza tot: ${r.stock ?? 0}</span>
        </button>
      `).join('');
    } catch (e) {
      console.error('[allineamento] fetch error', e);
      box.innerHTML = '<div class="small text-danger p-2">Errore rete</div>';
    }
  }

  function pickProduct(row, pid, title, stock){
    const pidEl   = document.querySelector('.pid-input[data-row="'+row+'"]');
    const box     = document.querySelector('.ac-box[data-row="'+row+'"]');
    const stockEl = document.querySelector('.stock-label[data-row="'+row+'"]');
    const input   = document.querySelector('.prod-input[data-row="'+row+'"]');

    if (pidEl)   pidEl.value = String(pid);
    if (box)     box.innerHTML = '';
    if (stockEl) stockEl.textContent = 'Giacenza attuale (tot): ' + (isFinite(stock) ? stock : 0);
    if (input)   input.value = title || input.value;

    // Se ho scelto un prodotto e sono sull'ultima riga -> aggiungi una nuova riga
    const rows = document.querySelectorAll('.align-row');
    const isLast = rows.length && rows[rows.length-1].dataset.row === String(row);
    if (isLast) addRow();
  }

  // ---- Gestione righe dinamiche ----
  let nextRowId = 1;

  function bindRowEvents(row){
    const input = document.querySelector('.prod-input[data-row="'+row+'"]');
    const box   = document.querySelector('.ac-box[data-row="'+row+'"]');
    const qty   = document.querySelector('.qty-input[data-row="'+row+'"]');
    const removeBtn = document.querySelector('.align-row[data-row="'+row+'"] .remove-row-btn');

    if (input) {
      input.addEventListener('input', e => doAutocomplete(row, e.target.value));
    }
    if (box) {
      box.addEventListener('click', ev => {
        const btn = ev.target.closest('button[data-pid]');
        if (!btn) return;
        pickProduct(
          row,
          parseInt(btn.dataset.pid,10),
          btn.dataset.title,
          parseFloat(btn.dataset.stock) || 0
        );
      });
    }
    if (qty) {
      // se metti la quantità e sei sull'ultima riga, crea la prossima
      qty.addEventListener('change', () => {
        const rows = document.querySelectorAll('.align-row');
        const isLast = rows.length && rows[rows.length-1].dataset.row === String(row);
        const hasPid = !!document.querySelector('.pid-input[data-row="'+row+'"]')?.value;
        if (isLast && hasPid && qty.value !== '') addRow();
      });
    }
    if (removeBtn) {
      removeBtn.addEventListener('click', () => {
        const rowEl = document.querySelector('.align-row[data-row="'+row+'"]');
        if (rowEl) rowEl.remove();
        // se non c'è più nessuna riga, aggiungine una
        if (!document.querySelector('.align-row')) addRow();
      });
    }
  }

  function addRow(){
    const tpl = document.getElementById('row-template');
    const container = document.getElementById('rows-container');
    if (!tpl || !container) return;

    const rowId = nextRowId++;
    const frag = tpl.content.cloneNode(true);
    const rowEl = frag.querySelector('.align-row');
    rowEl.dataset.row = String(rowId);

    // aggiorna tutti gli elementi della riga col data-row
    frag.querySelectorAll('[data-row=""]').forEach(el => el.setAttribute('data-row', String(rowId)));

    container.appendChild(frag);
    bindRowEvents(rowId);
  }

  ready(() => {
    // bottone "Aggiungi riga"
    const addBtn = document.getElementById('add-row-btn');
    if (addBtn) addBtn.addEventListener('click', addRow);

    // prima riga iniziale
    addRow();
  });
})();
