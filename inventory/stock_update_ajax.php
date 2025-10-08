<?php


declare(strict_types=1);
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/db.php';

start_session();
header('Content-Type: application/json; charset=utf-8');

function fail(int $code, string $msg) {
  http_response_code($code);
  echo json_encode(['ok'=>false,'error'=>$msg], JSON_UNESCAPED_UNICODE);
  exit;
}

// (opzionale) stretta same-origin per sicurezza ulteriore
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$host   = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https':'http').'://'.$_SERVER['HTTP_HOST'];
if ($origin && $origin !== $host) {
  fail(403, 'Origin non consentita');
}

// Permessi
$user = current_user();
if (!$user || !(is_admin() || (($user['dipartimento'] ?? '') === 'Amministrazione') || (($user['dipartimento'] ?? '') === 'Bar'))) {
  fail(403, 'Permesso negato');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  fail(405, 'Metodo non consentito');
}

// Leggi JSON o form
$raw  = file_get_contents('php://input') ?: '';
$data = $_POST ?: (json_decode($raw, true) ?? []);

// CSRF: accetta sia body sia header X-CSRF-Token
$csrf = (string)($data['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
if (!csrf_check($csrf)) {
  // DEBUG (rimuovi in prod):
  // error_log('[DEBUG CSRF] sid='.session_id().' sess='.($_SESSION['csrf_token'] ?? 'NULL').' posted='.($csrf ?: 'EMPTY'));
  fail(400, 'CSRF non valido');
}





// parametri
$product_id = (int)($data['product_id'] ?? 0);
$warehouse  = trim((string)($data['warehouse'] ?? ''));
$qty_str    = (string)($data['qty'] ?? '');
$qty        = ($qty_str === '') ? 0.0 : (float)$qty_str;

if ($product_id <= 0)       fail(400, 'product_id mancante/invalid');
if ($warehouse === '')      fail(400, 'warehouse mancante');
if (!is_finite($qty) || $qty < 0) fail(400, 'qty non valida');

// magazzini ammessi
$WAREHOUSES = ['Tizzo','Tramonto']; // adatta se diversi
if (!in_array($warehouse, $WAREHOUSES, true)) fail(400, 'warehouse non valida');

// DB
$pdo = db();

// verifica prodotto
$st = $pdo->prepare("SELECT id, min_qty FROM products WHERE id=?");
$st->execute([$product_id]);
$prod = $st->fetch(PDO::FETCH_ASSOC);
if (!$prod) fail(404, 'Prodotto non trovato');

// upsert qty
try {
  $pdo->prepare("
    INSERT INTO stock_levels (product_id, warehouse, qty)
    VALUES (?,?,?)
    ON DUPLICATE KEY UPDATE qty = VALUES(qty)
  ")->execute([$product_id, $warehouse, $qty]);

  // aggregati
  $agg = $pdo->prepare("
    SELECT
      COALESCE(SUM(qty),0) AS total_qty,
      COALESCE(SUM(CASE WHEN warehouse='Tizzo' THEN qty ELSE 0 END),0) AS qty_tizzo,
      COALESCE(SUM(CASE WHEN warehouse='Tramonto' THEN qty ELSE 0 END),0) AS qty_tramonto
    FROM stock_levels WHERE product_id=?
  ");
  $agg->execute([$product_id]);
  $tot = $agg->fetch(PDO::FETCH_ASSOC) ?: ['total_qty'=>0,'qty_tizzo'=>0,'qty_tramonto'=>0];

  echo json_encode([
    'ok'           => true,
    'product_id'   => $product_id,
    'warehouse'    => $warehouse,
    'qty'          => (float)$qty,
    'total_qty'    => (float)$tot['total_qty'],
    'qty_tizzo'    => (float)$tot['qty_tizzo'],
    'qty_tramonto' => (float)$tot['qty_tramonto'],
    'is_under_min' => ($prod['min_qty'] !== null && (float)$tot['total_qty'] < (float)$prod['min_qty']),
  ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  fail(500, 'Errore salvataggio: '.$e->getMessage());
}


error_log('[DEBUG CSRF] sid='.session_id().' sess='.($_SESSION['csrf_token'] ?? 'NULL').' posted='.($csrf ?: 'EMPTY'));

