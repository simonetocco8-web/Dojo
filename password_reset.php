<?php
require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/security.php';
$env = require __DIR__ . '/config/env.php';
$base = rtrim($env['app']['base_url'] ?? '', '/');
$message = '';
$selector = $_GET['selector'] ?? '';
$validator_hex = $_GET['validator'] ?? '';
if (!preg_match('/^[a-f0-9]{16}$/', $selector) || !preg_match('/^[a-f0-9]{64}$/', $validator_hex)) {
  $invalid = true;
} else {
  $invalid = false;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_check($_POST['csrf'] ?? '')) {
    $message = 'Token CSRF non valido.';
  } else {
    $selector = $_POST['selector'] ?? '';
    $validator_hex = $_POST['validator'] ?? '';
    $pass1 = $_POST['password'] ?? '';
    $pass2 = $_POST['password_confirm'] ?? '';
    if ($pass1 !== $pass2 || strlen($pass1) < 8) {
      $message = 'Le password non coincidono o sono troppo corte (min 8).';
    } else {
      $pdo = db();
      $stmt = $pdo->prepare('SELECT pr.id, pr.user_id, pr.validator_hash, pr.expires_at FROM password_resets pr WHERE pr.selector=? LIMIT 1');
      $stmt->execute([$selector]);
      $row = $stmt->fetch();
      if (!$row) {
        $message = 'Link non valido.';
      } elseif (new DateTime() > new DateTime($row['expires_at'])) {
        $message = 'Link scaduto. Richiedi un nuovo reset.';
      } else {
        $calc = hash('sha256', hex2bin($validator_hex));
        if (!hash_equals($row['validator_hash'], $calc)) {
          $message = 'Token non valido.';
        } else {
          $hash = password_hash($pass1, PASSWORD_DEFAULT);
          $pdo->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([$hash, $row['user_id']]);
          $pdo->prepare('DELETE FROM password_resets WHERE user_id=?')->execute([$row['user_id']]);
          header('Location: ' . $base . '/index.php?msg=reset_ok');
          exit;
        }
      }
    }
  }
}
$title = 'Reimposta password';
include __DIR__ . '/partials/header.php';
?>
<div class="row justify-content-center">
  <div class="col-12 col-md-6 col-lg-5">
    <div class="card shadow-sm">
      <div class="card-body">
        <h1 class="h4 mb-3">Imposta una nuova password</h1>
        <?php if($invalid): ?><div class="alert alert-danger">Link non valido.</div><?php endif; ?>
        <?php if($message): ?><div class="alert alert-warning"><?= e($message) ?></div><?php endif; ?>
        <?php if(!$invalid): ?>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="selector" value="<?= e($selector) ?>">
          <input type="hidden" name="validator" value="<?= e($validator_hex) ?>">
          <div class="mb-3">
            <label class="form-label">Nuova password</label>
            <input type="password" name="password" class="form-control" minlength="8" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Conferma password</label>
            <input type="password" name="password_confirm" class="form-control" minlength="8" required>
          </div>
          <button class="btn btn-primary w-100">Salva nuova password</button>
        </form>
        <?php endif; ?>
        <div class="mt-3">
          <a href="<?= e($base) ?>/index.php">Torna al login</a>
        </div>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/partials/footer.php'; ?>
