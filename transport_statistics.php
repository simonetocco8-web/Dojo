<?php
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/security.php';
require_once __DIR__ . '/core/roles.php';
require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/settings.php';

start_session();
$env = require __DIR__ . '/config/env.php';
$base = rtrim($env['app']['base_url'] ?? '', '/');
$user = current_user();
if (!$user) { header('Location: ' . $base . '/index.php?msg=auth'); exit; }
if (!user_is_reception_or_amministrazione($user)) { http_response_code(403); echo 'Accesso negato'; exit; }

$pdo = db();
ensure_transfer_internal_details_columns($pdo);
ensure_transfer_locations_table($pdo);
ensure_transfer_external_travel_columns($pdo);
$range = get_summer_season_range($pdo);
$tz = new DateTimeZone('Europe/Rome');
$today = new DateTimeImmutable('today', $tz);
$start = DateTimeImmutable::createFromFormat('!Y-m-d', (string)($range['start'] ?? ''), $tz) ?: $today->setDate((int)$today->format('Y'), 1, 1);
$end = DateTimeImmutable::createFromFormat('!Y-m-d', (string)($range['end'] ?? ''), $tz) ?: $today->setDate((int)$today->format('Y'), 12, 31);
if ($start > $end) [$start, $end] = [$end, $start];
$dateFrom = $start->format('Y-m-d');
$dateTo = $end->format('Y-m-d');

$dates = [];
for ($date = $start; $date <= $end; $date = $date->modify('+1 day')) $dates[$date->format('Y-m-d')] = 0;

$internalDaily = $dates;
$internalStmt = $pdo->prepare("SELECT DATE(when_at) AS transfer_day, COUNT(*) AS total FROM transfers_internal WHERE deleted_at IS NULL AND when_at >= ? AND when_at < DATE_ADD(?, INTERVAL 1 DAY) AND when_at <= NOW() GROUP BY DATE(when_at)");
$internalStmt->execute([$dateFrom, $dateTo]);
foreach ($internalStmt->fetchAll() as $row) if (isset($internalDaily[$row['transfer_day']])) $internalDaily[$row['transfer_day']] = (int)$row['total'];
$internalTotal = array_sum($internalDaily);

$internalKmStmt = $pdo->prepare("SELECT COALESCE(SUM(tl.distance_km * 2), 0) AS total_km FROM transfers_internal ti LEFT JOIN transfer_locations tl ON tl.name = ti.location WHERE ti.deleted_at IS NULL AND ti.when_at >= ? AND ti.when_at < DATE_ADD(?, INTERVAL 1 DAY) AND ti.when_at <= NOW()");
$internalKmStmt->execute([$dateFrom, $dateTo]);
$internalTotalKm = (float)$internalKmStmt->fetchColumn();

$topLocationsStmt = $pdo->prepare("SELECT COALESCE(NULLIF(TRIM(location), ''), 'Non specificata') AS location_name, COUNT(*) AS total FROM transfers_internal WHERE deleted_at IS NULL AND when_at >= ? AND when_at < DATE_ADD(?, INTERVAL 1 DAY) AND when_at <= NOW() GROUP BY COALESCE(NULLIF(TRIM(location), ''), 'Non specificata') ORDER BY total DESC, location_name ASC LIMIT 3");
$topLocationsStmt->execute([$dateFrom, $dateTo]);
$topLocations = $topLocationsStmt->fetchAll();

$externalDateSql = 'COALESCE(date_time, arrival_date_time, departure_date_time)';
$externalDaily = $dates;
$externalStmt = $pdo->prepare("SELECT DATE($externalDateSql) AS transfer_day, COUNT(*) AS total FROM transfers_external WHERE deleted_at IS NULL AND status <> 'annullato' AND $externalDateSql >= ? AND $externalDateSql < DATE_ADD(?, INTERVAL 1 DAY) AND $externalDateSql <= NOW() GROUP BY DATE($externalDateSql)");
$externalStmt->execute([$dateFrom, $dateTo]);
foreach ($externalStmt->fetchAll() as $row) if (isset($externalDaily[$row['transfer_day']])) $externalDaily[$row['transfer_day']] = (int)$row['total'];

$externalSummaryStmt = $pdo->prepare("SELECT COUNT(*) AS total, COALESCE(SUM(price_eur), 0) AS customer_total, COALESCE(SUM(supplier_price_eur), 0) AS supplier_total FROM transfers_external WHERE deleted_at IS NULL AND status <> 'annullato' AND $externalDateSql >= ? AND $externalDateSql < DATE_ADD(?, INTERVAL 1 DAY) AND $externalDateSql <= NOW()");
$externalSummaryStmt->execute([$dateFrom, $dateTo]);
$externalSummary = $externalSummaryStmt->fetch() ?: ['total' => 0, 'customer_total' => 0, 'supplier_total' => 0];

$chartLabels = array_map(static fn(string $date): string => (new DateTimeImmutable($date))->format('d/m'), array_keys($dates));
$money = static fn($value): string => number_format((float)$value, 2, ',', '.');
$title = 'Statistiche Trasporti';
include __DIR__ . '/partials/header.php';
?>
<div class="container-fluid">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div><div class="text-muted small text-uppercase fw-semibold">Trasporti</div><h1 class="h3 mb-1">Statistiche</h1><p class="text-muted mb-0">Dal <?= e($start->format('d/m/Y')) ?> al <?= e($end->format('d/m/Y')) ?> · stagione configurata</p></div>
    <a class="btn btn-outline-primary" href="<?= e($base) ?>/transfere.php"><i class="bi bi-car-front me-1"></i>Vai ai transfer</a>
  </div>

  <section class="mb-5" aria-labelledby="internalStatisticsTitle">
    <h2 class="h4 mb-3" id="internalStatisticsTitle">Transfer interni</h2>
    <div class="row g-3 mb-3">
      <div class="col-12 col-md-6"><div class="card shadow-sm h-100"><div class="card-body"><div class="text-muted small">Transfer eseguiti</div><div class="display-6 fw-semibold"><?= $internalTotal ?></div></div></div></div>
      <div class="col-12 col-md-6"><div class="card shadow-sm h-100"><div class="card-body"><div class="text-muted small">Km complessivi percorsi</div><div class="display-6 fw-semibold text-success"><?= e(number_format($internalTotalKm, 2, ',', '.')) ?> km</div><div class="small text-muted">Andata e ritorno: distanza località × 2 per transfer</div></div></div></div>
    </div>
    <div class="row g-3 mb-3">
      <?php for ($rank = 0; $rank < 3; $rank++): $location = $topLocations[$rank] ?? null; ?>
        <div class="col-12 col-md-4"><div class="card shadow-sm h-100"><div class="card-body"><div class="text-muted small">Top <?= $rank + 1 ?> località</div><div class="h5 mb-1"><?= e($location['location_name'] ?? '—') ?></div><div class="text-primary fw-semibold"><?= (int)($location['total'] ?? 0) ?> transfer</div></div></div></div>
      <?php endfor; ?>
    </div>
    <div class="card shadow-sm"><div class="card-body"><h3 class="h6">Andamento giornaliero</h3><div class="transport-chart-wrap"><canvas data-transport-chart data-color="#0d6efd" data-labels="<?= e(json_encode($chartLabels)) ?>" data-values="<?= e(json_encode(array_values($internalDaily))) ?>"></canvas></div></div></div>
  </section>

  <section aria-labelledby="externalStatisticsTitle">
    <h2 class="h4 mb-3" id="externalStatisticsTitle">Transfer esterni</h2>
    <div class="row g-3 mb-3">
      <div class="col-12 col-md-4"><div class="card shadow-sm h-100"><div class="card-body"><div class="text-muted small">Transfer eseguiti</div><div class="display-6 fw-semibold"><?= (int)$externalSummary['total'] ?></div></div></div></div>
      <div class="col-12 col-md-4"><div class="card shadow-sm h-100"><div class="card-body"><div class="text-muted small">Totale prezzo cliente</div><div class="display-6 fw-semibold text-success">€ <?= e($money($externalSummary['customer_total'])) ?></div></div></div></div>
      <div class="col-12 col-md-4"><div class="card shadow-sm h-100"><div class="card-body"><div class="text-muted small">Totale prezzo fornitore</div><div class="display-6 fw-semibold text-danger">€ <?= e($money($externalSummary['supplier_total'])) ?></div></div></div></div>
    </div>
    <div class="card shadow-sm"><div class="card-body"><h3 class="h6">Andamento giornaliero</h3><div class="transport-chart-wrap"><canvas data-transport-chart data-color="#198754" data-labels="<?= e(json_encode($chartLabels)) ?>" data-values="<?= e(json_encode(array_values($externalDaily))) ?>"></canvas></div></div></div>
  </section>
</div>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
<?php $pageScripts = ['assets/transport-statistics.js']; include __DIR__ . '/partials/footer.php'; ?>
