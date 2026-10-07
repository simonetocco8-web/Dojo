<?php

// Helper di compatibilità per installazioni con core/db.php precedente.
// Mantiene le implementazioni già caricate; gli schemi replicano quelli di db.php.
// Le inizializzazioni vengono eseguite solo dalle procedure che le richiedono.

if (!function_exists('ensure_autocontrollo_electrical_panels_table')) {
function ensure_autocontrollo_electrical_panels_table(PDO $pdo): void {
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS autocontrollo_electrical_panels (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      installation_location VARCHAR(190) NOT NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  ");
}
}

if (!function_exists('ensure_autocontrollo_pool_products_table')) {
function ensure_autocontrollo_pool_products_table(PDO $pdo): void {
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS autocontrollo_pool_products (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      description VARCHAR(255) NOT NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  ");
}
}

if (!function_exists('ensure_autocontrollo_rodent_traps_table')) {
function ensure_autocontrollo_rodent_traps_table(PDO $pdo): void {
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS autocontrollo_rodent_traps (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      location VARCHAR(190) NOT NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  ");

  // Le prime versioni della tabella includevano una colonna `identifier`
  // obbligatoria con valore predefinito vuoto e indice univoco. Poiché l'ID
  // della trappola è già la chiave primaria auto-incrementale, quella colonna
  // impediva l'inserimento della seconda trappola (duplicate entry '').
  $legacyColumn = $pdo->query("
    SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'autocontrollo_rodent_traps'
      AND COLUMN_NAME = 'identifier'
    LIMIT 1
  ")->fetchColumn();
  if ($legacyColumn !== false) {
    try {
      $legacyIndexes = $pdo->query("
        SELECT DISTINCT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'autocontrollo_rodent_traps'
          AND COLUMN_NAME = 'identifier'
          AND NON_UNIQUE = 0
          AND INDEX_NAME = 'uq_rodent_trap_identifier'
      ")->fetchAll(PDO::FETCH_COLUMN);
      foreach ($legacyIndexes as $indexName) {
        if (preg_match('/^[A-Za-z0-9_]+$/', (string)$indexName)) {
          $pdo->exec('ALTER TABLE autocontrollo_rodent_traps DROP INDEX `' . $indexName . '`');
        }
      }
      $pdo->exec('ALTER TABLE autocontrollo_rodent_traps DROP COLUMN identifier');
    } catch (Throwable $exception) {
      // Alcuni hosting consentono INSERT/UPDATE ma non ALTER TABLE. In questo
      // caso la pagina usa un identificativo legacy univoco come fallback.
      error_log('[Autocontrollo] Migrazione identifier trappole non applicata: ' . $exception->getMessage());
    }
  }
}
}

if (!function_exists('ensure_autocontrollo_grounding_rods_table')) {
function ensure_autocontrollo_grounding_rods_table(PDO $pdo): void {
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS autocontrollo_grounding_rods (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      location VARCHAR(190) NOT NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  ");
}
}

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

if (!function_exists('ensure_autocontrollo_fire_extinguishers_table')) {
function ensure_autocontrollo_fire_extinguishers_table(PDO $pdo): void {
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS autocontrollo_fire_extinguishers (
      id VARCHAR(50) NOT NULL PRIMARY KEY,
      extinguisher_type ENUM('polvere','co2','schiuma','carrellato') NOT NULL,
      capacity_kg DECIMAL(6,2) NOT NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  ");
}
}

if (!function_exists('ensure_autocontrollo_fire_inspections_tables')) {
function ensure_autocontrollo_fire_inspections_tables(PDO $pdo): void {
  ensure_autocontrollo_fire_extinguishers_table($pdo);
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS autocontrollo_fire_inspections (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      season_start DATE NOT NULL,
      season_end DATE NOT NULL,
      scheduled_date DATE NOT NULL,
      status ENUM('in_corso','completata') NOT NULL DEFAULT 'in_corso',
      operator_id INT UNSIGNED DEFAULT NULL,
      started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      completed_at DATETIME DEFAULT NULL,
      email_sent_at DATETIME DEFAULT NULL,
      UNIQUE KEY uq_fire_inspection_date (season_start, season_end, scheduled_date),
      CONSTRAINT fk_fire_inspection_operator FOREIGN KEY (operator_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  ");
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS autocontrollo_fire_inspection_results (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      inspection_id INT UNSIGNED NOT NULL,
      extinguisher_id VARCHAR(50) DEFAULT NULL,
      extinguisher_label VARCHAR(50) NOT NULL,
      extinguisher_type ENUM('polvere','co2','schiuma','carrellato') NOT NULL,
      capacity_kg DECIMAL(6,2) NOT NULL,
      sort_order INT UNSIGNED NOT NULL,
      is_suitable TINYINT(1) DEFAULT NULL,
      checked_at DATETIME DEFAULT NULL,
      UNIQUE KEY uq_fire_result_extinguisher (inspection_id, sort_order),
      CONSTRAINT fk_fire_result_inspection FOREIGN KEY (inspection_id) REFERENCES autocontrollo_fire_inspections(id) ON DELETE CASCADE,
      CONSTRAINT fk_fire_result_extinguisher FOREIGN KEY (extinguisher_id) REFERENCES autocontrollo_fire_extinguishers(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  ");
}
}

if (!function_exists('ensure_autocontrollo_haccp_inspections_tables')) {
function ensure_autocontrollo_haccp_inspections_tables(PDO $pdo): void {
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS autocontrollo_haccp_inspections (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      season_start DATE NOT NULL,
      season_end DATE NOT NULL,
      scheduled_date DATE NOT NULL,
      status ENUM('in_corso','completata') NOT NULL DEFAULT 'in_corso',
      operator_id INT UNSIGNED DEFAULT NULL,
      started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      completed_at DATETIME DEFAULT NULL,
      email_sent_at DATETIME DEFAULT NULL,
      UNIQUE KEY uq_haccp_inspection_date (season_start, season_end, scheduled_date),
      CONSTRAINT fk_haccp_inspection_operator FOREIGN KEY (operator_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  ");
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS autocontrollo_haccp_inspection_results (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      inspection_id INT UNSIGNED NOT NULL,
      surface_code VARCHAR(50) NOT NULL,
      surface_label VARCHAR(190) NOT NULL,
      frequency ENUM('giornaliera','settimanale') NOT NULL,
      sort_order INT UNSIGNED NOT NULL,
      is_clean TINYINT(1) DEFAULT NULL,
      checked_at DATETIME DEFAULT NULL,
      UNIQUE KEY uq_haccp_result_surface (inspection_id, surface_code),
      CONSTRAINT fk_haccp_result_inspection FOREIGN KEY (inspection_id) REFERENCES autocontrollo_haccp_inspections(id) ON DELETE CASCADE
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

if (!function_exists('ensure_autocontrollo_grounding_inspections_tables')) {
function ensure_autocontrollo_grounding_inspections_tables(PDO $pdo): void {
  ensure_autocontrollo_grounding_rods_table($pdo);
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS autocontrollo_grounding_inspections (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      season_start DATE NOT NULL,
      season_end DATE NOT NULL,
      inspection_type ENUM('pre_apertura','post_chiusura') NOT NULL,
      scheduled_date DATE NOT NULL,
      status ENUM('in_corso','completata') NOT NULL DEFAULT 'in_corso',
      operator_id INT UNSIGNED DEFAULT NULL,
      started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      completed_at DATETIME DEFAULT NULL,
      email_sent_at DATETIME DEFAULT NULL,
      UNIQUE KEY uq_grounding_inspection_season_type (season_start, season_end, inspection_type),
      INDEX idx_grounding_inspection_started_at (started_at),
      CONSTRAINT fk_grounding_inspection_operator FOREIGN KEY (operator_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  ");
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS autocontrollo_grounding_inspection_results (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      inspection_id INT UNSIGNED NOT NULL,
      grounding_rod_id INT UNSIGNED DEFAULT NULL,
      rod_location VARCHAR(190) NOT NULL,
      sort_order INT UNSIGNED NOT NULL,
      clamp_checked TINYINT(1) DEFAULT NULL,
      antioxidant_applied TINYINT(1) DEFAULT NULL,
      checked_at DATETIME DEFAULT NULL,
      UNIQUE KEY uq_grounding_result_rod (inspection_id, sort_order),
      CONSTRAINT fk_grounding_result_inspection FOREIGN KEY (inspection_id) REFERENCES autocontrollo_grounding_inspections(id) ON DELETE CASCADE,
      CONSTRAINT fk_grounding_result_rod FOREIGN KEY (grounding_rod_id) REFERENCES autocontrollo_grounding_rods(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  ");
}

/**
 * Compatibilità con database che conservano ancora la colonna `identifier`.
 * Restituisce la lunghezza massima disponibile, oppure 0 se la colonna non c'è.
 */
}

if (!function_exists('ensure_autocontrollo_rodent_inspections_tables')) {
function ensure_autocontrollo_rodent_inspections_tables(PDO $pdo): void {
  ensure_autocontrollo_rodent_traps_table($pdo);
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS autocontrollo_rodent_inspections (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      season_start DATE NOT NULL,
      season_end DATE NOT NULL,
      scheduled_date DATE NOT NULL,
      status ENUM('in_corso','completata') NOT NULL DEFAULT 'in_corso',
      operator_id INT UNSIGNED DEFAULT NULL,
      started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      completed_at DATETIME DEFAULT NULL,
      email_sent_at DATETIME DEFAULT NULL,
      UNIQUE KEY uq_rodent_inspection_schedule (season_start, season_end, scheduled_date),
      INDEX idx_rodent_inspection_started_at (started_at),
      CONSTRAINT fk_rodent_inspection_operator FOREIGN KEY (operator_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  ");
  // Migrazione compatibile con le procedure già registrate.
  $columns = $pdo->query('SHOW COLUMNS FROM autocontrollo_rodent_inspections')->fetchAll(PDO::FETCH_COLUMN);
  if (!in_array('is_emergency', $columns, true)) {
    $pdo->exec("ALTER TABLE autocontrollo_rodent_inspections
      ADD COLUMN is_emergency TINYINT(1) NOT NULL DEFAULT 0,
      ADD COLUMN calendar_date DATE GENERATED ALWAYS AS (IF(is_emergency=0, scheduled_date, NULL)) STORED,
      MODIFY status ENUM('programmata','in_corso','completata') NOT NULL DEFAULT 'in_corso',
      MODIFY started_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
      DROP INDEX uq_rodent_inspection_schedule,
      ADD UNIQUE KEY uq_rodent_inspection_schedule (season_start, season_end, calendar_date)");
  }
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS autocontrollo_rodent_inspection_results (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      inspection_id INT UNSIGNED NOT NULL,
      trap_id INT UNSIGNED DEFAULT NULL,
      trap_location VARCHAR(190) NOT NULL,
      sort_order INT UNSIGNED NOT NULL,
      bait_present TINYINT(1) DEFAULT NULL,
      bait_eaten TINYINT(1) DEFAULT NULL,
      bait_replaced TINYINT(1) DEFAULT NULL,
      checked_at DATETIME DEFAULT NULL,
      UNIQUE KEY uq_rodent_result_trap (inspection_id, sort_order),
      CONSTRAINT fk_rodent_result_inspection FOREIGN KEY (inspection_id) REFERENCES autocontrollo_rodent_inspections(id) ON DELETE CASCADE,
      CONSTRAINT fk_rodent_result_trap FOREIGN KEY (trap_id) REFERENCES autocontrollo_rodent_traps(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  ");
}
}

if (!function_exists('ensure_autocontrollo_pool_inspections_tables')) {
function ensure_autocontrollo_pool_inspections_tables(PDO $pdo): void {
  ensure_autocontrollo_pool_products_table($pdo);
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS autocontrollo_pool_inspections (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      season_start DATE NOT NULL,
      season_end DATE NOT NULL,
      inspection_date DATE NOT NULL,
      inspection_time TIME NOT NULL,
      operator_id INT UNSIGNED DEFAULT NULL,
      chlorine DECIMAL(6,2) NOT NULL,
      water_temperature DECIMAL(5,2) NOT NULL,
      ph_value DECIMAL(4,2) NOT NULL,
      people_in_pool INT UNSIGNED NOT NULL DEFAULT 0,
      backwash_minutes INT UNSIGNED DEFAULT NULL,
      sample_location ENUM('interno','esterno') NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uq_pool_inspection_season_date (season_start, season_end, inspection_date),
      INDEX idx_pool_inspection_date (inspection_date),
      CONSTRAINT fk_pool_inspection_operator FOREIGN KEY (operator_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  ");
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS autocontrollo_pool_inspection_products (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      inspection_id INT UNSIGNED NOT NULL,
      product_id INT UNSIGNED DEFAULT NULL,
      product_description VARCHAR(255) NOT NULL,
      quantity_kg DECIMAL(8,3) NOT NULL,
      CONSTRAINT fk_pool_product_inspection FOREIGN KEY (inspection_id) REFERENCES autocontrollo_pool_inspections(id) ON DELETE CASCADE,
      CONSTRAINT fk_pool_product_catalog FOREIGN KEY (product_id) REFERENCES autocontrollo_pool_products(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  ");
}
}

if (!function_exists('ensure_autocontrollo_electrical_inspections_tables')) {
function ensure_autocontrollo_electrical_inspections_tables(PDO $pdo): void {
  ensure_autocontrollo_electrical_panels_table($pdo);
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS autocontrollo_electrical_inspections (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      season_start DATE NOT NULL,
      season_end DATE NOT NULL,
      inspection_type ENUM('pre_apertura','post_chiusura') NOT NULL,
      scheduled_date DATE NOT NULL,
      status ENUM('in_corso','completata') NOT NULL DEFAULT 'in_corso',
      started_by INT UNSIGNED DEFAULT NULL,
      started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      completed_at DATETIME DEFAULT NULL,
      email_sent_at DATETIME DEFAULT NULL,
      UNIQUE KEY uq_electrical_inspection_season_type (season_start, season_end, inspection_type),
      INDEX idx_electrical_inspection_started_at (started_at),
      CONSTRAINT fk_electrical_inspection_user FOREIGN KEY (started_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  ");
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS autocontrollo_electrical_inspection_results (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      inspection_id INT UNSIGNED NOT NULL,
      panel_id INT UNSIGNED DEFAULT NULL,
      panel_location VARCHAR(190) NOT NULL,
      sort_order INT UNSIGNED NOT NULL,
      external_check TINYINT(1) DEFAULT NULL,
      differentials_ok TINYINT(1) DEFAULT NULL,
      anomaly VARCHAR(500) DEFAULT NULL,
      anomaly_resolved_date DATE DEFAULT NULL,
      anomaly_resolved_by INT UNSIGNED DEFAULT NULL,
      anomaly_resolved_at DATETIME DEFAULT NULL,
      checked_at DATETIME DEFAULT NULL,
      UNIQUE KEY uq_electrical_result_panel (inspection_id, sort_order),
      CONSTRAINT fk_electrical_result_inspection FOREIGN KEY (inspection_id) REFERENCES autocontrollo_electrical_inspections(id) ON DELETE CASCADE,
      CONSTRAINT fk_electrical_result_panel FOREIGN KEY (panel_id) REFERENCES autocontrollo_electrical_panels(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  ");

  $resultColumns = $pdo->query("
    SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'autocontrollo_electrical_inspection_results'
      AND COLUMN_NAME IN ('anomaly_resolved_date','anomaly_resolved_by','anomaly_resolved_at')
  ")->fetchAll(PDO::FETCH_COLUMN);
  $missingResultColumns = [
    'anomaly_resolved_date' => 'DATE DEFAULT NULL AFTER anomaly',
    'anomaly_resolved_by' => 'INT UNSIGNED DEFAULT NULL AFTER anomaly_resolved_date',
    'anomaly_resolved_at' => 'DATETIME DEFAULT NULL AFTER anomaly_resolved_by',
  ];
  foreach ($missingResultColumns as $column => $definition) {
    if (!in_array($column, $resultColumns, true)) {
      $pdo->exec("ALTER TABLE autocontrollo_electrical_inspection_results ADD COLUMN {$column} {$definition}");
    }
  }
}
}
