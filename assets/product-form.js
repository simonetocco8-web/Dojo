(function () {
  function isInternetSupplier(option) {
    if (!option) return false;
    var supplierName = option.getAttribute('data-supplier-name') || option.text || '';
    return option.getAttribute('data-is-internet') === '1' || supplierName.trim().toLowerCase() === 'internet';
  }

  function toggleProductUrlField() {
    var supplierSelect = document.getElementById('supplier_id');
    var urlField = document.getElementById('productUrlField');
    if (!supplierSelect || !urlField) return;
    var option = supplierSelect.options[supplierSelect.selectedIndex];
    urlField.classList.toggle('d-none', !isInternetSupplier(option));
  }

  function initProductForm() {
    var supplierSelect = document.getElementById('supplier_id');
    if (!supplierSelect) return;
    supplierSelect.addEventListener('change', toggleProductUrlField);
    toggleProductUrlField();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initProductForm);
  } else {
    initProductForm();
  }
})();
