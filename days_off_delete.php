<?php
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/security.php';
require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/google/gcal_days_off.php';


start_session();
$env  = require __DIR__ . '/config/env.php';
$base = rtrim($env['app']['base_url'] ?? '', '/');
$pdo  = db();
$user = current_user();

if (!$user || !(is_admin() || (($user['dipartimento'] ?? '') === 'Amministrazione'))) {
  http_response_code(403); exit('Permesso negato.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Metodo non consentito.'); }
if (!csrf_check($_POST['csrf'] ?? '')) { http_response_code(400); exit('Token CSRF non valido.'); }

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) { http_response_code(400); exit('ID non valido.'); }

$pdo = db();

try {
  // elimina evento da Calendar (best effort)

  gcal_days_off_delete($pdo, $id);

  // soft delete
  $del = $pdo->prepare("UPDATE days_off SET deleted_at = NOW() WHERE id = ?");
  $del->execute([$id]);

  header('Location: days_off_list.php?msg=deleted');
  exit;
} catch (Throwable $e) {
  error_log('days_off delete: '.$e->getMessage());
  //header('Location: days_off_list.php?msg=error');
  exit;
}
