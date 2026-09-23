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
    const childrenCount = document.getElementById('children_count');
    const childSeatOptions = document.getElementById('childSeatOptions');
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

    function syncChildSeatOptions() {
      if (!childrenCount || !childSeatOptions) return;
      const previous = {};
      childSeatOptions.querySelectorAll('[data-child-index]').forEach(function(row){
        const index = row.dataset.childIndex;
        previous[index] = { checked: row.querySelector('[type="checkbox"]').checked, weight: row.querySelector('[type="number"]').value };
      });
      if (!Object.keys(previous).length) {
        const selected = JSON.parse(childSeatOptions.dataset.selected || '[]').map(String);
        const weights = JSON.parse(childSeatOptions.dataset.weights || '{}');
        selected.forEach(function(index){ previous[index] = { checked: true, weight: weights[index] || '' }; });
      }
      const count = Math.max(0, Math.min(20, parseInt(childrenCount.value || '0', 10) || 0));
      childSeatOptions.replaceChildren();
      for (let index = 0; index < count; index += 1) {
        const row = document.createElement('div');
        row.className = 'col-12 col-lg-6';
        row.dataset.childIndex = String(index);
        row.innerHTML = '<div class="border rounded-3 p-3"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="child_seat[' + index + ']" value="1" id="childSeat' + index + '"><label class="form-check-label fw-semibold" for="childSeat' + index + '">Bambino ' + (index + 1) + ': Seggiolino</label></div><div class="mt-2 d-none" data-weight-wrap><label class="form-label small" for="childWeight' + index + '">Peso del bambino (kg)</label><input class="form-control" type="number" name="child_weight[' + index + ']" id="childWeight' + index + '" min="0.1" max="100" step="0.1"></div></div>';
        const checkbox = row.querySelector('[type="checkbox"]');
        const weight = row.querySelector('[type="number"]');
        const weightWrap = row.querySelector('[data-weight-wrap]');
        const state = previous[String(index)] || { checked: false, weight: '' };
        checkbox.checked = state.checked;
        weight.value = state.weight;
        const syncWeight = function(){ weightWrap.classList.toggle('d-none', !checkbox.checked); weight.disabled = !checkbox.checked; weight.required = checkbox.checked; };
        checkbox.addEventListener('change', syncWeight);
        syncWeight();
        childSeatOptions.appendChild(row);
      }
    }

    function buildTransferDetails() {
      const currentType = fieldValue('type');
      const lines = [
        'Tipo: ' + typeLabel(currentType),
        'Camera: ' + optionalValue(fieldValue('room_number')),
        'Nominativo: ' + optionalValue(fieldValue('guest_name')),
        'Numero adulti: ' + optionalValue(fieldValue('adults_count')),
        'Numero bambini: ' + optionalValue(fieldValue('children_count')),
      ];
      childSeatOptions?.querySelectorAll('[data-child-index]').forEach(function(row){
        const checkbox = row.querySelector('[type="checkbox"]');
        const weight = row.querySelector('[type="number"]');
        if (checkbox.checked) lines.push('Bambino ' + (Number(row.dataset.childIndex) + 1) + ': seggiolino richiesto, peso ' + optionalValue(weight.value) + ' kg');
      });

      if (currentType === 'arrivo_partenza') {
        lines.push('', 'Arrivo:');
        lines.push('- Luogo: ' + optionalValue(selectedText('arrival_place')));
        lines.push('- Data/Ora: ' + formatDateTime(fieldValue('arrival_date'), fieldValue('arrival_time')));
        lines.push('- Pickup: ' + optionalValue(fieldValue('arrival_pickup_time')));
        if (fieldValue('arrival_travel_reference')) lines.push('- Numero volo o treno: ' + fieldValue('arrival_travel_reference'));
        lines.push('', 'Partenza:');
        lines.push('- Luogo: ' + optionalValue(selectedText('departure_place')));
        lines.push('- Data/Ora: ' + formatDateTime(fieldValue('departure_date'), fieldValue('departure_time')));
        lines.push('- Pickup: ' + optionalValue(fieldValue('departure_pickup_time')));
        if (fieldValue('departure_travel_reference')) lines.push('- Numero volo o treno: ' + fieldValue('departure_travel_reference'));
      } else {
        lines.push('Luogo: ' + optionalValue(selectedText('place')));
        lines.push('Data/Ora: ' + formatDateTime(fieldValue('date'), fieldValue('time')));
        lines.push('Pickup: ' + optionalValue(fieldValue('pickup_time')));
        if (fieldValue('travel_reference')) lines.push('Numero volo o treno: ' + fieldValue('travel_reference'));
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
    if (childrenCount) childrenCount.addEventListener('input', syncChildSeatOptions);
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
        submitWithEmailChoice(window.confirm(buildEmailBody() + '\n\nInviare questa email a daniexpress.viaggi@gmail.com?'));
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
    syncChildSeatOptions();
  });
})();
