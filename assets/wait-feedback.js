(function () {
  const forms = document.querySelectorAll('form[data-wait-feedback]');
  if (!forms.length) return;

  let overlay = document.getElementById('waitFeedbackOverlay');
  let overlayText = document.getElementById('waitFeedbackOverlayText');

  function showOverlay(message) {
    if (!overlay) return;
    if (overlayText) overlayText.textContent = message || 'Operazione in corso, attendere...';
    overlay.classList.remove('d-none');
    overlay.setAttribute('aria-hidden', 'false');
  }

  forms.forEach(function (form) {
    form.addEventListener('submit', function (event) {
      if (form.dataset.waitFeedbackActive === '1') return;

      if (typeof form.checkValidity === 'function' && !form.checkValidity()) {
        if (typeof form.reportValidity === 'function') form.reportValidity();
        return;
      }

      form.dataset.waitFeedbackActive = '1';
      const message = form.getAttribute('data-wait-feedback') || 'Operazione in corso, attendere...';
      const submitButtons = form.querySelectorAll('button[type="submit"], button:not([type]), input[type="submit"]');
      submitButtons.forEach(function (button) {
        button.dataset.originalText = button.tagName === 'INPUT' ? button.value : button.innerHTML;
        button.disabled = true;
        if (button.tagName === 'INPUT') {
          button.value = 'Attendere...';
        } else {
          button.innerHTML = '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Attendere...';
        }
      });
      showOverlay(message);
    });
  });
})();
