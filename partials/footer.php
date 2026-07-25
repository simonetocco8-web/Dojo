    </main>
    <footer class="text-center py-4 text-muted small">© <?= date('Y') ?> Dojo Platform - Sunset srl - Powered by Simone Tocco</footer>
  </div>
</div>

<div class="modal fade" id="deleteConfirmModal" tabindex="-1" aria-labelledby="deleteConfirmModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="deleteConfirmModalLabel">Conferma operazione</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
      </div>
      <div class="modal-body" id="deleteConfirmModalMessage">Confermi di voler procedere?</div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annulla</button>
        <button type="button" class="btn btn-danger" id="deleteConfirmModalButton">Conferma</button>
      </div>
    </div>
  </div>
</div>

<div class="position-fixed top-0 start-0 w-100 h-100 bg-dark bg-opacity-50 d-none" id="waitFeedbackOverlay" style="z-index: 2000;" aria-hidden="true">
  <div class="d-flex h-100 align-items-center justify-content-center p-3">
    <div class="card shadow text-center" role="status" aria-live="polite">
      <div class="card-body p-4">
        <div class="spinner-border text-primary mb-3" aria-hidden="true"></div>
        <div class="fw-semibold" id="waitFeedbackOverlayText">Operazione in corso, attendere...</div>
        <div class="text-muted small mt-2">Non chiudere o ricaricare la pagina.</div>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php
$confirmDeleteVersion = @filemtime(__DIR__ . '/../assets/confirm-delete.js') ?: time();
$waitFeedbackVersion = @filemtime(__DIR__ . '/../assets/wait-feedback.js') ?: time();
$menuDedupeVersion = @filemtime(__DIR__ . '/../assets/menu-dedupe.js') ?: time();
$footerBase = $base ?? '';
?>
<script src="<?= e($footerBase) ?>/assets/confirm-delete.js?v=<?= (int)$confirmDeleteVersion ?>"></script>
<script src="<?= e($footerBase) ?>/assets/wait-feedback.js?v=<?= (int)$waitFeedbackVersion ?>"></script>
<script src="<?= e($footerBase) ?>/assets/menu-dedupe.js?v=<?= (int)$menuDedupeVersion ?>"></script>
</body>
</html>
