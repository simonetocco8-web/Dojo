<?php
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/security.php';
require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/roles.php';

start_session();
$env  = require __DIR__ . '/config/env.php';
$base = rtrim($env['app']['base_url'] ?? '', '/');
$user = current_user();

if (!$user) {
  header('Location: ' . $base . '/index.php?msg=auth');
  exit;
}

$pdo = db();
ensure_tramontoday_bookings_table($pdo);
ensure_tramontoday_availability_table($pdo);

$tz = new DateTimeZone('Europe/Rome');
$today = new DateTimeImmutable('today', $tz);
$endDay = $today->modify('+30 days');
$todayYmd = $today->format('Y-m-d');
$endYmd = $endDay->format('Y-m-d');

$errors = [];
$message = '';

function tramontoday_availability_date_it(string $ymd, DateTimeZone $tz): string {
  $dt = DateTimeImmutable::createFromFormat('Y-m-d', $ymd, $tz);
  return $dt ? $dt->format('d/m/Y') : $ymd;
}

function tramontoday_availability_weekday_it(DateTimeImmutable $date): string {
  $labels = ['Dom', 'Lun', 'Mar', 'Mer', 'Gio', 'Ven', 'Sab'];
  return $labels[(int)$date->format('w')];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_check($_POST['csrf_token'] ?? '')) {
    $errors[] = 'Token CSRF non valido, ricarica la pagina e riprova.';
  }

  $dateRaw = trim((string)($_POST['availability_date'] ?? ''));
  $date = DateTimeImmutable::createFromFormat('Y-m-d', $dateRaw, $tz);
  if (!$date || $date->format('Y-m-d') !== $dateRaw) {
    $errors[] = 'Seleziona una data valida.';
  } elseif ($dateRaw < $todayYmd || $dateRaw > $endYmd) {
    $errors[] = 'Puoi modificare solo i prossimi 31 giorni del calendario.';
  }

  $maxStationsRaw = trim((string)($_POST['max_sellable_stations'] ?? ''));
  if ($maxStationsRaw === '' || !ctype_digit($maxStationsRaw)) {
    $errors[] = 'Inserisci un numero massimo di postazioni vendibili valido.';
    $maxStations = 0;
  } else {
    $maxStations = (int)$maxStationsRaw;
  }

  $isOpen = isset($_POST['is_open']) ? 1 : 0;
  $notes = trim((string)($_POST['internal_notes'] ?? ''));

  if (!$errors) {
    $stmt = $pdo->prepare('INSERT INTO tramontoday_availability (availability_date, max_sellable_stations, is_open, internal_notes, updated_by, updated_at)
      VALUES (:availability_date, :max_sellable_stations, :is_open, :internal_notes, :updated_by, NOW())
      ON DUPLICATE KEY UPDATE max_sellable_stations = VALUES(max_sellable_stations), is_open = VALUES(is_open), internal_notes = VALUES(internal_notes), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)');
    $stmt->execute([
      ':availability_date' => $dateRaw,
      ':max_sellable_stations' => $maxStations,
      ':is_open' => $isOpen,
      ':internal_notes' => $notes === '' ? null : $notes,
      ':updated_by' => $user['id'] ?? null,
    ]);
    $message = 'Disponibilità aggiornata correttamente.';
  }
}

$stmt = $pdo->prepare('SELECT availability_date, max_sellable_stations, is_open, internal_notes FROM tramontoday_availability WHERE availability_date BETWEEN ? AND ?');
$stmt->execute([$todayYmd, $endYmd]);
$availabilityRows = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
  $availabilityRows[$row['availability_date']] = $row;
}

$stmt = $pdo->prepare('SELECT booking_date, formula, SUM(stations_count) AS stations FROM tramontoday_bookings WHERE booking_date BETWEEN ? AND ? AND booking_status NOT IN ("annullata", "no_show") GROUP BY booking_date, formula');
$stmt->execute([$todayYmd, $endYmd]);
$bookedByDate = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
  $date = (string)$row['booking_date'];
  if (!isset($bookedByDate[$date])) {
    $bookedByDate[$date] = ['morning' => 0, 'afternoon' => 0];
  }
  $stations = (int)$row['stations'];
  if ($row['formula'] === 'giornata_intera' || $row['formula'] === 'mattina') {
    $bookedByDate[$date]['morning'] += $stations;
  }
  if ($row['formula'] === 'giornata_intera' || $row['formula'] === 'pomeriggio') {
    $bookedByDate[$date]['afternoon'] += $stations;
  }
}

$days = [];
for ($i = 0; $i < 31; $i++) {
  $date = $today->modify('+' . $i . ' days');
  $ymd = $date->format('Y-m-d');
  $availability = $availabilityRows[$ymd] ?? null;
  $maxStations = $availability ? (int)$availability['max_sellable_stations'] : 0;
  $isOpen = $availability ? (int)$availability['is_open'] === 1 : true;
  $bookedMorning = $bookedByDate[$ymd]['morning'] ?? 0;
  $bookedAfternoon = $bookedByDate[$ymd]['afternoon'] ?? 0;
  $days[] = [
    'date' => $ymd,
    'display_date' => tramontoday_availability_date_it($ymd, $tz),
    'weekday' => tramontoday_availability_weekday_it($date),
    'day_number' => $date->format('d'),
    'max_stations' => $maxStations,
    'is_open' => $isOpen,
    'notes' => (string)($availability['internal_notes'] ?? ''),
    'morning_available' => $isOpen ? max(0, $maxStations - $bookedMorning) : 0,
    'afternoon_available' => $isOpen ? max(0, $maxStations - $bookedAfternoon) : 0,
  ];
}

$title = 'Calendario disponibilità TramontoDay';
include __DIR__ . '/partials/header.php';
?>
<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 mb-3">
  <div>
    <h1 class="h4 mb-1"><i class="bi bi-calendar-week me-1"></i>Calendario disponibilità TramontoDay</h1>
    <div class="text-muted small">Prossimi 31 giorni dal <?= e($today->format('d/m/Y')) ?> al <?= e($endDay->format('d/m/Y')) ?>.</div>
  </div>
</div>

<?php if ($message): ?>
  <div class="alert alert-success"><?= e($message) ?></div>
<?php endif; ?>
<?php if ($errors): ?>
  <div class="alert alert-danger">
    <?php foreach ($errors as $error): ?>
      <div><?= e($error) ?></div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<div class="row row-cols-1 row-cols-sm-2 row-cols-md-3 row-cols-xl-5 g-3">
  <?php foreach ($days as $day): ?>
    <?php
      $hasReducedAvailability = $day['is_open']
        && $day['max_stations'] > 0
        && $day['morning_available'] < $day['max_stations']
        && $day['afternoon_available'] < $day['max_stations'];
      $cardClass = !$day['is_open']
        ? 'border-danger bg-danger-subtle'
        : ($hasReducedAvailability ? 'border-warning bg-warning-subtle' : 'border-success bg-success-subtle');
    ?>
    <div class="col">
      <button type="button"
        class="card h-100 w-100 text-start shadow-sm <?= e($cardClass) ?>"
        data-tramontoday-availability-day="1"
        data-date="<?= e($day['date']) ?>"
        data-display-date="<?= e($day['display_date']) ?>"
        data-max-stations="<?= (int)$day['max_stations'] ?>"
        data-is-open="<?= $day['is_open'] ? '1' : '0' ?>"
        data-notes="<?= e($day['notes']) ?>">
        <span class="card-body d-block">
          <span class="d-flex justify-content-between align-items-start mb-2">
            <span>
              <span class="fw-bold d-block"><?= e($day['weekday']) ?> <?= e($day['day_number']) ?></span>
              <span class="small text-muted"><?= e($day['display_date']) ?></span>
            </span>
            <span class="badge <?= $day['is_open'] ? 'bg-success' : 'bg-danger' ?>"><?= $day['is_open'] ? 'Aperto' : 'Chiuso' ?></span>
          </span>
          <span class="small d-block">Max postazioni vendibili: <strong><?= (int)$day['max_stations'] ?></strong></span>
          <span class="small d-block">Disponibilità mattina: <strong><?= (int)$day['morning_available'] ?></strong></span>
          <span class="small d-block">Disponibilità pomeriggio: <strong><?= (int)$day['afternoon_available'] ?></strong></span>
          <?php if (trim($day['notes']) !== ''): ?>
            <span class="small text-muted d-block mt-2">Note: <?= e($day['notes']) ?></span>
          <?php endif; ?>
        </span>
      </button>
    </div>
  <?php endforeach; ?>
</div>

<div class="modal fade" id="tramontoDayAvailabilityModal" tabindex="-1" aria-labelledby="tramontoDayAvailabilityModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="post">
        <div class="modal-header">
          <h2 class="modal-title h5" id="tramontoDayAvailabilityModalLabel">Disponibilità <span id="availabilityDateLabel"></span></h2>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="availability_date" id="availability_date" value="">
          <div class="mb-3">
            <label for="max_sellable_stations" class="form-label">Numero massimo di postazioni vendibili</label>
            <input type="number" min="0" step="1" class="form-control" id="max_sellable_stations" name="max_sellable_stations" required>
          </div>
          <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" role="switch" id="is_open" name="is_open" value="1">
            <label class="form-check-label" for="is_open">Servizio aperto</label>
          </div>
          <div class="mb-0">
            <label for="internal_notes" class="form-label">Note interne</label>
            <textarea class="form-control" id="internal_notes" name="internal_notes" rows="4"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annulla</button>
          <button type="submit" class="btn btn-primary">Salva disponibilità</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php $tramontoDayAvailabilityJsVersion = @filemtime(__DIR__ . '/assets/tramontoday-availability.js') ?: time(); ?>
<script src="<?= e($base) ?>/assets/tramontoday-availability.js?v=<?= (int)$tramontoDayAvailabilityJsVersion ?>"></script>
<?php include __DIR__ . '/partials/footer.php'; ?>
