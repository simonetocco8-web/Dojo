<?php
// core/db.php
function db(): PDO {
  static $pdo = null;
  if ($pdo) return $pdo;
  $env = require __DIR__ . '/../config/env.php';
  $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s',
    $env['db']['host'], $env['db']['port'], $env['db']['name'], $env['db']['charset']);
  $pdo = new PDO($dsn, $env['db']['user'], $env['db']['pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
  return $pdo;
}
