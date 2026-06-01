<?php
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/security.php';
require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/roles.php';
require_once __DIR__ . '/core/sms.php';

require_login();
start_session();

$env  = require __DIR__ . '/config/env.php';
$base = rtrim($env['app']['base_url'] ?? '', '/');
$pdo  = db();
$user = current_user();

if (!$user || !user_can_send_sms($user)) {
  http_response_code(403);
  $title = 'Accesso negato';
  include __DIR__ . '/partials/header.php';
  echo '<div class="alert alert-danger">Funzionalità disponibile solo per Amministrazione, Reception e Booking.</div>';
  include __DIR__ . '/partials/footer.php';
  exit;
}

ensure_sms_history_table($pdo);

$allowedDeps = available_departments();
$message = '';
$messageType = 'info';

$users = $pdo->query("SELECT id, nome, cognome, email, telefono, dipartimento
                      FROM users
                      WHERE deleted_at IS NULL AND is_active = 1 AND telefono <> ''
                      ORDER BY cognome, nome, email")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_check($_POST['csrf'] ?? '')) {
    $message = 'Token CSRF non valido.';
    $messageType = 'danger';
  } else {
    $recipientType = $_POST['recipient_type'] ?? 'users';
    $body = trim($_POST['body'] ?? '');
    $recipients = [];
    $recipientLabels = [];

    if ($body === '') {
      $message = 'Inserire il corpo del SMS.';
      $messageType = 'danger';
    } elseif ((function_exists('mb_strlen') ? mb_strlen($body, 'UTF-8') : strlen($body)) > 160) {
      $message = 'Il corpo del SMS può contenere al massimo 160 caratteri.';
      $messageType = 'danger';
    } elseif ($recipientType === 'users') {
      $selectedIds = $_POST['user_ids'] ?? [];
      if (!is_array($selectedIds)) $selectedIds = [$selectedIds];
      $selectedIds = array_values(array_unique(array_filter(array_map('intval', $selectedIds))));

      if (!$selectedIds) {
        $message = 'Selezionare almeno un utente destinatario.';
        $messageType = 'danger';
      } else {
        $placeholders = implode(',', array_fill(0, count($selectedIds), '?'));
        $stmt = $pdo->prepare("SELECT id, nome, cognome, email, telefono
                               FROM users
                               WHERE deleted_at IS NULL AND is_active = 1 AND telefono <> '' AND id IN ($placeholders)
                               ORDER BY cognome, nome, email");
        $stmt->execute($selectedIds);
        $selectedUsers = $stmt->fetchAll();
        foreach ($selectedUsers as $selectedUser) {
          $recipients[] = $selectedUser['telefono'];
          $name = trim(($selectedUser['nome'] ?? '') . ' ' . ($selectedUser['cognome'] ?? ''));
          $recipientLabels[] = ($name !== '' ? $name : $selectedUser['email']) . ' (' . $selectedUser['telefono'] . ')';
        }
      }
    } elseif ($recipientType === 'department') {
      $department = $_POST['department'] ?? '';
      if (!in_array($department, $allowedDeps, true)) {
        $message = 'Dipartimento destinatario non valido.';
        $messageType = 'danger';
      } else {
        $stmt = $pdo->prepare("SELECT nome, cognome, email, telefono
                               FROM users
                               WHERE deleted_at IS NULL
                                 AND is_active = 1
                                 AND telefono <> ''
                                 AND FIND_IN_SET(?, REPLACE(dipartimento, ' ', '')) > 0
                               ORDER BY cognome, nome, email");
        $stmt->execute([$department]);
        $departmentUsers = $stmt->fetchAll();
        foreach ($departmentUsers as $departmentUser) {
          $recipients[] = $departmentUser['telefono'];
          $name = trim(($departmentUser['nome'] ?? '') . ' ' . ($departmentUser['cognome'] ?? ''));
          $recipientLabels[] = ($name !== '' ? $name : $departmentUser['email']) . ' (' . $departmentUser['telefono'] . ')';
        }
        if (!$recipients) {
          $message = 'Nessun utente attivo con telefono trovato per il dipartimento selezionato.';
          $messageType = 'danger';
        }
      }
    } else {
      $message = 'Tipo destinatario non valido.';
      $messageType = 'danger';
    }

    if (!$message) {
      try {
        $recipients = array_values(array_unique($recipients));
        sms_send_message($env, $recipients, $body);

        $stmt = $pdo->prepare('INSERT INTO sms_history (sent_by, recipient_type, recipients, message, sent_at)
                               VALUES (?, ?, ?, ?, NOW())');
        $stmt->execute([
          $user['id'],
          $recipientType,
          implode(', ', $recipientLabels),
          $body,
        ]);

        header('Location: ' . $base . '/send_sms.php?msg=sent');
        exit;
      } catch (Throwable $e) {
        error_log('Manual SMS send failed: ' . $e->getMessage());
        $message = 'SMS non inviato: ' . $e->getMessage();
        $messageType = 'danger';
      }
    }
  }
}

if (($_GET['msg'] ?? '') === 'sent') {
  $message = 'SMS inviato e salvato nello storico.';
  $messageType = 'success';
}

$history = $pdo->query("SELECT h.*, u.nome, u.cognome, u.email
                       FROM sms_history h
                       LEFT JOIN users u ON u.id = h.sent_by
                       ORDER BY h.sent_at DESC, h.id DESC
                       LIMIT 100")->fetchAll();

$title = 'Invia SMS';
include __DIR__ . '/partials/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h5 mb-0">Invia SMS</h1>
</div>

<?php if ($message): ?>
  <div class="alert alert-<?= e($messageType) ?>"><?= e($message) ?></div>
<?php endif; ?>

<div class="row g-4">
  <div class="col-12 col-lg-5">
    <div class="card shadow-sm">
      <div class="card-body">
        <h2 class="h6 mb-3">Nuovo SMS</h2>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

          <div class="mb-3">
            <label class="form-label">Tipo destinatario</label>
            <select name="recipient_type" class="form-select">
              <option value="users">Utente singolo / più utenti</option>
              <option value="department">Intero dipartimento</option>
            </select>
            <div class="form-text">Per inviare a un solo utente, seleziona un solo nominativo nella lista.</div>
          </div>

          <div class="mb-3">
            <label class="form-label">Utenti</label>
            <select name="user_ids[]" class="form-select" multiple size="8">
              <?php foreach ($users as $u): ?>
                <?php
                  $labelName = trim(($u['nome'] ?? '') . ' ' . ($u['cognome'] ?? ''));
                  $labelName = $labelName !== '' ? $labelName : $u['email'];
                ?>
                <option value="<?= (int)$u['id'] ?>"><?= e($labelName) ?> — <?= e($u['telefono']) ?> — <?= e(departments_label($u['dipartimento'] ?? '')) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="mb-3">
            <label class="form-label">Dipartimento</label>
            <select name="department" class="form-select">
              <?php foreach ($allowedDeps as $dep): ?>
                <option value="<?= e($dep) ?>"><?= e($dep) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="mb-3">
            <label class="form-label">Corpo SMS</label>
            <textarea name="body" class="form-control" rows="4" maxlength="160" required><?= e($_POST['body'] ?? '') ?></textarea>
            <div class="form-text">Massimo 160 caratteri.</div>
          </div>

          <button class="btn btn-primary">Invia SMS</button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-7">
    <div class="card shadow-sm">
      <div class="card-body">
        <h2 class="h6 mb-3">Storico SMS</h2>
        <div class="table-responsive">
          <table class="table table-sm align-middle">
            <thead>
              <tr>
                <th>Data/Ora</th>
                <th>Inviato da</th>
                <th>Destinatari</th>
                <th>Messaggio</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$history): ?>
                <tr><td colspan="4" class="text-muted">Nessun SMS inviato.</td></tr>
              <?php endif; ?>
              <?php foreach ($history as $row): ?>
                <?php
                  $senderName = trim(($row['nome'] ?? '') . ' ' . ($row['cognome'] ?? ''));
                  $senderName = $senderName !== '' ? $senderName : ($row['email'] ?? 'Utente eliminato');
                ?>
                <?php
                  $sentAtTs = strtotime((string)$row['sent_at']);
                  $sentAtLabel = $sentAtTs ? date('d/m/Y H:i', $sentAtTs) : (string)$row['sent_at'];
                ?>
                <tr>
                  <td class="text-nowrap"><?= e($sentAtLabel) ?></td>
                  <td><?= e($senderName) ?></td>
                  <td><?= e($row['recipients']) ?></td>
                  <td><?= e($row['message']) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/partials/footer.php'; ?>
