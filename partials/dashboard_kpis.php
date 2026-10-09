<section class="mb-4" aria-label="Indicatori operativi">
  <div class="row g-3">
    <?php foreach ($dashboardKpis as $kpi): ?>
    <div class="col-6 col-md-4 col-xl-2">
      <a class="card dashboard-kpi h-100 text-decoration-none text-body shadow-sm" href="<?= e($base . $kpi['url']) ?>" aria-label="<?= e($kpi['label'] . ': ' . $kpi['value'] . '. Apri modulo') ?>">
        <div class="card-body"><div class="d-flex align-items-center gap-2 text-muted small mb-2"><i class="bi bi-<?= e($kpi['icon']) ?>" aria-hidden="true"></i><span><?= e($kpi['label']) ?></span></div><div class="fs-2 fw-semibold lh-1 mb-2"><?= (int)$kpi['value'] ?></div><div class="small text-muted"><?= e($kpi['detail']) ?></div></div>
      </a>
    </div>
    <?php endforeach; ?>
  </div>
</section>
