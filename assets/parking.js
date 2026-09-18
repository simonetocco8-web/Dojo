(() => {
  const modalElement = document.getElementById('parkingSpaceModal');
  if (!modalElement) return;
  const bootstrapModal = typeof window.bootstrap !== 'undefined' && window.bootstrap.Modal
    ? window.bootstrap.Modal.getOrCreateInstance(modalElement)
    : null;
  let fallbackBackdrop = null;
  const modal = {
    show() {
      if (bootstrapModal) { bootstrapModal.show(); return; }
      modalElement.classList.add('show', 'parking-modal-fallback');
      modalElement.removeAttribute('aria-hidden');
      modalElement.setAttribute('aria-modal', 'true');
      document.body.classList.add('modal-open');
      fallbackBackdrop = document.createElement('div');
      fallbackBackdrop.className = 'modal-backdrop fade show';
      document.body.appendChild(fallbackBackdrop);
      modalElement.querySelector('.btn-close')?.focus();
    },
    hide() {
      if (bootstrapModal) { bootstrapModal.hide(); return; }
      modalElement.classList.remove('show', 'parking-modal-fallback');
      modalElement.setAttribute('aria-hidden', 'true');
      modalElement.removeAttribute('aria-modal');
      document.body.classList.remove('modal-open');
      fallbackBackdrop?.remove();
      fallbackBackdrop = null;
    }
  };
  const fields = {
    id: document.getElementById('parkingSpaceId'), status: document.getElementById('parkingStatus'),
    size: document.getElementById('parkingSize'), covered: document.getElementById('parkingCovered'),
    type: document.getElementById('parkingAssignmentType'), detail: document.getElementById('parkingAssignmentDetail'),
    wrap: document.getElementById('parkingAssignmentDetailWrap'), label: document.getElementById('parkingAssignmentDetailLabel'),
    help: document.getElementById('parkingAssignmentHelp'), title: document.getElementById('parkingSpaceModalTitle')
  };

  const updateDetail = () => {
    const settings = {
      appartamento: ['Numero appartamento', 'Es. 12', 'number'], personale: ['Nome o riferimento', 'Indica il membro del personale', 'text'],
      altro: ['Dettaglio assegnazione', 'Specifica il destinatario', 'text']
    };
    const setting = settings[fields.type.value];
    fields.wrap.hidden = !setting;
    fields.detail.required = Boolean(setting);
    if (setting) { fields.label.textContent = setting[0]; fields.help.textContent = setting[1]; fields.detail.type = setting[2]; }
  };
  fields.type.addEventListener('change', updateDetail);
  modalElement.querySelectorAll('[data-bs-dismiss="modal"]').forEach((button) => button.addEventListener('click', () => modal.hide()));
  modalElement.addEventListener('click', (event) => { if (event.target === modalElement) modal.hide(); });
  document.addEventListener('keydown', (event) => { if (event.key === 'Escape' && modalElement.classList.contains('show')) modal.hide(); });

  document.querySelectorAll('.parking-space').forEach((space) => {
    const open = () => {
      const data = JSON.parse(space.dataset.space);
      fields.id.value = data.space_id; fields.title.textContent = `Posto ${data.space_id}`;
      fields.status.value = data.status; fields.size.value = data.size; fields.covered.checked = data.is_covered === 1 || data.is_covered === '1';
      fields.type.value = data.assignment_type; fields.detail.value = data.assignment_detail || '';
      updateDetail(); modal.show();
    };
    space.addEventListener('click', open);
    space.addEventListener('keydown', (event) => { if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); open(); } });
  });
})();
