<?php
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/security.php';
require_once __DIR__ . '/core/db.php';
start_session();
$env  = require __DIR__ . '/config/env.php';
$base = rtrim($env['app']['base_url'] ?? '', '/');
$pdo  = db();
$user = current_user();
if (!$user) { header('Location: ' . $base . '/index.php?msg=auth'); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_check($_POST['csrf'] ?? '')) {
  header('Location: ' . $base . '/transfers_external.php');
  exit;
}

$id = (int)($_POST['id'] ?? 0);
$act = $_POST['action'] ?? '';

$stmt = $pdo->prepare('SELECT * FROM transfers_external WHERE id=? LIMIT 1');
$stmt->execute([$id]);
$row = $stmt->fetch();
if (!$row) { header('Location: ' . $base . '/transfers_external.php'); exit; }

try {
  switch ($act) {
    case 'set_booked':
      $pdo->prepare('UPDATE transfers_external SET booked=1 WHERE id=?')->execute([$id]);
      break;
    case 'unset_booked':
      $pdo->prepare('UPDATE transfers_external SET booked=0, service_company=NULL WHERE id=?')->execute([$id]);
      break;
    case 'toggle_paid':
      $new = $row['paid'] ? 0 : 1;
      $pdo->prepare('UPDATE transfers_external SET paid=? WHERE id=?')->execute([$new, $id]);
      break;
    case 'toggle_cancel':
      $new = ($row['status'] ?? 'attivo') === 'annullato' ? 'attivo' : 'annullato';
      $pdo->prepare('UPDATE transfers_external SET status=? WHERE id=?')->execute([$new, $id]);
      break;
    case 'set_pickup':
      $pickup = trim($_POST['pickup_time'] ?? '');
      if ($pickup === '') {
        $pdo->prepare('UPDATE transfers_external SET pickup_time=NULL WHERE id=?')->execute([$id]);
      } else {
        $dt = DateTime::createFromFormat('H:i', $pickup) ?: DateTime::createFromFormat('H:i:s', $pickup);
        if ($dt) {
          $pdo->prepare('UPDATE transfers_external SET pickup_time=? WHERE id=?')->execute([$dt->format('H:i:s'), $id]);
        }
      }
      break;
    case 'delete':
      $pdo->prepare('UPDATE transfers_external SET deleted_at=NOW() WHERE id=? AND deleted_at IS NULL')->execute([$id]);
      break;
  }
} catch (PDOException $e) {}

header('Location: ' . $base . '/transfers_external.php');
exit;
