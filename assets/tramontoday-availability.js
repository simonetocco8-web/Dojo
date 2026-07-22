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

    document.querySelectorAll('[data-tramontoday-availability-day]').forEach(function (button) {
      button.addEventListener('click', function () {
        if (dateInput) dateInput.value = button.dataset.date || '';
        if (dateLabel) dateLabel.textContent = button.dataset.displayDate || '';
        if (maxInput) maxInput.value = button.dataset.maxStations || '0';
        if (isOpenInput) isOpenInput.checked = button.dataset.isOpen === '1';
        if (notesInput) notesInput.value = button.dataset.notes || '';
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
