<section class="col-12 col-lg-6" id="vehicles" style="scroll-margin-top:1rem">
<div class="card shadow-sm h-100"><div class="card-body">
<h2 class="h5 mb-3"><i class="bi bi-truck me-1"></i>Veicoli</h2>
<p class="text-muted small">Marca e modello sono obbligatori. Lascia la targa vuota per un veicolo non targato.</p>
<form method="post" class="row g-2 align-items-end mb-4">
<input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="vehicle_create">
<div class="col-12 col-md-4"><label class="form-label" for="vehicleBrand">Marca</label><input class="form-control" id="vehicleBrand" name="vehicle_brand" maxlength="190" required></div>
<div class="col-12 col-md-4"><label class="form-label" for="vehicleModel">Modello</label><input class="form-control" id="vehicleModel" name="vehicle_model" maxlength="190" required></div>
<div class="col-12 col-md-4"><label class="form-label" for="vehiclePlate">Targa (facoltativa)</label><input class="form-control" id="vehiclePlate" name="vehicle_plate" maxlength="32"></div>
<div class="col-12"><button class="btn btn-primary"><i class="bi bi-plus-circle me-1"></i>Aggiungi veicolo</button></div>
</form>
<?php if (!$vehicles): ?><p class="text-muted mb-0">Nessun veicolo configurato.</p><?php endif; ?>
<?php foreach ($vehicles as $vehicle): ?>
<form method="post" class="border-top pt-3 mt-3 row g-2 align-items-end">
<input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int)$vehicle['id'] ?>">
<div class="col-12 col-md-4"><label class="form-label" for="vehicleBrand<?= (int)$vehicle['id'] ?>">Marca</label><input class="form-control" id="vehicleBrand<?= (int)$vehicle['id'] ?>" name="vehicle_brand" maxlength="190" value="<?= e($vehicle['brand']) ?>" required></div>
<div class="col-12 col-md-4"><label class="form-label" for="vehicleModel<?= (int)$vehicle['id'] ?>">Modello</label><input class="form-control" id="vehicleModel<?= (int)$vehicle['id'] ?>" name="vehicle_model" maxlength="190" value="<?= e($vehicle['model']) ?>" required></div>
<div class="col-12 col-md-4"><label class="form-label" for="vehiclePlate<?= (int)$vehicle['id'] ?>">Targa (facoltativa)</label><input class="form-control" id="vehiclePlate<?= (int)$vehicle['id'] ?>" name="vehicle_plate" maxlength="32" value="<?= e($vehicle['license_plate'] ?? '') ?>" placeholder="Non targato"></div>
<div class="col-12 d-flex gap-2"><button class="btn btn-outline-primary" name="action" value="vehicle_update">Salva</button><button class="btn btn-outline-danger" name="action" value="vehicle_delete" formnovalidate onclick="return confirm('Eliminare questo veicolo?')">Elimina</button></div>
</form>
<?php endforeach; ?>
</div></div>
</section>
