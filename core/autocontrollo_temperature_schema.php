<?php

// Compatibilità con installazioni che conservano un core/db.php precedente
// all'introduzione dei controlli delle temperature. Non ridefinire helper esistenti.

if (!function_exists('ensure_autocontrollo_refrigerators_table')) {
function ensure_autocontrollo_refrigerators_table(PDO $pdo): void {
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS autocontrollo_refrigerators (
      id VARCHAR(50) NOT NULL PRIMARY KEY,
      appliance_type ENUM('frigorifero','congelatore','cella') NOT NULL,
      operating_temperature DECIMAL(5,2) NOT NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  ");
}
}

if (!function_exists('ensure_autocontrollo_temperature_inspections_tables')) {
function ensure_autocontrollo_temperature_inspections_tables(PDO $pdo): void {
  ensure_autocontrollo_refrigerators_table($pdo);
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS autocontrollo_temperature_inspections (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      season_start DATE NOT NULL,
      season_end DATE NOT NULL,
      inspection_date DATE NOT NULL,
      time_slot ENUM('mattina','pomeriggio') NOT NULL,
      status ENUM('in_corso','completata') NOT NULL DEFAULT 'in_corso',
      operator_id INT UNSIGNED DEFAULT NULL,
      started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      completed_at DATETIME DEFAULT NULL,
      email_sent_at DATETIME DEFAULT NULL,
      UNIQUE KEY uq_temperature_inspection_slot (season_start, season_end, inspection_date, time_slot),
      INDEX idx_temperature_inspection_date (inspection_date, started_at),
      CONSTRAINT fk_temperature_inspection_operator FOREIGN KEY (operator_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  ");
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS autocontrollo_temperature_results (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      inspection_id INT UNSIGNED NOT NULL,
      refrigerator_id VARCHAR(50) DEFAULT NULL,
      refrigerator_label VARCHAR(50) NOT NULL,
      appliance_type ENUM('frigorifero','congelatore','cella') NOT NULL,
      operating_temperature DECIMAL(5,2) NOT NULL,
      sort_order INT UNSIGNED NOT NULL,
      is_compliant TINYINT(1) DEFAULT NULL,
      checked_at DATETIME DEFAULT NULL,
      UNIQUE KEY uq_temperature_result_appliance (inspection_id, sort_order),
      CONSTRAINT fk_temperature_result_inspection FOREIGN KEY (inspection_id) REFERENCES autocontrollo_temperature_inspections(id) ON DELETE CASCADE,
      CONSTRAINT fk_temperature_result_refrigerator FOREIGN KEY (refrigerator_id) REFERENCES autocontrollo_refrigerators(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  ");
}
}
