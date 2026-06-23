(function () {
  document.querySelectorAll('[data-maxlength]').forEach(function (field) {
    var max = parseInt(field.getAttribute('data-maxlength'), 10) || 0;
    var counter = document.querySelector('[data-char-counter-for="' + field.id + '"]');
    if (!counter) return;

    var update = function () {
      var remaining = Math.max(0, max - Array.from(field.value).length);
      counter.textContent = remaining + (remaining === 1 ? ' carattere rimanente' : ' caratteri rimanenti');
    };

    field.addEventListener('input', update);
    update();
  });
})();
