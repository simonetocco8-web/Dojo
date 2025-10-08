
(function(){
  const booked = document.getElementById('booked');
  const wrap = document.getElementById('companyWrap');
  const input = document.getElementById('service_company');

  function syncCompanyField(){
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

  booked.addEventListener('change', syncCompanyField);
  syncCompanyField();
})();
