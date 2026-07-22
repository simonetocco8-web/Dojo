(function () {
  function initTramontoDayBookingCalculator() {
    const form = document.getElementById('tramontodayBookingForm');
    if (!form) return;

    const totalInput = document.getElementById('total_amount');
    const finalInput = document.getElementById('final_amount');
    const formulaInput = document.getElementById('formula');
    const money = new Intl.NumberFormat('it-IT', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    const readNumber = function (id) {
      const input = document.getElementById(id);
      const value = input ? Number(String(input.value || '0').replace(',', '.')) : 0;
      return Number.isFinite(value) && value > 0 ? value : 0;
    };

    const readPrice = function (name, fallback) {
      const value = Number(String(form.dataset[name] || '').replace(',', '.'));
      return Number.isFinite(value) ? value : fallback;
    };

    const calculate = function () {
      const formula = formulaInput ? formulaInput.value : 'giornata_intera';
      const adultPrice = formula === 'giornata_intera' ? readPrice('adultFull', 0) : readPrice('adultHalf', 0);
      const childPrice = formula === 'giornata_intera' ? readPrice('childFull', 0) : readPrice('childHalf', 0);
      const sunbedPrice = readPrice('sunbed', 10);

      const total = (readNumber('adults_count') * adultPrice)
        + (readNumber('children_count') * childPrice)
        + (readNumber('infants_count') * 0)
        + (readNumber('extra_sunbeds_count') * sunbedPrice);
      const discount = Math.min(readNumber('discount_percent'), 100);
      const finalAmount = Math.max(0, total - (total * discount / 100));

      if (totalInput) totalInput.value = money.format(total);
      if (finalInput) finalInput.value = money.format(finalAmount);
    };

    form.addEventListener('input', calculate);
    form.addEventListener('change', calculate);
    calculate();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initTramontoDayBookingCalculator);
  } else {
    initTramontoDayBookingCalculator();
  }
})();
