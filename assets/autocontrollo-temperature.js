document.addEventListener('DOMContentLoaded', () => {
  const allCompliantButton = document.getElementById('temperatureAllCompliant');
  if (!allCompliantButton) return;

  allCompliantButton.addEventListener('click', () => {
    document.querySelectorAll('.temperature-compliance-yes').forEach((input) => {
      input.checked = true;
    });
  });
});
