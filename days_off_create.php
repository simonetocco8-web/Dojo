<?php
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/security.php';
require_once __DIR__ . '/core/db.php';

start_session();
$env  = require __DIR__ . '/config/env.php';
$base = rtrim($env['app']['base_url'] ?? '', '/');
$pdo  = db();
$user = current_user();

if (!$user || !(is_admin() || (($user['dipartimento'] ?? '') === 'Amministrazione'))) {
  http_response_code(403); exit('Permesso negato.');
}

$pdo = db();
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_check($_POST['csrf'] ?? '')) {
    $message = 'Token CSRF non valido.';
  } else {
    $uid  = (int)($_POST['user_id'] ?? 0);
    $day  = trim($_POST['day'] ?? '');
    $note = trim($_POST['note'] ?? '');

    // Normalizza data: accetta d/m/Y o Y-m-d
    if (preg_match('#^\d{2}/\d{2}/\d{4}$#', $day)) {
      [$d,$m,$y] = explode('/', $day);
      $day = sprintf('%04d-%02d-%02d', $y, $m, $d);
    }

    if ($uid <= 0 || !preg_match('#^\d{4}-\d{2}-\d{2}$#', $day)) {
      $message = 'Seleziona utente e una data valida.';
    } else {
      try {
        $pdo->beginTransaction();
        $ins = $pdo->prepare("INSERT INTO days_off (user_id, day, note, created_by) VALUES (?,?,?,?)");
        $ins->execute([$uid, $day, $note ?: null, $user['id'] ?? null]);
        $daysOffId = (int)$pdo->lastInsertId();
        $pdo->commit();

        // Crea evento su Google Calendar (best effort)
        require_once __DIR__ . '/google/gcal_days_off.php';
        try { gcal_days_off_create($pdo, $daysOffId); } catch (Throwable $e) { error_log($e->getMessage()); }

        header('Location: days_off_list.php?msg=created');
        exit;
      } catch (Throwable $e) {
        $pdo->rollBack();
        $message = 'Errore salvataggio: '.htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
      }
    }
  }
}

// elenco utenti attivi (puoi filtrare per dipartimento se vuoi)
$users = $pdo->query("SELECT id, nome, cognome, dipartimento FROM users WHERE deleted_at IS NULL ORDER BY cognome, nome")->fetchAll(PDO::FETCH_ASSOC);

$title = 'Nuovo Giorno Libero';
include __DIR__ . '/partials/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h5 mb-0">Nuovo Giorno Libero</h1>
  <a href="days_off_list.php" class="btn btn-outline-secondary btn-sm">Torna alla lista</a>
</div>

<?php if($message): ?><div class="alert alert-warning"><?= e($message) ?></div><?php endif; ?>

<div class="card">
  <div class="card-body">
    <form method="post">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

      <div class="mb-3">
        <label class="form-label">Utente</label>
        <select name="user_id" class="form-select" required>
          <option value="">— Seleziona —</option>
          <?php foreach ($users as $u): ?>
            <option value="<?= (int)$u['id'] ?>">
              <?= e(trim(($u['cognome'] ?? '').' '.($u['nome'] ?? ''))) ?> (<?= e($u['dipartimento']) ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="mb-3">
        <label class="form-label">Data</label>
        <input type="date" name="day" class="form-control" required>
      </div>

      <div class="mb-3">
        <label class="form-label">Note (facoltative)</label>
        <input type="text" name="note" class="form-control" maxlength="255" placeholder="es. permesso, cambio turno...">
      </div>

      <div class="d-flex gap-2">
        <button class="btn btn-primary">Salva</button>
        <a href="days_off_list.php" class="btn btn-outline-secondary">Annulla</a>
      </div>
    </form>
  </div>
</div>

<?php include __DIR__ . '/partials/footer.php'; ?>
