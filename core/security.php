<?php
// core/security.php
function e($str): string {
  // accetta qualsiasi tipo; tratta NULL come stringa vuota
  // ENT_SUBSTITUTE evita errori se arrivano byte non validi in UTF-8
  return htmlspecialchars((string)($str ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function security_start_session(): void {
  if (session_status() === PHP_SESSION_ACTIVE) return;

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

function csrf_token(): string {
  security_start_session();
  if (empty($_SESSION['csrf_token'])) {
    $env = require __DIR__ . '/../config/env.php';
    $_SESSION['csrf_token'] = hash_hmac('sha256', bin2hex(random_bytes(32)), $env['app']['csrf_key']);
  }
  return $_SESSION['csrf_token'];
}
function csrf_check(string $token): bool {
  security_start_session();
  return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}
