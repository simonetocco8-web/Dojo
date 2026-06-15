<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/db.php';

start_session();
$env  = require __DIR__ . '/../config/env.php';
$base = rtrim($env['app']['base_url'] ?? '', '/');
$pdo  = db();
ensure_product_categories_table($pdo);
$user = current_user();

if (!$user || !(is_admin() || user_has_department($user, 'Amministrazione'))) {
  http_response_code(403);
  exit('Permesso negato.');
}

function product_category_normalize(string $name): string {
  $name = trim($name);
  $name = preg_replace('/\s+/u', ' ', $name) ?? $name;
  return trim($name);
}

$message = '';
$messageType = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_check($_POST['csrf'] ?? '')) {
    $message = 'Token CSRF non valido.';
    $messageType = 'danger';
  } else {
    $action = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    $name = product_category_normalize((string)($_POST['name'] ?? ''));

    try {
      if ($action === 'create') {
        if ($name === '') {
          $message = 'Inserisci il nome della categoria.';
          $messageType = 'danger';
        } else {
          $stmt = $pdo->prepare('INSERT INTO product_categories (name) VALUES (?)');
          $stmt->execute([$name]);
          $message = 'Categoria creata.';
          $messageType = 'success';
        }
      } elseif ($action === 'update') {
        if ($id <= 0 || $name === '') {
          $message = 'Dati categoria non validi.';
          $messageType = 'danger';
        } else {
          $currentStmt = $pdo->prepare('SELECT name FROM product_categories WHERE id = ?');
          $currentStmt->execute([$id]);
          $oldName = (string)($currentStmt->fetchColumn() ?: '');
          if ($oldName === '') {
            $message = 'Categoria non trovata.';
            $messageType = 'danger';
          } else {
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE product_categories SET name = ? WHERE id = ?')->execute([$name, $id]);
            $pdo->prepare('UPDATE products SET category = ? WHERE category = ?')->execute([$name, $oldName]);
            $pdo->commit();
            $message = 'Categoria aggiornata.';
            $messageType = 'success';
          }
        }
      } elseif ($action === 'delete') {
        if ($id <= 0) {
          $message = 'Categoria non valida.';
          $messageType = 'danger';
        } else {
          $categoryStmt = $pdo->prepare('SELECT name FROM product_categories WHERE id = ?');
          $categoryStmt->execute([$id]);
          $categoryName = (string)($categoryStmt->fetchColumn() ?: '');
          if ($categoryName === '') {
            $message = 'Categoria non trovata.';
            $messageType = 'danger';
          } else {
            $countStmt = $pdo->prepare('SELECT COUNT(*) FROM products WHERE category = ?');
            $countStmt->execute([$categoryName]);
            $productCount = (int)$countStmt->fetchColumn();
            if ($productCount > 0) {
              $message = 'Impossibile eliminare: categoria collegata a uno o più prodotti.';
              $messageType = 'warning';
            } else {
              $pdo->prepare('DELETE FROM product_categories WHERE id = ?')->execute([$id]);
              $message = 'Categoria eliminata.';
              $messageType = 'success';
            }
          }
        }
      }
    } catch (PDOException $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      $message = 'Operazione non completata. Verifica che la categoria non esista già.';
      $messageType = 'danger';
    }
  }
}

$categories = $pdo->query("
  SELECT c.id, c.name, COUNT(p.id) AS product_count
  FROM product_categories c
  LEFT JOIN products p ON p.category = c.name
  GROUP BY c.id, c.name
  ORDER BY c.name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$title = 'Categorie Prodotti';
include __DIR__ . '/../partials/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h5 mb-0">Categorie Prodotti</h1>
  <a class="btn btn-outline-secondary btn-sm" href="<?= e($base) ?>/inventory/products.php">← Torna ai prodotti</a>
</div>

<?php if ($message): ?>
  <div class="alert alert-<?= e($messageType) ?>"><?= e($message) ?></div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-12 col-lg-4">
    <div class="card shadow-sm">
      <div class="card-body">
        <h2 class="h6">Nuova categoria</h2>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="create">
          <label class="form-label" for="category_name">Nome</label>
          <input type="text" id="category_name" name="name" class="form-control" maxlength="100" required>
          <button class="btn btn-primary mt-3">Aggiungi</button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-8">
    <div class="card shadow-sm">
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead>
            <tr>
              <th>Categoria</th>
              <th class="text-center">Prodotti collegati</th>
              <th class="text-end">Azioni</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($categories as $category): ?>
              <tr>
                <td>
                  <form method="post" class="d-flex gap-2 align-items-center">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="id" value="<?= (int)$category['id'] ?>">
                    <input type="text" name="name" class="form-control form-control-sm" maxlength="100" value="<?= e($category['name']) ?>" required>
                    <button class="btn btn-outline-primary btn-sm">Salva</button>
                  </form>
                </td>
                <td class="text-center"><?= (int)$category['product_count'] ?></td>
                <td class="text-end">
                  <form method="post" class="d-inline" data-confirm-message="Eliminare questa categoria?">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int)$category['id'] ?>">
                    <button class="btn btn-outline-danger btn-sm" <?= ((int)$category['product_count'] > 0) ? 'disabled title="Categoria collegata a prodotti"' : '' ?>>Elimina</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$categories): ?>
              <tr><td colspan="3" class="text-center text-muted py-3">Nessuna categoria.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
