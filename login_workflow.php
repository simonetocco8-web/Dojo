<?php
require_once __DIR__ . '/core/login_workflow.php';
require_once __DIR__ . '/core/security.php';
require_login();
$user = current_user();
$pdo = db();
$env = require __DIR__ . '/config/env.php';
$base = rtrim($env['app']['base_url'] ?? '', '/');
$error = (string)($_SESSION['login_workflow_error'] ?? '');
unset($_SESSION['login_workflow_error']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check((string)($_POST['csrf'] ?? ''))) { http_response_code(400); exit('Token CSRF non valido.'); }
    if (($_POST['action'] ?? '') === 'finish') {
        unset($_SESSION['login_workflow_active']);
        header('Location: ' . $base . '/dashboard.php'); exit;
    }
    try {
        if (($_POST['action'] ?? '') !== 'control') throw new InvalidArgumentException('Azione non valida.');
        header('Location: ' . $base . login_workflow_start_control($pdo, $user, (string)($_POST['procedure'] ?? ''))); exit;
    } catch (Throwable $exception) { $error = $exception->getMessage(); }
}
$_SESSION['login_workflow_active'] = true;
$tasks = login_workflow_tasks($pdo, $user);
$controls = login_workflow_controls($pdo, $user);
$taskId = (int)($_GET['task'] ?? 0);
$selectedTask = null;
foreach ($tasks as $task) if ((int)$task['id'] === $taskId) $selectedTask = $task;
$title = 'Compiti da svolgere';
include __DIR__ . '/partials/header.php';
?>
<h1 class="h4">Compiti da svolgere</h1>
<p class="text-muted">Riepilogo dei task assegnati e delle procedure di autocontrollo di cui sei responsabile.</p>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<?php if (isset($_GET['msg'])): ?><div class="alert alert-success">Operazione completata. Il riepilogo è stato aggiornato.</div><?php endif; ?>
<?php if (!$tasks && !$controls): ?><div class="alert alert-success">Non ci sono compiti da svolgere.</div><?php endif; ?>
<?php if ($selectedTask): ?>
<div class="card shadow-sm mb-4"><div class="card-body">
  <h2 class="h5"><?= e($selectedTask['title']) ?></h2>
  <p class="text-muted">Scadenza: <?= e($selectedTask['due_date']) ?> · Priorità: <?= e($selectedTask['priority']) ?></p>
  <p><?= nl2br(e($selectedTask['description'])) ?></p>
  <form method="post" action="<?= e($base) ?>/task_status.php" class="mb-3">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int)$selectedTask['id'] ?>"><input type="hidden" name="return_to" value="login_workflow"><input type="hidden" name="action" value="complete">
    <label class="form-label" for="completedAt">Quando è stato completato?</label>
    <input class="form-control mb-2" type="datetime-local" id="completedAt" name="completed_at" value="<?= e((new DateTimeImmutable('now', new DateTimeZone('Europe/Rome')))->format('Y-m-d\TH:i')) ?>" max="<?= e((new DateTimeImmutable('now', new DateTimeZone('Europe/Rome')))->format('Y-m-d\TH:i')) ?>" required>
    <button class="btn btn-success">Registra completamento</button>
  </form>
  <form method="post" action="<?= e($base) ?>/task_status.php">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int)$selectedTask['id'] ?>"><input type="hidden" name="return_to" value="login_workflow"><input type="hidden" name="action" value="nonfattibile">
    <label class="form-label" for="statusNote">Motivo della non fattibilità</label><textarea class="form-control mb-2" id="statusNote" name="status_note" required></textarea>
    <button class="btn btn-outline-danger">Non fattibile</button>
  </form>
</div></div>
<?php elseif ($taskId): ?><div class="alert alert-warning">Il task non è più disponibile o non è assegnato a te.</div><?php endif; ?>
<div class="row g-4">
<div class="col-12 col-lg-6"><div class="card"><div class="card-body"><h2 class="h5">Task assegnati (<?= count($tasks) ?>)</h2>
<?php if (!$tasks): ?><p class="text-muted">Nessun task aperto assegnato.</p><?php endif; ?>
<ul class="list-group list-group-flush"><?php foreach ($tasks as $task): ?><li class="list-group-item px-0"><a href="<?= e($base) ?>/login_workflow.php?task=<?= (int)$task['id'] ?>"><?= e($task['title']) ?></a><div class="small text-muted">Scadenza: <?= e($task['due_date']) ?> · <?= e($task['priority']) ?></div></li><?php endforeach; ?></ul>
</div></div></div>
<div class="col-12 col-lg-6"><div class="card"><div class="card-body"><h2 class="h5">Autocontrolli da eseguire (<?= count($controls) ?>)</h2>
<?php if (!$controls): ?><p class="text-muted">Nessuna procedura assegnata disponibile.</p><?php endif; ?>
<?php foreach ($controls as $procedure => $control): ?><form method="post" class="border-bottom py-3">
<input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="control"><input type="hidden" name="procedure" value="<?= e($procedure) ?>">
<div class="fw-semibold mb-1"><?= e($control['label']) ?></div><div class="small text-muted mb-2">Data prevista: <?= e($control['date']) ?> <?= e($control['slot'] ?? '') ?><?= $control['emergency'] ? ' · Emergenza' : '' ?></div><button class="btn btn-primary"><?= $control['inspection'] ? 'Continua procedura' : 'Avvia procedura guidata' ?></button>
</form><?php endforeach; ?>
</div></div></div></div>
<form method="post" class="mt-4"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="finish"><button class="btn btn-outline-secondary">Vai alla dashboard</button></form>
<?php include __DIR__ . '/partials/footer.php'; ?>
