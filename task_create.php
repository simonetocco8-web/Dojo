<?php
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/security.php';
require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/sms.php';
require_once __DIR__ . '/notification/send-notification-chat.php';
start_session();
$env  = require __DIR__ . '/config/env.php';
$base = rtrim($env['app']['base_url'] ?? '', '/');
$pdo  = db();
$user = current_user();
if (!$user) { header('Location: ' . $base . '/index.php?msg=auth'); exit; }

ensure_task_user_assignments_table($pdo);

$allowedDeps = available_departments();
$allowedPri  = ['bassa','media','alta','urgente'];
$allowedRec  = ['nessuna','giornaliera','settimanale','mensile','annuale'];
$message = '';
$formData = [
  'target_type' => 'department',
  'dipartimento' => 'Amministrazione',
  'user_ids' => [],
  'title' => '',
  'description' => '',
  'priority' => 'media',
  'due_date' => '',
  'recurrence' => 'nessuna',
];

function task_sms_body(string $title, string $dueDate, string $priority): string {
  $dateLabel = $dueDate;
  $dt = DateTime::createFromFormat('Y-m-d', $dueDate);
  if ($dt && $dt->format('Y-m-d') === $dueDate) {
    $dateLabel = $dt->format('d/m/Y');
  }
  $body = sprintf('Nuovo task: %s - Scad. %s - Priorita %s', $title, $dateLabel, $priority);
  if ((function_exists('mb_strlen') ? mb_strlen($body, 'UTF-8') : strlen($body)) > 160) {
    $suffix = sprintf(' - Scad. %s - Priorita %s', $dateLabel, $priority);
    $maxTitle = 160 - (function_exists('mb_strlen') ? mb_strlen('Nuovo task: ', 'UTF-8') + mb_strlen($suffix, 'UTF-8') : strlen('Nuovo task: ') + strlen($suffix));
    $maxTitle = max(10, $maxTitle);
    $shortTitle = function_exists('mb_substr') ? mb_substr($title, 0, $maxTitle, 'UTF-8') : substr($title, 0, $maxTitle);
    $body = 'Nuovo task: ' . $shortTitle . $suffix;
  }
  return $body;
}

$users = $pdo->query("SELECT id, nome, cognome, email, telefono, dipartimento
                      FROM users
                      WHERE deleted_at IS NULL AND is_active = 1
                      ORDER BY cognome, nome, email")->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD']==='POST') {
  $formData['target_type'] = $_POST['target_type'] ?? 'department';
  $formData['dipartimento'] = $_POST['dipartimento'] ?? 'Amministrazione';
  $formData['user_ids'] = $_POST['user_ids'] ?? [];
  if (!is_array($formData['user_ids'])) $formData['user_ids'] = [$formData['user_ids']];
  $formData['title'] = trim($_POST['title'] ?? '');
  $formData['description'] = trim($_POST['description'] ?? '');
  $formData['priority'] = $_POST['priority'] ?? 'media';
  $formData['due_date'] = $_POST['due_date'] ?? '';
  $formData['recurrence'] = $_POST['recurrence'] ?? 'nessuna';

  if (!csrf_check($_POST['csrf'] ?? '')) {
    $message = 'Token CSRF non valido.';
  } else {
    $title       = $formData['title'];
    $description = $formData['description'];
    $priority    = $formData['priority'];
    $targetType  = in_array($formData['target_type'], ['department','users'], true) ? $formData['target_type'] : 'department';
    $dip         = $formData['dipartimento'];
    $selectedIds = array_values(array_unique(array_filter(array_map('intval', $formData['user_ids']))));
    $due_date    = $formData['due_date'];
    $recurrence  = $formData['recurrence'];

    if (!in_array($priority, $allowedPri, true)) $priority = 'media';
    if (!in_array($recurrence, $allowedRec, true)) $recurrence = 'nessuna';
    if ($targetType === 'department' && !in_array($dip, $allowedDeps, true)) { $message = 'Dipartimento non valido.'; }
    if ($targetType === 'users' && !$selectedIds) { $message = 'Seleziona almeno un utente destinatario.'; }

    $dt = DateTime::createFromFormat('Y-m-d', $due_date);
    if (!$dt || $dt->format('Y-m-d') !== $due_date) { $message = 'Data di esecuzione non valida.'; }

    $taskUsers = [];
    $smsRecipients = [];
    if (!$message && $targetType === 'users') {
      $placeholders = implode(',', array_fill(0, count($selectedIds), '?'));
      $st = $pdo->prepare("SELECT id, telefono, dipartimento FROM users WHERE deleted_at IS NULL AND is_active = 1 AND id IN ($placeholders)");
      $st->execute($selectedIds);
      $taskUsers = $st->fetchAll(PDO::FETCH_ASSOC);
      if (!$taskUsers) {
        $message = 'Nessun utente valido selezionato.';
      } else {
        foreach ($taskUsers as $taskUser) {
          if (!empty($taskUser['telefono'])) $smsRecipients[] = $taskUser['telefono'];
        }
        $firstDepartments = user_departments($taskUsers[0]);
        $dip = $firstDepartments[0] ?? 'Amministrazione';
        if (!in_array($dip, $allowedDeps, true)) $dip = 'Amministrazione';
      }
    } elseif (!$message && $targetType === 'department') {
      $st = $pdo->prepare("SELECT telefono FROM users WHERE deleted_at IS NULL AND is_active = 1 AND telefono <> '' AND FIND_IN_SET(?, REPLACE(dipartimento, ' ', '')) > 0");
      $st->execute([$dip]);
      $smsRecipients = $st->fetchAll(PDO::FETCH_COLUMN);
    }

    if (!$message && $title && $description && $due_date) {
      try {
        $pdo->beginTransaction();
        $q = 'INSERT INTO tasks (title, description, priority, dipartimento, due_date, recurrence, created_by)
              VALUES (?,?,?,?,?,?,?)';
        $pdo->prepare($q)->execute([$title, $description, $priority, $dip, $due_date, $recurrence, $user['id']]);
        $taskId = (int)$pdo->lastInsertId();

        if ($targetType === 'users') {
          $assign = $pdo->prepare('INSERT IGNORE INTO task_user_assignments (task_id, user_id) VALUES (?, ?)');
          foreach ($taskUsers as $taskUser) {
            $assign->execute([$taskId, (int)$taskUser['id']]);
          }
        }
        $pdo->commit();

        if ($targetType === 'department') {
          notify($title, $dip, $due_date, $priority, $description);
        }

        try {
          sms_send_message($env, $smsRecipients, task_sms_body($title, $due_date, $priority));
        } catch (Throwable $e) {
          error_log('Task SMS send failed: ' . $e->getMessage());
          header('Location: tasks.php?id=' . $taskId . '&msg=' . rawurlencode('created_sms_error: ' . $e->getMessage()));
          exit;
        }

        header('Location: tasks.php?id=' . $taskId . '&msg=created');
        exit;
      } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $message = 'Errore durante la creazione del compito: '.htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
      }
    } else if(!$message) {
      $message = 'Compila titolo, descrizione e data.';
    }
  }
}

$title = 'Nuovo compito';
include __DIR__ . '/partials/header.php';
?>
<div class="row justify-content-center">
  <div class="col-12 col-lg-8 col-xl-7">
    <div class="card shadow-sm">
      <div class="card-body">
        <h1 class="h5 mb-3">Crea nuovo compito</h1>
        <?php if($message): ?><div class="alert alert-info"><?= e($message) ?></div><?php endif; ?>
        <form method="post" data-wait-feedback="Creazione task e invio SMS in corso...">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <div class="row g-3">
            <div class="col-md-8">
              <label class="form-label">Titolo</label>
              <input type="text" name="title" class="form-control" value="<?= e($formData['title']) ?>" required>
            </div>
            <div class="col-md-4">
              <label class="form-label">Priorità</label>
              <select name="priority" class="form-select">
                <?php foreach($allowedPri as $p): ?>
                  <option value="<?= e($p) ?>" <?= $formData['priority'] === $p ? 'selected' : '' ?>><?= e(ucfirst($p)) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label">Destinatari task</label>
              <div class="form-check">
                <input class="form-check-input" type="radio" name="target_type" value="department" id="target_department" <?= $formData['target_type'] !== 'users' ? 'checked' : '' ?>>
                <label class="form-check-label" for="target_department">Dipartimento specifico</label>
              </div>
              <div class="form-check">
                <input class="form-check-input" type="radio" name="target_type" value="users" id="target_users" <?= $formData['target_type'] === 'users' ? 'checked' : '' ?>>
                <label class="form-check-label" for="target_users">Uno o più utenti</label>
              </div>
            </div>
            <div class="col-md-6">
              <label class="form-label">Dipartimento responsabile</label>
              <select name="dipartimento" class="form-select">
                <?php foreach($allowedDeps as $d): ?>
                  <option value="<?= e($d) ?>" <?= $formData['dipartimento'] === $d ? 'selected' : '' ?>><?= e($d) ?></option>
                <?php endforeach; ?>
              </select>
              <div class="form-text">Usato quando scegli “Dipartimento specifico”.</div>
            </div>
            <div class="col-md-6">
              <label class="form-label">Utenti destinatari</label>
              <select name="user_ids[]" class="form-select" multiple size="7">
                <?php foreach($users as $u): ?>
                  <?php $label = trim(($u['cognome'] ?? '').' '.($u['nome'] ?? '')); $label = $label !== '' ? $label : $u['email']; ?>
                  <option value="<?= (int)$u['id'] ?>" <?= in_array((int)$u['id'], array_map('intval', $formData['user_ids']), true) ? 'selected' : '' ?>>
                    <?= e($label) ?><?= !empty($u['telefono']) ? ' — '.e($u['telefono']) : ' — senza telefono' ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <div class="form-text">Usato quando scegli “Uno o più utenti”.</div>
            </div>
            <div class="col-md-6">
              <label class="form-label">Data di esecuzione</label>
              <input type="date" name="due_date" class="form-control" value="<?= e($formData['due_date']) ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Ricorrenza</label>
              <select name="recurrence" class="form-select">
                <?php foreach($allowedRec as $r): ?>
                  <option value="<?= e($r) ?>" <?= $formData['recurrence'] === $r ? 'selected' : '' ?>><?= e(ucfirst($r)) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label">Descrizione</label>
              <textarea name="description" class="form-control" rows="4" required><?= e($formData['description']) ?></textarea>
            </div>
          </div>
          <div class="mt-3 d-flex gap-2">
            <button class="btn btn-primary">Crea compito</button>
            <a class="btn btn-outline-secondary" href="<?= e($base) ?>/tasks.php">Annulla</a>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/partials/footer.php'; ?>
