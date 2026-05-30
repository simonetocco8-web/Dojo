<?php
require_once __DIR__ . '/db.php';

function start_session() {
  if (session_status() === PHP_SESSION_NONE) {
    $env = require __DIR__ . '/../config/env.php';
    $sessionName = $env['app']['session_name'] ?? 'APPSESSID';
    $sessionLifetime = (int)($env['app']['session_lifetime'] ?? 36000);
    if ($sessionLifetime <= 0) $sessionLifetime = 36000;

    if (session_name() !== $sessionName) session_name($sessionName);
    ini_set('session.gc_maxlifetime', (string)$sessionLifetime);
    ini_set('session.cookie_lifetime', (string)$sessionLifetime);
    session_set_cookie_params([
      'lifetime' => $sessionLifetime,
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


function user_departments($user = null) {
  if ($user === null) $user = current_user();
  if (!$user) return array();

  $raw = isset($user['dipartimento']) ? $user['dipartimento'] : '';
  if (is_array($raw)) {
    $parts = $raw;
  } else {
    $raw = trim((string)$raw);
    if ($raw === '') return array();

    $decoded = json_decode($raw, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
      $parts = $decoded;
    } else {
      $parts = preg_split('/\s*[,;|]\s*/', $raw);
    }
  }

  $departments = array();
  foreach ($parts as $part) {
    $part = trim((string)$part);
    if ($part !== '' && !in_array($part, $departments, true)) {
      $departments[] = $part;
    }
  }
  return $departments;
}

function user_has_department($user, $department) {
  return in_array($department, user_departments($user), true);
}

function user_has_any_department($user, $departments) {
  foreach ($departments as $department) {
    if (user_has_department($user, $department)) return true;
  }
  return false;
}

function departments_label($departments) {
  if (!is_array($departments)) $departments = user_departments(array('dipartimento' => $departments));
  return implode(', ', $departments);
}

function department_badges($departments) {
  $items = is_array($departments) ? $departments : user_departments(array('dipartimento' => $departments));
  if (!$items) return '<span class="text-muted">—</span>';

  $html = '';
  foreach ($items as $department) {
    $html .= '<span class="badge bg-light text-dark border me-1">' . htmlspecialchars($department, ENT_QUOTES, 'UTF-8') . '</span>';
  }
  return $html;
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

function logout() {
  start_session();
  $_SESSION = [];

  if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
      session_name(),
      '',
      time() - 42000,
      $params['path'] ?? '/',
      $params['domain'] ?? '',
      $params['secure'] ?? false,
      $params['httponly'] ?? true
    );
  }

  session_destroy();
}
