<?php
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/security.php';
require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/roles.php';

start_session();
$env  = require __DIR__ . '/config/env.php';
$base = rtrim($env['app']['base_url'] ?? '', '/');
$pdo  = db();
$user = current_user();

if (!$user || !user_is_amministrazione($user)) {
  http_response_code(403); exit('Permesso negato.');
}

ensure_overtime_entries_table($pdo);

$message = '';
$messageType = 'info';
$msg = $_GET['msg'] ?? '';
$formData = [
  'user_id' => '',
  'work_date' => (new DateTimeImmutable('today'))->format('Y-m-d'),
  'hours' => '',
  'note' => '',
];

if ($msg === 'created') {
  $message = 'Straordinario registrato.';
  $messageType = 'success';
} elseif ($msg === 'deleted') {
  $message = 'Straordinario eliminato.';
  $messageType = 'success';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? 'create';

  if (!csrf_check($_POST['csrf'] ?? '')) {
    $message = 'Token CSRF non valido.';
    $messageType = 'danger';
  } elseif ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
      $stmt = $pdo->prepare('UPDATE overtime_entries SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL');
      $stmt->execute([$id]);
      header('Location: ' . $base . '/overtime.php?msg=deleted');
      exit;
    }
    $message = 'Straordinario non valido.';
    $messageType = 'danger';
  } else {
    $formData['user_id'] = (string)($_POST['user_id'] ?? '');
    $formData['work_date'] = trim((string)($_POST['work_date'] ?? ''));
    $formData['hours'] = trim(str_replace(',', '.', (string)($_POST['hours'] ?? '')));
    $formData['note'] = trim((string)($_POST['note'] ?? ''));

    $targetUserId = (int)$formData['user_id'];
    $workDate = DateTime::createFromFormat('Y-m-d', $formData['work_date']);
    $hours = filter_var($formData['hours'], FILTER_VALIDATE_FLOAT);

    if ($targetUserId <= 0) {
      $message = 'Seleziona un utente.';
      $messageType = 'danger';
    } elseif (!$workDate || $workDate->format('Y-m-d') !== $formData['work_date']) {
      $message = 'Data non valida.';
      $messageType = 'danger';
    } elseif ($hours === false || $hours <= 0) {
      $message = 'Inserisci un numero di ore maggiore di zero.';
      $messageType = 'danger';
    } else {
      $checkUser = $pdo->prepare('SELECT id FROM users WHERE id = ? AND deleted_at IS NULL AND is_active = 1 LIMIT 1');
      $checkUser->execute([$targetUserId]);
      if (!$checkUser->fetchColumn()) {
        $message = 'Utente selezionato non valido o non attivo.';
        $messageType = 'danger';
      } else {
        $stmt = $pdo->prepare('INSERT INTO overtime_entries (user_id, work_date, hours, note, created_by) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([
          $targetUserId,
          $workDate->format('Y-m-d'),
          number_format((float)$hours, 2, '.', ''),
          $formData['note'] !== '' ? $formData['note'] : null,
          (int)$user['id'],
        ]);
        header('Location: ' . $base . '/overtime.php?msg=created');
        exit;
      }
    }
  }
}

$month = $_GET['month'] ?? (new DateTimeImmutable('first day of this month'))->format('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
  $month = (new DateTimeImmutable('first day of this month'))->format('Y-m');
}
$start = $month . '-01';
$end = (new DateTimeImmutable($start))->modify('last day of this month')->format('Y-m-d');

$users = $pdo->query("SELECT id, nome, cognome, email, dipartimento FROM users WHERE deleted_at IS NULL AND is_active = 1 ORDER BY cognome ASC, nome ASC, email ASC")->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("\n  SELECT o.id, o.work_date, o.hours, o.note, o.created_at,\n         u.nome, u.cognome, u.email, u.dipartimento,\n         cu.nome AS created_by_nome, cu.cognome AS created_by_cognome, cu.email AS created_by_email\n  FROM overtime_entries o\n  JOIN users u ON u.id = o.user_id\n  LEFT JOIN users cu ON cu.id = o.created_by\n  WHERE o.deleted_at IS NULL\n    AND o.work_date BETWEEN ? AND ?\n  ORDER BY o.work_date DESC, u.cognome ASC, u.nome ASC, o.id DESC\n");
$stmt->execute([$start, $end]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$title = 'Straordinari';
include __DIR__ . '/partials/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h5 mb-0">Straordinari</h1>
  <a class="btn btn-outline-primary btn-sm" href="<?= e($base) ?>/overtime_monthly.php">Calcolo Mensile</a>
</div>

<?php if ($message): ?>
  <div class="alert alert-<?= e($messageType) ?>"><?= e($message) ?></div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-12 col-lg-4">
    <div class="card shadow-sm">
      <div class="card-body">
        <h2 class="h6 mb-3">Inserisci ore extra</h2>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="create">
          <div class="mb-3">
            <label class="form-label">Utente</label>
            <select name="user_id" class="form-select" required>
              <option value="">Seleziona...</option>
              <?php foreach ($users as $u): ?>
                <?php $label = trim(($u['cognome'] ?? '') . ' ' . ($u['nome'] ?? '')); $label = $label !== '' ? $label : $u['email']; ?>
                <option value="<?= (int)$u['id'] ?>" <?= (int)$formData['user_id'] === (int)$u['id'] ? 'selected' : '' ?>>
                  <?= e($label) ?> — <?= e(departments_label($u['dipartimento'] ?? '')) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Data</label>
            <input type="date" name="work_date" class="form-control" value="<?= e($formData['work_date']) ?>" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Ore straordinarie</label>
            <input type="number" name="hours" class="form-control" min="0.25" step="0.25" value="<?= e($formData['hours']) ?>" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Note</label>
            <textarea name="note" class="form-control" rows="3"><?= e($formData['note']) ?></textarea>
          </div>
          <button class="btn btn-primary">Salva straordinario</button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-8">
    <div class="card shadow-sm">
      <div class="card-body">
        <form class="row g-2 align-items-end mb-3" method="get">
          <div class="col-auto">
            <label class="form-label">Mese</label>
            <input type="month" class="form-control" name="month" value="<?= e($month) ?>">
          </div>
          <div class="col-auto">
            <button class="btn btn-outline-secondary btn-sm">Filtra</button>
          </div>
        </form>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead>
              <tr>
                <th>Data</th>
                <th>Utente</th>
                <th>Dipartimento</th>
                <th class="text-end">Ore</th>
                <th>Note</th>
                <th class="text-end">Azioni</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rows as $row): ?>
                <tr>
                  <td><?= e((new DateTime($row['work_date']))->format('d/m/Y')) ?></td>
                  <td><?= e(trim(($row['cognome'] ?? '') . ' ' . ($row['nome'] ?? '')) ?: ($row['email'] ?? '')) ?></td>
                  <td><?= e(departments_label($row['dipartimento'] ?? '')) ?></td>
                  <td class="text-end"><?= e(number_format((float)$row['hours'], 2, ',', '.')) ?></td>
                  <td><?= nl2br(e($row['note'] ?? '')) ?></td>
                  <td class="text-end">
                    <form method="post" class="d-inline" data-confirm-message="Eliminare questo straordinario?">
                      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                      <button class="btn btn-link p-0 text-danger" title="Elimina">🗑️</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
              <?php if (!$rows): ?>
                <tr><td colspan="6" class="text-center text-muted py-3">Nessuno straordinario registrato nel mese selezionato.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/partials/footer.php'; ?>
