document.addEventListener('DOMContentLoaded', () => {
  const allCleanButton = document.getElementById('haccpAllClean');
  if (!allCleanButton) return;

  allCleanButton.addEventListener('click', () => {
    document.querySelectorAll('.haccp-clean-yes').forEach((input) => {
      input.checked = true;
    });
  });
});
