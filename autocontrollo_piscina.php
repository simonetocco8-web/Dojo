<?php

require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/security.php';
require_once __DIR__ . '/core/autocontrollo_pool.php';

require_login();
$user = current_user();
if (!$user || !user_has_department($user, 'Amministrazione')) {
    http_response_code(403);
    exit('Accesso negato.');
}

$env = require __DIR__ . '/config/env.php';
$base = rtrim($env['app']['base_url'] ?? '', '/');
$pdo = db();
ensure_autocontrollo_pool_inspections_tables($pdo);
$range = get_summer_season_range($pdo);
$products = $pdo->query('SELECT id, description FROM autocontrollo_pool_products ORDER BY description, id')->fetchAll(PDO::FETCH_ASSOC);
$completedDates = [];
if (!empty($range['start']) && !empty($range['end'])) {
    $stmt = $pdo->prepare('SELECT inspection_date FROM autocontrollo_pool_inspections WHERE season_start=? AND season_end=? ORDER BY inspection_date');
    $stmt->execute([$range['start'], $range['end']]);
    $completedDates = $stmt->fetchAll(PDO::FETCH_COLUMN);
}
$nextDate = autocontrollo_pool_next_required_date($range, $completedDates);
$today = new DateTimeImmutable('today', new DateTimeZone('Europe/Rome'));
$error = '';
$previousInspection = null;
$previousProductQuantities = [];
if ($nextDate) {
    $previousDate = (new DateTimeImmutable($nextDate))->modify('-1 day')->format('Y-m-d');
    $stmt = $pdo->prepare('SELECT * FROM autocontrollo_pool_inspections WHERE season_start=? AND season_end=? AND inspection_date=? LIMIT 1');
    $stmt->execute([$range['start'], $range['end'], $previousDate]);
    $previousInspection = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($previousInspection) {
        $stmt = $pdo->prepare('SELECT product_id, quantity_kg FROM autocontrollo_pool_inspection_products WHERE inspection_id=? AND product_id IS NOT NULL');
        $stmt->execute([$previousInspection['id']]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $previousProduct) {
            $previousProductQuantities[(int)$previousProduct['product_id']] = (string)$previousProduct['quantity_kg'];
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check((string)($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Token CSRF non valido.');
    }
    try {
        if (($_POST['action'] ?? '') !== 'create') throw new InvalidArgumentException('Azione non valida.');
        if (!$nextDate) throw new RuntimeException('Non risultano controlli piscina da registrare.');
        $id = autocontrollo_pool_create($pdo, $range, $nextDate, (int)$user['id'], $_POST);
        header('Location: ' . $base . '/autocontrollo_piscina.php?created=' . $id);
        exit;
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

$inspections = [];
if (!empty($range['start']) && !empty($range['end'])) {
    $stmt = $pdo->prepare("SELECT i.*, u.email AS operator_email,
        (SELECT GROUP_CONCAT(CONCAT(p.product_description, ' — ', FORMAT(p.quantity_kg, 3), ' kg') ORDER BY p.id SEPARATOR ' | ')
         FROM autocontrollo_pool_inspection_products p WHERE p.inspection_id=i.id) AS products
        FROM autocontrollo_pool_inspections i
        LEFT JOIN users u ON u.id=i.operator_id
        WHERE i.season_start=? AND i.season_end=? ORDER BY i.inspection_date DESC, i.inspection_time DESC");
    $stmt->execute([$range['start'], $range['end']]);
    $inspections = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
$canCreate = $nextDate && new DateTimeImmutable($nextDate, new DateTimeZone('Europe/Rome')) <= $today;
$postedProductQuantities = isset($_POST['product_quantities']) && is_array($_POST['product_quantities']) ? $_POST['product_quantities'] : [];
$title = 'Autocontrollo Piscina';
include __DIR__ . '/partials/header.php';
?>
<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 mb-4"><div><div class="text-muted small text-uppercase fw-semibold">Autocontrollo</div><h1 class="h4 mb-0"><i class="bi bi-water me-1"></i>Piscina</h1></div></div>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<?php if (isset($_GET['created'])): ?><div class="alert alert-success"><i class="bi bi-check-circle me-1"></i>Procedura piscina registrata correttamente.</div><?php endif; ?>

<?php if (empty($range['start']) || empty($range['end'])): ?>
  <div class="alert alert-warning">Configurare le date di apertura e chiusura della stagione nelle impostazioni di sistema.</div>
<?php elseif ($nextDate === null): ?>
  <div class="alert alert-success"><i class="bi bi-check-circle me-1"></i>Tutti i controlli piscina della stagione dal <?= e((new DateTimeImmutable($range['start']))->format('d/m/Y')) ?> al <?= e((new DateTimeImmutable($range['end']))->format('d/m/Y')) ?> sono stati registrati.</div>
<?php elseif (!$canCreate): ?>
  <div class="alert alert-info"><i class="bi bi-calendar-event me-1"></i>Il prossimo controllo sarà disponibile il <strong><?= e((new DateTimeImmutable($nextDate))->format('d/m/Y')) ?></strong>.</div>
<?php else: ?>
<div class="card shadow-sm border-primary mb-4"><div class="card-body p-3 p-md-4">
  <div class="d-flex justify-content-between align-items-center mb-3"><div><div class="small text-uppercase text-muted fw-semibold">Prossimo controllo obbligatorio</div><h2 class="h5 mb-0"><?= e((new DateTimeImmutable($nextDate))->format('d/m/Y')) ?></h2></div><span class="badge text-bg-primary">In attesa</span></div>
  <?php if ($nextDate < $today->format('Y-m-d')): ?><div class="alert alert-warning py-2"><i class="bi bi-exclamation-triangle me-1"></i>Controllo arretrato: deve essere completato prima di poter registrare le date successive.</div><?php endif; ?>
  <?php if ($previousInspection): ?><div class="d-grid d-md-flex justify-content-md-end mb-3"><button type="button" id="poolAutoComplete" class="btn btn-outline-primary" data-inspection-time="<?= e(substr($previousInspection['inspection_time'], 0, 5)) ?>" data-chlorine="<?= e($previousInspection['chlorine']) ?>" data-temperature="<?= e($previousInspection['water_temperature']) ?>" data-ph="<?= e($previousInspection['ph_value']) ?>" data-people="<?= (int)$previousInspection['people_in_pool'] ?>" data-backwash="<?= e($previousInspection['backwash_minutes'] ?? '') ?>" data-sample-location="<?= e($previousInspection['sample_location']) ?>" data-products="<?= e(json_encode($previousProductQuantities, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>"><i class="bi bi-magic me-1"></i>Auto Completamento</button></div><?php endif; ?>
  <form method="post" id="poolInspectionForm" class="row g-3"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="create">
    <div class="col-12 col-md-4"><label class="form-label" for="inspectionTime">Orario</label><input class="form-control" type="time" id="inspectionTime" name="inspection_time" required value="<?= e($_POST['inspection_time'] ?? (new DateTimeImmutable('now', new DateTimeZone('Europe/Rome')))->format('H:i')) ?>"></div>
    <div class="col-12 col-md-4"><label class="form-label" for="chlorine">Cloro rilevato</label><input class="form-control" type="number" inputmode="decimal" step="0.01" min="0" max="100" id="chlorine" name="chlorine" required value="<?= e($_POST['chlorine'] ?? '') ?>"></div>
    <div class="col-12 col-md-4"><label class="form-label" for="waterTemperature">Temperatura °C</label><input class="form-control" type="number" inputmode="decimal" step="0.01" min="-10" max="60" id="waterTemperature" name="water_temperature" required value="<?= e($_POST['water_temperature'] ?? '') ?>"></div>
    <div class="col-12 col-md-4"><label class="form-label" for="phValue">Valore pH</label><input class="form-control" type="number" inputmode="decimal" step="0.01" min="0" max="14" id="phValue" name="ph_value" required value="<?= e($_POST['ph_value'] ?? '') ?>"></div>
    <div class="col-12 col-md-4"><label class="form-label" for="peopleInPool">N° persone in vasca</label><input class="form-control" type="number" inputmode="numeric" min="0" id="peopleInPool" name="people_in_pool" required value="<?= e($_POST['people_in_pool'] ?? '0') ?>"></div>
    <div class="col-12 col-md-4"><label class="form-label" for="backwashMinutes">Durata controlavaggio <span class="text-muted">(min, facoltativa)</span></label><input class="form-control" type="number" inputmode="numeric" min="0" max="1440" id="backwashMinutes" name="backwash_minutes" value="<?= e($_POST['backwash_minutes'] ?? '') ?>"></div>
    <fieldset class="col-12"><legend class="form-label">Punto di prelievo campioni</legend><div class="d-flex gap-4"><div class="form-check"><input class="form-check-input" type="radio" name="sample_location" id="sampleInside" value="interno" required <?= ($_POST['sample_location'] ?? '') === 'interno' ? 'checked' : '' ?>><label class="form-check-label" for="sampleInside">Interno vasca</label></div><div class="form-check"><input class="form-check-input" type="radio" name="sample_location" id="sampleOutside" value="esterno" required <?= ($_POST['sample_location'] ?? '') === 'esterno' ? 'checked' : '' ?>><label class="form-check-label" for="sampleOutside">Esterno vasca</label></div></div></fieldset>
    <div class="col-12"><div class="card bg-light"><div class="card-body"><h3 class="h6">Prodotti immessi <span class="text-muted fw-normal">(facoltativi)</span></h3><?php if (!$products): ?><div class="text-muted small">Nessun prodotto configurato nei Setting Autocontrollo.</div><?php else: ?><div class="row g-2"><?php foreach ($products as $product): ?><div class="col-12 col-md-6"><label class="form-label small" for="product-<?= (int)$product['id'] ?>"><?= e($product['description']) ?> — quantità in kg</label><input class="form-control" type="number" inputmode="decimal" step="0.001" min="0" id="product-<?= (int)$product['id'] ?>" name="product_quantities[<?= (int)$product['id'] ?>]" value="<?= e($postedProductQuantities[$product['id']] ?? '') ?>"></div><?php endforeach; ?></div><?php endif; ?></div></div></div>
    <div class="col-12"><button class="btn btn-primary btn-lg w-100"><i class="bi bi-check2-circle me-1"></i>Registra controllo del <?= e((new DateTimeImmutable($nextDate))->format('d/m/Y')) ?></button></div>
  </form>
</div></div>
<?php endif; ?>

<div class="card shadow-sm"><div class="card-header bg-white"><h2 class="h5 mb-0">Controlli effettuati</h2></div><div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>ID</th><th>Data e ora</th><th>Operatore</th><th>Cloro</th><th>Temp.</th><th>pH</th><th>Persone</th><th>Controlavaggio</th><th>Prelievo</th><th>Prodotti</th></tr></thead><tbody>
<?php if (!$inspections): ?><tr><td colspan="10" class="text-center text-muted py-4">Nessun controllo piscina registrato.</td></tr><?php endif; ?>
<?php foreach ($inspections as $inspection): ?><tr><td><code>#<?= (int)$inspection['id'] ?></code></td><td class="text-nowrap"><?= e((new DateTimeImmutable($inspection['inspection_date']))->format('d/m/Y')) ?> <?= e(substr($inspection['inspection_time'], 0, 5)) ?></td><td><?= e($inspection['operator_email'] ?? '—') ?></td><td><?= e(number_format((float)$inspection['chlorine'], 2, ',', '')) ?></td><td><?= e(number_format((float)$inspection['water_temperature'], 1, ',', '')) ?> °C</td><td><?= e(number_format((float)$inspection['ph_value'], 2, ',', '')) ?></td><td><?= (int)$inspection['people_in_pool'] ?></td><td><?= $inspection['backwash_minutes'] === null ? '—' : (int)$inspection['backwash_minutes'] . ' min' ?></td><td><?= $inspection['sample_location'] === 'interno' ? 'Interno' : 'Esterno' ?></td><td><?= e($inspection['products'] ?: '—') ?></td></tr><?php endforeach; ?>
</tbody></table></div></div>
<script src="<?= e($base) ?>/assets/autocontrollo-pool.js" defer></script>
<?php include __DIR__ . '/partials/footer.php'; ?>
