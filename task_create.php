<?php
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/security.php';
require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/sms.php';
require_once __DIR__ . '/core/task_sms_reminders.php';
require_once __DIR__ . '/notification/send-notification-chat.php';
start_session();
$env  = require __DIR__ . '/config/env.php';
$base = rtrim($env['app']['base_url'] ?? '', '/');
$pdo  = db();
$user = current_user();
if (!$user) { header('Location: ' . $base . '/index.php?msg=auth'); exit; }

ensure_task_user_assignments_table($pdo);
ensure_task_sms_reminders_table($pdo);

$allowedDeps = available_departments();
$allowedPri  = ['bassa','media','alta','urgente'];
$allowedRec  = ['nessuna','giornaliera','settimanale','mensile','annuale'];
$taskTitleMaxLength = 30;
$taskDescriptionMaxLength = 120;
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

function task_sms_body(string $title, string $description): string {
  $title = sms_utf8_substr(trim($title), 0, 30);
  $description = sms_utf8_substr(trim($description), 0, 120);
  $body = 'Task: ' . $title . "\n" . $description;
  return sms_utf8_substr($body, 0, 160);
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
    if (sms_utf8_length($title) > $taskTitleMaxLength) { $message = 'Il titolo può contenere al massimo ' . $taskTitleMaxLength . ' caratteri.'; }
    if (sms_utf8_length($description) > $taskDescriptionMaxLength) { $message = 'La descrizione può contenere al massimo ' . $taskDescriptionMaxLength . ' caratteri.'; }
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

        $smsMessage = task_sms_body($title, $description);
        $today = (new DateTimeImmutable('now', task_sms_timezone()))->format('Y-m-d');
        try {
          if ($due_date === $today) {
            sms_send_message($env, $smsRecipients, $smsMessage);
          } else {
            schedule_task_sms_reminder($pdo, $taskId, $smsRecipients, $smsMessage, $due_date);
          }
        } catch (Throwable $e) {
          error_log('Task SMS handling failed: ' . $e->getMessage());
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
        <form method="post" data-wait-feedback="Creazione task e gestione SMS in corso...">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <div class="row g-3">
            <div class="col-md-8">
              <div class="d-flex justify-content-between align-items-center mb-1">
                <label class="form-label mb-0" for="task_title">Titolo</label>
                <small class="text-muted" data-char-counter-for="task_title"><?= $taskTitleMaxLength ?> caratteri rimanenti</small>
              </div>
              <input type="text" id="task_title" name="title" class="form-control" value="<?= e($formData['title']) ?>" maxlength="<?= $taskTitleMaxLength ?>" data-maxlength="<?= $taskTitleMaxLength ?>" required>
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
              <div class="d-flex justify-content-between align-items-center mb-1">
                <label class="form-label mb-0" for="task_description">Descrizione</label>
                <small class="text-muted" data-char-counter-for="task_description"><?= $taskDescriptionMaxLength ?> caratteri rimanenti</small>
              </div>
              <textarea id="task_description" name="description" class="form-control" rows="4" maxlength="<?= $taskDescriptionMaxLength ?>" data-maxlength="<?= $taskDescriptionMaxLength ?>" required><?= e($formData['description']) ?></textarea>
            </div>
          </div>
          <div class="mt-3 d-flex gap-2">
            <button class="btn btn-primary">Crea compito</button>
            <a class="btn btn-outline-secondary" href="<?= e($base) ?>/tasks.php">Annulla</a>
          </div>
        </form>
        <script>
          (function () {
            document.querySelectorAll('[data-maxlength]').forEach(function (field) {
              var max = parseInt(field.getAttribute('data-maxlength'), 10) || 0;
              var counter = document.querySelector('[data-char-counter-for="' + field.id + '"]');
              if (!counter) return;
              var update = function () {
                var remaining = Math.max(0, max - Array.from(field.value).length);
                counter.textContent = remaining + (remaining === 1 ? ' carattere rimanente' : ' caratteri rimanenti');
              };
              field.addEventListener('input', update);
              update();
            });
          })();
        </script>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/partials/footer.php'; ?>
