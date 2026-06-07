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
    const singleFields = document.getElementById('singleTransferFields');
    const roundTripFields = document.getElementById('roundTripTransferFields');

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

    if (booked) booked.addEventListener('change', syncCompanyField);
    if (type) type.addEventListener('change', syncTransferType);
    document.querySelectorAll('[data-travel-place]').forEach(function(select){
      select.addEventListener('change', function(){ syncTravelReference(select); });
    });

    syncCompanyField();
    syncTransferType();
  });
})();
