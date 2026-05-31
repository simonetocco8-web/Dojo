(function(){
  function esc(s){
    return String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
      .replace(/"/g,'&quot;').replace(/'/g,'&#39;');
  }
  function num(v){
    var n = parseFloat(v);
    return isNaN(n) ? 0 : n;
  }
  function formatQty(v){
    return (num(v) + 0).toString();
  }

  var items = [];
  var pendingProduct = null;
  var debounce = null;
  var warehouseInput = document.getElementById('caricoWarehouse');
  var warehouseStep = document.getElementById('warehouseStep');
  var productStep = document.getElementById('productStep');
  var selectedWarehouseLabel = document.getElementById('selectedWarehouseLabel');
  var searchInput = document.getElementById('caricoSearch');
  var resultsBox = document.getElementById('caricoResults');
  var cart = document.getElementById('caricoCart');
  var cartEmpty = document.getElementById('caricoCartEmpty');
  var hiddenItems = document.getElementById('caricoHiddenItems');
  var submitBtn = document.getElementById('submitCaricoBtn');
  var itemsCount = document.getElementById('itemsCount');
  var qtyModalEl = document.getElementById('qtyModal');
  var qtyModal = qtyModalEl && window.bootstrap ? new bootstrap.Modal(qtyModalEl) : null;
  var qtyInput = document.getElementById('qtyInput');
  var qtyProductTitle = document.getElementById('qtyProductTitle');
  var qtyProductStock = document.getElementById('qtyProductStock');

  if (!warehouseInput || !warehouseStep || !productStep) return;

  function selectedWarehouse(){ return warehouseInput.value || ''; }

  function goToProducts(warehouse){
    warehouseInput.value = warehouse;
    selectedWarehouseLabel.textContent = warehouse;
    warehouseStep.classList.add('d-none');
    productStep.classList.remove('d-none');
    document.querySelectorAll('.warehouse-choice').forEach(function(btn){
      btn.classList.toggle('active', btn.dataset.warehouse === warehouse);
    });
    setTimeout(function(){ if (searchInput) searchInput.focus(); }, 100);
  }

  function goToWarehouse(){
    productStep.classList.add('d-none');
    warehouseStep.classList.remove('d-none');
    if (searchInput) searchInput.value = '';
    if (resultsBox) resultsBox.innerHTML = '';
  }

  function renderCart(){
    hiddenItems.innerHTML = '';
    cart.innerHTML = '';
    items.forEach(function(item, index){
      var row = document.createElement('div');
      row.className = 'list-group-item d-flex justify-content-between align-items-start gap-3';
      row.innerHTML = '<div class="flex-grow-1">'
        + '<div class="fw-semibold">' + esc(item.title) + '</div>'
        + '<div class="small text-muted">Giacenza ' + esc(selectedWarehouse()) + ': ' + esc(formatQty(item.stock)) + ' · Q.tà: <strong>' + esc(formatQty(item.qty)) + '</strong></div>'
        + '</div>'
        + '<button type="button" class="btn btn-sm btn-outline-danger" data-remove="' + index + '" aria-label="Rimuovi prodotto"><i class="bi bi-trash"></i></button>';
      cart.appendChild(row);

      var pid = document.createElement('input');
      pid.type = 'hidden';
      pid.name = 'items[' + index + '][product_id]';
      pid.value = item.id;
      hiddenItems.appendChild(pid);
      var qty = document.createElement('input');
      qty.type = 'hidden';
      qty.name = 'items[' + index + '][qty]';
      qty.value = item.qty;
      hiddenItems.appendChild(qty);
    });
    cartEmpty.classList.toggle('d-none', items.length > 0);
    submitBtn.disabled = items.length === 0;
    itemsCount.textContent = items.length === 1 ? '1 prodotto' : items.length + ' prodotti';
  }

  function addItem(product, qty){
    var existing = items.find(function(item){ return item.id === product.id; });
    if (existing) {
      existing.qty = num(existing.qty) + num(qty);
    } else {
      items.push({ id: product.id, title: product.title, stock: product.stock, qty: qty });
    }
    renderCart();
    if (searchInput) {
      searchInput.value = '';
      searchInput.focus();
    }
    if (resultsBox) resultsBox.innerHTML = '';
  }

  function openQtyModal(product){
    pendingProduct = product;
    qtyProductTitle.textContent = product.title;
    qtyProductStock.textContent = 'Giacenza attuale in ' + selectedWarehouse() + ': ' + formatQty(product.stock);
    qtyInput.value = '';
    if (qtyModal) {
      qtyModal.show();
      qtyModalEl.addEventListener('shown.bs.modal', function onShown(){
        qtyModalEl.removeEventListener('shown.bs.modal', onShown);
        qtyInput.focus();
      });
    } else {
      var q = prompt('Quantità da caricare per ' + product.title);
      if (q !== null && num(q) > 0) addItem(product, num(q));
    }
  }

  function searchProducts(q){
    var warehouse = selectedWarehouse();
    if (!q || q.length < 2 || !warehouse) {
      resultsBox.innerHTML = '';
      return;
    }
    fetch('product_search.php?q=' + encodeURIComponent(q) + '&warehouse=' + encodeURIComponent(warehouse), {credentials:'same-origin'})
      .then(function(res){
        if (!res.ok) throw new Error('HTTP ' + res.status);
        return res.json();
      })
      .then(function(data){
        if (!Array.isArray(data) || data.length === 0) {
          resultsBox.innerHTML = '<div class="list-group-item small text-muted">Nessun risultato</div>';
          return;
        }
        resultsBox.innerHTML = data.map(function(r){
          var stock = r.stock == null ? 0 : r.stock;
          return '<button type="button" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center gap-2"'
            + ' data-pid="' + esc(r.id) + '" data-title="' + esc(r.title) + '" data-stock="' + esc(stock) + '">'
            + '<span class="text-start">' + esc(r.title) + '</span>'
            + '<span class="badge text-bg-light flex-shrink-0">' + esc(formatQty(stock)) + '</span>'
            + '</button>';
        }).join('');
      })
      .catch(function(){
        resultsBox.innerHTML = '<div class="list-group-item small text-danger">Errore durante la ricerca</div>';
      });
  }

  document.querySelectorAll('.warehouse-choice').forEach(function(btn){
    btn.addEventListener('click', function(){ goToProducts(btn.dataset.warehouse); });
  });

  var changeWarehouseBtn = document.getElementById('changeWarehouseBtn');
  if (changeWarehouseBtn) {
    changeWarehouseBtn.addEventListener('click', function(){
      if (items.length && !confirm('Cambiando magazzino verrà svuotata la lista prodotti. Continuare?')) return;
      items = [];
      renderCart();
      warehouseInput.value = '';
      goToWarehouse();
    });
  }

  if (searchInput) {
    searchInput.addEventListener('input', function(){
      clearTimeout(debounce);
      var q = searchInput.value.trim();
      debounce = setTimeout(function(){ searchProducts(q); }, 180);
    });
  }

  if (resultsBox) {
    resultsBox.addEventListener('click', function(ev){
      var btn = ev.target.closest('button[data-pid]');
      if (!btn) return;
      openQtyModal({ id: parseInt(btn.dataset.pid, 10), title: btn.dataset.title || '', stock: num(btn.dataset.stock) });
    });
  }

  var confirmQtyBtn = document.getElementById('confirmQtyBtn');
  if (confirmQtyBtn) {
    confirmQtyBtn.addEventListener('click', function(){
      var qty = num(qtyInput.value);
      if (!pendingProduct || qty <= 0) {
        qtyInput.classList.add('is-invalid');
        qtyInput.focus();
        return;
      }
      qtyInput.classList.remove('is-invalid');
      addItem(pendingProduct, qty);
      pendingProduct = null;
      if (qtyModal) qtyModal.hide();
    });
  }

  if (qtyInput) {
    qtyInput.addEventListener('keydown', function(ev){
      if (ev.key === 'Enter') {
        ev.preventDefault();
        confirmQtyBtn.click();
      }
    });
  }

  if (cart) {
    cart.addEventListener('click', function(ev){
      var btn = ev.target.closest('button[data-remove]');
      if (!btn) return;
      items.splice(parseInt(btn.dataset.remove, 10), 1);
      renderCart();
    });
  }

  var form = document.getElementById('caricoForm');
  if (form) {
    form.addEventListener('submit', function(ev){
      if (!selectedWarehouse()) {
        ev.preventDefault();
        goToWarehouse();
        return;
      }
      if (items.length === 0) {
        ev.preventDefault();
        alert('Aggiungi almeno un prodotto prima di inviare il carico.');
      }
    });
  }

  renderCart();
  if (warehouseInput.value) goToProducts(warehouseInput.value);
})();
