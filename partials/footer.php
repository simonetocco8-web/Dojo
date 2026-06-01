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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php
$confirmDeleteVersion = @filemtime(__DIR__ . '/../assets/confirm-delete.js') ?: time();
$footerBase = $base ?? '';
?>
<script src="<?= e($footerBase) ?>/assets/confirm-delete.js?v=<?= (int)$confirmDeleteVersion ?>"></script>
</body>
</html>
