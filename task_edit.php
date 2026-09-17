<?php
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/security.php';
require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/roles.php';
require_once __DIR__ . '/core/sms.php';
start_session();

$env = require __DIR__ . '/config/env.php';
$base = rtrim($env['app']['base_url'] ?? '', '/');
$pdo = db();
$user = current_user();
if (!$user) { header('Location: ' . $base . '/index.php?msg=auth'); exit; }

ensure_task_user_assignments_table($pdo);
ensure_task_recurrence_series_column($pdo);

$allowedViews = ['mio', 'tutti', 'completati', 'nonfattibili'];
$returnView = $_POST['return_view'] ?? $_GET['view'] ?? 'mio';
if (!in_array($returnView, $allowedViews, true)) $returnView = 'mio';
$returnUrl = $base . '/tasks.php?view=' . rawurlencode($returnView);

$taskId = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM tasks WHERE id=? AND deleted_at IS NULL LIMIT 1');
$stmt->execute([$taskId]);
$task = $stmt->fetch();
if (!$task || ($task['recurrence'] ?? 'nessuna') === 'nessuna') { header('Location: ' . $returnUrl); exit; }

$canManage = user_is_amministrazione($user) || (int)$task['created_by'] === (int)$user['id'];
if (!$canManage) { http_response_code(403); exit('Operazione non autorizzata.'); }

$allowedDeps = available_departments();
$allowedPri = ['bassa', 'media', 'alta', 'urgente'];
$allowedRec = ['giornaliera', 'settimanale', 'mensile', 'annuale'];
$titleMaxLength = 30;
$descriptionMaxLength = 120;
$users = $pdo->query("SELECT id, nome, cognome, email, dipartimento
                      FROM users WHERE deleted_at IS NULL AND is_active=1
                      ORDER BY cognome, nome, email")->fetchAll(PDO::FETCH_ASSOC);
$assignedStmt = $pdo->prepare('SELECT user_id FROM task_user_assignments WHERE task_id=? ORDER BY user_id');
$assignedStmt->execute([$taskId]);
$assignedIds = array_map('intval', $assignedStmt->fetchAll(PDO::FETCH_COLUMN));

$form = [
  'title' => (string)$task['title'],
  'description' => (string)$task['description'],
  'priority' => (string)$task['priority'],
  'due_date' => (string)$task['due_date'],
  'recurrence' => (string)$task['recurrence'],
  'target_type' => $assignedIds ? 'users' : 'department',
  'dipartimento' => (string)$task['dipartimento'],
  'user_ids' => $assignedIds,
];
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $form['title'] = trim((string)($_POST['title'] ?? ''));
  $form['description'] = trim((string)($_POST['description'] ?? ''));
  $form['priority'] = (string)($_POST['priority'] ?? 'media');
  $form['due_date'] = (string)($_POST['due_date'] ?? '');
  $form['recurrence'] = (string)($_POST['recurrence'] ?? 'giornaliera');
  $form['target_type'] = ($_POST['target_type'] ?? 'department') === 'users' ? 'users' : 'department';
  $form['dipartimento'] = (string)($_POST['dipartimento'] ?? 'Amministrazione');
  $postedIds = $_POST['user_ids'] ?? [];
  $form['user_ids'] = array_values(array_unique(array_filter(array_map('intval', is_array($postedIds) ? $postedIds : [$postedIds]))));

  $date = DateTime::createFromFormat('Y-m-d', $form['due_date']);
  if (!csrf_check($_POST['csrf'] ?? '')) $message = 'Token CSRF non valido.';
  elseif ($form['title'] === '' || sms_utf8_length($form['title']) > $titleMaxLength) $message = 'Il titolo è obbligatorio e può contenere al massimo 30 caratteri.';
  elseif ($form['description'] === '' || sms_utf8_length($form['description']) > $descriptionMaxLength) $message = 'La descrizione è obbligatoria e può contenere al massimo 120 caratteri.';
  elseif (!in_array($form['priority'], $allowedPri, true)) $message = 'Priorità non valida.';
  elseif (!in_array($form['recurrence'], $allowedRec, true)) $message = 'Ricorrenza non valida.';
  elseif (!$date || $date->format('Y-m-d') !== $form['due_date']) $message = 'Data di esecuzione non valida.';
  elseif ($form['target_type'] === 'department' && !in_array($form['dipartimento'], $allowedDeps, true)) $message = 'Dipartimento non valido.';
  elseif ($form['target_type'] === 'users' && !$form['user_ids']) $message = 'Seleziona almeno un utente destinatario.';

  if ($message === '') {
    try {
      $pdo->beginTransaction();
      $department = $form['dipartimento'];
      if ($form['target_type'] === 'users') {
        $placeholders = implode(',', array_fill(0, count($form['user_ids']), '?'));
        $validUsers = $pdo->prepare("SELECT id, dipartimento FROM users WHERE deleted_at IS NULL AND is_active=1 AND id IN ($placeholders)");
        $validUsers->execute($form['user_ids']);
        $selectedUsers = $validUsers->fetchAll(PDO::FETCH_ASSOC);
        if (count($selectedUsers) !== count($form['user_ids'])) throw new RuntimeException('Uno o più utenti selezionati non sono validi.');
        $departments = user_departments($selectedUsers[0]);
        $department = $departments[0] ?? 'Amministrazione';
      }

      $seriesId = (int)($task['recurrence_series_id'] ?: $task['id']);
      $futureIdsStmt = $pdo->prepare('SELECT id FROM tasks WHERE recurrence_series_id=? AND due_date>=? AND deleted_at IS NULL');
      $futureIdsStmt->execute([$seriesId, $task['due_date']]);
      $futureIds = array_map('intval', $futureIdsStmt->fetchAll(PDO::FETCH_COLUMN));
      if (!$futureIds) $futureIds = [$taskId];
      $futurePlaceholders = implode(',', array_fill(0, count($futureIds), '?'));
      $update = $pdo->prepare("UPDATE tasks SET title=?, description=?, priority=?, dipartimento=?, recurrence=? WHERE id IN ($futurePlaceholders)");
      $update->execute(array_merge([$form['title'], $form['description'], $form['priority'], $department, $form['recurrence']], $futureIds));
      $pdo->prepare('UPDATE tasks SET due_date=? WHERE id=?')->execute([$form['due_date'], $taskId]);

      $deleteAssignments = $pdo->prepare("DELETE FROM task_user_assignments WHERE task_id IN ($futurePlaceholders)");
      $deleteAssignments->execute($futureIds);
      if ($form['target_type'] === 'users') {
        $insertAssignment = $pdo->prepare('INSERT INTO task_user_assignments (task_id, user_id) VALUES (?, ?)');
        foreach ($futureIds as $futureId) foreach ($form['user_ids'] as $selectedId) $insertAssignment->execute([$futureId, $selectedId]);
      }
      $pdo->commit();
      header('Location: ' . $returnUrl . '&msg=recurrence_updated');
      exit;
    } catch (Throwable $exception) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      $message = 'Impossibile aggiornare il task ricorrente: ' . $exception->getMessage();
    }
  }
}

$title = 'Modifica task ricorrente';
include __DIR__ . '/partials/header.php';
?>
<div class="row justify-content-center">
  <div class="col-12 col-lg-8 col-xl-7">
    <div class="card shadow-sm"><div class="card-body">
      <h1 class="h5 mb-2">Modifica task ricorrente</h1>
      <p class="text-muted small">Le modifiche saranno applicate al task selezionato e alle eventuali occorrenze future già create.</p>
      <?php if ($message !== ''): ?><div class="alert alert-danger"><?= e($message) ?></div><?php endif; ?>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="id" value="<?= $taskId ?>">
        <input type="hidden" name="return_view" value="<?= e($returnView) ?>">
        <div class="row g-3">
          <div class="col-md-8"><label class="form-label" for="title">Titolo</label><input class="form-control" id="title" name="title" maxlength="<?= $titleMaxLength ?>" value="<?= e($form['title']) ?>" required></div>
          <div class="col-md-4"><label class="form-label" for="priority">Priorità</label><select class="form-select" id="priority" name="priority"><?php foreach ($allowedPri as $priority): ?><option value="<?= e($priority) ?>" <?= $form['priority'] === $priority ? 'selected' : '' ?>><?= e(ucfirst($priority)) ?></option><?php endforeach; ?></select></div>
          <div class="col-12"><label class="form-label" for="description">Descrizione</label><textarea class="form-control" id="description" name="description" maxlength="<?= $descriptionMaxLength ?>" rows="4" required><?= e($form['description']) ?></textarea></div>
          <div class="col-md-6"><label class="form-label" for="due_date">Data di esecuzione</label><input type="date" class="form-control" id="due_date" name="due_date" value="<?= e($form['due_date']) ?>" required></div>
          <div class="col-md-6"><label class="form-label" for="recurrence">Ricorrenza</label><select class="form-select" id="recurrence" name="recurrence"><?php foreach ($allowedRec as $recurrence): ?><option value="<?= e($recurrence) ?>" <?= $form['recurrence'] === $recurrence ? 'selected' : '' ?>><?= e(ucfirst($recurrence)) ?></option><?php endforeach; ?></select></div>
          <div class="col-12"><label class="form-label">Destinatari</label><div class="form-check"><input class="form-check-input" type="radio" name="target_type" id="target_department" value="department" <?= $form['target_type'] === 'department' ? 'checked' : '' ?>><label class="form-check-label" for="target_department">Dipartimento</label></div><div class="form-check"><input class="form-check-input" type="radio" name="target_type" id="target_users" value="users" <?= $form['target_type'] === 'users' ? 'checked' : '' ?>><label class="form-check-label" for="target_users">Utenti specifici</label></div></div>
          <div class="col-md-6"><label class="form-label" for="dipartimento">Dipartimento</label><select class="form-select" id="dipartimento" name="dipartimento"><?php foreach ($allowedDeps as $department): ?><option value="<?= e($department) ?>" <?= $form['dipartimento'] === $department ? 'selected' : '' ?>><?= e($department) ?></option><?php endforeach; ?></select></div>
          <div class="col-md-6"><label class="form-label" for="user_ids">Utenti</label><select class="form-select" id="user_ids" name="user_ids[]" multiple size="7"><?php foreach ($users as $recipient): ?><?php $label = trim(($recipient['cognome'] ?? '') . ' ' . ($recipient['nome'] ?? '')) ?: $recipient['email']; ?><option value="<?= (int)$recipient['id'] ?>" <?= in_array((int)$recipient['id'], $form['user_ids'], true) ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
        </div>
        <div class="mt-3 d-flex gap-2"><button class="btn btn-primary">Salva modifiche</button><a class="btn btn-outline-secondary" href="<?= e($returnUrl) ?>">Annulla</a></div>
      </form>
    </div></div>
  </div>
</div>
<?php include __DIR__ . '/partials/footer.php'; ?>
