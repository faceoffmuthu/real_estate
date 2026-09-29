CREATE TABLE IF NOT EXISTS property_media (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    record_id INT UNSIGNED NOT NULL,
    media_type ENUM('image','video') NOT NULL,
    file_name VARCHAR(80) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    file_size BIGINT UNSIGNED NOT NULL,
    created_by INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_property_media_record (record_id, media_type, id),
    CONSTRAINT fk_property_media_record FOREIGN KEY (record_id) REFERENCES real_estate_records(id) ON DELETE CASCADE,
    CONSTRAINT fk_property_media_creator FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
