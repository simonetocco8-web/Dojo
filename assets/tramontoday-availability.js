(function () {
  function initAvailabilityModal() {
    const modalEl = document.getElementById('tramontoDayAvailabilityModal');
    if (!modalEl || typeof bootstrap === 'undefined') return;

    const modal = new bootstrap.Modal(modalEl);
    const dateInput = document.getElementById('availability_date');
    const dateLabel = document.getElementById('availabilityDateLabel');
    const morningInput = document.getElementById('morning_sellable_stations');
    const afternoonInput = document.getElementById('afternoon_sellable_stations');
    const isOpenInput = document.getElementById('is_open');
    const notesInput = document.getElementById('internal_notes');
    const extendDaysInput = document.getElementById('extend_days');
    const extendDaysHelp = document.getElementById('extendDaysHelp');

    document.querySelectorAll('[data-tramontoday-availability-day]').forEach(function (button) {
      button.addEventListener('click', function () {
        if (dateInput) dateInput.value = button.dataset.date || '';
        if (dateLabel) dateLabel.textContent = button.dataset.displayDate || '';
        if (morningInput) morningInput.value = button.dataset.morningStations || '0';
        if (afternoonInput) afternoonInput.value = button.dataset.afternoonStations || '0';
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

  function initAvailabilitySearch() {
    const form = document.getElementById('tramontoDayAvailabilitySearch');
    const dateSearchInput = document.getElementById('availabilitySearchDate');
    const modalEl = document.getElementById('tramontoDayAvailabilitySearchModal');
    const result = document.getElementById('availabilitySearchResult');
    const bookingLink = document.getElementById('availabilitySearchBookingLink');
    if (!form || !dateSearchInput || !modalEl || !result || !bookingLink || typeof bootstrap === 'undefined') return;

    const modal = new bootstrap.Modal(modalEl);
    form.addEventListener('submit', function (event) {
      event.preventDefault();
      if (!form.checkValidity()) {
        form.classList.add('was-validated');
        return;
      }

      const selectedDate = dateSearchInput.value;
      const day = Array.from(document.querySelectorAll('[data-tramontoday-availability-day]')).find(function (candidate) {
        return candidate.dataset.date === selectedDate;
      });
      const bookingUrl = bookingLink.dataset.bookingUrl || 'tramontoday_booking_create.php';
      bookingLink.href = bookingUrl + '?booking_date=' + encodeURIComponent(selectedDate);

      if (!day || day.dataset.isOpen !== '1') {
        const displayDate = day ? day.dataset.displayDate : selectedDate.split('-').reverse().join('/');
        result.innerHTML = '<div class="alert alert-warning mb-0"><i class="bi bi-exclamation-triangle me-1"></i>'
          + '<strong>Nessuna disponibilità per il ' + displayDate + '.</strong></div>';
        modal.show();
        return;
      }

      const morning = Math.max(0, Number(day.dataset.morningAvailable || '0'));
      const afternoon = Math.max(0, Number(day.dataset.afternoonAvailable || '0'));
      const solutions = [];
      if (Math.min(morning, afternoon) > 0) solutions.push('Giornata intera: ' + Math.min(morning, afternoon) + ' postazioni');
      if (morning > 0) solutions.push('Mattina: ' + morning + ' postazioni');
      if (afternoon > 0) solutions.push('Pomeriggio: ' + afternoon + ' postazioni');

      if (solutions.length === 0) {
        result.innerHTML = '<div class="alert alert-warning mb-0"><i class="bi bi-exclamation-triangle me-1"></i>'
          + '<strong>Nessuna disponibilità per il ' + day.dataset.displayDate + '.</strong></div>';
      } else {
        result.innerHTML = '<p class="mb-2">Soluzioni disponibili per il <strong>' + day.dataset.displayDate + '</strong>:</p>'
          + '<ul class="list-group">' + solutions.map(function (solution) {
            return '<li class="list-group-item d-flex align-items-center"><i class="bi bi-check-circle-fill text-success me-2"></i>' + solution + '</li>';
          }).join('') + '</ul>';
      }
      modal.show();
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAvailabilityModal);
    document.addEventListener('DOMContentLoaded', initAvailabilitySearch);
  } else {
    initAvailabilityModal();
    initAvailabilitySearch();
  }
})();
