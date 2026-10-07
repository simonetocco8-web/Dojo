(function () {
  'use strict';
  var form = document.getElementById('rodentInspectionForm');
  var question = document.getElementById('baitEatenQuestion');
  if (!form || !question) return;
  function update() {
    var yes = document.getElementById('bait_presentYes');
    var visible = yes && yes.checked;
    question.classList.toggle('d-none', !visible);
    question.querySelectorAll('input').forEach(function (input) {
      input.required = visible;
      if (!visible) input.checked = false;
    });
  }
  form.querySelectorAll('input[name="bait_present"]').forEach(function (input) { input.addEventListener('change', update); });
  update();
}());

(function () {
  'use strict';
  var form = document.getElementById('rodentEmergencyForm');
  if (!form) return;
  var date = document.getElementById('emergencyDate');
  function update() {
    var scheduled = form.querySelector('input[value="date"]').checked;
    date.required = scheduled;
    date.disabled = !scheduled;
  }
  form.querySelectorAll('input[name="emergency_mode"]').forEach(function (input) {
    input.addEventListener('change', update);
  });
  update();
}());
