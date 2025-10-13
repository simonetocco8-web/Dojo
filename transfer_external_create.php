<?php
// transfer_external_create.php — crea transfer esterno (Prenotato => Compagnia, Pickup opzionale)
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/security.php';
require_once __DIR__ . '/core/db.php';

start_session();
$env  = require __DIR__ . '/config/env.php';
$base = rtrim($env['app']['base_url'] ?? '', '/');
$pdo  = db();
$user = current_user();
if (!$user) { header('Location: ' . $base . '/index.php?msg=auth'); exit; }

// Opzioni luogo arrivo/partenza
$places = [
  'Aeroporto Lamezia Terme',
  'Aeroporto Reggio Calabria',
  'Stazione Lamezia Terme',
  'Stazione Rosarno'
];

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_check($_POST['csrf'] ?? '')) {
    $message = 'Token CSRF non valido.';
  } else {
    $type   = $_POST['type'] ?? 'arrivo';
    $place  = $_POST['place'] ?? $places[0];
    $date   = $_POST['date'] ?? '';
    $time   = $_POST['time'] ?? '';
    $pickup = $_POST['pickup_time'] ?? ''; // opzionale
    $room   = trim($_POST['room_number'] ?? '');
    $name   = trim($_POST['guest_name'] ?? '');
    $people_raw = trim((string)($_POST['people_count'] ?? ''));
    $price_raw  = trim((string)($_POST['price_eur'] ?? ''));
    $booked = isset($_POST['booked']) ? 1 : 0;
    $paid   = isset($_POST['paid']) ? 1 : 0;

    // Normalizza campi enumerati
    if (!in_array($type, ['arrivo','partenza'], true)) $type = 'arrivo';
    if (!in_array($place, $places, true)) { $place = $places[0]; }

    // Compagnia solo se prenotato
    $service_company = null;
    if ($booked) {
      $service_company = trim($_POST['service_company'] ?? '');
      if ($service_company === '') {
        $message = 'Inserisci la Compagnia del Servizio per i transfer prenotati.';
      }
    }

    // Pickup opzionale: salva NULL se vuoto
    $pickup_db = ($pickup === '' ? null : $pickup);

    // Validazione minima (pickup escluso)
    $people = null;
    if ($people_raw === '' || ($people = filter_var($people_raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) === false) {
      $message = 'Inserisci un Numero Persone valido (maggiore o uguale a 1).';
    }

    $price = null;
    if (!$message) {
      if ($price_raw === '') {
        $message = 'Inserisci il Prezzo del transfer.';
      } else {
        $normalized_price = str_replace([' ', ','], ['', '.'], $price_raw);
        if (!is_numeric($normalized_price)) {
          $message = 'Inserisci un Prezzo valido.';
        } else {
          $price_value = (float)$normalized_price;
          if ($price_value < 0) {
            $message = 'Il Prezzo non può essere negativo.';
          } else {
            $price = number_format($price_value, 2, '.', '');
          }
        }
      }
    }

    if (!$message && $date && $time && $room && $name) {
      $dt = DateTime::createFromFormat('Y-m-d H:i', $date . ' ' . $time);
      if (!$dt) {
        $message = 'Data/ora non valida.';
      } else {
        try {
          $sql = 'INSERT INTO transfers_external
                    (type, place, date_time, pickup_time, room_number, guest_name, people_count, price_eur, booked, paid, service_company, created_by)
                  VALUES (?,?,?,?,?,?,?,?,?,?,?,?)';
          $stmt = $pdo->prepare($sql);
          $stmt->execute([
            $type,
            $place,
            $dt->format('Y-m-d H:i:s'),
            $pickup_db,
            $room,
            $name,
            $people,
            $price,
            $booked,
            $paid,
            $service_company,
            $user['id']
          ]);
          header('Location: ' . $base . '/transfers_external.php');
          exit;
        } catch (PDOException $e) {
          $message = 'Errore durante la creazione del transfer.';
        }
      }
    } elseif (!$message) {
      $message = 'Compila i campi obbligatori (eccetto Pickup).';
    }
  }
}

$title = 'Nuovo Transfer Esterno';
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
              <select name="type" class="form-select">
                <option value="arrivo">Arrivo</option>
                <option value="partenza">Partenza</option>
              </select>
            </div>

            <div class="col-md-9">
              <label class="form-label">Luogo Arrivo/Partenza</label>
              <select name="place" class="form-select">
                <?php foreach($places as $p): ?>
                  <option value="<?= e($p) ?>"><?= e($p) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-md-4">
              <label class="form-label">Data Arrivo/Partenza</label>
              <input type="date" name="date" class="form-control" required>
            </div>
            <div class="col-md-4">
              <label class="form-label">Ora Arrivo/Partenza</label>
              <input type="time" name="time" class="form-control" required>
            </div>
            <div class="col-md-4">
              <label class="form-label">Orario Pickup (opz.)</label>
              <input type="time" name="pickup_time" class="form-control">
            </div>

            <div class="col-md-4">
              <label class="form-label">Camera</label>
              <input type="text" name="room_number" class="form-control" required>
            </div>
            <div class="col-md-4">
              <label class="form-label">Nominativo</label>
              <input type="text" name="guest_name" class="form-control" required>
            </div>

            <div class="col-md-4">
              <label class="form-label">Numero Persone</label>
              <input type="number" name="people_count" class="form-control" min="1" step="1" required>
            </div>

            <div class="col-md-4">
              <label class="form-label">Prezzo</label>
              <div class="input-group">
                <span class="input-group-text">€</span>
                <input type="number" name="price_eur" class="form-control" min="0" step="0.01" required>
              </div>
            </div>

            <div class="col-md-4 d-flex align-items-center">
              <div class="row w-100">
                <div class="col-6">
                  <div class="form-check mt-4">
                    <input class="form-check-input" type="checkbox" name="booked" id="booked">
                    <label class="form-check-label" for="booked">Prenotato</label>
                  </div>
                </div>
                <div class="col-6">
                  <div class="form-check mt-4">
                    <input class="form-check-input" type="checkbox" name="paid" id="paid">
                    <label class="form-check-label" for="paid">Pagato</label>
                  </div>
                </div>
              </div>
            </div>

            <!-- Compagnia: visibile/obbligatoria solo se "Prenotato" -->
            <div class="col-md-8" id="companyWrap" style="display:none;">
              <label class="form-label">Compagnia del Servizio</label>
              <input type="text" name="service_company" id="service_company" class="form-control" placeholder="Es. NCC Rossi S.r.l.">
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

<script src="/assets/transfer_external_create.js"></script>

<!--
<script>
(function(){
  const booked = document.getElementById('booked');
  const wrap = document.getElementById('companyWrap');
  const input = document.getElementById('service_company');

  function syncCompanyField(){
    if (booked.checked) {
      wrap.style.display = '';
      input.required = true;
      input.disabled = false;
    } else {
      wrap.style.display = 'none';
      input.required = false;
      input.disabled = true;
      input.value = '';
    }
  }

  booked.addEventListener('change', syncCompanyField);
  syncCompanyField();
})();
</script> -->

<?php include __DIR__ . '/partials/footer.php'; ?>
