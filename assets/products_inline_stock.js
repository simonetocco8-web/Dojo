(function(){
    const ENDPOINT = 'stock_update_ajax.php'; // <— questo
    
    
  function ready(fn){
      const root = document.getElementById('stock-inline-root');
    const csrf = root?.getAttribute('data-csrf') || '';
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fn, {once:true});
    else fn();
  }

  async function saveStock({productId, warehouse, qty, csrf}) {
      const token = document.getElementById('stock-inline-root')?.dataset.csrf || '';
  const res = await fetch(ENDPOINT, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json',
    'X-CSRF-Token': token},
    credentials: 'same-origin',
    body: JSON.stringify({ product_id: productId, warehouse, qty, csrf })
  });
  
  




  let data = null;
  try { data = await res.json(); } catch { /* noop */ }

  if (!res.ok || !data || !data.ok) {
    const msg = (data && (data.error || data.detail)) || ('HTTP ' + res.status);
    throw new Error(msg);
  }
  return data;
}

  function parseNum(v){ const n = parseFloat(v); return isFinite(n) ? n : 0; }

  function updateRowTotalsFromInputs(rowEl){
    const inT = rowEl.querySelector('.stock-input[data-warehouse="Tizzo"]');
    const inR = rowEl.querySelector('.stock-input[data-warehouse="Tramonto"]');
    const badge = rowEl.querySelector('[data-total-for]');
    if (!badge) return;

    const t = inT ? parseNum(inT.value) : 0;
    const r = inR ? parseNum(inR.value) : 0;
    const tot = t + r;
    badge.textContent = tot;

    const min = parseFloat(badge.getAttribute('data-min')) || 0;
    badge.className = (min && tot < min) ? 'badge bg-danger' : 'badge bg-light text-dark';
  }

  ready(function(){
    const root = document.getElementById('stock-inline-root');
    if (!root) { console.warn('[stock-inline] root mancante'); return; }
    const csrf = root.getAttribute('data-csrf');

    document.querySelectorAll('.stock-input').forEach(input=>{
      const productId = parseInt(input.getAttribute('data-product-id'),10);
      const warehouse = input.getAttribute('data-warehouse');

      // Update ottimistico mentre digiti / cambi
      input.addEventListener('input', ()=>{
        const rowEl = input.closest('tr');
        if (rowEl) updateRowTotalsFromInputs(rowEl);
      });
      input.addEventListener('change', ()=>{
        const rowEl = input.closest('tr');
        if (rowEl) updateRowTotalsFromInputs(rowEl);
      });

      // Salva su Enter o blur
      input.addEventListener('keydown', (e)=>{
        if (e.key === 'Enter') { e.preventDefault(); input.blur(); }
      });

      input.addEventListener('blur', async ()=>{
        const rowEl = input.closest('tr');
        const qty = input.value === '' ? 0 : parseFloat(input.value);
        if (!isFinite(qty) || qty < 0) { input.classList.add('is-invalid'); return; }
        input.classList.remove('is-invalid');

        try {
          input.disabled = true;
          const data = await saveStock({productId, warehouse, qty, csrf});

          // riallinea gli input con i valori “ufficiali” del server
          const inT = rowEl.querySelector('.stock-input[data-warehouse="Tizzo"]');
          const inR = rowEl.querySelector('.stock-input[data-warehouse="Tramonto"]');
          if (inT && typeof data.qty_tizzo === 'number')    inT.value = data.qty_tizzo;
          if (inR && typeof data.qty_tramonto === 'number') inR.value = data.qty_tramonto;

          // aggiorna badge totale dalla risposta
          const badge = rowEl.querySelector('[data-total-for]');
          if (badge) {
            badge.textContent = data.total_qty;
            const min = parseFloat(badge.getAttribute('data-min')) || 0;
            badge.className = (min && data.total_qty < min) ? 'badge bg-danger' : 'badge bg-light text-dark';
          }
        } catch(err){
          console.error('[stock-inline] save error', err);
          input.classList.add('is-invalid');
          alert('Errore salvataggio: ' + err.message);
        } finally {
          input.disabled = false;
        }
      });
    });
  });
})();
