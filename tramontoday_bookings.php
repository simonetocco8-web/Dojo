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

$statusOptions = [
  'confermata' => 'Confermata',
  'arrivata' => 'Arrivata',
  'conclusa' => 'Conclusa',
  'annullata' => 'Annullata',
  'no_show' => 'No-show',
];
$statusLabels = ['prenotata' => 'Prenotata'] + $statusOptions;
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
$paymentLabels = [
  'da_pagare' => 'Da pagare',
  'acconto' => 'Acconto',
  'pagato' => 'Pagato',
];

$errors = [];
$message = '';

function tramontoday_format_date(?string $date): string {
  if (!$date) return '';
  $dt = DateTime::createFromFormat('Y-m-d', $date);
  return $dt ? $dt->format('d/m/Y') : $date;
}

function tramontoday_format_money($amount): string {
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
    $errors[] = 'Seleziona uno status valido.';
  }

  if (!$errors) {
    $stmt = $pdo->prepare('UPDATE tramontoday_bookings SET booking_status = ?, updated_at = NOW() WHERE id = ?');
    $stmt->execute([$newStatus, $bookingId]);
    if ($stmt->rowCount() > 0) {
      $message = 'Status aggiornato correttamente.';
    } else {
      $message = 'Status già aggiornato o prenotazione non trovata.';
    }
  }
}

$stmt = $pdo->query('SELECT id, booking_date, formula, stations_count, contact_name, phone, email, adults_count, children_count, infants_count, extra_sunbeds_count, total_amount, discount_percent, final_amount, payment_status, booking_status, notes, created_at FROM tramontoday_bookings ORDER BY booking_date DESC, id DESC LIMIT 300');
$bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

$title = 'Prenotazioni TramontoDay';
include __DIR__ . '/partials/header.php';
?>
<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 mb-3">
  <div>
    <h1 class="h4 mb-1"><i class="bi bi-journal-check me-1"></i>Prenotazioni TramontoDay</h1>
    <div class="text-muted small">Elenco delle prenotazioni/accessi creati e aggiornamento rapido dello status.</div>
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

<div class="card shadow-sm">
  <div class="card-body">
    <?php if (!$bookings): ?>
      <p class="text-muted mb-0">Nessuna prenotazione/accesso TramontoDay presente.</p>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-sm align-middle">
          <thead>
            <tr>
              <th>Data</th>
              <th>Referente</th>
              <th>Formula</th>
              <th class="text-center">Post.</th>
              <th>Partecipanti</th>
              <th class="text-end">Totale</th>
              <th class="text-end">Finale</th>
              <th>Pagamento</th>
              <th>Status</th>
              <th class="text-end">Aggiorna status</th>
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
                <td class="text-nowrap"><?= e(tramontoday_format_date($booking['booking_date'] ?? '')) ?></td>
                <td>
                  <div class="fw-semibold"><?= e($booking['contact_name']) ?></div>
                  <div class="small text-muted">
                    <?= e($booking['phone']) ?><?php if (!empty($booking['email'])): ?> · <?= e($booking['email']) ?><?php endif; ?>
                  </div>
                  <?php if (!empty(trim((string)($booking['notes'] ?? '')))): ?>
                    <div class="small text-muted">Note: <?= e($booking['notes']) ?></div>
                  <?php endif; ?>
                </td>
                <td><?= e($formulaLabels[$booking['formula']] ?? $booking['formula']) ?></td>
                <td class="text-center"><?= (int)$booking['stations_count'] ?></td>
                <td class="small text-nowrap">
                  Ad. <?= (int)$booking['adults_count'] ?> · Bamb. <?= (int)$booking['children_count'] ?> · Inf. <?= (int)$booking['infants_count'] ?> · Sdraio +<?= (int)$booking['extra_sunbeds_count'] ?>
                </td>
                <td class="text-end text-nowrap">€ <?= e(tramontoday_format_money($booking['total_amount'])) ?></td>
                <td class="text-end text-nowrap">
                  <div class="fw-semibold">€ <?= e(tramontoday_format_money($booking['final_amount'])) ?></div>
                  <?php if ((float)$booking['discount_percent'] > 0): ?>
                    <div class="small text-muted">Sconto <?= e(tramontoday_format_money($booking['discount_percent'])) ?>%</div>
                  <?php endif; ?>
                </td>
                <td><?= e($paymentLabels[$booking['payment_status']] ?? $booking['payment_status']) ?></td>
                <td><span class="badge <?= e($statusClass) ?>"><?= e($statusLabel) ?></span></td>
                <td class="text-end">
                  <form method="post" class="d-inline-flex gap-2 justify-content-end">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="id" value="<?= (int)$booking['id'] ?>">
                    <select class="form-select form-select-sm" name="booking_status" aria-label="Aggiorna status prenotazione #<?= (int)$booking['id'] ?>">
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
<?php include __DIR__ . '/partials/footer.php'; ?>
