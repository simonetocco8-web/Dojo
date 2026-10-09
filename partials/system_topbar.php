<?php if ($user): ?>
<header class="dojo-topbar d-flex align-items-center justify-content-between gap-3 px-3 py-2 bg-white border-bottom">
  <span class="fw-semibold">Dojo <span class="text-muted fw-normal d-none d-sm-inline">· <?= e($user['email']) ?></span></span>
  <div class="dropdown" id="systemAlerts" data-alerts-url="<?= e($base) ?>/user_alerts.php" data-base-url="<?= e($base) ?>">
    <button class="btn btn-outline-secondary position-relative" type="button" id="systemAlertsButton" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Alert di sistema">
      <i class="bi bi-bell" aria-hidden="true"></i>
      <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger d-none" id="systemAlertsCount"></span>
    </button>
    <div class="dropdown-menu dropdown-menu-end dojo-alerts-menu p-0" aria-labelledby="systemAlertsButton">
      <div class="fw-semibold px-3 py-2 border-bottom">Alert per te</div>
      <div id="systemAlertsList" class="dojo-alerts-list"><p class="text-muted small m-0 px-3 py-3">Caricamento degli alert…</p></div>
      <div id="systemAlertsStatus" class="small text-muted px-3 py-2 border-top" role="status" aria-live="polite"></div>
    </div>
  </div>
</header>
<script src="<?= e($base) ?>/assets/system-alerts.js?v=<?= (int)(@filemtime(__DIR__ . '/../assets/system-alerts.js') ?: time()) ?>" defer></script>
<?php endif; ?>
