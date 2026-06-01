<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/roles.php';

start_session();
$env = require __DIR__ . '/../config/env.php';
$base = rtrim($env['app']['base_url'] ?? '', '/');
$pdo = db();
$user = current_user();

if (!$user || !user_is_bar_or_amministrazione($user)) {
  http_response_code(403);
  exit('Permesso negato.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  exit('Metodo non consentito.');
}

if (!csrf_check((string)($_POST['csrf'] ?? ''))) {
  http_response_code(400);
  exit('CSRF non valido.');
}

ensure_products_active_column($pdo);

$id = (int)($_POST['id'] ?? 0);
$action = (string)($_POST['action'] ?? '');
$return = (string)($_POST['return'] ?? 'products');

if ($id <= 0) {
  http_response_code(400);
  exit('Prodotto non valido.');
}

try {
  if ($action === 'deactivate') {
    $stmt = $pdo->prepare('UPDATE products SET is_active = 0 WHERE id = ?');
    $stmt->execute([$id]);
    header('Location: ' . $base . '/inventory/products.php?msg=deactivated');
    exit;
  }

  if ($action === 'activate') {
    $stmt = $pdo->prepare('UPDATE products SET is_active = 1 WHERE id = ?');
    $stmt->execute([$id]);
    header('Location: ' . $base . '/inventory/products_inactive.php?msg=activated');
    exit;
  }

  if ($action === 'delete') {
    $pdo->beginTransaction();
    $pdo->prepare('DELETE FROM stock_levels WHERE product_id = ?')->execute([$id]);
    $pdo->prepare('DELETE FROM products WHERE id = ?')->execute([$id]);
    $pdo->commit();

    $target = $return === 'inactive' ? '/inventory/products_inactive.php?msg=deleted' : '/inventory/products.php?msg=deleted';
    header('Location: ' . $base . $target);
    exit;
  }
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  error_log('Product action failed: ' . $e->getMessage());
  $target = $return === 'inactive' ? '/inventory/products_inactive.php?msg=error' : '/inventory/products.php?msg=error';
  header('Location: ' . $base . $target);
  exit;
}

http_response_code(400);
echo 'Azione non valida.';
