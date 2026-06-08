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


function ensure_system_settings_table(PDO $pdo): void {
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS system_settings (
      setting_key VARCHAR(190) NOT NULL PRIMARY KEY,
      setting_value TEXT DEFAULT NULL,
      updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");
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



function ensure_transfer_internal_details_columns(PDO $pdo): void {
  $columns = array();
  $stmt = $pdo->query("
    SELECT COLUMN_NAME
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'transfers_internal'
      AND COLUMN_NAME IN ('people_count', 'note')
  ");
  foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $column) {
    $columns[(string)$column] = true;
  }

  if (empty($columns['people_count'])) {
    $pdo->exec("ALTER TABLE transfers_internal ADD COLUMN people_count INT UNSIGNED DEFAULT NULL AFTER location");
  }
  if (empty($columns['note'])) {
    $pdo->exec("ALTER TABLE transfers_internal ADD COLUMN note VARCHAR(255) DEFAULT NULL AFTER people_count");
  }
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

function ensure_overtime_entries_table(PDO $pdo): void {
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS overtime_entries (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      user_id INT UNSIGNED NOT NULL,
      work_date DATE NOT NULL,
      hours DECIMAL(6,2) NOT NULL,
      note TEXT DEFAULT NULL,
      created_by INT UNSIGNED DEFAULT NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      deleted_at DATETIME DEFAULT NULL,
      INDEX idx_overtime_entries_user_date (user_id, work_date),
      INDEX idx_overtime_entries_work_date (work_date),
      INDEX idx_overtime_entries_deleted_at (deleted_at),
      CONSTRAINT fk_overtime_entries_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_overtime_entries_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");
}

function ensure_transfer_external_travel_columns(PDO $pdo): void {
  $pdo->exec("ALTER TABLE transfers_external MODIFY COLUMN type VARCHAR(32) NOT NULL DEFAULT 'arrivo'");
  $pdo->exec("ALTER TABLE transfers_external MODIFY COLUMN status VARCHAR(32) NOT NULL DEFAULT 'attivo'");

  $stmt = $pdo->query("
    SELECT COLUMN_NAME
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'transfers_external'
      AND COLUMN_NAME IN (
        'supplier_name',
        'supplier_confirm_token', 'supplier_reject_token', 'supplier_token_expires_at', 'supplier_responded_at', 'rejection_reason',
        'flight_number', 'train_number',
        'arrival_place', 'arrival_date_time', 'arrival_pickup_time', 'arrival_flight_number', 'arrival_train_number',
        'departure_place', 'departure_date_time', 'departure_pickup_time', 'departure_flight_number', 'departure_train_number'
      )
  ");
  $columns = array_fill_keys($stmt->fetchAll(PDO::FETCH_COLUMN), true);

  if (empty($columns['supplier_name'])) {
    $pdo->exec("ALTER TABLE transfers_external ADD COLUMN supplier_name VARCHAR(80) NOT NULL DEFAULT 'Dany Express' AFTER service_company");
  }
  if (empty($columns['supplier_confirm_token'])) {
    $pdo->exec("ALTER TABLE transfers_external ADD COLUMN supplier_confirm_token VARCHAR(64) DEFAULT NULL AFTER supplier_name");
  }
  if (empty($columns['supplier_reject_token'])) {
    $pdo->exec("ALTER TABLE transfers_external ADD COLUMN supplier_reject_token VARCHAR(64) DEFAULT NULL AFTER supplier_confirm_token");
  }
  if (empty($columns['supplier_token_expires_at'])) {
    $pdo->exec("ALTER TABLE transfers_external ADD COLUMN supplier_token_expires_at DATETIME DEFAULT NULL AFTER supplier_reject_token");
  }
  if (empty($columns['supplier_responded_at'])) {
    $pdo->exec("ALTER TABLE transfers_external ADD COLUMN supplier_responded_at DATETIME DEFAULT NULL AFTER supplier_token_expires_at");
  }
  if (empty($columns['rejection_reason'])) {
    $pdo->exec("ALTER TABLE transfers_external ADD COLUMN rejection_reason VARCHAR(255) DEFAULT NULL AFTER supplier_responded_at");
  }
  if (empty($columns['flight_number'])) {
    $pdo->exec("ALTER TABLE transfers_external ADD COLUMN flight_number VARCHAR(80) DEFAULT NULL AFTER rejection_reason");
  }
  if (empty($columns['train_number'])) {
    $pdo->exec("ALTER TABLE transfers_external ADD COLUMN train_number VARCHAR(80) DEFAULT NULL AFTER flight_number");
  }
  if (empty($columns['arrival_place'])) {
    $pdo->exec("ALTER TABLE transfers_external ADD COLUMN arrival_place VARCHAR(190) DEFAULT NULL AFTER train_number");
  }
  if (empty($columns['arrival_date_time'])) {
    $pdo->exec("ALTER TABLE transfers_external ADD COLUMN arrival_date_time DATETIME DEFAULT NULL AFTER arrival_place");
  }
  if (empty($columns['arrival_pickup_time'])) {
    $pdo->exec("ALTER TABLE transfers_external ADD COLUMN arrival_pickup_time TIME DEFAULT NULL AFTER arrival_date_time");
  }
  if (empty($columns['arrival_flight_number'])) {
    $pdo->exec("ALTER TABLE transfers_external ADD COLUMN arrival_flight_number VARCHAR(80) DEFAULT NULL AFTER arrival_pickup_time");
  }
  if (empty($columns['arrival_train_number'])) {
    $pdo->exec("ALTER TABLE transfers_external ADD COLUMN arrival_train_number VARCHAR(80) DEFAULT NULL AFTER arrival_flight_number");
  }
  if (empty($columns['departure_place'])) {
    $pdo->exec("ALTER TABLE transfers_external ADD COLUMN departure_place VARCHAR(190) DEFAULT NULL AFTER arrival_train_number");
  }
  if (empty($columns['departure_date_time'])) {
    $pdo->exec("ALTER TABLE transfers_external ADD COLUMN departure_date_time DATETIME DEFAULT NULL AFTER departure_place");
  }
  if (empty($columns['departure_pickup_time'])) {
    $pdo->exec("ALTER TABLE transfers_external ADD COLUMN departure_pickup_time TIME DEFAULT NULL AFTER departure_date_time");
  }
  if (empty($columns['departure_flight_number'])) {
    $pdo->exec("ALTER TABLE transfers_external ADD COLUMN departure_flight_number VARCHAR(80) DEFAULT NULL AFTER departure_pickup_time");
  }
  if (empty($columns['departure_train_number'])) {
    $pdo->exec("ALTER TABLE transfers_external ADD COLUMN departure_train_number VARCHAR(80) DEFAULT NULL AFTER departure_flight_number");
  }
}
