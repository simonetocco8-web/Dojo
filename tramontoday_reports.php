<?php
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/security.php';
require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/roles.php';
require_once __DIR__ . '/core/settings.php';

start_session();
$env  = require __DIR__ . '/config/env.php';
$base = rtrim($env['app']['base_url'] ?? '', '/');
$user = current_user();

if (!$user) {
  header('Location: ' . $base . '/index.php?msg=auth');
  exit;
}

if (!user_is_reception_or_amministrazione($user)) {
  http_response_code(403);
  echo 'Accesso negato';
  exit;
}

$pdo = db();
ensure_tramontoday_bookings_table($pdo);

$extraSunbedPrice = (float)(get_setting('tramontoday_extra_sunbed_price', '10', $pdo) ?? 10);
if ($extraSunbedPrice <= 0) $extraSunbedPrice = 10.00;

$formulaLabels = [
  'giornata_intera' => 'Giornata intera',
  'mattina' => 'Mattina',
  'pomeriggio' => 'Pomeriggio',
];

function tramontoday_report_money($amount): string {
  return '€ ' . number_format((float)$amount, 2, ',', '.');
}

function tramontoday_report_int($value): string {
  return number_format((int)$value, 0, ',', '.');
}

function tramontoday_report_decimal($value): string {
  return number_format((float)$value, 1, ',', '.');
}

$activeStatusSql = "booking_status NOT IN ('annullata', 'no_show')";

$summaryStmt = $pdo->query("SELECT
    COUNT(*) AS active_accesses,
    COALESCE(SUM(adults_count), 0) AS adults,
    COALESCE(SUM(children_count), 0) AS children,
    COALESCE(SUM(infants_count), 0) AS infants,
    COALESCE(SUM(stations_count), 0) AS stations,
    COALESCE(SUM(final_amount), 0) AS total_revenue,
    COALESCE(SUM(extra_sunbeds_count), 0) AS extra_sunbeds
  FROM tramontoday_bookings
  WHERE $activeStatusSql");
$summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$statusStmt = $pdo->query("SELECT
    SUM(CASE WHEN booking_status = 'annullata' THEN 1 ELSE 0 END) AS cancelled,
    SUM(CASE WHEN booking_status = 'no_show' THEN 1 ELSE 0 END) AS no_show
  FROM tramontoday_bookings");
$statusTotals = $statusStmt->fetch(PDO::FETCH_ASSOC) ?: ['cancelled' => 0, 'no_show' => 0];

$byDayStmt = $pdo->query("SELECT booking_date, COUNT(*) AS accesses
  FROM tramontoday_bookings
  WHERE $activeStatusSql
  GROUP BY booking_date
  ORDER BY booking_date DESC
  LIMIT 31");
$accessesByDay = $byDayStmt->fetchAll(PDO::FETCH_ASSOC);

$byMonthStmt = $pdo->query("SELECT DATE_FORMAT(booking_date, '%Y-%m') AS month_key, COUNT(*) AS accesses
  FROM tramontoday_bookings
  WHERE $activeStatusSql
  GROUP BY month_key
  ORDER BY month_key DESC
  LIMIT 12");
$accessesByMonth = $byMonthStmt->fetchAll(PDO::FETCH_ASSOC);

$revenueByFormulaStmt = $pdo->query("SELECT formula, COALESCE(SUM(final_amount), 0) AS revenue
  FROM tramontoday_bookings
  WHERE $activeStatusSql
  GROUP BY formula");
$revenueByFormula = [];
foreach ($revenueByFormulaStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
  $revenueByFormula[$row['formula']] = (float)$row['revenue'];
}

$occupancyStmt = $pdo->query("SELECT booking_date, formula, SUM(stations_count) AS stations
  FROM tramontoday_bookings
  WHERE $activeStatusSql
  GROUP BY booking_date, formula");
$dailyOccupancy = [];
foreach ($occupancyStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
  $date = (string)$row['booking_date'];
  if (!isset($dailyOccupancy[$date])) {
    $dailyOccupancy[$date] = ['morning' => 0, 'afternoon' => 0];
  }
  $stations = (int)$row['stations'];
  if ($row['formula'] === 'giornata_intera' || $row['formula'] === 'mattina') {
    $dailyOccupancy[$date]['morning'] += $stations;
  }
  if ($row['formula'] === 'giornata_intera' || $row['formula'] === 'pomeriggio') {
    $dailyOccupancy[$date]['afternoon'] += $stations;
  }
}
$daysWithOccupancy = count($dailyOccupancy);
$morningTotal = 0;
$afternoonTotal = 0;
foreach ($dailyOccupancy as $totals) {
  $morningTotal += $totals['morning'];
  $afternoonTotal += $totals['afternoon'];
}
$averageMorning = $daysWithOccupancy > 0 ? $morningTotal / $daysWithOccupancy : 0;
$averageAfternoon = $daysWithOccupancy > 0 ? $afternoonTotal / $daysWithOccupancy : 0;
$extraSunbedRevenue = (int)($summary['extra_sunbeds'] ?? 0) * $extraSunbedPrice;

function tramontoday_report_date_it(?string $date): string {
  if (!$date) return '';
  $dt = DateTime::createFromFormat('Y-m-d', $date);
  return $dt ? $dt->format('d/m/Y') : $date;
}

function tramontoday_report_month_it(?string $month): string {
  if (!$month) return '';
  $dt = DateTime::createFromFormat('Y-m', $month);
  return $dt ? $dt->format('m/Y') : $month;
}

$title = 'Report TramontoDay';
include __DIR__ . '/partials/header.php';
?>
<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 mb-3">
  <div>
    <h1 class="h4 mb-1"><i class="bi bi-bar-chart-line me-1"></i>Report TramontoDay</h1>
    <div class="text-muted small">Riepilogo operativo ed economico delle prenotazioni/accessi registrati.</div>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-12 col-md-6 col-xl-3">
    <div class="card shadow-sm h-100">
      <div class="card-body">
        <div class="text-muted small">Accessi totali</div>
        <div class="display-6 fw-semibold"><?= e(tramontoday_report_int($summary['active_accesses'] ?? 0)) ?></div>
      </div>
    </div>
  </div>
  <div class="col-12 col-md-6 col-xl-3">
    <div class="card shadow-sm h-100">
      <div class="card-body">
        <div class="text-muted small">Numero di postazioni vendute</div>
        <div class="display-6 fw-semibold"><?= e(tramontoday_report_int($summary['stations'] ?? 0)) ?></div>
      </div>
    </div>
  </div>
  <div class="col-12 col-md-6 col-xl-3">
    <div class="card shadow-sm h-100">
      <div class="card-body">
        <div class="text-muted small">Ricavi totali</div>
        <div class="display-6 fw-semibold"><?= e(tramontoday_report_money($summary['total_revenue'] ?? 0)) ?></div>
      </div>
    </div>
  </div>
  <div class="col-12 col-md-6 col-xl-3">
    <div class="card shadow-sm h-100">
      <div class="card-body">
        <div class="text-muted small">Ricavi da sdraio aggiuntive</div>
        <div class="display-6 fw-semibold"><?= e(tramontoday_report_money($extraSunbedRevenue)) ?></div>
      </div>
    </div>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-12 col-xl-4">
    <div class="card shadow-sm h-100">
      <div class="card-body">
        <h2 class="h5 mb-3">Adulti, bambini e infant</h2>
        <div class="list-group list-group-flush">
          <div class="list-group-item px-0 d-flex justify-content-between"><span>Adulti</span><strong><?= e(tramontoday_report_int($summary['adults'] ?? 0)) ?></strong></div>
          <div class="list-group-item px-0 d-flex justify-content-between"><span>Bambini</span><strong><?= e(tramontoday_report_int($summary['children'] ?? 0)) ?></strong></div>
          <div class="list-group-item px-0 d-flex justify-content-between"><span>Infant</span><strong><?= e(tramontoday_report_int($summary['infants'] ?? 0)) ?></strong></div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-12 col-xl-4">
    <div class="card shadow-sm h-100">
      <div class="card-body">
        <h2 class="h5 mb-3">Occupazione media</h2>
        <div class="list-group list-group-flush">
          <div class="list-group-item px-0 d-flex justify-content-between"><span>Mattina</span><strong><?= e(tramontoday_report_decimal($averageMorning)) ?> postazioni</strong></div>
          <div class="list-group-item px-0 d-flex justify-content-between"><span>Pomeriggio</span><strong><?= e(tramontoday_report_decimal($averageAfternoon)) ?> postazioni</strong></div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-12 col-xl-4">
    <div class="card shadow-sm h-100">
      <div class="card-body">
        <h2 class="h5 mb-3">Prenotazioni annullate / no-show</h2>
        <div class="list-group list-group-flush">
          <div class="list-group-item px-0 d-flex justify-content-between"><span>Prenotazioni annullate</span><strong><?= e(tramontoday_report_int($statusTotals['cancelled'] ?? 0)) ?></strong></div>
          <div class="list-group-item px-0 d-flex justify-content-between"><span>No-show</span><strong><?= e(tramontoday_report_int($statusTotals['no_show'] ?? 0)) ?></strong></div>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="row g-3">
  <div class="col-12 col-xl-4">
    <div class="card shadow-sm h-100">
      <div class="card-body">
        <h2 class="h5 mb-3">Accessi per giorno</h2>
        <?php if (!$accessesByDay): ?>
          <p class="text-muted mb-0">Nessun dato disponibile.</p>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
              <thead><tr><th>Giorno</th><th class="text-end">Accessi</th></tr></thead>
              <tbody>
                <?php foreach ($accessesByDay as $row): ?>
                  <tr><td><?= e(tramontoday_report_date_it($row['booking_date'])) ?></td><td class="text-end fw-semibold"><?= e(tramontoday_report_int($row['accesses'])) ?></td></tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-12 col-xl-4">
    <div class="card shadow-sm h-100">
      <div class="card-body">
        <h2 class="h5 mb-3">Accessi per mese</h2>
        <?php if (!$accessesByMonth): ?>
          <p class="text-muted mb-0">Nessun dato disponibile.</p>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
              <thead><tr><th>Mese</th><th class="text-end">Accessi</th></tr></thead>
              <tbody>
                <?php foreach ($accessesByMonth as $row): ?>
                  <tr><td><?= e(tramontoday_report_month_it($row['month_key'])) ?></td><td class="text-end fw-semibold"><?= e(tramontoday_report_int($row['accesses'])) ?></td></tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-12 col-xl-4">
    <div class="card shadow-sm h-100">
      <div class="card-body">
        <h2 class="h5 mb-3">Ricavi per formula</h2>
        <div class="list-group list-group-flush">
          <?php foreach ($formulaLabels as $formula => $label): ?>
            <div class="list-group-item px-0 d-flex justify-content-between"><span><?= e($label) ?></span><strong><?= e(tramontoday_report_money($revenueByFormula[$formula] ?? 0)) ?></strong></div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/partials/footer.php'; ?>
