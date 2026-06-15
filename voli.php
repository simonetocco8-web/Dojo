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

$title = 'Voli da Lamezia Terme';
include __DIR__ . '/partials/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h5 mb-0">Voli da Lamezia Terme</h1>
</div>

<div class="card shadow-sm">
  <div class="card-body">
    <div class="ratio ratio-16x9">
      <iframe
        src="https://www.flightera.net/it/widgets/airport?iata=SUF&amp;depArr=arr&amp;nrFlights=20&amp;airlineIata=&amp;columns=arrivaltime%2Cterminalgate"
        title="Voli da Lamezia Terme"
        loading="lazy"
        referrerpolicy="no-referrer-when-downgrade"
        style="border:0;"
        allowfullscreen></iframe>
    </div>
  </div>
</div>

<?php include __DIR__ . '/partials/footer.php'; ?>
