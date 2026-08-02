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

if (!user_is_reception_or_amministrazione($user)) {
  http_response_code(403);
  echo 'Accesso negato';
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

  $morningStationsRaw = trim((string)($_POST['morning_sellable_stations'] ?? ''));
  $afternoonStationsRaw = trim((string)($_POST['afternoon_sellable_stations'] ?? ''));
  if ($morningStationsRaw === '' || !ctype_digit($morningStationsRaw)) {
    $errors[] = 'Inserisci un numero di disponibilità per la mattina valido.';
    $morningStations = 0;
  } else {
    $morningStations = (int)$morningStationsRaw;
  }
  if ($afternoonStationsRaw === '' || !ctype_digit($afternoonStationsRaw)) {
    $errors[] = 'Inserisci un numero di disponibilità per il pomeriggio valido.';
    $afternoonStations = 0;
  } else {
    $afternoonStations = (int)$afternoonStationsRaw;
  }

  $isOpen = isset($_POST['is_open']) ? 1 : 0;
  $notes = trim((string)($_POST['internal_notes'] ?? ''));

  $extendDaysRaw = trim((string)($_POST['extend_days'] ?? '1'));
  if ($extendDaysRaw === '' || !ctype_digit($extendDaysRaw)) {
    $errors[] = 'Inserisci un numero di giorni da prorogare valido.';
    $extendDays = 1;
  } else {
    $extendDays = (int)$extendDaysRaw;
  }

  if (isset($date) && $date instanceof DateTimeImmutable) {
    $remainingDays = $dateRaw >= $todayYmd && $dateRaw <= $endYmd ? ((int)$date->diff($endDay)->format('%a') + 1) : 1;
    if ($extendDays < 1 || $extendDays > $remainingDays) {
      $errors[] = 'Il numero di giorni da prorogare deve essere compreso tra 1 e ' . $remainingDays . '.';
    }
  }

  if (!$errors) {
    $selectedDayStmt = $pdo->prepare('INSERT INTO tramontoday_availability (availability_date, max_sellable_stations, morning_sellable_stations, afternoon_sellable_stations, is_open, internal_notes, updated_by, updated_at)
      VALUES (:availability_date, :max_sellable_stations, :morning_sellable_stations, :afternoon_sellable_stations, :is_open, :internal_notes, :updated_by, NOW())
      ON DUPLICATE KEY UPDATE max_sellable_stations = VALUES(max_sellable_stations), morning_sellable_stations = VALUES(morning_sellable_stations), afternoon_sellable_stations = VALUES(afternoon_sellable_stations), is_open = VALUES(is_open), internal_notes = VALUES(internal_notes), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)');
    $selectedDayStmt->execute([
      ':availability_date' => $dateRaw,
      ':max_sellable_stations' => max($morningStations, $afternoonStations),
      ':morning_sellable_stations' => $morningStations,
      ':afternoon_sellable_stations' => $afternoonStations,
      ':is_open' => $isOpen,
      ':internal_notes' => $notes === '' ? null : $notes,
      ':updated_by' => $user['id'] ?? null,
    ]);

    if ($extendDays > 1) {
      $extendedDaysStmt = $pdo->prepare('INSERT INTO tramontoday_availability (availability_date, max_sellable_stations, morning_sellable_stations, afternoon_sellable_stations, updated_by, updated_at)
        VALUES (:availability_date, :max_sellable_stations, :morning_sellable_stations, :afternoon_sellable_stations, :updated_by, NOW())
        ON DUPLICATE KEY UPDATE max_sellable_stations = VALUES(max_sellable_stations), morning_sellable_stations = VALUES(morning_sellable_stations), afternoon_sellable_stations = VALUES(afternoon_sellable_stations), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)');
      for ($i = 1; $i < $extendDays; $i++) {
        $targetDate = $date->modify('+' . $i . ' days')->format('Y-m-d');
        $extendedDaysStmt->execute([
          ':availability_date' => $targetDate,
          ':max_sellable_stations' => max($morningStations, $afternoonStations),
          ':morning_sellable_stations' => $morningStations,
          ':afternoon_sellable_stations' => $afternoonStations,
          ':updated_by' => $user['id'] ?? null,
        ]);
      }
    }

    $message = $extendDays === 1
      ? 'Disponibilità aggiornata correttamente.'
      : 'Disponibilità prorogata correttamente per ' . $extendDays . ' giorni.';
  }
}

$stmt = $pdo->prepare('SELECT availability_date, morning_sellable_stations, afternoon_sellable_stations, is_open, internal_notes FROM tramontoday_availability WHERE availability_date BETWEEN ? AND ?');
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
  $morningStations = $availability ? (int)$availability['morning_sellable_stations'] : 0;
  $afternoonStations = $availability ? (int)$availability['afternoon_sellable_stations'] : 0;
  $isOpen = $availability ? (int)$availability['is_open'] === 1 : true;
  $bookedMorning = $bookedByDate[$ymd]['morning'] ?? 0;
  $bookedAfternoon = $bookedByDate[$ymd]['afternoon'] ?? 0;
  $days[] = [
    'date' => $ymd,
    'display_date' => tramontoday_availability_date_it($ymd, $tz),
    'weekday' => tramontoday_availability_weekday_it($date),
    'day_number' => $date->format('d'),
    'morning_stations' => $morningStations,
    'afternoon_stations' => $afternoonStations,
    'is_open' => $isOpen,
    'notes' => (string)($availability['internal_notes'] ?? ''),
    'morning_available' => $isOpen ? max(0, $morningStations - $bookedMorning) : 0,
    'afternoon_available' => $isOpen ? max(0, $afternoonStations - $bookedAfternoon) : 0,
    'remaining_days' => 31 - $i,
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

<div class="card shadow-sm mb-4">
  <div class="card-body">
    <form id="tramontoDayAvailabilitySearch" class="row g-2 align-items-end">
      <div class="col-12 col-md-6 col-lg-4">
        <label for="availabilitySearchDate" class="form-label fw-semibold">Cerca disponibilità per data</label>
        <input type="date" class="form-control" id="availabilitySearchDate"
          min="<?= e($todayYmd) ?>" max="<?= e($endYmd) ?>" required>
      </div>
      <div class="col-12 col-md-auto">
        <button type="submit" class="btn btn-primary w-100">
          <i class="bi bi-search me-1"></i>Cerca disponibilità
        </button>
      </div>
    </form>
    <div class="form-text mt-2">La ricerca è disponibile per i 31 giorni mostrati nel calendario.</div>
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
      $isSoldOut = $day['is_open']
        && $day['morning_available'] === 0
        && $day['afternoon_available'] === 0;
      $cardClass = !$day['is_open']
        ? 'border-danger bg-danger-subtle'
        : ($isSoldOut ? 'border-warning bg-warning-subtle' : 'border-success bg-success-subtle');
      $statusBadgeClass = !$day['is_open'] ? 'bg-danger' : ($isSoldOut ? 'bg-warning text-dark' : 'bg-success');
      $statusLabel = !$day['is_open'] ? 'Chiuso' : ($isSoldOut ? 'Esaurito' : 'Aperto');
    ?>
    <div class="col">
      <button type="button"
        class="card h-100 w-100 text-start shadow-sm <?= e($cardClass) ?>"
        data-tramontoday-availability-day="1"
        data-date="<?= e($day['date']) ?>"
        data-display-date="<?= e($day['display_date']) ?>"
        data-morning-stations="<?= (int)$day['morning_stations'] ?>"
        data-afternoon-stations="<?= (int)$day['afternoon_stations'] ?>"
        data-is-open="<?= $day['is_open'] ? '1' : '0' ?>"
        data-morning-available="<?= (int)$day['morning_available'] ?>"
        data-afternoon-available="<?= (int)$day['afternoon_available'] ?>"
        data-notes="<?= e($day['notes']) ?>"
        data-remaining-days="<?= (int)$day['remaining_days'] ?>">
        <span class="card-body d-block">
          <span class="d-flex justify-content-between align-items-start mb-2">
            <span>
              <span class="fw-bold d-block"><?= e($day['weekday']) ?> <?= e($day['day_number']) ?></span>
              <span class="small text-muted"><?= e($day['display_date']) ?></span>
            </span>
            <span class="badge <?= e($statusBadgeClass) ?>"><?= e($statusLabel) ?></span>
          </span>
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

<div class="modal fade" id="tramontoDayAvailabilitySearchModal" tabindex="-1" aria-labelledby="tramontoDayAvailabilitySearchModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h2 class="modal-title h5" id="tramontoDayAvailabilitySearchModalLabel">Disponibilità TramontoDay</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
      </div>
      <div class="modal-body" id="availabilitySearchResult" aria-live="polite"></div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Chiudi</button>
        <a class="btn btn-primary" id="availabilitySearchBookingLink"
          data-booking-url="<?= e($base) ?>/tramontoday_booking_create.php" href="<?= e($base) ?>/tramontoday_booking_create.php">
          <i class="bi bi-calendar-check me-1"></i>Prenota Data
        </a>
      </div>
    </div>
  </div>
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
          <div class="row g-3 mb-3">
            <div class="col-sm-6">
              <label for="morning_sellable_stations" class="form-label">Disponibilità mattina</label>
              <input type="number" min="0" step="1" class="form-control" id="morning_sellable_stations" name="morning_sellable_stations" required>
            </div>
            <div class="col-sm-6">
              <label for="afternoon_sellable_stations" class="form-label">Disponibilità pomeriggio</label>
              <input type="number" min="0" step="1" class="form-control" id="afternoon_sellable_stations" name="afternoon_sellable_stations" required>
            </div>
          </div>
          <div class="mb-3">
            <label for="extend_days" class="form-label">Proroga per giorni</label>
            <input type="number" min="1" step="1" class="form-control" id="extend_days" name="extend_days" value="1" required>
            <div class="form-text" id="extendDaysHelp">1 = solo il giorno selezionato.</div>
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
