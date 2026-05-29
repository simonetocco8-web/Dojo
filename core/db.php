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


function ensure_users_department_column_supports_multiple(PDO $pdo): void {
  $stmt = $pdo->query("
    SELECT DATA_TYPE, CHARACTER_MAXIMUM_LENGTH
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'dipartimento'
    LIMIT 1
  ");
  $col = $stmt->fetch();

  if (!$col) {
    $pdo->exec("ALTER TABLE users ADD COLUMN dipartimento VARCHAR(255) NOT NULL DEFAULT 'Amministrazione'");
    return;
  }

  $type = strtolower((string)($col['DATA_TYPE'] ?? ''));
  $len = (int)($col['CHARACTER_MAXIMUM_LENGTH'] ?? 0);

  if ($type !== 'varchar' || $len < 255) {
    $pdo->exec("ALTER TABLE users MODIFY COLUMN dipartimento VARCHAR(255) NOT NULL DEFAULT 'Amministrazione'");
  }
}
