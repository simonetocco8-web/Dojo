<?php
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/security.php';
require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/roles.php';
require_once __DIR__ . '/google/calendar_riassetti.php';
start_session();

$env  = require __DIR__ . '/config/env.php';
$base = rtrim($env['app']['base_url'] ?? '', '/');

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

$pdo  = db();
$user = current_user();
if (!$user) { header('Location: ' . $base . '/index.php?msg=auth'); exit; }

if (!user_is_reception_or_amministrazione($user)) {
  http_response_code(403);
  echo 'Accesso negato';
  exit;
}

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$redirect = trim((string)($_POST['redirect'] ?? ''));
if ($redirect === '' || preg_match('#^https?://#i', $redirect)) {
  $redirect = $base . '/riassetti.php';
}

if ($id <= 0) {
  header('Location: ' . $base . '/riassetti.php?msg=delete_error');
  exit;
}

$stmt = $pdo->prepare('SELECT id FROM riassetti WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
if (!$stmt->fetchColumn()) {
  header('Location: ' . $base . '/riassetti.php?msg=delete_error');
  exit;
}

$calendarWarn = false;
try {
  gcal_delete_riassetto_event($pdo, $id);
} catch (Exception $e) {
  error_log('Errore eliminazione evento Google riassetto: ' . $e->getMessage());
  $calendarWarn = true;
}

try {
  $del = $pdo->prepare('DELETE FROM riassetti WHERE id = ?');
  $del->execute([$id]);
} catch (Exception $e) {
  error_log('Errore eliminazione riassetto: ' . $e->getMessage());
  header('Location: ' . $base . '/riassetti.php?msg=delete_error');
  exit;
}

$separator = strpos($redirect, '?') === false ? '?' : '&';
$target = $redirect . $separator . 'msg=deleted';
if ($calendarWarn) {
  $target .= '&warn=calendar';
}
header('Location: ' . $target);
exit;
