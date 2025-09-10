<?php
require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/security.php';
require_once __DIR__ . '/core/token.php';
require_once __DIR__ . '/core/mailer.php';
$env = require __DIR__ . '/config/env.php';
$base = rtrim($env['app']['base_url'] ?? '', '/');
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_check($_POST['csrf'] ?? '')) {
    $message = 'Token CSRF non valido.';
  } else {
    $email = trim($_POST['email'] ?? '');
    if ($email) {
      $pdo = db();
      $stmt = $pdo->prepare('SELECT id, email FROM users WHERE email=? AND is_active=1 LIMIT 1');
      $stmt->execute([$email]);
      $user = $stmt->fetch();
      if ($user) {
        if (!isset($_SESSION)) session_start();
        $last = $_SESSION['pw_last'] ?? 0;
        if (time() - $last >= 60) {
          $_SESSION['pw_last'] = time();
          [$selector, $validator_hex, $validator_hash] = token_pair();
          $expires = (new DateTime('+1 hour'))->format('Y-m-d H:i:s');
          $pdo->prepare('DELETE FROM password_resets WHERE user_id=?')->execute([$user['id']]);
          $pdo->prepare('INSERT INTO password_resets (user_id, selector, validator_hash, expires_at) VALUES (?,?,?,?)')
              ->execute([$user['id'], $selector, $validator_hash, $expires]);
          $reset_url = sprintf('%s/password_reset.php?selector=%s&validator=%s', $base ?: '', urlencode($selector), urlencode($validator_hex));
          if (!$base) {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $path = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
            $reset_url = $scheme . '://' . $host . $path . '/password_reset.php?selector=' . urlencode($selector) . '&validator=' . urlencode($validator_hex);
          }
          $html = '<p>Hai richiesto il reset della password.</p><p><a href="'.$reset_url.'">Clicca qui per reimpostare la password</a> (valido 1 ora).</p>';
          send_mail($email, 'Reimposta la tua password', $html);
        }
      }
      $message = 'Se l’indirizzo è registrato, riceverai una mail con le istruzioni (controlla anche lo spam).';
    } else {
      $message = 'Inserisci un indirizzo email valido.';
    }
  }
}
$title = 'Password dimenticata';
include __DIR__ . '/partials/header.php';
?>
<div class="row justify-content-center">
  <div class="col-12 col-md-6 col-lg-5">
    <div class="card shadow-sm">
      <div class="card-body">
        <h1 class="h4 mb-3">Recupera password</h1>
        <?php if($message): ?><div class="alert alert-info"><?= e($message) ?></div><?php endif; ?>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <div class="mb-3">
            <label class="form-label">Email</label>
            <input type="email" name="email" class="form-control" required>
          </div>
          <button class="btn btn-primary w-100">Invia link di reset</button>
        </form>
        <div class="mt-3">
          <a href="<?= e($base) ?>/index.php">Torna al login</a>
        </div>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/partials/footer.php'; ?>
