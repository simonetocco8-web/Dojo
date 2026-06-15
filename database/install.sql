-- database/install.sql
CREATE DATABASE IF NOT EXISTS php_admin_starter CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE php_admin_starter;

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('admin','editor') NOT NULL DEFAULT 'editor',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Admin default: admin@example.com / Admin123!
INSERT INTO users (email, password_hash, role, is_active) VALUES
('admin@example.com', '$2y$10$0wHjQ5e9jCzFjv9P6R4fhuZKzC2bJE4uWf0mL2gZ2Nn8Q2p3r8dGm', 'admin', 1)
ON DUPLICATE KEY UPDATE email=email;

-- Dipartimenti multipli utente: usare VARCHAR per salvare liste comma-separated
ALTER TABLE users
  ADD COLUMN IF NOT EXISTS dipartimento VARCHAR(255) NOT NULL DEFAULT 'Amministrazione';
ALTER TABLE users
  MODIFY COLUMN dipartimento VARCHAR(255) NOT NULL DEFAULT 'Amministrazione';

CREATE TABLE IF NOT EXISTS ewelink_tokens (
  user_id INT UNSIGNED NOT NULL PRIMARY KEY,
  access_token TEXT NOT NULL,
  refresh_token TEXT DEFAULT NULL,
  token_type VARCHAR(50) DEFAULT NULL,
  expires_at DATETIME DEFAULT NULL,
  scope VARCHAR(190) DEFAULT NULL,
  api_region VARCHAR(20) DEFAULT NULL,
  api_endpoint VARCHAR(255) DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_ewelink_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS riassetti (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  data_riassetto DATE NOT NULL,
  room VARCHAR(50) NOT NULL,
  qty_matrimoniale INT UNSIGNED NOT NULL DEFAULT 0,
  qty_singola INT UNSIGNED NOT NULL DEFAULT 0,
  qty_set_bagno INT UNSIGNED NOT NULL DEFAULT 0,
  pulizia_extra TINYINT(1) NOT NULL DEFAULT 0,
  note TEXT DEFAULT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'da_preparare',
  google_event_id VARCHAR(255) DEFAULT NULL,
  completed_at DATETIME DEFAULT NULL,
  completed_by INT UNSIGNED DEFAULT NULL,
  created_by INT UNSIGNED NOT NULL,
  updated_by INT UNSIGNED DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_riassetti_completed_by FOREIGN KEY (completed_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_riassetti_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_riassetti_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Aggiornamenti tabella transfers_external
ALTER TABLE transfers_external
  ADD COLUMN IF NOT EXISTS people_count INT UNSIGNED DEFAULT NULL AFTER guest_name,
  ADD COLUMN IF NOT EXISTS price_eur DECIMAL(10,2) DEFAULT NULL AFTER people_count,
  ADD COLUMN IF NOT EXISTS supplier_price_eur DECIMAL(10,2) DEFAULT NULL AFTER price_eur;

-- Aggiornamenti tabella suppliers
ALTER TABLE suppliers
  ADD COLUMN IF NOT EXISTS is_active TINYINT(1) NOT NULL DEFAULT 1;

-- Aggiornamenti URL prodotti
ALTER TABLE products
  ADD COLUMN IF NOT EXISTS product_url VARCHAR(2048) DEFAULT NULL AFTER supplier_id;

CREATE TABLE IF NOT EXISTS system_settings (
  setting_key VARCHAR(190) NOT NULL PRIMARY KEY,
  setting_value TEXT DEFAULT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO system_settings (setting_key, setting_value)
VALUES ('departments', '["Amministrazione","Reception","Booking","Manutenzione","Bar","HouseKeeping","Navettista","Magazziniere Tizzo","Magazziniere Tramonto"]');


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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


CREATE TABLE IF NOT EXISTS task_user_assignments (
  task_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (task_id, user_id),
  INDEX idx_task_user_assignments_user (user_id),
  CONSTRAINT fk_task_user_assignments_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
  CONSTRAINT fk_task_user_assignments_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;



-- Dettagli transfer interni
ALTER TABLE transfers_internal
  ADD COLUMN IF NOT EXISTS people_count INT UNSIGNED DEFAULT NULL AFTER location,
  ADD COLUMN IF NOT EXISTS note VARCHAR(255) DEFAULT NULL AFTER people_count;

CREATE TABLE IF NOT EXISTS transfer_internal_sms_reminders (
  transfer_id INT UNSIGNED NOT NULL PRIMARY KEY,
  sent_at DATETIME DEFAULT NULL,
  last_error TEXT DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_transfer_internal_sms_reminders_transfer FOREIGN KEY (transfer_id) REFERENCES transfers_internal(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- Prodotti disattivabili: i prodotti non attivi non sono mostrati nella lista principale
ALTER TABLE products
  ADD COLUMN IF NOT EXISTS is_active TINYINT(1) NOT NULL DEFAULT 1;

-- Straordinari del personale
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Dettagli transfer esterni per arrivo/partenza e riferimenti viaggio
ALTER TABLE transfers_external
  MODIFY COLUMN type VARCHAR(32) NOT NULL DEFAULT 'arrivo',
  MODIFY COLUMN status VARCHAR(32) NOT NULL DEFAULT 'attivo',
  ADD COLUMN IF NOT EXISTS supplier_name VARCHAR(80) NOT NULL DEFAULT 'Dany Express' AFTER service_company,
  ADD COLUMN IF NOT EXISTS supplier_confirm_token VARCHAR(64) DEFAULT NULL AFTER supplier_name,
  ADD COLUMN IF NOT EXISTS supplier_reject_token VARCHAR(64) DEFAULT NULL AFTER supplier_confirm_token,
  ADD COLUMN IF NOT EXISTS supplier_token_expires_at DATETIME DEFAULT NULL AFTER supplier_reject_token,
  ADD COLUMN IF NOT EXISTS supplier_responded_at DATETIME DEFAULT NULL AFTER supplier_token_expires_at,
  ADD COLUMN IF NOT EXISTS rejection_reason VARCHAR(255) DEFAULT NULL AFTER supplier_responded_at,
  ADD COLUMN IF NOT EXISTS flight_number VARCHAR(80) DEFAULT NULL AFTER rejection_reason,
  ADD COLUMN IF NOT EXISTS train_number VARCHAR(80) DEFAULT NULL AFTER flight_number,
  ADD COLUMN IF NOT EXISTS arrival_place VARCHAR(190) DEFAULT NULL AFTER train_number,
  ADD COLUMN IF NOT EXISTS arrival_date_time DATETIME DEFAULT NULL AFTER arrival_place,
  ADD COLUMN IF NOT EXISTS arrival_pickup_time TIME DEFAULT NULL AFTER arrival_date_time,
  ADD COLUMN IF NOT EXISTS arrival_flight_number VARCHAR(80) DEFAULT NULL AFTER arrival_pickup_time,
  ADD COLUMN IF NOT EXISTS arrival_train_number VARCHAR(80) DEFAULT NULL AFTER arrival_flight_number,
  ADD COLUMN IF NOT EXISTS departure_place VARCHAR(190) DEFAULT NULL AFTER arrival_train_number,
  ADD COLUMN IF NOT EXISTS departure_date_time DATETIME DEFAULT NULL AFTER departure_place,
  ADD COLUMN IF NOT EXISTS departure_pickup_time TIME DEFAULT NULL AFTER departure_date_time,
  ADD COLUMN IF NOT EXISTS departure_flight_number VARCHAR(80) DEFAULT NULL AFTER departure_pickup_time,
  ADD COLUMN IF NOT EXISTS departure_train_number VARCHAR(80) DEFAULT NULL AFTER departure_flight_number;
