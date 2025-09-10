<?php
// core/security.php
function e($str): string {
  // accetta qualsiasi tipo; tratta NULL come stringa vuota
  // ENT_SUBSTITUTE evita errori se arrivano byte non validi in UTF-8
  return htmlspecialchars((string)($str ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function csrf_token(): string {
  if (session_status() !== PHP_SESSION_ACTIVE) session_start();
  if (empty($_SESSION['csrf_token'])) {
    $env = require __DIR__ . '/../config/env.php';
    $_SESSION['csrf_token'] = hash_hmac('sha256', bin2hex(random_bytes(32)), $env['app']['csrf_key']);
  }
  return $_SESSION['csrf_token'];
}
function csrf_check(string $token): bool {
  if (session_status() !== PHP_SESSION_ACTIVE) session_start();
  return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}
