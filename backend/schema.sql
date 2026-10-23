-- Execute no banco configurado. Preserva tabelas do iPlate original.
CREATE TABLE IF NOT EXISTS iplate_users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  username VARCHAR(80) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  api_token CHAR(64) NOT NULL UNIQUE,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS iplate_simples_entries (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  operator_name VARCHAR(120) NOT NULL,
  reservation_code VARCHAR(100) NOT NULL,
  guest_name VARCHAR(255) NOT NULL,
  plate VARCHAR(7) NOT NULL,
  plate_photo_path VARCHAR(500) NOT NULL,
  front_photo_path VARCHAR(500) NOT NULL,
  right_photo_path VARCHAR(500) NOT NULL,
  left_photo_path VARCHAR(500) NOT NULL,
  rear_photo_path VARCHAR(500) NOT NULL,
  roof_photo_path VARCHAR(500) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_simple_reservation (reservation_code),
  INDEX idx_simple_plate (plate),
  INDEX idx_simple_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
