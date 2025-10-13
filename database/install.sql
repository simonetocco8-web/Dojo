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
  ADD COLUMN IF NOT EXISTS price_eur DECIMAL(10,2) DEFAULT NULL AFTER people_count;
