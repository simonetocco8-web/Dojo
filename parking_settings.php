<?php
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/security.php';
require_once __DIR__ . '/core/roles.php';
require_once __DIR__ . '/core/db.php';

start_session();
$env = require __DIR__ . '/config/env.php';
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
ensure_parking_spaces_table($pdo);
$validSizes = ['piccolo', 'medio', 'grande'];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_check($_POST['csrf_token'] ?? '')) {
    $errors[] = 'Sessione scaduta: ricarica la pagina e riprova.';
  }

  $submittedSizes = is_array($_POST['size'] ?? null) ? $_POST['size'] : [];
  $submittedCovered = is_array($_POST['covered'] ?? null) ? $_POST['covered'] : [];
  $spaceIds = $pdo->query('SELECT space_id FROM parking_spaces ORDER BY parking, CAST(SUBSTRING(space_id, 2) AS UNSIGNED)')->fetchAll(PDO::FETCH_COLUMN);
  foreach ($spaceIds as $spaceId) {
    if (!in_array($submittedSizes[$spaceId] ?? '', $validSizes, true)) {
      $errors[] = 'Dimensione non valida per il posto ' . $spaceId . '.';
    }
  }

  if (!$errors) {
    $pdo->beginTransaction();
    try {
      $update = $pdo->prepare('UPDATE parking_spaces SET size=?, is_covered=?, updated_by=? WHERE space_id=?');
      foreach ($spaceIds as $spaceId) {
        $update->execute([$submittedSizes[$spaceId], isset($submittedCovered[$spaceId]) ? 1 : 0, $user['id'], $spaceId]);
      }
      $pdo->commit();
      $_SESSION['parking_settings_flash'] = 'Configurazione dei posti aggiornata correttamente.';
      header('Location: ' . $base . '/parking_settings.php');
      exit;
    } catch (Throwable $exception) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      $errors[] = 'Non è stato possibile salvare la configurazione. Riprova.';
    }
  }
}

$spaces = $pdo->query('SELECT space_id, parking, size, is_covered FROM parking_spaces ORDER BY parking, CAST(SUBSTRING(space_id, 2) AS UNSIGNED)')->fetchAll();
$groupedSpaces = ['primario' => [], 'secondario' => []];
foreach ($spaces as $space) $groupedSpaces[$space['parking']][] = $space;
$flash = $_SESSION['parking_settings_flash'] ?? '';
unset($_SESSION['parking_settings_flash']);
$title = 'Setting Parcheggi';
include __DIR__ . '/partials/header.php';
?>
<div class="container-fluid parking-page">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div><div class="text-muted small text-uppercase fw-semibold">Parcheggi</div><h1 class="h3 mb-1">Setting</h1><p class="text-muted mb-0">Configura dimensione e copertura di ogni posto auto.</p></div>
    <a class="btn btn-outline-primary" href="<?= e($base) ?>/parking.php"><i class="bi bi-map me-1"></i>Torna alla mappa</a>
  </div>
  <?php if ($flash): ?><div class="alert alert-success" role="status"><?= e($flash) ?></div><?php endif; ?>
  <?php foreach ($errors as $error): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endforeach; ?>
  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <div class="row g-4">
      <?php foreach ([['primario', 'Parcheggio Primario'], ['secondario', 'Parcheggio Secondario']] as [$parkingKey, $parkingLabel]): ?>
        <div class="<?= $parkingKey === 'primario' ? 'col-12 col-xl-7' : 'col-12 col-xl-5' ?>">
          <div class="card border-0 shadow-sm h-100"><div class="card-header bg-white py-3"><h2 class="h5 mb-0"><?= e($parkingLabel) ?></h2></div><div class="table-responsive">
            <table class="table table-hover align-middle parking-settings-table mb-0"><thead><tr><th scope="col">Posto</th><th scope="col">Dimensione</th><th scope="col" class="text-center">Copertura</th></tr></thead><tbody>
            <?php foreach ($groupedSpaces[$parkingKey] as $space): ?>
              <tr><th scope="row"><span class="badge text-bg-dark fs-6"><?= e($space['space_id']) ?></span></th><td>
                <select class="form-select form-select-sm" name="size[<?= e($space['space_id']) ?>]" aria-label="Dimensione <?= e($space['space_id']) ?>" required>
                  <?php foreach ($validSizes as $size): ?><option value="<?= e($size) ?>" <?= $space['size'] === $size ? 'selected' : '' ?>><?= e(ucfirst($size)) ?></option><?php endforeach; ?>
                </select>
              </td><td class="text-center"><div class="form-check form-switch d-inline-flex"><input class="form-check-input" type="checkbox" name="covered[<?= e($space['space_id']) ?>]" value="1" aria-label="Posto <?= e($space['space_id']) ?> coperto" <?= (int)$space['is_covered'] === 1 ? 'checked' : '' ?>></div><div class="small text-muted"><?= (int)$space['is_covered'] === 1 ? 'Coperto' : 'Scoperto' ?></div></td></tr>
            <?php endforeach; ?>
            </tbody></table>
          </div></div>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="parking-settings-actions"><button class="btn btn-primary btn-lg shadow" type="submit"><i class="bi bi-check2-circle me-1"></i>Salva configurazione</button></div>
  </form>
</div>
<?php include __DIR__ . '/partials/footer.php'; ?>
