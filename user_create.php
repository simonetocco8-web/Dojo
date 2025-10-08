<?php
// user_create.php — creazione nuovo utente in pagina dedicata
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/security.php';
require_once __DIR__ . '/core/db.php';

require_admin();
start_session();

$env  = require __DIR__ . '/config/env.php';
$base = rtrim($env['app']['base_url'] ?? '', '/');
$pdo  = db();

$message = '';
$allowedDeps = ['Amministrazione','Reception','Booking','Manutenzione','Bar','HouseKeeping'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_check($_POST['csrf'] ?? '')) {
    $message = 'Token CSRF non valido.';
  } else {
    $email        = trim($_POST['email'] ?? '');
    $password     = $_POST['password'] ?? '';
    $role         = $_POST['role'] ?? 'editor';
    $is_active    = isset($_POST['is_active']) ? 1 : 0;
    $nome         = trim($_POST['nome'] ?? '');
    $cognome      = trim($_POST['cognome'] ?? '');
    $telefono     = trim($_POST['telefono'] ?? '');
    $dipartimento = $_POST['dipartimento'] ?? 'Amministrazione';

    if (!in_array($dipartimento, $allowedDeps, true)) {
      $dipartimento = 'Amministrazione';
    }

    if ($email && $password && $nome && $cognome) {
      try {
        // email già in uso (tra non eliminati)?
        $chk = $pdo->prepare('SELECT id FROM users WHERE email = ? AND deleted_at IS NULL LIMIT 1');
        $chk->execute([$email]);
        if ($chk->fetch()) {
          $message = 'Questa email è già in uso da un altro utente.';
        } else {
          $hash = password_hash($password, PASSWORD_DEFAULT);
          $q = 'INSERT INTO users (email, password_hash, role, is_active, nome, cognome, telefono, dipartimento)
                VALUES (?,?,?,?,?,?,?,?)';
          $pdo->prepare($q)->execute([$email, $hash, $role, $is_active, $nome, $cognome, $telefono, $dipartimento]);

          // redirect all’elenco con messaggio
          header('Location: ' . $base . '/users.php?msg=created');
          exit;
        }
      } catch (PDOException $e) {
        $message = ($e->getCode() === '23000')
          ? 'Vincolo violato (probabilmente email duplicata).'
          : 'Errore durante la creazione dell’utente.';
      }
    } else {
      $message = 'Compila i campi obbligatori (nome, cognome, email, password).';
    }
  }
}

$title = 'Nuovo utente';
include __DIR__ . '/partials/header.php';
?>
<div class="row justify-content-center">
  <div class="col-12 col-lg-7 col-xl-6">
    <div class="card shadow-sm">
      <div class="card-body">
        <h1 class="h5 mb-3">Crea nuovo utente</h1>
        <?php if($message): ?><div class="alert alert-info"><?= e($message) ?></div><?php endif; ?>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Nome</label>
              <input type="text" name="nome" class="form-control" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Cognome</label>
              <input type="text" name="cognome" class="form-control" required>
            </div>

            <div class="col-md-6">
              <label class="form-label">Telefono</label>
              <input type="text" name="telefono" class="form-control">
            </div>
            <div class="col-md-6">
              <label class="form-label">Dipartimento</label>
              <select name="dipartimento" class="form-select">
                <?php foreach($allowedDeps as $d): ?>
                  <option value="<?= e($d) ?>"><?= e($d) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-md-6">
              <label class="form-label">Email</label>
              <input type="email" name="email" class="form-control" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Password</label>
              <input type="password" name="password" class="form-control" required>
            </div>

            <div class="col-md-6">
              <label class="form-label">Ruolo</label>
              <select name="role" class="form-select">
                <option value="editor">Editor</option>
                <option value="admin">Admin</option>
              </select>
            </div>
            <div class="col-md-6 d-flex align-items-center">
              <div class="form-check mt-4">
                <input class="form-check-input" type="checkbox" name="is_active" id="is_active" checked>
                <label class="form-check-label" for="is_active">Attivo</label>
              </div>
            </div>
          </div>

          <div class="mt-3 d-flex gap-2">
            <button class="btn btn-primary">Crea utente</button>
            <a class="btn btn-outline-secondary" href="<?= e($base) ?>/users.php">Annulla</a>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/partials/footer.php'; ?>
