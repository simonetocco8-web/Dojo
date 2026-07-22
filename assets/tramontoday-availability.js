(function () {
  function initAvailabilityModal() {
    const modalEl = document.getElementById('tramontoDayAvailabilityModal');
    if (!modalEl || typeof bootstrap === 'undefined') return;

    const modal = new bootstrap.Modal(modalEl);
    const dateInput = document.getElementById('availability_date');
    const dateLabel = document.getElementById('availabilityDateLabel');
    const maxInput = document.getElementById('max_sellable_stations');
    const isOpenInput = document.getElementById('is_open');
    const notesInput = document.getElementById('internal_notes');
    const extendDaysInput = document.getElementById('extend_days');
    const extendDaysHelp = document.getElementById('extendDaysHelp');

    document.querySelectorAll('[data-tramontoday-availability-day]').forEach(function (button) {
      button.addEventListener('click', function () {
        if (dateInput) dateInput.value = button.dataset.date || '';
        if (dateLabel) dateLabel.textContent = button.dataset.displayDate || '';
        if (maxInput) maxInput.value = button.dataset.maxStations || '0';
        if (isOpenInput) isOpenInput.checked = button.dataset.isOpen === '1';
        if (notesInput) notesInput.value = button.dataset.notes || '';
        if (extendDaysInput) {
          const remainingDays = Math.max(1, Number(button.dataset.remainingDays || '1'));
          extendDaysInput.value = '1';
          extendDaysInput.max = String(remainingDays);
          if (extendDaysHelp) {
            extendDaysHelp.textContent = 'Puoi prorogare da 1 a ' + remainingDays + ' giorni a partire dal giorno selezionato.';
          }
        }
        modal.show();
      });
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAvailabilityModal);
  } else {
    initAvailabilityModal();
  }
})();
