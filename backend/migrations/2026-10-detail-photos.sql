-- Fotos adicionais vinculadas à vistoria/veículo, sem modificar o iPlate original.
CREATE TABLE IF NOT EXISTS iplate_simples_detail_photos (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  entry_id BIGINT UNSIGNED NOT NULL,
  sequence_no INT UNSIGNED NOT NULL,
  photo_path VARCHAR(500) NOT NULL,
  description VARCHAR(180) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_simple_entry_detail_position (entry_id, sequence_no),
  CONSTRAINT fk_simple_detail_entry FOREIGN KEY (entry_id)
    REFERENCES iplate_simples_entries (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
