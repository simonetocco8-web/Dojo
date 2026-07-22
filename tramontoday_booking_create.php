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

$settingKeys = [
  'tramontoday_adult_full_day_price',
  'tramontoday_child_full_day_price',
  'tramontoday_adult_half_day_price',
  'tramontoday_child_half_day_price',
  'tramontoday_extra_sunbed_price',
];
$priceSettings = get_settings($settingKeys, $pdo);
$adultFullDayPrice = (float)($priceSettings['tramontoday_adult_full_day_price'] ?? 0);
$childFullDayPrice = (float)($priceSettings['tramontoday_child_full_day_price'] ?? 0);
$adultHalfDayPrice = (float)($priceSettings['tramontoday_adult_half_day_price'] ?? 0);
$childHalfDayPrice = (float)($priceSettings['tramontoday_child_half_day_price'] ?? 0);
$extraSunbedPrice = (float)($priceSettings['tramontoday_extra_sunbed_price'] ?? 10);
if ($extraSunbedPrice <= 0) $extraSunbedPrice = 10.00;

$formulaOptions = [
  'giornata_intera' => 'Giornata intera',
  'mattina' => 'Mattina',
  'pomeriggio' => 'Pomeriggio',
];
$paymentOptions = [
  'da_pagare' => 'Da pagare',
  'acconto' => 'Acconto',
  'pagato' => 'Pagato',
];
$bookingStatusOptions = [
  'prenotata' => 'Prenotata',
  'confermata' => 'Confermata',
  'arrivata' => 'Arrivata',
];

function tramontoday_int_value(array $source, string $key, int $default = 0): int {
  $value = trim((string)($source[$key] ?? ''));
  if ($value === '') return $default;
  return ctype_digit($value) ? (int)$value : -1;
}

function tramontoday_money_total(string $formula, int $adults, int $children, int $extraSunbeds, float $adultFull, float $childFull, float $adultHalf, float $childHalf, float $sunbedPrice): float {
  $adultPrice = $formula === 'giornata_intera' ? $adultFull : $adultHalf;
  $childPrice = $formula === 'giornata_intera' ? $childFull : $childHalf;
  return round(($adults * $adultPrice) + ($children * $childPrice) + ($extraSunbeds * $sunbedPrice), 2);
}

$values = [
  'booking_date' => '',
  'formula' => 'giornata_intera',
  'stations_count' => '1',
  'contact_name' => '',
  'phone' => '',
  'email' => '',
  'adults_count' => '0',
  'children_count' => '0',
  'infants_count' => '0',
  'extra_sunbeds_count' => '0',
  'notes' => '',
  'discount_percent' => '0',
  'payment_status' => 'da_pagare',
  'booking_status' => 'prenotata',
];
$errors = [];
$successMessage = '';
$totalAmount = 0.00;
$finalAmount = 0.00;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  foreach ($values as $key => $default) {
    $values[$key] = trim((string)($_POST[$key] ?? $default));
  }

  if (!csrf_check($_POST['csrf_token'] ?? '')) {
    $errors[] = 'Token CSRF non valido, ricarica la pagina e riprova.';
  }

  $date = DateTime::createFromFormat('Y-m-d', $values['booking_date']);
  if (!$date || $date->format('Y-m-d') !== $values['booking_date']) {
    $errors[] = 'Inserisci una data valida.';
  }

  if (!array_key_exists($values['formula'], $formulaOptions)) {
    $errors[] = 'Seleziona una formula valida.';
  }
  if (!array_key_exists($values['payment_status'], $paymentOptions)) {
    $errors[] = 'Seleziona uno stato pagamento valido.';
  }
  if (!array_key_exists($values['booking_status'], $bookingStatusOptions)) {
    $errors[] = 'Seleziona uno stato prenotazione valido.';
  }

  $stationsCount = tramontoday_int_value($_POST, 'stations_count', 1);
  $adultsCount = tramontoday_int_value($_POST, 'adults_count');
  $childrenCount = tramontoday_int_value($_POST, 'children_count');
  $infantsCount = tramontoday_int_value($_POST, 'infants_count');
  $extraSunbedsCount = tramontoday_int_value($_POST, 'extra_sunbeds_count');

  if ($stationsCount <= 0) $errors[] = 'Il numero di postazioni deve essere almeno 1.';
  if ($adultsCount < 0) $errors[] = 'Il numero adulti deve essere un intero maggiore o uguale a zero.';
  if ($childrenCount < 0) $errors[] = 'Il numero bambini deve essere un intero maggiore o uguale a zero.';
  if ($infantsCount < 0) $errors[] = 'Il numero infant deve essere un intero maggiore o uguale a zero.';
  if ($extraSunbedsCount < 0) $errors[] = 'Il numero di sdraio aggiuntive deve essere un intero maggiore o uguale a zero.';
  if (($adultsCount + $childrenCount + $infantsCount) <= 0) $errors[] = 'Inserisci almeno una persona tra adulti, bambini o infant.';

  if ($values['contact_name'] === '') $errors[] = 'Inserisci nome e cognome del referente.';
  if ($values['phone'] === '') $errors[] = 'Inserisci il numero di telefono.';
  if ($values['email'] !== '' && !filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Inserisci un indirizzo e-mail valido oppure lascia il campo vuoto.';
  }

  $discountNormalized = str_replace(',', '.', $values['discount_percent']);
  if ($discountNormalized === '' || !is_numeric($discountNormalized) || (float)$discountNormalized < 0 || (float)$discountNormalized > 100) {
    $errors[] = 'Lo sconto deve essere una percentuale compresa tra 0 e 100.';
    $discountPercent = 0.00;
  } else {
    $discountPercent = round((float)$discountNormalized, 2);
    $values['discount_percent'] = number_format($discountPercent, 2, '.', '');
  }

  $totalAmount = tramontoday_money_total($values['formula'], max(0, $adultsCount), max(0, $childrenCount), max(0, $extraSunbedsCount), $adultFullDayPrice, $childFullDayPrice, $adultHalfDayPrice, $childHalfDayPrice, $extraSunbedPrice);
  $finalAmount = round($totalAmount - ($totalAmount * ($discountPercent ?? 0) / 100), 2);

  if (!$errors) {
    $stmt = $pdo->prepare("INSERT INTO tramontoday_bookings
      (booking_date, formula, stations_count, contact_name, phone, email, adults_count, children_count, infants_count, extra_sunbeds_count, notes, total_amount, discount_percent, final_amount, payment_status, booking_status, created_by)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
      $values['booking_date'],
      $values['formula'],
      $stationsCount,
      $values['contact_name'],
      $values['phone'],
      $values['email'] === '' ? null : $values['email'],
      $adultsCount,
      $childrenCount,
      $infantsCount,
      $extraSunbedsCount,
      $values['notes'] === '' ? null : $values['notes'],
      $totalAmount,
      $discountPercent,
      $finalAmount,
      $values['payment_status'],
      $values['booking_status'],
      $user['id'] ?? null,
    ]);
    $successMessage = 'Prenotazione/accesso TramontoDay creato correttamente. ID #' . $pdo->lastInsertId();
    $values = [
      'booking_date' => '',
      'formula' => 'giornata_intera',
      'stations_count' => '1',
      'contact_name' => '',
      'phone' => '',
      'email' => '',
      'adults_count' => '0',
      'children_count' => '0',
      'infants_count' => '0',
      'extra_sunbeds_count' => '0',
      'notes' => '',
      'discount_percent' => '0',
      'payment_status' => 'da_pagare',
      'booking_status' => 'prenotata',
    ];
    $totalAmount = 0.00;
    $finalAmount = 0.00;
  }
}

$title = 'Nuova prenotazione/accesso TramontoDay';
include __DIR__ . '/partials/header.php';
?>
<div class="row justify-content-center">
  <div class="col-12 col-xl-10">
    <div class="card shadow-sm mb-3">
      <div class="card-body">
        <h1 class="h4 mb-2"><i class="bi bi-plus-circle me-1"></i>Nuova prenotazione/accesso TramontoDay</h1>
        <p class="text-muted mb-0">Inserisci i dati del referente, la formula, i partecipanti e lo stato iniziale della prenotazione/accesso.</p>
      </div>
    </div>

    <div class="alert alert-info border-info-subtle shadow-sm" role="note">
      <div class="fw-semibold mb-1"><i class="bi bi-info-circle me-1"></i>Come considerare una postazione</div>
      <div>Una postazione è rappresentata dalla dotazione di un ombrellone, una sdraio, un lettino e un posto auto. Il numero di persone non è legato alle postazioni, ma è consigliabile mantenere un numero di persone accettabile. Es: 1 postazione x 4 persone totali.</div>
    </div>

    <?php if ($successMessage): ?>
      <div class="alert alert-success"><?= e($successMessage) ?></div>
    <?php endif; ?>
    <?php if ($errors): ?>
      <div class="alert alert-danger">
        <?php foreach ($errors as $error): ?>
          <div><?= e($error) ?></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <form method="post" id="tramontodayBookingForm" novalidate
      data-adult-full="<?= e(number_format($adultFullDayPrice, 2, '.', '')) ?>"
      data-child-full="<?= e(number_format($childFullDayPrice, 2, '.', '')) ?>"
      data-adult-half="<?= e(number_format($adultHalfDayPrice, 2, '.', '')) ?>"
      data-child-half="<?= e(number_format($childHalfDayPrice, 2, '.', '')) ?>"
      data-sunbed="<?= e(number_format($extraSunbedPrice, 2, '.', '')) ?>">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

      <div class="card shadow-sm mb-3">
        <div class="card-body">
          <h2 class="h5 mb-3">Dettagli prenotazione/accesso</h2>
          <div class="row g-3">
            <div class="col-12 col-md-4">
              <label for="booking_date" class="form-label">Data</label>
              <input type="date" class="form-control" id="booking_date" name="booking_date" value="<?= e($values['booking_date']) ?>" required>
            </div>
            <div class="col-12 col-md-4">
              <label for="formula" class="form-label">Formula</label>
              <select class="form-select" id="formula" name="formula" required>
                <?php foreach ($formulaOptions as $value => $label): ?>
                  <option value="<?= e($value) ?>" <?= $values['formula'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12 col-md-4">
              <label for="stations_count" class="form-label">Numero di postazioni</label>
              <input type="number" min="1" step="1" class="form-control" id="stations_count" name="stations_count" value="<?= e($values['stations_count']) ?>" required>
            </div>
          </div>
        </div>
      </div>

      <div class="card shadow-sm mb-3">
        <div class="card-body">
          <h2 class="h5 mb-3">Referente</h2>
          <div class="row g-3">
            <div class="col-12 col-md-5">
              <label for="contact_name" class="form-label">Nome e cognome del referente</label>
              <input type="text" class="form-control" id="contact_name" name="contact_name" value="<?= e($values['contact_name']) ?>" required>
            </div>
            <div class="col-12 col-md-3">
              <label for="phone" class="form-label">Numero di telefono</label>
              <input type="tel" class="form-control" id="phone" name="phone" value="<?= e($values['phone']) ?>" required>
            </div>
            <div class="col-12 col-md-4">
              <label for="email" class="form-label">E-mail eventuale</label>
              <input type="email" class="form-control" id="email" name="email" value="<?= e($values['email']) ?>">
            </div>
          </div>
        </div>
      </div>

      <div class="card shadow-sm mb-3">
        <div class="card-body">
          <h2 class="h5 mb-3">Partecipanti e importi</h2>
          <div class="row g-3">
            <div class="col-6 col-md-3">
              <label for="adults_count" class="form-label">Numero adulti</label>
              <input type="number" min="0" step="1" class="form-control js-tramontoday-calc" id="adults_count" name="adults_count" value="<?= e($values['adults_count']) ?>">
            </div>
            <div class="col-6 col-md-3">
              <label for="children_count" class="form-label">Bambini 3-11 anni</label>
              <input type="number" min="0" step="1" class="form-control js-tramontoday-calc" id="children_count" name="children_count" value="<?= e($values['children_count']) ?>">
            </div>
            <div class="col-6 col-md-3">
              <label for="infants_count" class="form-label">Numero infant</label>
              <input type="number" min="0" step="1" class="form-control" id="infants_count" name="infants_count" value="<?= e($values['infants_count']) ?>">
            </div>
            <div class="col-6 col-md-3">
              <label for="extra_sunbeds_count" class="form-label">Sdraio aggiuntive</label>
              <input type="number" min="0" step="1" class="form-control js-tramontoday-calc" id="extra_sunbeds_count" name="extra_sunbeds_count" value="<?= e($values['extra_sunbeds_count']) ?>">
            </div>
            <div class="col-12 col-md-4">
              <label for="total_amount" class="form-label">Importo Totale</label>
              <div class="input-group">
                <span class="input-group-text">€</span>
                <input type="text" class="form-control" id="total_amount" value="<?= e(number_format($totalAmount, 2, ',', '.')) ?>" readonly>
              </div>
              <div class="form-text">Calcolato da adulti, bambini e sdraio aggiuntive.</div>
            </div>
            <div class="col-12 col-md-4">
              <label for="discount_percent" class="form-label">Sconto (%)</label>
              <input type="number" min="0" max="100" step="0.01" class="form-control js-tramontoday-calc" id="discount_percent" name="discount_percent" value="<?= e($values['discount_percent']) ?>">
            </div>
            <div class="col-12 col-md-4">
              <label for="final_amount" class="form-label">Importo Finale</label>
              <div class="input-group">
                <span class="input-group-text">€</span>
                <input type="text" class="form-control" id="final_amount" value="<?= e(number_format($finalAmount, 2, ',', '.')) ?>" readonly>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="card shadow-sm mb-3">
        <div class="card-body">
          <h2 class="h5 mb-3">Stati e note</h2>
          <div class="row g-3">
            <div class="col-12 col-md-6">
              <label for="payment_status" class="form-label">Stato pagamento</label>
              <select class="form-select" id="payment_status" name="payment_status" required>
                <?php foreach ($paymentOptions as $value => $label): ?>
                  <option value="<?= e($value) ?>" <?= $values['payment_status'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12 col-md-6">
              <label for="booking_status" class="form-label">Status prenotazione/accesso</label>
              <select class="form-select" id="booking_status" name="booking_status" required>
                <?php foreach ($bookingStatusOptions as $value => $label): ?>
                  <option value="<?= e($value) ?>" <?= $values['booking_status'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12">
              <label for="notes" class="form-label">Note</label>
              <textarea class="form-control" id="notes" name="notes" rows="4"><?= e($values['notes']) ?></textarea>
            </div>
          </div>
        </div>
      </div>

      <div class="d-flex justify-content-end mb-4">
        <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Crea prenotazione/accesso</button>
      </div>
    </form>
  </div>
</div>
<?php $tramontoDayBookingJsVersion = @filemtime(__DIR__ . '/assets/tramontoday-booking-create.js') ?: time(); ?>
<script src="<?= e($base) ?>/assets/tramontoday-booking-create.js?v=<?= (int)$tramontoDayBookingJsVersion ?>"></script>
<?php include __DIR__ . '/partials/footer.php'; ?>
