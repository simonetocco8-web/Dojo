<?php
require_once __DIR__ . '/db.php';

function start_session() {
  if (session_status() === PHP_SESSION_NONE) {
    if (session_name() !== 'APPSESSID') session_name('APPSESSID');
    session_set_cookie_params([
      'lifetime' => 0,
      'path'     => '/',
      'domain'   => '',
      'secure'   => !empty($_SERVER['HTTPS']),
      'httponly' => true,
      'samesite' => 'Lax',
    ]);
    session_start();
  }
}

function current_user() {
  start_session();
  if (empty($_SESSION['user_id'])) return null;
  $pdo = db();
  $st = $pdo->prepare('SELECT * FROM users WHERE id=? AND deleted_at IS NULL LIMIT 1');
  $st->execute([$_SESSION['user_id']]);
  return $st->fetch() ?: null;
}

function is_admin() {
  $u = current_user();
  return $u && isset($u['role']) && $u['role'] === 'admin';
}

function require_login($redirect = 'login.php') {
  if (!current_user()) {
    // opzionale: piccolo flash message
    $_SESSION['flash'] = 'Autenticazione richiesta.';
    header('Location: ' . $redirect);
    exit;
  }
}

/**
 * Protegge pagine riservate agli admin.
 * - Usa redirect relativo per evitare mismatch host/scheme
 * - Non dipende da $base esterno
 */
function require_admin($redirect = 'login.php') {
  $u = current_user();   // assicura anche start_session()
  if (!$u) {
    $_SESSION['flash'] = 'Autenticazione richiesta.';
    header('Location: ' . $redirect);
    exit;
  }
  if (($u['role'] ?? null) !== 'admin') {
    // Puoi scegliere: 403 o redirect
    header($_SERVER['SERVER_PROTOCOL'] . ' 403 Forbidden');
    echo '<h1>403</h1><p>Accesso riservato agli amministratori.</p>';
    exit;
  }
}
