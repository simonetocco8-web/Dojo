<?php
// debug_login_probe.php — NON in produzione (stampa dettagli sensibili)
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/security.php';
require_once __DIR__ . '/core/db.php';

start_session();
$pdo = db();
$out = ['session_active' => (session_status() === PHP_SESSION_ACTIVE)];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $posted_csrf = $_POST['csrf'] ?? null;
  $has_csrf_in_sess = isset($_SESSION['csrf_token']);
  $csrf_ok = $has_csrf_in_sess && is_string($posted_csrf) && hash_equals($_SESSION['csrf_token'], $posted_csrf);

  $email = trim($_POST['email'] ?? '');
  $pwd   = $_POST['password'] ?? '';
  $user  = null;
  $found = false;
  $pwd_ok = false;

  if ($email !== '') {
    $st = $pdo->prepare('SELECT id, email, password_hash FROM users WHERE email=? AND deleted_at IS NULL LIMIT 1');
    $st->execute([$email]);
    $user = $st->fetch();
    $found = (bool)$user;
    if ($found && $pwd !== '') {
      $pwd_ok = password_verify($pwd, $user['password_hash']);
    }
  }

  $out += [
    'csrf_in_session' => $has_csrf_in_sess ? 'yes' : 'no',
    'csrf_posted'     => $posted_csrf ? 'yes' : 'no',
    'csrf_ok'         => $csrf_ok ? 'yes' : 'no',
    'email_posted'    => $email,
    'user_found'      => $found ? 'yes' : 'no',
    'password_verify' => $pwd_ok ? 'yes' : 'no',
    'session_cookie_present' => isset($_COOKIE[session_name()]) ? 'yes' : 'no',
  ];

  if ($csrf_ok && $found && $pwd_ok) {
    $_SESSION['user_id'] = $user['id'];
    $out['result'] = 'LOGIN_OK';
  } else {
    $out['result'] = 'LOGIN_FAIL';
  }
}

?>
<!doctype html><meta charset="utf-8">
<div style="max-width:520px;margin:40px auto;font-family:system-ui;">
  <h2>Debug Login Probe</h2>
  <form method="post" style="border:1px solid #ddd;padding:12px;border-radius:8px;">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <div style="margin-bottom:8px;">
      <label>Email</label><br>
      <input name="email" type="email" required style="width:100%;">
    </div>
    <div style="margin-bottom:8px;">
      <label>Password</label><br>
      <input name="password" type="password" required style="width:100%;">
    </div>
    <button>Prova login</button>
  </form>
  <?php if (!empty($out)): ?>
    <pre style="background:#f7f7f7;padding:12px;border-radius:8px;margin-top:12px;"><?php print_r($out); ?></pre>
  <?php endif; ?>
</div>
