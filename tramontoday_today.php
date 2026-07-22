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

$tz = new DateTimeZone('Europe/Rome');
$today = (new DateTimeImmutable('today', $tz))->format('Y-m-d');

$statusOptions = [
  'arrivata' => 'Arrivata',
  'conclusa' => 'Conclusa',
  'annullata' => 'Annullata',
  'no_show' => 'No-show',
];
$statusLabels = [
  'prenotata' => 'Prenotata',
  'confermata' => 'Confermata',
  'arrivata' => 'Arrivata',
  'conclusa' => 'Conclusa',
  'annullata' => 'Annullata',
  'no_show' => 'No-show',
];
$statusClasses = [
  'prenotata' => 'bg-secondary',
  'confermata' => 'bg-primary',
  'arrivata' => 'bg-info text-dark',
  'conclusa' => 'bg-success',
  'annullata' => 'bg-danger',
  'no_show' => 'bg-warning text-dark',
];
$formulaLabels = [
  'giornata_intera' => 'Giornata intera',
  'mattina' => 'Mattina',
  'pomeriggio' => 'Pomeriggio',
];

$errors = [];
$message = '';

function tramontoday_today_money($amount): string {
  return number_format((float)$amount, 2, ',', '.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_check($_POST['csrf_token'] ?? '')) {
    $errors[] = 'Token CSRF non valido, ricarica la pagina e riprova.';
  }

  $bookingId = isset($_POST['id']) ? (int)$_POST['id'] : 0;
  $newStatus = (string)($_POST['booking_status'] ?? '');
  if ($bookingId <= 0) {
    $errors[] = 'Prenotazione/accesso non valida.';
  }
  if (!array_key_exists($newStatus, $statusOptions)) {
    $errors[] = 'Seleziona uno status valido per gli accessi di oggi.';
  }

  if (!$errors) {
    $stmt = $pdo->prepare('UPDATE tramontoday_bookings SET booking_status = ?, updated_at = NOW() WHERE id = ? AND booking_date = ?');
    $stmt->execute([$newStatus, $bookingId, $today]);
    $message = $stmt->rowCount() > 0 ? 'Status aggiornato correttamente.' : 'Status già aggiornato o prenotazione di oggi non trovata.';
  }
}

$stmt = $pdo->prepare('SELECT id, booking_date, formula, stations_count, contact_name, final_amount, adults_count, children_count, infants_count, extra_sunbeds_count, booking_status FROM tramontoday_bookings WHERE booking_date = ? ORDER BY FIELD(booking_status, "arrivata", "confermata", "prenotata", "conclusa", "annullata", "no_show"), contact_name ASC, id ASC');
$stmt->execute([$today]);
$bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

$summary = [
  'stations' => 0,
  'adults' => 0,
  'children' => 0,
  'infants' => 0,
  'extra_sunbeds' => 0,
  'morning_stations' => 0,
  'afternoon_stations' => 0,
  'arrived_entries' => 0,
  'pending_entries' => 0,
];
foreach ($bookings as $booking) {
  $status = (string)($booking['booking_status'] ?? 'prenotata');
  if (in_array($status, ['annullata', 'no_show'], true)) {
    continue;
  }

  $stations = (int)$booking['stations_count'];
  $summary['stations'] += $stations;
  $summary['adults'] += (int)$booking['adults_count'];
  $summary['children'] += (int)$booking['children_count'];
  $summary['infants'] += (int)$booking['infants_count'];
  $summary['extra_sunbeds'] += (int)$booking['extra_sunbeds_count'];

  if ($booking['formula'] === 'mattina' || $booking['formula'] === 'giornata_intera') {
    $summary['morning_stations'] += $stations;
  }
  if ($booking['formula'] === 'pomeriggio' || $booking['formula'] === 'giornata_intera') {
    $summary['afternoon_stations'] += $stations;
  }

  if (in_array($status, ['arrivata', 'conclusa'], true)) {
    $summary['arrived_entries']++;
  } elseif (in_array($status, ['prenotata', 'confermata'], true)) {
    $summary['pending_entries']++;
  }
}

$title = 'Accessi di oggi TramontoDay';
include __DIR__ . '/partials/header.php';
?>
<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 mb-3">
  <div>
    <h1 class="h4 mb-1"><i class="bi bi-door-open me-1"></i>Accessi di oggi TramontoDay</h1>
    <div class="text-muted small">Giornata del <?= e((new DateTimeImmutable($today, $tz))->format('d/m/Y')) ?>: monitoraggio ingressi e riepilogo operativo.</div>
  </div>
  <a class="btn btn-primary" href="<?= e($base) ?>/tramontoday_booking_create.php"><i class="bi bi-plus-circle me-1"></i>Nuova prenotazione/accesso</a>
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

<div class="row g-4">
  <div class="col-12 col-xl-8">
    <div class="card shadow-sm h-100">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <h2 class="h5 mb-0">Prenotazioni/accessi di oggi</h2>
          <span class="badge bg-light text-dark border"><?= count($bookings) ?> record</span>
        </div>
        <?php if (!$bookings): ?>
          <p class="text-muted mb-0">Nessuna prenotazione/accesso per la giornata odierna.</p>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-sm align-middle">
              <thead>
                <tr>
                  <th>Referente</th>
                  <th>Formula</th>
                  <th class="text-end">Importo Finale</th>
                  <th class="text-center">Postazioni</th>
                  <th>Status</th>
                  <th class="text-end">Aggiorna</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($bookings as $booking): ?>
                  <?php
                    $currentStatus = (string)($booking['booking_status'] ?? 'prenotata');
                    $statusLabel = $statusLabels[$currentStatus] ?? ucfirst(str_replace('_', ' ', $currentStatus));
                    $statusClass = $statusClasses[$currentStatus] ?? 'bg-secondary';
                  ?>
                  <tr>
                    <td class="fw-semibold"><?= e($booking['contact_name']) ?></td>
                    <td><?= e($formulaLabels[$booking['formula']] ?? $booking['formula']) ?></td>
                    <td class="text-end text-nowrap">€ <?= e(tramontoday_today_money($booking['final_amount'])) ?></td>
                    <td class="text-center"><?= (int)$booking['stations_count'] ?></td>
                    <td><span class="badge <?= e($statusClass) ?>"><?= e($statusLabel) ?></span></td>
                    <td class="text-end">
                      <form method="post" class="d-inline-flex gap-2 justify-content-end">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="id" value="<?= (int)$booking['id'] ?>">
                        <select class="form-select form-select-sm" name="booking_status" aria-label="Aggiorna status accesso #<?= (int)$booking['id'] ?>">
                          <?php foreach ($statusOptions as $value => $label): ?>
                            <option value="<?= e($value) ?>" <?= $currentStatus === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                          <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn btn-sm btn-outline-primary">Salva</button>
                      </form>
                    </td>
                  </tr>
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
        <h2 class="h5 mb-3">Riepilogo giornata odierna</h2>
        <div class="list-group list-group-flush">
          <div class="list-group-item px-0 d-flex justify-content-between"><span>Numero di Postazioni</span><strong><?= (int)$summary['stations'] ?></strong></div>
          <div class="list-group-item px-0 d-flex justify-content-between"><span>Numero Adulti</span><strong><?= (int)$summary['adults'] ?></strong></div>
          <div class="list-group-item px-0 d-flex justify-content-between"><span>Numero Bambini</span><strong><?= (int)$summary['children'] ?></strong></div>
          <div class="list-group-item px-0 d-flex justify-content-between"><span>Numero Enfant</span><strong><?= (int)$summary['infants'] ?></strong></div>
          <div class="list-group-item px-0 d-flex justify-content-between"><span>Numero Sdraio Extra</span><strong><?= (int)$summary['extra_sunbeds'] ?></strong></div>
          <div class="list-group-item px-0 d-flex justify-content-between"><span>Numero Postazioni di Mattina</span><strong><?= (int)$summary['morning_stations'] ?></strong></div>
          <div class="list-group-item px-0 d-flex justify-content-between"><span>Numero Postazioni Pomeriggio</span><strong><?= (int)$summary['afternoon_stations'] ?></strong></div>
          <div class="list-group-item px-0 d-flex justify-content-between"><span>Ingressi già effettuati</span><strong><?= (int)$summary['arrived_entries'] ?></strong></div>
          <div class="list-group-item px-0 d-flex justify-content-between"><span>Ingressi ancora da arrivare</span><strong><?= (int)$summary['pending_entries'] ?></strong></div>
        </div>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/partials/footer.php'; ?>
