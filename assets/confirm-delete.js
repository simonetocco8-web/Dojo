(function () {
  'use strict';

  var pendingForm = null;
  var pendingSubmitter = null;

  function hiddenActionValue(form) {
    var actionInput = form.querySelector('input[name="action"]');
    return actionInput ? String(actionInput.value || '').toLowerCase() : '';
  }

  function formActionValue(form) {
    return String(form.getAttribute('action') || '').toLowerCase();
  }

  function buttonText(form) {
    var btn = form.querySelector('button[type="submit"], button:not([type]), input[type="submit"]');
    if (!btn) return '';
    return [btn.getAttribute('title'), btn.getAttribute('aria-label'), btn.textContent, btn.value].filter(Boolean).join(' ').toLowerCase();
  }

  function needsConfirmation(form) {
    if (form.dataset.confirmMessage) return true;

    var action = hiddenActionValue(form);
    if (['delete', 'trash', 'toggle_cancel', 'unset_booked'].indexOf(action) !== -1) return true;

    var target = formActionValue(form);
    if (target.indexOf('delete') !== -1) return true;

    var label = buttonText(form);
    return /elimina|cestina|cancell|rimuovi/.test(label);
  }

  function defaultMessage(form) {
    var action = hiddenActionValue(form);
    if (action === 'toggle_cancel') return 'Confermi di voler annullare o ripristinare questo elemento?';
    if (action === 'unset_booked') return 'Confermi di voler rimuovere Prenotato?';
    if (action === 'trash') return 'Confermi di voler spostare questo elemento nel cestino?';
    return 'Confermi di voler eliminare questo elemento?';
  }

  function submitConfirmedForm() {
    if (!pendingForm) return;
    var form = pendingForm;
    var submitter = pendingSubmitter;
    pendingForm = null;
    pendingSubmitter = null;
    form.dataset.confirmed = '1';
    if (typeof form.requestSubmit === 'function') {
      if (submitter && submitter.form === form) {
        form.requestSubmit(submitter);
      } else {
        form.requestSubmit();
      }
    } else {
      form.submit();
    }
  }

  function showModal(message) {
    var modalEl = document.getElementById('deleteConfirmModal');
    var messageEl = document.getElementById('deleteConfirmModalMessage');
    var confirmBtn = document.getElementById('deleteConfirmModalButton');

    if (!modalEl || !confirmBtn || typeof bootstrap === 'undefined' || !bootstrap.Modal) {
      if (window.confirm(message)) {
        submitConfirmedForm();
      } else {
        pendingForm = null;
        pendingSubmitter = null;
      }
      return;
    }

    if (messageEl) messageEl.textContent = message;
    var modal = bootstrap.Modal.getOrCreateInstance(modalEl);

    confirmBtn.onclick = function () {
      modal.hide();
      submitConfirmedForm();
    };

    modal.show();
  }

  document.addEventListener('submit', function (event) {
    var form = event.target;
    if (!(form instanceof HTMLFormElement) || !needsConfirmation(form)) return;

    if (form.dataset.confirmed === '1') {
      delete form.dataset.confirmed;
      return;
    }

    event.preventDefault();
    pendingForm = form;
    pendingSubmitter = event.submitter || null;
    showModal(form.dataset.confirmMessage || defaultMessage(form));
  }, true);
}());
