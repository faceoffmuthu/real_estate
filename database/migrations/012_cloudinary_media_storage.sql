ALTER TABLE property_media
    ADD COLUMN storage_provider ENUM('local','cloudinary') NOT NULL DEFAULT 'local' AFTER file_path,
    ADD COLUMN storage_key VARCHAR(255) NULL AFTER storage_provider,
    ADD COLUMN delivery_url VARCHAR(500) NULL AFTER storage_key;
