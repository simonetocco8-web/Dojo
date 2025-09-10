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
  header('Location: ' . $base . '/tasks.php');
  exit;
}

$id = (int)($_POST['id'] ?? 0);
$action = $_POST['action'] ?? '';

$stmt = $pdo->prepare('SELECT * FROM tasks WHERE id=? LIMIT 1');
$stmt->execute([$id]);
$task = $stmt->fetch();
if (!$task) { header('Location: ' . $base . '/tasks.php'); exit; }

$st = $pdo->prepare('SELECT dipartimento, role FROM users WHERE id=? LIMIT 1');
$st->execute([$user['id']]);
$me = $st->fetch();
$is_admin = ($me['role'] ?? '') === 'admin';
$canAct = $is_admin || (($me['dipartimento'] ?? '') === $task['dipartimento']);

try {
  if ($action === 'complete' && $task['status']==='aperto' && $canAct && $task['deleted_at']===null) {
    $pdo->prepare('UPDATE tasks SET status="completato", completed_by=?, completed_at=NOW() WHERE id=?')->execute([$user['id'], $id]);
    if ($task['recurrence'] !== 'nessuna') {
      $due = new DateTime($task['due_date']);
      switch ($task['recurrence']) {
        case 'giornaliera': $due->modify('+1 day'); break;
        case 'settimanale': $due->modify('+1 week'); break;
        case 'mensile': $due->modify('+1 month'); break;
        case 'annuale': $due->modify('+1 year'); break;
      }
      $pdo->prepare('INSERT INTO tasks (title, description, priority, dipartimento, due_date, recurrence, created_by)
                     VALUES (?,?,?,?,?,?,?)')->execute([
        $task['title'], $task['description'], $task['priority'], $task['dipartimento'], $due->format('Y-m-d'), $task['recurrence'], $user['id']
      ]);
    }
  } elseif ($action === 'nonfattibile' && $task['status']==='aperto' && $canAct && $task['deleted_at']===null) {
    $note = trim($_POST['status_note'] ?? '');
    if ($note === '') { $note = 'Non specificato'; }
    $pdo->prepare('UPDATE tasks SET status="non_fattibile", status_note=?, not_feasible_by=?, not_feasible_at=NOW() WHERE id=?')
        ->execute([$note, $user['id'], $id]);
  } elseif ($action === 'trash' && $is_admin) {
    $pdo->prepare('UPDATE tasks SET deleted_at=NOW() WHERE id=? AND deleted_at IS NULL')->execute([$id]);
  } elseif ($action === 'restore' && $is_admin) {
    $pdo->prepare('UPDATE tasks SET deleted_at=NULL WHERE id=? AND deleted_at IS NOT NULL')->execute([$id]);
  }
} catch (PDOException $e) {}

header('Location: ' . $base . '/tasks.php');
exit;
