(function () {
  'use strict';

  var button = document.getElementById('poolAutoComplete');
  var form = document.getElementById('poolInspectionForm');
  if (!button || !form) return;

  function setValue(name, value) {
    var field = form.elements.namedItem(name);
    if (field) field.value = value || '';
  }

  button.addEventListener('click', function () {
    setValue('inspection_time', button.dataset.inspectionTime);
    setValue('chlorine', button.dataset.chlorine);
    setValue('water_temperature', button.dataset.temperature);
    setValue('ph_value', button.dataset.ph);
    setValue('people_in_pool', button.dataset.people);
    setValue('backwash_minutes', button.dataset.backwash);

    var location = form.querySelector('input[name="sample_location"][value="' + button.dataset.sampleLocation + '"]');
    if (location) location.checked = true;

    form.querySelectorAll('input[name^="product_quantities["]').forEach(function (field) {
      field.value = '';
    });
    var products = {};
    try {
      products = JSON.parse(button.dataset.products || '{}');
    } catch (error) {
      products = {};
    }
    Object.keys(products).forEach(function (productId) {
      var field = form.elements.namedItem('product_quantities[' + productId + ']');
      if (field) field.value = products[productId];
    });

    var firstField = form.elements.namedItem('inspection_time');
    if (firstField) firstField.focus();
  });
}());
