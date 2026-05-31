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


function ensure_sms_history_table(PDO $pdo): void {
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS sms_history (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      sent_by INT UNSIGNED NOT NULL,
      recipient_type ENUM('users','department') NOT NULL,
      recipients TEXT NOT NULL,
      message VARCHAR(160) NOT NULL,
      sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_sms_history_sent_at (sent_at),
      INDEX idx_sms_history_sent_by (sent_by),
      CONSTRAINT fk_sms_history_sent_by FOREIGN KEY (sent_by) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");
}


function ensure_task_user_assignments_table(PDO $pdo): void {
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS task_user_assignments (
      task_id INT UNSIGNED NOT NULL,
      user_id INT UNSIGNED NOT NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (task_id, user_id),
      INDEX idx_task_user_assignments_user (user_id),
      CONSTRAINT fk_task_user_assignments_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
      CONSTRAINT fk_task_user_assignments_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");
}


function ensure_transfer_internal_sms_reminders_table(PDO $pdo): void {
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS transfer_internal_sms_reminders (
      transfer_id INT UNSIGNED NOT NULL PRIMARY KEY,
      sent_at DATETIME DEFAULT NULL,
      last_error TEXT DEFAULT NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      CONSTRAINT fk_transfer_internal_sms_reminders_transfer FOREIGN KEY (transfer_id) REFERENCES transfers_internal(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");
}


function ensure_products_active_column(PDO $pdo): void {
  $stmt = $pdo->query("
    SELECT COLUMN_NAME
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'products'
      AND COLUMN_NAME = 'is_active'
    LIMIT 1
  ");
  if (!$stmt->fetch()) {
    $pdo->exec("ALTER TABLE products ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1");
  }
}
