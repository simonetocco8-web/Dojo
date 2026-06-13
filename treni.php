<?php
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/security.php';
require_once __DIR__ . '/core/roles.php';

start_session();
$env = require __DIR__ . '/config/env.php';
$base = rtrim($env['app']['base_url'] ?? '', '/');
$user = current_user();
if (!$user) { header('Location: ' . $base . '/index.php?msg=auth'); exit; }
if (!user_is_reception_or_amministrazione($user)) {
  http_response_code(403);
  exit('Permesso negato.');
}

$title = 'Treni da Ricadi Capo Vaticano';
include __DIR__ . '/partials/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h5 mb-0">Treni da Ricadi Capo Vaticano</h1>
</div>

<div class="card shadow-sm">
  <div class="card-body">
    <div class="ratio ratio-16x9">
      <iframe
        src="https://iechub.rfi.it/ArriviPartenze/ArrivalsDepartures/Monitor?placeId=129&amp;arrivals=False"
        title="Treni da Ricadi Capo Vaticano"
        loading="lazy"
        referrerpolicy="no-referrer-when-downgrade"
        style="border:0;"
        allowfullscreen></iframe>
    </div>
  </div>
</div>

<?php include __DIR__ . '/partials/footer.php'; ?>
