<?php
$selectedResponsible = (string)($responsibleSettings['autocontrollo_responsible_' . $responsibilityProcedure] ?? '');
$responsibleAvailable = $selectedResponsible === '' || in_array($selectedResponsible, array_map('strval', array_column($activeUsers, 'id')), true);
?>
<form method="post" class="row g-2 align-items-end mb-4">
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
  <input type="hidden" name="action" value="responsible_update">
  <input type="hidden" name="procedure" value="<?= e($responsibilityProcedure) ?>">
  <div class="col-12 col-md">
    <label for="responsible-<?= e($responsibilityProcedure) ?>" class="form-label">Responsabile della procedura</label>
    <select class="form-select" name="responsible_user_id" id="responsible-<?= e($responsibilityProcedure) ?>">
      <option value="">Nessun responsabile</option>
      <?php if (!$responsibleAvailable): ?><option value="" selected disabled>Responsabile precedente non più attivo: selezionare un utente</option><?php endif; ?>
      <?php foreach ($activeUsers as $responsibleUser): ?>
        <?php $responsibleName = trim((string)($responsibleUser['cognome'] ?? '') . ' ' . (string)($responsibleUser['nome'] ?? '')); ?>
        <option value="<?= (int)$responsibleUser['id'] ?>" <?= $selectedResponsible === (string)$responsibleUser['id'] ? 'selected' : '' ?>><?= e($responsibleName !== '' ? $responsibleName . ' — ' . $responsibleUser['email'] : $responsibleUser['email']) ?></option>
      <?php endforeach; ?>
    </select>
    <?php if (!$activeUsers): ?><div class="form-text">Non sono disponibili utenti attivi.</div><?php endif; ?>
  </div>
  <div class="col-12 col-md-auto"><button class="btn btn-outline-primary"><i class="bi bi-save me-1"></i>Salva responsabile</button></div>
</form>
