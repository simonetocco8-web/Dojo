(function () {
  'use strict';

  function updateAnomalyField() {
    var negativeAnswer = document.getElementById('testNo');
    var box = document.getElementById('anomalyBox');
    var field = document.getElementById('anomaly');
    if (!negativeAnswer || !box || !field) return;

    var anomalyRequired = negativeAnswer.checked;
    box.classList.toggle('d-none', !anomalyRequired);
    field.required = anomalyRequired;
    if (!anomalyRequired) field.value = '';
  }

  document.querySelectorAll('input[name="differentials_ok"]').forEach(function (input) {
    input.addEventListener('change', updateAnomalyField);
  });
  updateAnomalyField();
}());
