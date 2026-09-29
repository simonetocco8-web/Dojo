<?php

require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/security.php';
require_once __DIR__ . '/core/roles.php';
require_once __DIR__ . '/core/db.php';

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check((string)($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Token CSRF non valido.');
    }
    $action = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    $location = trim((string)($_POST['installation_location'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    try {
        if ($action === 'panel_create' || $action === 'panel_update') {
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

<div class="card shadow-sm">
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
<?php include __DIR__ . '/partials/footer.php'; ?>
