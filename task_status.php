<?php
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/security.php';
require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/roles.php';
require_once __DIR__ . '/core/login_workflow.php';
start_session();
$env  = require __DIR__ . '/config/env.php';
$base = rtrim($env['app']['base_url'] ?? '', '/');
$pdo  = db();
$user = current_user();
if (!$user) { header('Location: ' . $base . '/index.php?msg=auth'); exit; }
ensure_task_user_assignments_table($pdo);
ensure_task_recurrence_series_column($pdo);

$allowedViews = ['mio','tutti','completati','nonfattibili','cestino'];
$returnView = $_POST['return_view'] ?? $_GET['view'] ?? 'mio';
if (!in_array($returnView, $allowedViews, true)) $returnView = 'mio';
$returnTo = $_POST['return_to'] ?? $_GET['return_to'] ?? '';
$returnUrl = $returnTo === 'login_workflow' ? $base . '/login_workflow.php' : ($returnTo === 'dashboard' ? $base . '/dashboard.php' : $base . '/tasks.php?view=' . rawurlencode($returnView));

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_check($_POST['csrf'] ?? '')) {
  header('Location: ' . $returnUrl);
  exit;
}

$id = (int)($_POST['id'] ?? 0);
$action = $_POST['action'] ?? '';

$stmt = $pdo->prepare('SELECT * FROM tasks WHERE id=? LIMIT 1');
$stmt->execute([$id]);
$task = $stmt->fetch();
if (!$task) { header('Location: ' . $returnUrl); exit; }

$st = $pdo->prepare('SELECT dipartimento, role FROM users WHERE id=? LIMIT 1');
$st->execute([$user['id']]);
$me = $st->fetch();
$is_admin = ($me['role'] ?? '') === 'admin';
$canManageRecurring = user_is_amministrazione($me) || (int)$task['created_by'] === (int)$user['id'];
$assignmentCount = $pdo->prepare('SELECT COUNT(*) FROM task_user_assignments WHERE task_id = ?');
$assignmentCount->execute([$id]);
$hasAssignments = (int)$assignmentCount->fetchColumn() > 0;
$assigned = $pdo->prepare('SELECT 1 FROM task_user_assignments WHERE task_id = ? AND user_id = ? LIMIT 1');
$assigned->execute([$id, $user['id']]);
$canAct = $is_admin || (!$hasAssignments && user_has_department($me, $task['dipartimento'])) || (bool)$assigned->fetchColumn();

try {
  if ($returnTo === 'login_workflow') {
    $assigned->execute([$id, $user['id']]);
    if (!$assigned->fetchColumn()) throw new RuntimeException('Task non assegnato a te.');
    if (!in_array($action, ['complete', 'nonfattibile'], true)) throw new RuntimeException('Azione non valida.');
  }
  $pdo->beginTransaction();
  $lock = $pdo->prepare('SELECT * FROM tasks WHERE id=? FOR UPDATE'); $lock->execute([$id]); $task = $lock->fetch();
  if ($action === 'complete' && $task['status']==='aperto' && $canAct && $task['deleted_at']===null) {
    if ($returnTo === 'login_workflow' && empty($_POST['completed_at'])) throw new InvalidArgumentException('Specificare quando il task è stato completato.');
    $completedAt = !empty($_POST['completed_at']) ? login_workflow_completion_time((string)$_POST['completed_at']) : (new DateTimeImmutable('now', new DateTimeZone('Europe/Rome')))->format('Y-m-d H:i:s');
    $pdo->prepare('UPDATE tasks SET status="completato", completed_by=?, completed_at=? WHERE id=?')->execute([$user['id'], $completedAt, $id]);
    if ($task['recurrence'] !== 'nessuna') {
      $due = new DateTime($task['due_date']);
      switch ($task['recurrence']) {
        case 'giornaliera': $due->modify('+1 day'); break;
        case 'settimanale': $due->modify('+1 week'); break;
        case 'mensile': $due->modify('+1 month'); break;
        case 'annuale': $due->modify('+1 year'); break;
      }
      $pdo->prepare('INSERT INTO tasks (title, description, priority, dipartimento, due_date, recurrence, recurrence_series_id, created_by)
                     VALUES (?,?,?,?,?,?,?,?)')->execute([
        $task['title'], $task['description'], $task['priority'], $task['dipartimento'], $due->format('Y-m-d'), $task['recurrence'], $task['recurrence_series_id'] ?: $task['id'], $task['created_by']
      ]);
      $newTaskId = (int)$pdo->lastInsertId();
      $copyAssignments = $pdo->prepare('INSERT IGNORE INTO task_user_assignments (task_id, user_id) SELECT ?, user_id FROM task_user_assignments WHERE task_id = ?');
      $copyAssignments->execute([$newTaskId, $id]);
    }
  } elseif ($action === 'nonfattibile' && $task['status']==='aperto' && $canAct && $task['deleted_at']===null) {
    $note = trim($_POST['status_note'] ?? '');
    if ($returnTo === 'login_workflow' && $note === '') throw new InvalidArgumentException('Specificare il motivo della non fattibilità.');
    if ($note === '') { $note = 'Non specificato'; }
    $pdo->prepare('UPDATE tasks SET status="non_fattibile", status_note=?, not_feasible_by=?, not_feasible_at=NOW() WHERE id=?')
        ->execute([$note, $user['id'], $id]);
  } elseif ($action === 'update_due_date' && $canAct && $task['deleted_at']===null) {
    $due = trim($_POST['due_date'] ?? '');
    $dt = DateTime::createFromFormat('Y-m-d', $due);
    if ($dt && $dt->format('Y-m-d') === $due) {
      $pdo->prepare('UPDATE tasks SET due_date=? WHERE id=?')->execute([$due, $id]);
    }
  } elseif ($action === 'delete_recurrence' && $task['recurrence'] !== 'nessuna' && $canManageRecurring) {
    $seriesId = (int)($task['recurrence_series_id'] ?: $task['id']);
    $pdo->prepare('UPDATE tasks SET deleted_at=NOW() WHERE recurrence_series_id=? AND due_date>=? AND deleted_at IS NULL')
        ->execute([$seriesId, $task['due_date']]);
    $pdo->commit();
    header('Location: ' . $returnUrl . '&msg=recurrence_deleted');
    exit;
  } elseif ($action === 'trash' && $is_admin && $task['recurrence'] === 'nessuna') {
    $pdo->prepare('UPDATE tasks SET deleted_at=NOW() WHERE id=? AND deleted_at IS NULL')->execute([$id]);
  } elseif ($action === 'restore' && $is_admin) {
    $pdo->prepare('UPDATE tasks SET deleted_at=NULL WHERE id=? AND deleted_at IS NOT NULL')->execute([$id]);
  }
  $pdo->commit();
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  if ($returnTo === 'login_workflow') $_SESSION['login_workflow_error'] = $e->getMessage();
}

header('Location: ' . $returnUrl);
exit;
