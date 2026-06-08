(function(){
  function onReady(callback) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', callback);
      return;
    }
    callback();
  }

  onReady(function(){
    const booked = document.getElementById('booked');
    const wrap = document.getElementById('companyWrap');
    const input = document.getElementById('service_company');
    const type = document.getElementById('transfer_type');
    const form = document.getElementById('extForm');
    const sendEmailInput = document.getElementById('send_supplier_email');
    const emailPreview = document.getElementById('transferEmailPreview');
    const emailModalElement = document.getElementById('transferEmailConfirmModal');
    const emailSendButton = document.getElementById('transferEmailSendButton');
    const emailSkipButton = document.getElementById('transferEmailSkipButton');
    const singleFields = document.getElementById('singleTransferFields');
    const roundTripFields = document.getElementById('roundTripTransferFields');
    let confirmedSubmit = false;

    function syncCompanyField(){
      if (!booked || !wrap || !input) return;
      if (booked.checked) {
        wrap.style.display = '';
        input.required = true;
        input.disabled = false;
      } else {
        wrap.style.display = 'none';
        input.required = false;
        input.disabled = true;
        input.value = '';
      }
    }

    function setSectionVisible(section, visible) {
      if (!section) return;
      section.classList.toggle('d-none', !visible);
      section.hidden = !visible;
      section.querySelectorAll('input, select, textarea').forEach(function(field){
        field.disabled = !visible;
      });
    }

    function syncRequiredFields(isRoundTrip) {
      document.querySelectorAll('[data-single-required]').forEach(function(field){
        field.required = !isRoundTrip;
        field.disabled = isRoundTrip || field.closest('[hidden]') !== null;
      });
      document.querySelectorAll('[data-roundtrip-required]').forEach(function(field){
        field.required = isRoundTrip;
        field.disabled = !isRoundTrip || field.closest('[hidden]') !== null;
      });
    }

    function syncTransferType(){
      const isRoundTrip = type && type.value === 'arrivo_partenza';
      setSectionVisible(singleFields, !isRoundTrip);
      setSectionVisible(roundTripFields, isRoundTrip);
      syncRequiredFields(isRoundTrip);
      syncAllTravelReferences();
    }

    function syncTravelReference(select) {
      if (!select) return;

      const map = {
        place: ['flight_number', 'train_number'],
        arrival_place: ['arrival_flight_number', 'arrival_train_number'],
        departure_place: ['departure_flight_number', 'departure_train_number'],
      };
      const names = map[select.name] || [];
      if (!names.length) return;

      const sectionIsVisible = select.closest('[hidden]') === null;
      const value = (select.value || '').toLowerCase();
      const visible = [value.includes('aeroporto'), value.includes('stazione')];

      names.forEach(function(name, index){
        const field = document.querySelector('[name="' + name + '"]');
        if (!field) return;
        const wrapper = field.closest('.travel-ref');
        const shouldShow = sectionIsVisible && visible[index];
        if (wrapper) wrapper.classList.toggle('d-none', !shouldShow);
        field.disabled = !shouldShow;
        if (!shouldShow) field.value = '';
      });
    }

    function syncAllTravelReferences() {
      document.querySelectorAll('[data-travel-place]').forEach(syncTravelReference);
    }

    function fieldValue(name) {
      if (!form) return '';
      const field = form.elements[name];
      return field ? (field.value || '').trim() : '';
    }

    function selectedText(name) {
      if (!form) return '';
      const field = form.elements[name];
      if (!field || !field.options || field.selectedIndex < 0) return fieldValue(name);
      return field.options[field.selectedIndex].text.trim();
    }

    function typeLabel(value) {
      if (value === 'arrivo') return 'Arrivo';
      if (value === 'partenza') return 'Partenza';
      if (value === 'arrivo_partenza') return 'Arrivo e Partenza';
      return value || '—';
    }

    function formatDateTime(date, time) {
      if (!date && !time) return '—';
      let formattedDate = date || '';
      const parts = date ? date.split('-') : [];
      if (parts.length === 3) formattedDate = parts[2] + '/' + parts[1] + '/' + parts[0];
      return (formattedDate + (time ? ' ' + time : '')).trim();
    }

    function optionalValue(value) {
      return value || '—';
    }

    function addReferenceLines(lines, flightName, trainName, prefix) {
      const flight = fieldValue(flightName);
      const train = fieldValue(trainName);
      if (flight) lines.push((prefix || '') + 'Numero volo: ' + flight);
      if (train) lines.push((prefix || '') + 'Numero treno: ' + train);
    }

    function buildTransferDetails() {
      const currentType = fieldValue('type');
      const lines = [
        'Tipo: ' + typeLabel(currentType),
        'Camera: ' + optionalValue(fieldValue('room_number')),
        'Nominativo: ' + optionalValue(fieldValue('guest_name')),
        'Numero persone: ' + optionalValue(fieldValue('people_count')),
        'Prezzo: € ' + optionalValue(fieldValue('price_eur')),
      ];

      if (currentType === 'arrivo_partenza') {
        lines.push('', 'Arrivo:');
        lines.push('- Luogo: ' + optionalValue(selectedText('arrival_place')));
        lines.push('- Data/Ora: ' + formatDateTime(fieldValue('arrival_date'), fieldValue('arrival_time')));
        lines.push('- Pickup: ' + optionalValue(fieldValue('arrival_pickup_time')));
        addReferenceLines(lines, 'arrival_flight_number', 'arrival_train_number', '- ');
        lines.push('', 'Partenza:');
        lines.push('- Luogo: ' + optionalValue(selectedText('departure_place')));
        lines.push('- Data/Ora: ' + formatDateTime(fieldValue('departure_date'), fieldValue('departure_time')));
        lines.push('- Pickup: ' + optionalValue(fieldValue('departure_pickup_time')));
        addReferenceLines(lines, 'departure_flight_number', 'departure_train_number', '- ');
      } else {
        lines.push('Luogo: ' + optionalValue(selectedText('place')));
        lines.push('Data/Ora: ' + formatDateTime(fieldValue('date'), fieldValue('time')));
        lines.push('Pickup: ' + optionalValue(fieldValue('pickup_time')));
        addReferenceLines(lines, 'flight_number', 'train_number', '');
      }

      return lines.join('\n');
    }

    function buildEmailBody() {
      const supplier = optionalValue(fieldValue('supplier_name'));
      return 'Gentile ' + supplier + ', \n' +
        'se possibile vorremmo prenotare un transfer con i seguenti dettagli: ' + buildTransferDetails() +
        '\n\n\nRestiamo in attesa di conferma \nGrazie';
    }

    function submitWithEmailChoice(sendEmail) {
      if (!form || !sendEmailInput) return;
      sendEmailInput.value = sendEmail ? '1' : '0';
      confirmedSubmit = true;
      if (window.bootstrap && emailModalElement) {
        const modal = window.bootstrap.Modal.getInstance(emailModalElement);
        if (modal) modal.hide();
      }
      form.requestSubmit();
    }

    if (booked) booked.addEventListener('change', syncCompanyField);
    if (type) type.addEventListener('change', syncTransferType);
    document.querySelectorAll('[data-travel-place]').forEach(function(select){
      select.addEventListener('change', function(){ syncTravelReference(select); });
    });

    if (form && emailModalElement && emailPreview && sendEmailInput) {
      form.addEventListener('submit', function(event){
        if (confirmedSubmit) return;
        if (!form.checkValidity()) return;
        event.preventDefault();
        emailPreview.textContent = buildEmailBody();
        if (window.bootstrap) {
          window.bootstrap.Modal.getOrCreateInstance(emailModalElement).show();
          return;
        }
        submitWithEmailChoice(window.confirm(buildEmailBody() + '\n\nInviare questa email a simone@villaggiotramonto.it?'));
      });
    }
    if (emailSendButton) {
      emailSendButton.addEventListener('click', function(){ submitWithEmailChoice(true); });
    }
    if (emailSkipButton) {
      emailSkipButton.addEventListener('click', function(){ submitWithEmailChoice(false); });
    }

    syncCompanyField();
    syncTransferType();
  });
})();
