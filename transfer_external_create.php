<?php
// transfer_external_create.php — crea transfer esterno (Prenotato => Compagnia, Pickup opzionale)
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/security.php';
require_once __DIR__ . '/core/db.php';

start_session();
$env  = require __DIR__ . '/config/env.php';
$base = rtrim($env['app']['base_url'] ?? '', '/');
$pdo  = db();
ensure_transfer_external_travel_columns($pdo);
$user = current_user();
if (!$user) { header('Location: ' . $base . '/index.php?msg=auth'); exit; }

// Opzioni luogo arrivo/partenza
$places = [
  'Aeroporto Lamezia Terme',
  'Aeroporto Reggio Calabria',
  'Stazione Lamezia Terme',
  'Stazione Rosarno'
];

$suppliers = ['Dany Express', 'Nino', 'Altro'];

$message = '';
$form = [
  'type' => 'arrivo',
  'place' => $places[0],
  'date' => '',
  'time' => '',
  'pickup_time' => '',
  'flight_number' => '',
  'train_number' => '',
  'arrival_place' => $places[0],
  'arrival_date' => '',
  'arrival_time' => '',
  'arrival_pickup_time' => '',
  'arrival_flight_number' => '',
  'arrival_train_number' => '',
  'departure_place' => $places[0],
  'departure_date' => '',
  'departure_time' => '',
  'departure_pickup_time' => '',
  'departure_flight_number' => '',
  'departure_train_number' => '',
  'room_number' => '',
  'guest_name' => '',
  'people_count' => '',
  'price_eur' => '',
  'supplier_name' => $suppliers[0],
  'booked' => 0,
  'paid' => 0,
  'service_company' => '',
];

function transfer_external_is_airport(string $place): bool {
  return stripos($place, 'Aeroporto') !== false;
}

function transfer_external_is_station(string $place): bool {
  return stripos($place, 'Stazione') !== false;
}

function transfer_external_clean_reference(string $value): string {
  $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
  if (function_exists('mb_substr')) {
    return mb_substr($value, 0, 80, 'UTF-8');
  }
  return substr($value, 0, 80);
}

function transfer_external_optional_time(?string $time): ?string {
  $time = trim((string)$time);
  if ($time === '') return null;
  $dt = DateTime::createFromFormat('H:i', $time);
  return $dt ? $dt->format('H:i:s') : null;
}

function transfer_external_required_datetime(string $date, string $time): ?DateTime {
  $dt = DateTime::createFromFormat('Y-m-d H:i', trim($date) . ' ' . trim($time));
  return $dt ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  foreach ($form as $key => $default) {
    if (in_array($key, ['booked', 'paid'], true)) continue;
    $form[$key] = trim((string)($_POST[$key] ?? $default));
  }
  $form['booked'] = isset($_POST['booked']) ? 1 : 0;
  $form['paid'] = isset($_POST['paid']) ? 1 : 0;

  if (!csrf_check($_POST['csrf'] ?? '')) {
    $message = 'Token CSRF non valido.';
  } else {
    $type = $form['type'];
    if (!in_array($type, ['arrivo','partenza','arrivo_partenza'], true)) $type = 'arrivo';

    $room = $form['room_number'];
    $name = $form['guest_name'];
    $peopleRaw = $form['people_count'];
    $priceRaw = $form['price_eur'];
    $booked = (int)$form['booked'];
    $paid = (int)$form['paid'];
    $supplierName = in_array($form['supplier_name'], $suppliers, true) ? $form['supplier_name'] : '';
    if ($supplierName === '') {
      $message = 'Seleziona un fornitore valido.';
    }

    $serviceCompany = null;
    if ($booked) {
      $serviceCompany = $form['service_company'];
      if ($serviceCompany === '') {
        $message = 'Inserisci la Compagnia del Servizio per i transfer prenotati.';
      }
    }

    $people = null;
    if ($peopleRaw === '' || ($people = filter_var($peopleRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) === false) {
      $message = 'Inserisci un Numero Persone valido (maggiore o uguale a 1).';
    }

    $price = null;
    if (!$message) {
      if ($priceRaw === '') {
        $message = 'Inserisci il Prezzo del transfer.';
      } else {
        $normalizedPrice = str_replace([' ', ','], ['', '.'], $priceRaw);
        if (!is_numeric($normalizedPrice)) {
          $message = 'Inserisci un Prezzo valido.';
        } else {
          $priceValue = (float)$normalizedPrice;
          if ($priceValue < 0) {
            $message = 'Il Prezzo non può essere negativo.';
          } else {
            $price = number_format($priceValue, 2, '.', '');
          }
        }
      }
    }

    if (!$message && (!$room || !$name)) {
      $message = 'Compila camera e nominativo.';
    }

    $place = null;
    $dateTime = null;
    $pickupDb = null;
    $flightNumber = null;
    $trainNumber = null;
    $arrivalPlace = null;
    $arrivalDateTime = null;
    $arrivalPickupDb = null;
    $arrivalFlightNumber = null;
    $arrivalTrainNumber = null;
    $departurePlace = null;
    $departureDateTime = null;
    $departurePickupDb = null;
    $departureFlightNumber = null;
    $departureTrainNumber = null;

    if (!$message && $type === 'arrivo_partenza') {
      $arrivalPlace = in_array($form['arrival_place'], $places, true) ? $form['arrival_place'] : $places[0];
      $departurePlace = in_array($form['departure_place'], $places, true) ? $form['departure_place'] : $places[0];
      $arrivalDateTime = transfer_external_required_datetime($form['arrival_date'], $form['arrival_time']);
      $departureDateTime = transfer_external_required_datetime($form['departure_date'], $form['departure_time']);
      $arrivalPickupDb = transfer_external_optional_time($form['arrival_pickup_time']);
      $departurePickupDb = transfer_external_optional_time($form['departure_pickup_time']);
      $arrivalFlightNumber = transfer_external_is_airport($arrivalPlace) ? transfer_external_clean_reference($form['arrival_flight_number']) : null;
      $arrivalTrainNumber = transfer_external_is_station($arrivalPlace) ? transfer_external_clean_reference($form['arrival_train_number']) : null;
      $departureFlightNumber = transfer_external_is_airport($departurePlace) ? transfer_external_clean_reference($form['departure_flight_number']) : null;
      $departureTrainNumber = transfer_external_is_station($departurePlace) ? transfer_external_clean_reference($form['departure_train_number']) : null;

      if (!$arrivalDateTime || !$departureDateTime) {
        $message = 'Data/ora di arrivo e partenza non valide.';
      } else {
        $place = 'Arrivo: ' . $arrivalPlace . ' / Partenza: ' . $departurePlace;
        $dateTime = $arrivalDateTime;
        $pickupDb = $arrivalPickupDb;
      }
    } elseif (!$message) {
      $place = in_array($form['place'], $places, true) ? $form['place'] : $places[0];
      $dateTime = transfer_external_required_datetime($form['date'], $form['time']);
      $pickupDb = transfer_external_optional_time($form['pickup_time']);
      $flightNumber = transfer_external_is_airport($place) ? transfer_external_clean_reference($form['flight_number']) : null;
      $trainNumber = transfer_external_is_station($place) ? transfer_external_clean_reference($form['train_number']) : null;
      if (!$dateTime) {
        $message = 'Data/ora non valida.';
      }
    }

    if (!$message) {
      try {
        $sql = 'INSERT INTO transfers_external
                  (type, place, date_time, pickup_time, room_number, guest_name, people_count, price_eur, booked, paid, service_company, supplier_name, flight_number, train_number, arrival_place, arrival_date_time, arrival_pickup_time, arrival_flight_number, arrival_train_number, departure_place, departure_date_time, departure_pickup_time, departure_flight_number, departure_train_number, created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
          $type,
          $place,
          $dateTime?->format('Y-m-d H:i:s'),
          $pickupDb,
          $room,
          $name,
          $people,
          $price,
          $booked,
          $paid,
          $serviceCompany,
          $supplierName,
          $flightNumber,
          $trainNumber,
          $arrivalPlace,
          $arrivalDateTime?->format('Y-m-d H:i:s'),
          $arrivalPickupDb,
          $arrivalFlightNumber,
          $arrivalTrainNumber,
          $departurePlace,
          $departureDateTime?->format('Y-m-d H:i:s'),
          $departurePickupDb,
          $departureFlightNumber,
          $departureTrainNumber,
          $user['id'],
        ]);
        header('Location: ' . $base . '/transfers_external.php');
        exit;
      } catch (PDOException $e) {
        error_log('External transfer create failed: ' . $e->getMessage());
        $message = 'Errore durante la creazione del transfer.';
      }
    }
  }
}

$title = 'Nuovo Transfer Esterno';
$isRoundTripSelected = $form['type'] === 'arrivo_partenza';
include __DIR__ . '/partials/header.php';
?>
<div class="row justify-content-center">
  <div class="col-12 col-lg-8 col-xl-7">
    <div class="card shadow-sm">
      <div class="card-body">
        <h1 class="h5 mb-3">Nuovo Transfer Esterno</h1>
        <?php if($message): ?><div class="alert alert-info"><?= e($message) ?></div><?php endif; ?>
        <form method="post" id="extForm">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <div class="row g-3">

            <div class="col-md-3">
              <label class="form-label">Tipo</label>
              <select name="type" id="transfer_type" class="form-select">
                <option value="arrivo" <?= $form['type'] === 'arrivo' ? 'selected' : '' ?>>Arrivo</option>
                <option value="partenza" <?= $form['type'] === 'partenza' ? 'selected' : '' ?>>Partenza</option>
                <option value="arrivo_partenza" <?= $form['type'] === 'arrivo_partenza' ? 'selected' : '' ?>>Arrivo e Partenza</option>
              </select>
            </div>

            <div class="col-12<?= $isRoundTripSelected ? ' d-none' : '' ?>" id="singleTransferFields"<?= $isRoundTripSelected ? ' hidden' : '' ?>>
              <div class="row g-3">
                <div class="col-md-9">
                  <label class="form-label">Luogo Arrivo/Partenza</label>
                  <select name="place" id="place" class="form-select" data-travel-place>
                    <?php foreach($places as $p): ?>
                      <option value="<?= e($p) ?>" <?= $form['place'] === $p ? 'selected' : '' ?>><?= e($p) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-4">
                  <label class="form-label">Data Arrivo/Partenza</label>
                  <input type="date" name="date" class="form-control" value="<?= e($form['date']) ?>" data-single-required>
                </div>
                <div class="col-md-4">
                  <label class="form-label">Ora Arrivo/Partenza</label>
                  <input type="time" name="time" class="form-control" value="<?= e($form['time']) ?>" data-single-required>
                </div>
                <div class="col-md-4">
                  <label class="form-label">Orario Pickup (opz.)</label>
                  <input type="time" name="pickup_time" class="form-control" value="<?= e($form['pickup_time']) ?>">
                </div>
                <div class="col-md-6 travel-ref flight-ref d-none">
                  <label class="form-label">Numero Volo</label>
                  <input type="text" name="flight_number" class="form-control" maxlength="80" value="<?= e($form['flight_number']) ?>">
                </div>
                <div class="col-md-6 travel-ref train-ref d-none">
                  <label class="form-label">Numero Treno</label>
                  <input type="text" name="train_number" class="form-control" maxlength="80" value="<?= e($form['train_number']) ?>">
                </div>
              </div>
            </div>

            <div class="col-12<?= $isRoundTripSelected ? '' : ' d-none' ?>" id="roundTripTransferFields"<?= $isRoundTripSelected ? '' : ' hidden' ?>>
              <div class="row g-3">
                <div class="col-12"><h2 class="h6 mb-0">Arrivo</h2></div>
                <div class="col-md-6">
                  <label class="form-label">Luogo Arrivo</label>
                  <select name="arrival_place" id="arrival_place" class="form-select" data-travel-place>
                    <?php foreach($places as $p): ?>
                      <option value="<?= e($p) ?>" <?= $form['arrival_place'] === $p ? 'selected' : '' ?>><?= e($p) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-3">
                  <label class="form-label">Data Arrivo</label>
                  <input type="date" name="arrival_date" class="form-control" value="<?= e($form['arrival_date']) ?>" data-roundtrip-required>
                </div>
                <div class="col-md-3">
                  <label class="form-label">Ora Arrivo</label>
                  <input type="time" name="arrival_time" class="form-control" value="<?= e($form['arrival_time']) ?>" data-roundtrip-required>
                </div>
                <div class="col-md-4">
                  <label class="form-label">Orario Pickup Arrivo (opz.)</label>
                  <input type="time" name="arrival_pickup_time" class="form-control" value="<?= e($form['arrival_pickup_time']) ?>">
                </div>
                <div class="col-md-4 travel-ref flight-ref d-none">
                  <label class="form-label">Numero Volo Arrivo</label>
                  <input type="text" name="arrival_flight_number" class="form-control" maxlength="80" value="<?= e($form['arrival_flight_number']) ?>">
                </div>
                <div class="col-md-4 travel-ref train-ref d-none">
                  <label class="form-label">Numero Treno Arrivo</label>
                  <input type="text" name="arrival_train_number" class="form-control" maxlength="80" value="<?= e($form['arrival_train_number']) ?>">
                </div>

                <div class="col-12"><hr><h2 class="h6 mb-0">Partenza</h2></div>
                <div class="col-md-6">
                  <label class="form-label">Luogo Partenza</label>
                  <select name="departure_place" id="departure_place" class="form-select" data-travel-place>
                    <?php foreach($places as $p): ?>
                      <option value="<?= e($p) ?>" <?= $form['departure_place'] === $p ? 'selected' : '' ?>><?= e($p) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-3">
                  <label class="form-label">Data Partenza</label>
                  <input type="date" name="departure_date" class="form-control" value="<?= e($form['departure_date']) ?>" data-roundtrip-required>
                </div>
                <div class="col-md-3">
                  <label class="form-label">Ora Partenza</label>
                  <input type="time" name="departure_time" class="form-control" value="<?= e($form['departure_time']) ?>" data-roundtrip-required>
                </div>
                <div class="col-md-4">
                  <label class="form-label">Orario Pickup Partenza (opz.)</label>
                  <input type="time" name="departure_pickup_time" class="form-control" value="<?= e($form['departure_pickup_time']) ?>">
                </div>
                <div class="col-md-4 travel-ref flight-ref d-none">
                  <label class="form-label">Numero Volo Partenza</label>
                  <input type="text" name="departure_flight_number" class="form-control" maxlength="80" value="<?= e($form['departure_flight_number']) ?>">
                </div>
                <div class="col-md-4 travel-ref train-ref d-none">
                  <label class="form-label">Numero Treno Partenza</label>
                  <input type="text" name="departure_train_number" class="form-control" maxlength="80" value="<?= e($form['departure_train_number']) ?>">
                </div>
              </div>
            </div>

            <div class="col-md-4">
              <label class="form-label">Camera</label>
              <input type="text" name="room_number" class="form-control" value="<?= e($form['room_number']) ?>" required>
            </div>
            <div class="col-md-4">
              <label class="form-label">Nominativo</label>
              <input type="text" name="guest_name" class="form-control" value="<?= e($form['guest_name']) ?>" required>
            </div>

            <div class="col-md-4">
              <label class="form-label">Numero Persone</label>
              <input type="number" name="people_count" class="form-control" min="1" step="1" value="<?= e($form['people_count']) ?>" required>
            </div>

            <div class="col-md-4">
              <label class="form-label">Prezzo</label>
              <div class="input-group">
                <span class="input-group-text">€</span>
                <input type="number" name="price_eur" class="form-control" min="0" step="0.01" value="<?= e($form['price_eur']) ?>" required>
              </div>
            </div>

            <div class="col-md-4">
              <label class="form-label">Fornitore</label>
              <select name="supplier_name" class="form-select" required>
                <?php foreach ($suppliers as $supplier): ?>
                  <option value="<?= e($supplier) ?>" <?= $form['supplier_name'] === $supplier ? 'selected' : '' ?>><?= e($supplier) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-md-4 d-flex align-items-center">
              <div class="row w-100">
                <div class="col-6">
                  <div class="form-check mt-4">
                    <input class="form-check-input" type="checkbox" name="booked" id="booked" <?= $form['booked'] ? 'checked' : '' ?>>
                    <label class="form-check-label" for="booked">Prenotato</label>
                  </div>
                </div>
                <div class="col-6">
                  <div class="form-check mt-4">
                    <input class="form-check-input" type="checkbox" name="paid" id="paid" <?= $form['paid'] ? 'checked' : '' ?>>
                    <label class="form-check-label" for="paid">Pagato</label>
                  </div>
                </div>
              </div>
            </div>

            <!-- Compagnia: visibile/obbligatoria solo se "Prenotato" -->
            <div class="col-md-8" id="companyWrap" style="display:none;">
              <label class="form-label">Compagnia del Servizio</label>
              <input type="text" name="service_company" id="service_company" class="form-control" value="<?= e($form['service_company']) ?>" placeholder="Es. NCC Rossi S.r.l.">
            </div>

          </div>

          <div class="mt-3 d-flex gap-2">
            <button class="btn btn-primary">Crea transfer</button>
            <a class="btn btn-outline-secondary" href="<?= e($base) ?>/transfers_external.php">Annulla</a>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<?php $transferExternalCreateJsVersion = @filemtime(__DIR__ . '/assets/transfer_external_create.js') ?: time(); ?>
<script src="<?= e($base) ?>/assets/transfer_external_create.js?v=<?= (int)$transferExternalCreateJsVersion ?>" defer></script>
<?php include __DIR__ . '/partials/footer.php'; ?>
