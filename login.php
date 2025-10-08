<?php
// login.php — versione con diagnostica
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/security.php';
require_once __DIR__ . '/core/db.php';

start_session();
$env  = require __DIR__ . '/config/env.php';
$base = rtrim($env['app']['base_url'] ?? '', '/');
$pdo  = db();

$user = current_user();
if ($user) {
  error_log('LOGIN: già autenticato, redirect a dashboard');
  header('Location: ' . $base . '/dashboard.php');
  exit;
}

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  error_log('LOGIN: POST ricevuto');
  if (!csrf_check($_POST['csrf'] ?? '')) {
    $message = 'Token CSRF non valido.';
    error_log('LOGIN: CSRF non valido');
  } else {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    if ($email && $password) {
      $st = $pdo->prepare('SELECT * FROM users WHERE email = ? AND deleted_at IS NULL LIMIT 1');
      $st->execute([$email]);
      $u = $st->fetch();
      if ($u) {
        $ok = password_verify($password, $u['password_hash']);
        
        
        
        
        
        start_session();                        // assicura che sia attiva
session_regenerate_id(true);            // sicurezza: nuovo ID
$_SESSION['user_id']    = $u['id'];
$_SESSION['csrf_token'] = bin2hex(random_bytes(32)); // opzionale

session_write_close();                  // <— salva e chiudi il file di sessione
header('Location: dashboard.php');      // usa redirect RELATIVO
exit;










        error_log('LOGIN: utente trovato id='.$u['id'].' pwd_ok=' . ($ok?'1':'0'));
        if ($ok) {
          session_regenerate_id(true);        
          $_SESSION['user_id'] = $u['id'];
          // rigenera token CSRF dopo login per sicurezza
          $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
          header('Location: dashboard.php');  // <-- relativo! niente $base
          exit;

        }
      } else {
        error_log('LOGIN: utente non trovato per email='.$email);
      }
      $message = 'Credenziali non valide.';
    } else {
      $message = 'Inserisci email e password.';
    }
  }
}

$title = 'Login';
include __DIR__ . '/partials/header.php';
?>
<div class="row justify-content-center mt-5">
  <div class="col-12 col-sm-8 col-md-6 col-lg-4">
    <div class="card shadow-sm">
      <div class="card-body">
        <h1 class="h5 mb-3 text-center">Accesso</h1>
        <?php if ($message): ?>
          <div class="alert alert-danger py-2 small"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <form method="post" autocomplete="off">
          <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
          <div class="mb-3">
            <label for="email" class="form-label">Email</label>
            <input type="email" name="email" id="email" class="form-control" required autofocus>
          </div>
          <div class="mb-3">
            <label for="password" class="form-label">Password</label>
            <input type="password" name="password" id="password" class="form-control" required>
          </div>
          <div class="d-grid gap-2">
            <button type="submit" class="btn btn-primary">Accedi</button>
          </div>
        </form>

        <?php if (!empty($_GET['debug'])): ?>
          <hr>
          <div class="small text-muted">
            <strong>DEBUG</strong><br>
            Session started: <?= session_status() === PHP_SESSION_ACTIVE ? 'yes' : 'no' ?><br>
            Session ID: <?= session_id() ?><br>
            Has csrf in session: <?= isset($_SESSION['csrf_token']) ? 'yes' : 'no' ?><br>
            Cookie test: <?= isset($_COOKIE[session_name()]) ? 'present' : 'missing' ?><br>
          </div>
        <?php endif; ?>

      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/partials/footer.php'; ?>
