<?php
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/security.php';
require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/roles.php';
start_session();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo 'Metodo non consentito';
  exit;
}

if (!csrf_check($_POST['csrf'] ?? '')) {
  http_response_code(400);
  echo 'Token CSRF non valido';
  exit;
}

$env  = require __DIR__ . '/config/env.php';
$base = rtrim($env['app']['base_url'] ?? '', '/');
$pdo  = db();
ensure_riassetti_status_column($pdo);
$user = current_user();
if (!$user) { header('Location: ' . $base . '/index.php?msg=auth'); exit; }

if (!(user_is_reception_or_amministrazione($user) || user_is_housekeeping($user))) {
  http_response_code(403);
  echo 'Accesso negato';
  exit;
}

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$action = $_POST['action'] ?? '';
if ($id <= 0 || !in_array($action, ['mark_ready','close','complete','reopen'], true)) {
  http_response_code(400);
  echo 'Richiesta non valida';
  exit;
}

$stmt = $pdo->prepare('SELECT id, status FROM riassetti WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$row = $stmt->fetch();
if (!$row) {
  http_response_code(404);
  echo 'Riassetto non trovato';
  exit;
}

if ($action === 'mark_ready') {
  $upd = $pdo->prepare("UPDATE riassetti SET status = 'da_consegnare', completed_at = NULL, completed_by = NULL, updated_by = ?, updated_at = NOW() WHERE id = ?");
  $upd->execute([$user['id'], $id]);
} elseif ($action === 'close' || $action === 'complete') {
  $upd = $pdo->prepare("UPDATE riassetti SET status = 'concluso', completed_at = NOW(), completed_by = ?, updated_by = ?, updated_at = NOW() WHERE id = ?");
  $upd->execute([$user['id'], $user['id'], $id]);
} else {
  $upd = $pdo->prepare("UPDATE riassetti SET status = 'da_preparare', completed_at = NULL, completed_by = NULL, updated_by = ?, updated_at = NOW() WHERE id = ?");
  $upd->execute([$user['id'], $id]);
}

$redirect = trim((string)($_POST['redirect'] ?? ''));
if ($redirect === '' || preg_match('/^https?:\/\//i', $redirect)) {
  $redirect = $base . '/riassetti.php?msg=completed';
} else {
  $separator = str_contains($redirect, '?') ? '&' : '?';
  $redirect .= $separator . 'msg=completed';
}
header('Location: ' . $redirect);
exit;
