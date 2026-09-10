<?php
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/security.php';
require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/roles.php';
require_once __DIR__ . '/core/settings.php';
start_session();

$env = require __DIR__ . '/config/env.php';
$base = rtrim($env['app']['base_url'] ?? '', '/');
$user = current_user();
if (!$user) {
  header('Location: ' . $base . '/index.php?msg=auth');
  exit;
}

$canView = user_is_reception_or_amministrazione($user) || user_is_housekeeping($user);
if (!$canView) {
  http_response_code(403);
  echo '<h1>403</h1><p>Accesso non autorizzato.</p>';
  exit;
}

$pdo = db();
$timezone = new DateTimeZone('Europe/Rome');
$year = (int)(new DateTimeImmutable('now', $timezone))->format('Y');
$dateFrom = sprintf('%d-01-01', $year);
$dateTo = sprintf('%d-01-01', $year + 1);

$stmt = $pdo->prepare(
  'SELECT COALESCE(SUM(qty_matrimoniale), 0) AS matrimoniale,
          COALESCE(SUM(qty_singola), 0) AS singola,
          COALESCE(SUM(qty_set_bagno), 0) AS set_bagno
   FROM riassetti
   WHERE data_riassetto >= ? AND data_riassetto < ?'
);
$stmt->execute([$dateFrom, $dateTo]);
$totals = $stmt->fetch() ?: [];
$quantities = [
  'matrimoniale' => (int)($totals['matrimoniale'] ?? 0),
  'singola' => (int)($totals['singola'] ?? 0),
  'set_bagno' => (int)($totals['set_bagno'] ?? 0),
];
$costs = get_riassetti_linen_costs($pdo);
$subtotals = [];
foreach ($quantities as $key => $quantity) {
  $subtotals[$key] = $quantity * $costs[$key];
}
$seasonCost = array_sum($subtotals);

function format_currency_it(float $amount): string {
  return '€ ' . number_format($amount, 2, ',', '.');
}

$title = 'Statistiche riassetti';
include __DIR__ . '/partials/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <div>
    <h1 class="h4 mb-1">Statistiche riassetti</h1>
    <p class="text-muted mb-0">Consumi e costi della stagione <?= $year ?>.</p>
  </div>
  <a class="btn btn-sm btn-outline-secondary" href="<?= e($base) ?>/riassetti.php"><i class="bi bi-arrow-left me-1"></i>Torna ai riassetti</a>
</div>

<div class="row g-3 mb-3">
  <?php
  $cards = [
    'matrimoniale' => ['Biancheria matrimoniale', 'bi-arrows-angle-expand'],
    'singola' => ['Biancheria singola', 'bi-person'],
    'set_bagno' => ['Set da bagno', 'bi-droplet'],
  ];
  foreach ($cards as $key => [$label, $icon]):
  ?>
    <div class="col-12 col-md-4">
      <div class="card shadow-sm h-100">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-start">
            <div>
              <div class="text-muted small mb-1"><?= e($label) ?></div>
              <div class="display-6 fw-semibold"><?= number_format($quantities[$key], 0, ',', '.') ?></div>
            </div>
            <i class="bi <?= e($icon) ?> fs-3 text-primary"></i>
          </div>
          <hr>
          <div class="d-flex justify-content-between small">
            <span><?= e(format_currency_it($costs[$key])) ?> cad.</span>
            <strong><?= e(format_currency_it($subtotals[$key])) ?></strong>
          </div>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<div class="card text-bg-primary shadow-sm">
  <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-3">
    <div>
      <h2 class="h5 mb-1">Costo totale stagione <?= $year ?></h2>
      <div class="opacity-75 small">Calcolato sui riassetti registrati dal 1° gennaio al 31 dicembre.</div>
    </div>
    <div class="display-6 fw-semibold"><?= e(format_currency_it($seasonCost)) ?></div>
  </div>
</div>
<?php include __DIR__ . '/partials/footer.php'; ?>
