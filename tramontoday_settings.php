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

$canEdit = user_is_reception_or_amministrazione($user);
$pdo = db();
ensure_system_settings_table($pdo);

$settingFields = [
  'adult_full_day_price' => [
    'key' => 'tramontoday_adult_full_day_price',
    'label' => 'Prezzo adulto giornata intera',
    'type' => 'money',
    'placeholder' => 'Es. 35.00',
  ],
  'child_full_day_price' => [
    'key' => 'tramontoday_child_full_day_price',
    'label' => 'Prezzo bambino giornata intera',
    'type' => 'money',
    'placeholder' => 'Es. 20.00',
  ],
  'adult_half_day_price' => [
    'key' => 'tramontoday_adult_half_day_price',
    'label' => 'Prezzo adulto mezza giornata',
    'type' => 'money',
    'placeholder' => 'Es. 25.00',
  ],
  'child_half_day_price' => [
    'key' => 'tramontoday_child_half_day_price',
    'label' => 'Prezzo bambino mezza giornata',
    'type' => 'money',
    'placeholder' => 'Es. 15.00',
  ],
  'extra_sunbed_price' => [
    'key' => 'tramontoday_extra_sunbed_price',
    'label' => 'Prezzo sdraio aggiuntiva',
    'type' => 'money',
    'placeholder' => 'Es. 8.00',
  ],
  'children_age_range' => [
    'key' => 'tramontoday_children_age_range',
    'label' => 'Fascia di età bambini',
    'type' => 'text',
    'placeholder' => 'Es. 4-12 anni',
  ],
  'full_day_hours' => [
    'key' => 'tramontoday_full_day_hours',
    'label' => 'Orari formula giornata intera',
    'type' => 'text',
    'placeholder' => 'Es. 09:00-18:00',
  ],
  'half_day_hours' => [
    'key' => 'tramontoday_half_day_hours',
    'label' => 'Orari formula mezza giornata',
    'type' => 'text',
    'placeholder' => 'Es. 09:00-13:00 / 14:00-18:00',
  ],
  'included_services_description' => [
    'key' => 'tramontoday_included_services_description',
    'label' => 'Descrizione dei servizi inclusi',
    'type' => 'textarea',
    'placeholder' => 'Es. Postazione, accesso spiaggia, doccia, servizi...',
  ],
  'people_per_station' => [
    'key' => 'tramontoday_people_per_station',
    'label' => 'Numero massimo o consigliato di persone per postazione',
    'type' => 'integer',
    'placeholder' => 'Es. 4',
  ],
  'max_booking_advance_days' => [
    'key' => 'tramontoday_max_booking_advance_days',
    'label' => 'Anticipo massimo di prenotazione (giorni)',
    'type' => 'integer',
    'placeholder' => 'Es. 30',
  ],
];

$keys = array_column($settingFields, 'key');
$storedSettings = get_settings($keys, $pdo);
$values = [];
foreach ($settingFields as $name => $field) {
  $values[$name] = (string)($storedSettings[$field['key']] ?? '');
}

$errors = [];
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!$canEdit) {
    http_response_code(403);
    echo 'Accesso negato';
    exit;
  }

  if (!csrf_check($_POST['csrf_token'] ?? '')) {
    $errors[] = 'Token CSRF non valido, ricarica la pagina e riprova.';
  }

  foreach ($settingFields as $name => $field) {
    $rawValue = trim((string)($_POST[$name] ?? ''));

    if ($field['type'] === 'money') {
      $normalized = str_replace(',', '.', $rawValue);
      if ($normalized !== '' && (!is_numeric($normalized) || (float)$normalized < 0)) {
        $errors[] = $field['label'] . ': inserisci un importo valido maggiore o uguale a zero.';
      }
      $values[$name] = $normalized === '' ? '' : number_format((float)$normalized, 2, '.', '');
    } elseif ($field['type'] === 'integer') {
      if ($rawValue !== '' && (!ctype_digit($rawValue) || (int)$rawValue < 0)) {
        $errors[] = $field['label'] . ': inserisci un numero intero maggiore o uguale a zero.';
      }
      $values[$name] = $rawValue;
    } else {
      $values[$name] = $rawValue;
    }
  }

  if (!$errors) {
    foreach ($settingFields as $name => $field) {
      set_setting($field['key'], $values[$name] === '' ? null : $values[$name], $pdo);
    }
    $message = 'Impostazioni TramontoDay salvate correttamente.';
  }
}

$title = 'Tariffe e impostazioni TramontoDay';
include __DIR__ . '/partials/header.php';
?>
<div class="row justify-content-center">
  <div class="col-12 col-xl-10">
    <div class="card shadow-sm mb-3">
      <div class="card-body">
        <div class="d-flex flex-column flex-md-row justify-content-between gap-2 align-items-md-start">
          <div>
            <h1 class="h4 mb-2"><i class="bi bi-sun me-1"></i>Tariffe e impostazioni TramontoDay</h1>
            <p class="text-muted mb-0">Configura prezzi, formule orarie, servizi inclusi e limiti operativi del modulo TramontoDay.</p>
          </div>
          <?php if (!$canEdit): ?>
            <span class="badge bg-info text-dark align-self-start">Solo visualizzazione</span>
          <?php endif; ?>
        </div>
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

    <form method="post" novalidate>
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <div class="card shadow-sm mb-3">
        <div class="card-body">
          <h2 class="h5 mb-3">Tariffe</h2>
          <div class="row g-3">
            <?php foreach (['adult_full_day_price', 'child_full_day_price', 'adult_half_day_price', 'child_half_day_price', 'extra_sunbed_price'] as $name): ?>
              <?php $field = $settingFields[$name]; ?>
              <div class="col-12 col-md-6 col-xl-4">
                <label for="<?= e($name) ?>" class="form-label"><?= e($field['label']) ?></label>
                <div class="input-group">
                  <span class="input-group-text">€</span>
                  <input type="number" min="0" step="0.01" class="form-control" id="<?= e($name) ?>" name="<?= e($name) ?>" value="<?= e($values[$name]) ?>" placeholder="<?= e($field['placeholder']) ?>" <?= $canEdit ? '' : 'disabled' ?>>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <div class="card shadow-sm mb-3">
        <div class="card-body">
          <h2 class="h5 mb-3">Regole formule</h2>
          <div class="row g-3">
            <?php foreach (['children_age_range', 'full_day_hours', 'half_day_hours', 'people_per_station', 'max_booking_advance_days'] as $name): ?>
              <?php $field = $settingFields[$name]; ?>
              <div class="col-12 col-md-6">
                <label for="<?= e($name) ?>" class="form-label"><?= e($field['label']) ?></label>
                <input type="<?= $field['type'] === 'integer' ? 'number' : 'text' ?>" <?= $field['type'] === 'integer' ? 'min="0" step="1"' : '' ?> class="form-control" id="<?= e($name) ?>" name="<?= e($name) ?>" value="<?= e($values[$name]) ?>" placeholder="<?= e($field['placeholder']) ?>" <?= $canEdit ? '' : 'disabled' ?>>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <div class="card shadow-sm mb-3">
        <div class="card-body">
          <?php $field = $settingFields['included_services_description']; ?>
          <label for="included_services_description" class="form-label h5"><?= e($field['label']) ?></label>
          <textarea class="form-control" id="included_services_description" name="included_services_description" rows="5" placeholder="<?= e($field['placeholder']) ?>" <?= $canEdit ? '' : 'disabled' ?>><?= e($values['included_services_description']) ?></textarea>
        </div>
      </div>

      <?php if ($canEdit): ?>
        <div class="d-flex justify-content-end gap-2 mb-4">
          <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Salva impostazioni</button>
        </div>
      <?php endif; ?>
    </form>
  </div>
</div>
<?php include __DIR__ . '/partials/footer.php'; ?>
