<?php
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/security.php';
require_once __DIR__ . '/core/roles.php';
require_once __DIR__ . '/core/db.php';

start_session();
$env = require __DIR__ . '/config/env.php';
$base = rtrim($env['app']['base_url'] ?? '', '/');
$user = current_user();
if (!$user) { header('Location: ' . $base . '/index.php?msg=auth'); exit; }
if (!user_is_reception_or_amministrazione($user)) { http_response_code(403); echo 'Accesso negato'; exit; }

$pdo = db();
ensure_transfer_locations_table($pdo);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_check($_POST['csrf_token'] ?? '')) $errors[] = 'Sessione scaduta: ricarica la pagina e riprova.';
  $action = (string)($_POST['action'] ?? '');
  $id = (int)($_POST['id'] ?? 0);
  $name = trim((string)($_POST['name'] ?? ''));
  $distanceRaw = str_replace(',', '.', trim((string)($_POST['distance_km'] ?? '')));

  if (in_array($action, ['create', 'update'], true)) {
    if ($name === '' || mb_strlen($name) > 190) $errors[] = 'Inserisci un nome valido (massimo 190 caratteri).';
    if ($distanceRaw === '' || !is_numeric($distanceRaw) || (float)$distanceRaw < 0 || (float)$distanceRaw > 999999.99) $errors[] = 'Inserisci una distanza valida in km.';
    if ($action === 'update' && $id <= 0) $errors[] = 'Località non valida.';
  } elseif ($action === 'delete' && $id <= 0) {
    $errors[] = 'Località non valida.';
  } elseif (!in_array($action, ['create', 'update', 'delete'], true)) {
    $errors[] = 'Operazione non valida.';
  }

  if (!$errors) {
    try {
      if ($action === 'create') {
        $stmt = $pdo->prepare('INSERT INTO transfer_locations (name, distance_km, is_active) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE distance_km=VALUES(distance_km), is_active=1');
        $stmt->execute([$name, number_format((float)$distanceRaw, 2, '.', '')]);
        $flash = 'Località inserita correttamente.';
      } elseif ($action === 'update') {
        $stmt = $pdo->prepare('UPDATE transfer_locations SET name=?, distance_km=? WHERE id=? AND is_active=1');
        $stmt->execute([$name, number_format((float)$distanceRaw, 2, '.', ''), $id]);
        $flash = 'Località aggiornata correttamente.';
      } else {
        // Disattivazione logica: i transfer storici conservano il testo della località.
        $stmt = $pdo->prepare('UPDATE transfer_locations SET is_active=0 WHERE id=?');
        $stmt->execute([$id]);
        $flash = 'Località eliminata. I transfer storici sono rimasti invariati.';
      }
      $_SESSION['transport_settings_flash'] = $flash;
      header('Location: ' . $base . '/transport_settings.php');
      exit;
    } catch (PDOException $exception) {
      $errors[] = ($exception->getCode() === '23000') ? 'Esiste già una località con questo nome.' : 'Non è stato possibile salvare la località.';
    }
  }
}

$locations = $pdo->query('SELECT id, name, distance_km FROM transfer_locations WHERE is_active=1 ORDER BY name ASC')->fetchAll();
$flash = $_SESSION['transport_settings_flash'] ?? '';
unset($_SESSION['transport_settings_flash']);
$title = 'Setting Trasporti';
include __DIR__ . '/partials/header.php';
?>
<div class="container-fluid" style="max-width:1100px">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4"><div><div class="text-muted small text-uppercase fw-semibold">Trasporti</div><h1 class="h3 mb-1">Setting</h1><p class="text-muted mb-0">Gestisci le località disponibili per i transfer interni e la relativa distanza.</p></div><a class="btn btn-outline-primary" href="<?= e($base) ?>/transfer_internal_create.php"><i class="bi bi-plus-circle me-1"></i>Nuovo transfer interno</a></div>
  <?php if ($flash): ?><div class="alert alert-success" role="status"><?= e($flash) ?></div><?php endif; ?>
  <?php foreach ($errors as $error): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endforeach; ?>

  <div class="card shadow-sm mb-4"><div class="card-body"><h2 class="h5 mb-3">Aggiungi località</h2><form method="post" class="row g-3 align-items-end"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="create"><div class="col-12 col-md-7"><label class="form-label" for="newLocationName">Località</label><input class="form-control" id="newLocationName" name="name" maxlength="190" required></div><div class="col-8 col-md-3"><label class="form-label" for="newLocationDistance">Distanza (km)</label><input class="form-control" id="newLocationDistance" type="number" name="distance_km" min="0" max="999999.99" step="0.01" required></div><div class="col-4 col-md-2"><button class="btn btn-primary w-100" type="submit">Inserisci</button></div></form></div></div>

  <div class="card shadow-sm"><div class="card-header bg-white py-3"><h2 class="h5 mb-0">Località configurate</h2></div><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead><tr><th>Località</th><th style="width:180px">Distanza (km)</th><th class="text-end" style="width:190px">Azioni</th></tr></thead><tbody>
    <?php if (!$locations): ?><tr><td colspan="3" class="text-center text-muted py-4">Nessuna località configurata.</td></tr><?php endif; ?>
    <?php foreach ($locations as $location): ?><tr><td colspan="3" class="p-0"><form method="post" class="row g-2 align-items-center p-2 m-0"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int)$location['id'] ?>"><div class="col-12 col-md"><label class="visually-hidden" for="locationName<?= (int)$location['id'] ?>">Località</label><input class="form-control" id="locationName<?= (int)$location['id'] ?>" name="name" value="<?= e($location['name']) ?>" maxlength="190" required></div><div class="col-7 col-md-3"><label class="visually-hidden" for="locationDistance<?= (int)$location['id'] ?>">Distanza km</label><input class="form-control" id="locationDistance<?= (int)$location['id'] ?>" type="number" name="distance_km" min="0" max="999999.99" step="0.01" value="<?= e(number_format((float)$location['distance_km'], 2, '.', '')) ?>" required></div><div class="col-5 col-md-auto ms-md-auto d-flex gap-2 justify-content-end"><button class="btn btn-outline-primary" name="action" value="update" type="submit" title="Salva <?= e($location['name']) ?>"><i class="bi bi-check-lg"></i></button><button class="btn btn-outline-danger" name="action" value="delete" type="submit" formnovalidate data-confirm-message="Eliminare la località <?= e($location['name']) ?>? I transfer storici non saranno modificati." title="Elimina <?= e($location['name']) ?>"><i class="bi bi-trash"></i></button></div></form></td></tr><?php endforeach; ?>
  </tbody></table></div></div>
</div>
<?php include __DIR__ . '/partials/footer.php'; ?>
