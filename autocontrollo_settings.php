<?php

require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/security.php';
require_once __DIR__ . '/core/roles.php';
require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/autocontrollo_schema.php';
require_once __DIR__ . '/core/autocontrollo_settings.php';
require_once __DIR__ . '/core/autocontrollo_vehicles.php';

require_login();
$user = current_user();
if (!$user || !user_has_department($user, 'Amministrazione')) {
    http_response_code(403);
    exit('Accesso negato.');
}

$env = require __DIR__ . '/config/env.php';
$base = rtrim($env['app']['base_url'] ?? '', '/');
$pdo = db();
ensure_autocontrollo_electrical_panels_table($pdo);
ensure_autocontrollo_pool_products_table($pdo);
ensure_autocontrollo_rodent_traps_table($pdo);
ensure_autocontrollo_grounding_rods_table($pdo);
ensure_autocontrollo_refrigerators_table($pdo);
ensure_autocontrollo_fire_extinguishers_table($pdo);
ensure_autocontrollo_vehicles_table($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check((string)($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Token CSRF non valido.');
    }
    $action = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    $location = trim((string)($_POST['installation_location'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $trapLocation = trim((string)($_POST['trap_location'] ?? ''));
    $groundingRodLocation = trim((string)($_POST['grounding_rod_location'] ?? ''));
    try {
        if ($action === 'vehicle_create' || $action === 'vehicle_update') {
            autocontrollo_vehicle_save($pdo, $action === 'vehicle_create' ? null : $id, (string)($_POST['vehicle_brand'] ?? ''), (string)($_POST['vehicle_model'] ?? ''), (string)($_POST['vehicle_plate'] ?? ''));
            $message = $action === 'vehicle_create' ? 'created' : 'updated';
        } elseif ($action === 'vehicle_delete') {
            autocontrollo_vehicle_delete($pdo, $id);
            $message = 'deleted';
        } elseif ($action === 'responsible_update') {
            autocontrollo_save_responsible($pdo, (string)($_POST['procedure'] ?? ''), (string)($_POST['responsible_user_id'] ?? ''));
            $message = 'updated';
        } elseif ($action === 'panel_create' || $action === 'panel_update') {
            if ($location === '') throw new InvalidArgumentException('Il luogo di installazione è obbligatorio.');
            if ((function_exists('mb_strlen') ? mb_strlen($location, 'UTF-8') : strlen($location)) > 190) {
                throw new InvalidArgumentException('Il luogo di installazione può contenere al massimo 190 caratteri.');
            }
            if ($action === 'panel_create') {
                $stmt = $pdo->prepare('INSERT INTO autocontrollo_electrical_panels (installation_location) VALUES (?)');
                $stmt->execute([$location]);
                $message = 'created';
            } else {
                if ($id <= 0) throw new InvalidArgumentException('ID quadro elettrico non valido.');
                $stmt = $pdo->prepare('UPDATE autocontrollo_electrical_panels SET installation_location = ? WHERE id = ?');
                $stmt->execute([$location, $id]);
                $message = 'updated';
            }
        } elseif ($action === 'panel_delete') {
            if ($id <= 0) throw new InvalidArgumentException('ID quadro elettrico non valido.');
            $stmt = $pdo->prepare('DELETE FROM autocontrollo_electrical_panels WHERE id = ?');
            $stmt->execute([$id]);
            $message = 'deleted';
        } elseif ($action === 'pool_product_create' || $action === 'pool_product_update') {
            if ($description === '') throw new InvalidArgumentException('La descrizione del prodotto piscina è obbligatoria.');
            if ((function_exists('mb_strlen') ? mb_strlen($description, 'UTF-8') : strlen($description)) > 255) {
                throw new InvalidArgumentException('La descrizione può contenere al massimo 255 caratteri.');
            }
            if ($action === 'pool_product_create') {
                $stmt = $pdo->prepare('INSERT INTO autocontrollo_pool_products (description) VALUES (?)');
                $stmt->execute([$description]);
                $message = 'created';
            } else {
                if ($id <= 0) throw new InvalidArgumentException('ID prodotto piscina non valido.');
                $stmt = $pdo->prepare('UPDATE autocontrollo_pool_products SET description = ? WHERE id = ?');
                $stmt->execute([$description, $id]);
                $message = 'updated';
            }
        } elseif ($action === 'pool_product_delete') {
            if ($id <= 0) throw new InvalidArgumentException('ID prodotto piscina non valido.');
            $stmt = $pdo->prepare('DELETE FROM autocontrollo_pool_products WHERE id = ?');
            $stmt->execute([$id]);
            $message = 'deleted';
        } elseif ($action === 'rodent_trap_create' || $action === 'rodent_trap_update') {
            if ($trapLocation === '') throw new InvalidArgumentException('La location della trappola è obbligatoria.');
            if ((function_exists('mb_strlen') ? mb_strlen($trapLocation, 'UTF-8') : strlen($trapLocation)) > 190) {
                throw new InvalidArgumentException('La location può contenere al massimo 190 caratteri.');
            }
            if ($action === 'rodent_trap_create') {
                $legacyIdentifierLength = autocontrollo_rodent_traps_legacy_identifier_length($pdo);
                if ($legacyIdentifierLength > 0) {
                    // Fallback per installazioni sulle quali la migrazione della
                    // vecchia colonna univoca non è ancora stata applicata.
                    $legacyIdentifier = substr('TRAP-' . bin2hex(random_bytes(12)), 0, $legacyIdentifierLength);
                    $stmt = $pdo->prepare('INSERT INTO autocontrollo_rodent_traps (identifier, location) VALUES (?, ?)');
                    $stmt->execute([$legacyIdentifier, $trapLocation]);
                } else {
                    $stmt = $pdo->prepare('INSERT INTO autocontrollo_rodent_traps (location) VALUES (?)');
                    $stmt->execute([$trapLocation]);
                }
                $message = 'created';
            } else {
                if ($id <= 0) throw new InvalidArgumentException('ID trappola non valido.');
                $stmt = $pdo->prepare('UPDATE autocontrollo_rodent_traps SET location = ? WHERE id = ?');
                $stmt->execute([$trapLocation, $id]);
                $message = 'updated';
            }
        } elseif ($action === 'rodent_trap_delete') {
            if ($id <= 0) throw new InvalidArgumentException('ID trappola non valido.');
            $stmt = $pdo->prepare('DELETE FROM autocontrollo_rodent_traps WHERE id = ?');
            $stmt->execute([$id]);
            $message = 'deleted';
        } elseif ($action === 'grounding_rod_create' || $action === 'grounding_rod_update') {
            if ($groundingRodLocation === '') throw new InvalidArgumentException('La location della palina è obbligatoria.');
            if ((function_exists('mb_strlen') ? mb_strlen($groundingRodLocation, 'UTF-8') : strlen($groundingRodLocation)) > 190) {
                throw new InvalidArgumentException('La location può contenere al massimo 190 caratteri.');
            }
            if ($action === 'grounding_rod_create') {
                $stmt = $pdo->prepare('INSERT INTO autocontrollo_grounding_rods (location) VALUES (?)');
                $stmt->execute([$groundingRodLocation]);
                $message = 'created';
            } else {
                if ($id <= 0) throw new InvalidArgumentException('ID palina non valido.');
                $stmt = $pdo->prepare('UPDATE autocontrollo_grounding_rods SET location = ? WHERE id = ?');
                $stmt->execute([$groundingRodLocation, $id]);
                $message = 'updated';
            }
        } elseif ($action === 'grounding_rod_delete') {
            if ($id <= 0) throw new InvalidArgumentException('ID palina non valido.');
            $stmt = $pdo->prepare('DELETE FROM autocontrollo_grounding_rods WHERE id = ?');
            $stmt->execute([$id]);
            $message = 'deleted';
        } else {
            throw new InvalidArgumentException('Azione non valida.');
        }
        header('Location: ' . $base . '/autocontrollo_settings.php?msg=' . $message);
        exit;
    } catch (Throwable $exception) {
        header('Location: ' . $base . '/autocontrollo_settings.php?msg=error&detail=' . urlencode($exception->getMessage()));
        exit;
    }
}

$panels = $pdo->query('SELECT id, installation_location FROM autocontrollo_electrical_panels ORDER BY installation_location, id')->fetchAll(PDO::FETCH_ASSOC);
$poolProducts = $pdo->query('SELECT id, description FROM autocontrollo_pool_products ORDER BY description, id')->fetchAll(PDO::FETCH_ASSOC);
$rodentTraps = $pdo->query('SELECT id, location FROM autocontrollo_rodent_traps ORDER BY location, id')->fetchAll(PDO::FETCH_ASSOC);
$groundingRods = $pdo->query('SELECT id, location FROM autocontrollo_grounding_rods ORDER BY location, id')->fetchAll(PDO::FETCH_ASSOC);
$refrigerators = $pdo->query('SELECT id, appliance_type, operating_temperature FROM autocontrollo_refrigerators ORDER BY appliance_type, id')->fetchAll(PDO::FETCH_ASSOC);
$fireExtinguishers = $pdo->query('SELECT id, extinguisher_type, capacity_kg FROM autocontrollo_fire_extinguishers ORDER BY extinguisher_type, id')->fetchAll(PDO::FETCH_ASSOC);
$activeUsers = $pdo->query('SELECT id, nome, cognome, email FROM users WHERE is_active=1 AND deleted_at IS NULL ORDER BY cognome, nome, email, id')->fetchAll(PDO::FETCH_ASSOC);
$responsibleSettings = get_settings(array_map(static fn($procedure) => 'autocontrollo_responsible_' . $procedure, array_keys(autocontrollo_responsibility_procedures())), $pdo);
$vehicles = $pdo->query('SELECT id,brand,model,license_plate FROM autocontrollo_vehicles ORDER BY brand,model,id')->fetchAll(PDO::FETCH_ASSOC);
$message = (string)($_GET['msg'] ?? '');
$title = 'Setting Autocontrollo';
include __DIR__ . '/partials/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <div><div class="text-muted small text-uppercase fw-semibold">Autocontrollo</div><h1 class="h4 mb-0">Setting</h1></div>
</div>

<?php if (in_array($message, ['created', 'updated', 'deleted'], true)): ?>
  <div class="alert alert-success">Operazione completata correttamente.</div>
<?php elseif ($message === 'error'): ?>
  <div class="alert alert-danger"><?= e($_GET['detail'] ?? 'Operazione non completata.') ?></div>
<?php endif; ?>

<nav class="card shadow-sm mb-4" aria-label="Sezioni setting Autocontrollo"><div class="card-body"><div class="row g-2">
  <?php foreach ([
    ['panels','lightning-charge','Quadri Elettrici'], ['pool-products','droplet-half','Prodotti Piscina'],
    ['rodent-traps','geo-alt','Trappole Roditori'], ['grounding-rods','plug','Paline Messa a Terra'],
    ['refrigerators','snow','Frigoriferi'], ['fire-extinguishers','fire','Estintori'],
    ['haccp-cleaning','clipboard-check','Pulizie HACCP'], ['vehicles','truck','Veicoli'],
  ] as [$anchor,$icon,$label]): ?>
  <div class="col-6 col-md-4 col-xl-2"><a class="btn btn-outline-primary w-100 h-100 py-3 d-flex flex-column justify-content-center align-items-center" href="#<?= e($anchor) ?>"><i class="bi bi-<?= e($icon) ?> fs-4 mb-1"></i><span><?= e($label) ?></span></a></div>
  <?php endforeach; ?>
</div></div></nav>

<div class="row g-4 align-items-start">
<section class="col-12 col-lg-6" id="panels" style="scroll-margin-top:1rem">
<div class="card shadow-sm h-100">
  <div class="card-body">
    <h2 class="h5 mb-3"><i class="bi bi-lightning-charge me-1"></i>Parametri Quadri Elettrici</h2>
    <form method="post" class="row g-2 align-items-end mb-4">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="panel_create">
      <div class="col-12 col-md"><label for="newPanelLocation" class="form-label">Luogo installazione</label><input class="form-control" id="newPanelLocation" name="installation_location" maxlength="190" required></div>
      <div class="col-12 col-md-auto"><button class="btn btn-primary"><i class="bi bi-plus-circle me-1"></i>Aggiungi quadro</button></div>
    </form>

    <?php if (!$panels): ?>
      <div class="text-muted">Nessun quadro elettrico configurato.</div>
    <?php else: ?>
      <div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th style="width:90px">ID</th><th>Luogo installazione</th><th class="text-end" style="width:210px">Azioni</th></tr></thead><tbody>
      <?php foreach ($panels as $panel): ?>
        <tr><td><code>#<?= (int)$panel['id'] ?></code></td><td colspan="2">
          <form method="post" class="d-flex flex-column flex-md-row gap-2">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int)$panel['id'] ?>">
            <input class="form-control" name="installation_location" maxlength="190" required value="<?= e($panel['installation_location']) ?>">
            <button class="btn btn-outline-primary text-nowrap" name="action" value="panel_update"><i class="bi bi-save me-1"></i>Salva</button>
            <button class="btn btn-outline-danger" name="action" value="panel_delete" formnovalidate onclick="return confirm('Eliminare questo quadro elettrico?')"><i class="bi bi-trash"></i></button>
          </form>
        </td></tr>
      <?php endforeach; ?>
      </tbody></table></div>
    <?php endif; ?>
  </div>
</div>

<div class="card shadow-sm mt-4">
  <div class="card-body">
    <h2 class="h5 mb-3"><i class="bi bi-droplet-half me-1"></i>Prodotti Piscina</h2>
    <form method="post" class="row g-2 align-items-end mb-4">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="pool_product_create">
      <div class="col-12 col-md"><label for="newPoolProductDescription" class="form-label">Descrizione</label><input class="form-control" id="newPoolProductDescription" name="description" maxlength="255" required></div>
      <div class="col-12 col-md-auto"><button class="btn btn-primary"><i class="bi bi-plus-circle me-1"></i>Aggiungi prodotto</button></div>
    </form>

    <?php if (!$poolProducts): ?>
      <div class="text-muted">Nessun prodotto piscina configurato.</div>
    <?php else: ?>
      <div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th style="width:90px">ID</th><th>Descrizione</th><th class="text-end" style="width:210px">Azioni</th></tr></thead><tbody>
      <?php foreach ($poolProducts as $product): ?>
        <tr><td><code>#<?= (int)$product['id'] ?></code></td><td colspan="2">
          <form method="post" class="d-flex flex-column flex-md-row gap-2">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int)$product['id'] ?>">
            <input class="form-control" name="description" maxlength="255" required value="<?= e($product['description']) ?>">
            <button class="btn btn-outline-primary text-nowrap" name="action" value="pool_product_update"><i class="bi bi-save me-1"></i>Salva</button>
            <button class="btn btn-outline-danger" name="action" value="pool_product_delete" formnovalidate onclick="return confirm('Eliminare questo prodotto piscina?')"><i class="bi bi-trash"></i></button>
          </form>
        </td></tr>
      <?php endforeach; ?>
      </tbody></table></div>
    <?php endif; ?>
  </div>
</div>

<div class="card shadow-sm mt-4">
  <div class="card-body">
    <h2 class="h5 mb-3"><i class="bi bi-geo-alt me-1"></i>Mappatura Trappole Roditori</h2>
    <form method="post" class="row g-2 align-items-end mb-4">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="rodent_trap_create">
      <div class="col-12 col-md"><label for="newRodentTrapLocation" class="form-label">Location</label><input class="form-control" id="newRodentTrapLocation" name="trap_location" maxlength="190" required></div>
      <div class="col-12 col-md-auto"><button class="btn btn-primary"><i class="bi bi-plus-circle me-1"></i>Aggiungi trappola</button></div>
    </form>

    <?php if (!$rodentTraps): ?>
      <div class="text-muted">Nessuna trappola per roditori configurata.</div>
    <?php else: ?>
      <div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th style="width:90px">ID</th><th>Location</th><th class="text-end" style="width:210px">Azioni</th></tr></thead><tbody>
      <?php foreach ($rodentTraps as $trap): ?>
        <tr><td><code>#<?= (int)$trap['id'] ?></code></td><td colspan="2">
          <form method="post" class="d-flex flex-column flex-md-row gap-2">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int)$trap['id'] ?>">
            <input class="form-control" name="trap_location" maxlength="190" required value="<?= e($trap['location']) ?>">
            <button class="btn btn-outline-primary text-nowrap" name="action" value="rodent_trap_update"><i class="bi bi-save me-1"></i>Salva</button>
            <button class="btn btn-outline-danger" name="action" value="rodent_trap_delete" formnovalidate onclick="return confirm('Eliminare questa trappola per roditori?')"><i class="bi bi-trash"></i></button>
          </form>
        </td></tr>
      <?php endforeach; ?>
      </tbody></table></div>
    <?php endif; ?>
  </div>
</div>

<div class="card shadow-sm mt-4">
  <div class="card-body">
    <h2 class="h5 mb-3"><i class="bi bi-plug me-1"></i>Mappatura Paline Messa a Terra</h2>
    <form method="post" class="row g-2 align-items-end mb-4">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="grounding_rod_create">
      <div class="col-12 col-md"><label for="newGroundingRodLocation" class="form-label">Location</label><input class="form-control" id="newGroundingRodLocation" name="grounding_rod_location" maxlength="190" required></div>
      <div class="col-12 col-md-auto"><button class="btn btn-primary"><i class="bi bi-plus-circle me-1"></i>Aggiungi palina</button></div>
    </form>

    <?php if (!$groundingRods): ?>
      <div class="text-muted">Nessuna palina di messa a terra configurata.</div>
    <?php else: ?>
      <div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th style="width:90px">ID</th><th>Location</th><th class="text-end" style="width:210px">Azioni</th></tr></thead><tbody>
      <?php foreach ($groundingRods as $rod): ?>
        <tr><td><code>#<?= (int)$rod['id'] ?></code></td><td colspan="2">
          <form method="post" class="d-flex flex-column flex-md-row gap-2">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int)$rod['id'] ?>">
            <input class="form-control" name="grounding_rod_location" maxlength="190" required value="<?= e($rod['location']) ?>">
            <button class="btn btn-outline-primary text-nowrap" name="action" value="grounding_rod_update"><i class="bi bi-save me-1"></i>Salva</button>
            <button class="btn btn-outline-danger" name="action" value="grounding_rod_delete" formnovalidate onclick="return confirm('Eliminare questa palina di messa a terra?')"><i class="bi bi-trash"></i></button>
          </form>
        </td></tr>
      <?php endforeach; ?>
      </tbody></table></div>
    <?php endif; ?>
  </div>
</div>
</section>

<section class="col-12 col-lg-6" id="refrigerators" style="scroll-margin-top:1rem">
<div class="card shadow-sm h-100">
  <div class="card-body">
    <h2 class="h5 mb-3"><i class="bi bi-snow me-1"></i>Mappatura Frigoriferi</h2>
    <?php $responsibilityProcedure = 'temperature'; include __DIR__ . '/partials/autocontrollo_responsible_form.php'; ?>
    <form method="post" class="row g-2 align-items-end mb-4">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="refrigerator_create">
      <div class="col-12 col-md-4"><label for="newRefrigeratorId" class="form-label">ID univoco</label><input class="form-control" id="newRefrigeratorId" name="refrigerator_id" maxlength="50" pattern="[A-Za-z0-9._-]+" required></div>
      <div class="col-12 col-md-3"><label for="newRefrigeratorType" class="form-label">Tipologia</label><select class="form-select" id="newRefrigeratorType" name="refrigerator_type" required><option value="frigorifero">Frigorifero</option><option value="congelatore">Congelatore</option><option value="cella">Cella</option></select></div>
      <div class="col-12 col-md-3"><label for="newOperatingTemperature" class="form-label">Temperatura esercizio °C</label><input class="form-control" type="number" inputmode="decimal" step="0.01" min="-99.99" max="99.99" id="newOperatingTemperature" name="operating_temperature" required></div>
      <div class="col-12 col-md-2"><button class="btn btn-primary w-100"><i class="bi bi-plus-circle me-1"></i>Aggiungi</button></div>
    </form>

    <?php if (!$refrigerators): ?>
      <div class="text-muted">Nessun frigorifero configurato.</div>
    <?php else: ?>
      <div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>ID</th><th>Tipologia</th><th>Temperatura esercizio</th><th class="text-end">Azioni</th></tr></thead><tbody>
      <?php foreach ($refrigerators as $refrigerator): ?>
        <tr><td colspan="4"><form method="post" class="row g-2 align-items-center"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="original_refrigerator_id" value="<?= e($refrigerator['id']) ?>"><div class="col-12 col-md-3"><input class="form-control" name="refrigerator_id" maxlength="50" pattern="[A-Za-z0-9._-]+" required value="<?= e($refrigerator['id']) ?>" aria-label="ID frigorifero"></div><div class="col-12 col-md-3"><select class="form-select" name="refrigerator_type" required aria-label="Tipologia frigorifero"><?php foreach (['frigorifero'=>'Frigorifero','congelatore'=>'Congelatore','cella'=>'Cella'] as $value=>$label): ?><option value="<?= e($value) ?>" <?= $refrigerator['appliance_type'] === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div><div class="col-12 col-md-3"><div class="input-group"><input class="form-control" type="number" inputmode="decimal" step="0.01" min="-99.99" max="99.99" name="operating_temperature" required value="<?= e($refrigerator['operating_temperature']) ?>" aria-label="Temperatura esercizio"><span class="input-group-text">°C</span></div></div><div class="col-12 col-md-3 d-flex justify-content-md-end gap-2"><button class="btn btn-outline-primary" name="action" value="refrigerator_update"><i class="bi bi-save me-1"></i>Salva</button><button class="btn btn-outline-danger" name="action" value="refrigerator_delete" formnovalidate onclick="return confirm('Eliminare questo frigorifero?')"><i class="bi bi-trash"></i></button></div></form></td></tr>
      <?php endforeach; ?>
      </tbody></table></div>
    <?php endif; ?>
  </div>
</div>
</section>
<section class="col-12 col-lg-6" id="fire-extinguishers" style="scroll-margin-top:1rem">
<div class="card shadow-sm h-100">
  <div class="card-body">
    <h2 class="h5 mb-3"><i class="bi bi-fire me-1"></i>Estintori</h2>
    <?php $responsibilityProcedure = 'fire'; include __DIR__ . '/partials/autocontrollo_responsible_form.php'; ?>
    <form method="post" class="row g-2 align-items-end mb-4">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="extinguisher_create">
      <div class="col-12 col-md-4"><label for="newExtinguisherId" class="form-label">ID univoco</label><input class="form-control" id="newExtinguisherId" name="extinguisher_id" maxlength="50" pattern="[A-Za-z0-9._-]+" required></div>
      <div class="col-12 col-md-3"><label for="newExtinguisherType" class="form-label">Tipologia</label><select class="form-select" id="newExtinguisherType" name="extinguisher_type" required><option value="polvere">Polvere</option><option value="co2">CO2</option><option value="schiuma">Schiuma</option><option value="carrellato">Carrellato</option></select></div>
      <div class="col-12 col-md-3"><label for="newExtinguisherCapacity" class="form-label">Capacità</label><div class="input-group"><input class="form-control" type="number" inputmode="decimal" step="0.01" min="0.01" max="9999.99" id="newExtinguisherCapacity" name="capacity_kg" required><span class="input-group-text">Kg</span></div></div>
      <div class="col-12 col-md-2"><button class="btn btn-primary w-100"><i class="bi bi-plus-circle me-1"></i>Aggiungi</button></div>
    </form>
    <?php if (!$fireExtinguishers): ?><div class="text-muted">Nessun estintore configurato.</div><?php else: ?>
    <div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>ID</th><th>Tipologia</th><th>Capacità</th><th class="text-end">Azioni</th></tr></thead><tbody>
    <?php foreach ($fireExtinguishers as $extinguisher): ?><tr><td colspan="4"><form method="post" class="row g-2 align-items-center"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="original_extinguisher_id" value="<?= e($extinguisher['id']) ?>"><div class="col-12 col-md-3"><input class="form-control" name="extinguisher_id" maxlength="50" pattern="[A-Za-z0-9._-]+" required value="<?= e($extinguisher['id']) ?>" aria-label="ID estintore"></div><div class="col-12 col-md-3"><select class="form-select" name="extinguisher_type" required aria-label="Tipologia estintore"><?php foreach (['polvere'=>'Polvere','co2'=>'CO2','schiuma'=>'Schiuma','carrellato'=>'Carrellato'] as $value=>$label): ?><option value="<?= e($value) ?>" <?= $extinguisher['extinguisher_type'] === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div><div class="col-12 col-md-3"><div class="input-group"><input class="form-control" type="number" inputmode="decimal" step="0.01" min="0.01" max="9999.99" name="capacity_kg" required value="<?= e($extinguisher['capacity_kg']) ?>" aria-label="Capacità estintore"><span class="input-group-text">Kg</span></div></div><div class="col-12 col-md-3 d-flex justify-content-md-end gap-2"><button class="btn btn-outline-primary" name="action" value="extinguisher_update"><i class="bi bi-save me-1"></i>Salva</button><button class="btn btn-outline-danger" name="action" value="extinguisher_delete" formnovalidate onclick="return confirm('Eliminare questo estintore?')"><i class="bi bi-trash"></i></button></div></form></td></tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
  </div>
</div>
</section>
<section class="col-12 col-lg-6" id="haccp-cleaning" style="scroll-margin-top:1rem">
<div class="card shadow-sm h-100"><div class="card-body">
  <h2 class="h5 mb-3"><i class="bi bi-clipboard-check me-1"></i>Pulizie HACCP</h2>
  <p class="text-muted">Assegna il responsabile della procedura di pulizia HACCP.</p>
  <?php $responsibilityProcedure = 'haccp'; include __DIR__ . '/partials/autocontrollo_responsible_form.php'; ?>
</div></div>
</section>
<?php include __DIR__ . '/partials/autocontrollo_vehicles.php'; ?>
</div>
<?php include __DIR__ . '/partials/footer.php'; ?>
