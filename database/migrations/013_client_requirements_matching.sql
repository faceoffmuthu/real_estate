-- Client requirements power property-to-client matching. A party can have one
-- current requirement; changing it updates the existing preference set.
CREATE TABLE IF NOT EXISTS client_requirements (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    party_id INT UNSIGNED NOT NULL,
    transaction_type ENUM('rental','sale') NOT NULL,
    property_category ENUM('residential','commercial') NULL,
    preferred_city VARCHAR(100) NOT NULL,
    preferred_locality VARCHAR(100) NULL,
    min_budget DECIMAL(14,2) NULL,
    max_budget DECIMAL(14,2) NULL,
    min_bedrooms TINYINT UNSIGNED NULL,
    min_bathrooms TINYINT UNSIGNED NULL,
    min_area_sqft DECIMAL(12,2) NULL,
    furnishing ENUM('unfurnished','semi_furnished','fully_furnished') NULL,
    notes VARCHAR(2000) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by INT UNSIGNED NOT NULL,
    updated_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_client_requirement_party (party_id),
    KEY idx_client_requirements_match (is_active, transaction_type, property_category, preferred_city),
    CONSTRAINT fk_client_requirement_party FOREIGN KEY (party_id) REFERENCES parties(id) ON DELETE CASCADE,
    CONSTRAINT fk_client_requirement_creator FOREIGN KEY (created_by) REFERENCES users(id),
    CONSTRAINT fk_client_requirement_updater FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
