(() => {
  const form = document.getElementById('internalTransferCreateForm');
  if (!form) return;
  const modalElement = document.getElementById('coopOpeningModal');
  const messageElement = document.getElementById('coopOpeningModalMessage');
  const changeButton = document.getElementById('coopOpeningChange');
  const closeButton = document.getElementById('coopOpeningClose');
  const confirmButton = document.getElementById('coopOpeningConfirm');
  const modal = modalElement && window.bootstrap?.Modal
    ? window.bootstrap.Modal.getOrCreateInstance(modalElement)
    : null;
  let warnings = [];
  let warningIndex = 0;
  let submitter = null;
  let bypassWarnings = false;

  const selectedLocation = () => {
    const mode = form.querySelector('input[name="loc_mode"]:checked')?.value;
    const field = mode === 'custom'
      ? form.querySelector('[name="location_custom"]')
      : form.querySelector('[name="location_predef"]');
    return (field?.value || '').trim().toLocaleLowerCase('it');
  };

  const collectWarnings = () => {
    if (selectedLocation() !== 'coop') return [];
    const dateField = form.querySelector('[name="date"]');
    const timeField = form.querySelector('[name="time"]');
    const dateParts = (dateField?.value || '').split('-').map(Number);
    const isSunday = dateParts.length === 3
      && new Date(dateParts[0], dateParts[1] - 1, dateParts[2], 12).getDay() === 0;
    const result = [];
    if (isSunday) result.push({ message: 'Il transfer per Coop è previsto di domenica. Hai verificato che il negozio sia effettivamente aperto?', changeLabel: 'Modifica data', confirmLabel: 'Conferma data', field: dateField });
    const transferTime = timeField?.value || '';
    if (transferTime >= '13:00' && transferTime <= '16:00') result.push({ message: 'Il transfer per Coop è previsto tra le 13:00 e le 16:00. Hai verificato che il negozio sia effettivamente aperto in questo orario?', changeLabel: 'Modifica orario', confirmLabel: 'Conferma orario', field: timeField });
    return result;
  };

  const showWarning = () => {
    const warning = warnings[warningIndex];
    messageElement.textContent = warning.message;
    changeButton.querySelector('span').textContent = warning.changeLabel;
    confirmButton.querySelector('span').textContent = warning.confirmLabel;
    modal.show();
  };

  const changeSelection = () => {
    const field = warnings[warningIndex]?.field;
    modalElement.addEventListener('hidden.bs.modal', () => field?.focus(), { once: true });
    modal.hide();
  };
  changeButton?.addEventListener('click', changeSelection);
  closeButton?.addEventListener('click', changeSelection);
  confirmButton?.addEventListener('click', () => {
    warningIndex += 1;
    if (warningIndex < warnings.length) {
      modalElement.addEventListener('hidden.bs.modal', showWarning, { once: true });
      modal.hide();
      return;
    }
    bypassWarnings = true;
    modalElement.addEventListener('hidden.bs.modal', () => submitter ? form.requestSubmit(submitter) : form.requestSubmit(), { once: true });
    modal.hide();
  });

  form.addEventListener('submit', (event) => {
    if (bypassWarnings) { bypassWarnings = false; return; }
    warnings = collectWarnings();
    if (!warnings.length) return;
    event.preventDefault();
    event.stopImmediatePropagation();
    warningIndex = 0;
    submitter = event.submitter || null;
    if (modal) showWarning();
    else if (warnings.every((warning) => window.confirm(warning.message))) { bypassWarnings = true; submitter ? form.requestSubmit(submitter) : form.requestSubmit(); }
  }, true);
})();
