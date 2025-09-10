<?php
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/security.php';
require_once __DIR__ . '/core/db.php';
require_admin();
$env = require __DIR__ . '/config/env.php';
$base = rtrim($env['app']['base_url'] ?? '', '/');
start_session();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_check($_POST['csrf'] ?? '')) {
  header('Location: ' . $base . '/users.php');
  exit;
}

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$me = current_user();
if ($me && (int)$me['id'] === $id) {
  header('Location: ' . $base . '/users.php?err=self');
  exit;
}

$pdo = db();
$stmt = $pdo->prepare('UPDATE users SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL');
$stmt->execute([$id]);

header('Location: ' . $base . '/users.php');
exit;
