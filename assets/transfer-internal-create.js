(() => {
  const form = document.getElementById('internalTransferCreateForm');
  if (!form) return;

  const selectedLocation = () => {
    const mode = form.querySelector('input[name="loc_mode"]:checked')?.value;
    const field = mode === 'custom'
      ? form.querySelector('[name="location_custom"]')
      : form.querySelector('[name="location_predef"]');
    return (field?.value || '').trim().toLocaleLowerCase('it');
  };

  form.addEventListener('submit', (event) => {
    if (selectedLocation() !== 'coop') return;

    const dateField = form.querySelector('[name="date"]');
    const timeField = form.querySelector('[name="time"]');
    const dateParts = (dateField?.value || '').split('-').map(Number);
    const isSunday = dateParts.length === 3
      && new Date(dateParts[0], dateParts[1] - 1, dateParts[2], 12).getDay() === 0;

    if (isSunday && !window.confirm('Il transfer per Coop è previsto di domenica. Hai verificato che il negozio sia effettivamente aperto?\n\nPremi OK per confermare la data oppure Annulla per modificarla.')) {
      event.preventDefault();
      event.stopImmediatePropagation();
      dateField?.focus();
      return;
    }

    const transferTime = timeField?.value || '';
    if (transferTime >= '13:00' && transferTime <= '16:00'
      && !window.confirm('Il transfer per Coop è previsto tra le 13:00 e le 16:00. Hai verificato che il negozio sia effettivamente aperto in questo orario?\n\nPremi OK per confermare l’orario oppure Annulla per modificarlo.')) {
      event.preventDefault();
      event.stopImmediatePropagation();
      timeField?.focus();
    }
  }, true);
})();
