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

$allowedDeps = ['Amministrazione','Reception','Booking','Manutenzione','Bar','HouseKeeping'];
$allowedPri  = ['bassa','media','alta','urgente'];
$allowedRec  = ['nessuna','giornaliera','settimanale','mensile','annuale'];
$message = '';

if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (!csrf_check($_POST['csrf'] ?? '')) {
    $message = 'Token CSRF non valido.';
  } else {
    $title       = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $priority    = $_POST['priority'] ?? 'media';
    $dip         = $_POST['dipartimento'] ?? '';
    $due_date    = $_POST['due_date'] ?? '';
    $recurrence  = $_POST['recurrence'] ?? 'nessuna';

    if (!in_array($priority, $allowedPri, true)) $priority = 'media';
    if (!in_array($dip, $allowedDeps, true)) { $message = 'Dipartimento non valido.'; }
    if (!in_array($recurrence, $allowedRec, true)) $recurrence = 'nessuna';

    if (!$message && $title && $description && $due_date) {
      try {
        $q = 'INSERT INTO tasks (title, description, priority, dipartimento, due_date, recurrence, created_by)
              VALUES (?,?,?,?,?,?,?)';
        $pdo->prepare($q)->execute([$title, $description, $priority, $dip, $due_date, $recurrence, $user['id']]);
        header('Location: ' . $base . '/tasks.php?view=mio');
        exit;
      } catch (PDOException $e) {
        $message = 'Errore durante la creazione del compito.';
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
        <form method="post">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <div class="row g-3">
            <div class="col-md-8">
              <label class="form-label">Titolo</label>
              <input type="text" name="title" class="form-control" required>
            </div>
            <div class="col-md-4">
              <label class="form-label">Priorità</label>
              <select name="priority" class="form-select">
                <option value="bassa">Bassa</option>
                <option value="media" selected>Media</option>
                <option value="alta">Alta</option>
                <option value="urgente">Urgente</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Dipartimento responsabile</label>
              <select name="dipartimento" class="form-select" required>
                <?php foreach($allowedDeps as $d): ?>
                  <option value="<?= e($d) ?>"><?= e($d) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label">Data di esecuzione</label>
              <input type="date" name="due_date" class="form-control" required>
            </div>
            <div class="col-md-3">
              <label class="form-label">Ricorrenza</label>
              <select name="recurrence" class="form-select">
                <option value="nessuna">Nessuna</option>
                <option value="giornaliera">Giornaliera</option>
                <option value="settimanale">Settimanale</option>
                <option value="mensile">Mensile</option>
                <option value="annuale">Annuale</option>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label">Descrizione</label>
              <textarea name="description" class="form-control" rows="4" required></textarea>
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
