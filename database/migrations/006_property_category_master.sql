-- Establish canonical transaction types while retaining legacy master rows
-- referenced by older records. Legacy rows are hidden from current type APIs.
INSERT IGNORE INTO property_types (slug, name, field_group, description, is_active, sort_order)
VALUES ('sale', 'Sale', 'general', 'Properties offered for sale.', 1, 2);

UPDATE property_types SET is_active = 0 WHERE slug IN ('residential', 'commercial');

CREATE TABLE IF NOT EXISTS property_categories (
    id SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug VARCHAR(30) NOT NULL,
    name VARCHAR(60) NOT NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_property_categories_slug (slug),
    UNIQUE KEY uq_property_categories_name (name),
    KEY idx_property_categories_active (is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO property_categories (slug, name, sort_order) VALUES
    ('residential', 'Residential', 1),
    ('commercial', 'Commercial', 2);

ALTER TABLE real_estate_records
    ADD COLUMN property_category_id SMALLINT UNSIGNED NULL AFTER transaction_type,
    ADD KEY idx_records_category (property_category_id),
    ADD CONSTRAINT fk_records_category FOREIGN KEY (property_category_id) REFERENCES property_categories(id) ON UPDATE CASCADE;

UPDATE real_estate_records r
JOIN property_categories c ON c.slug = r.property_category
SET r.property_category_id = c.id
WHERE r.property_category IS NOT NULL;

UPDATE real_estate_records r
JOIN property_types t ON t.slug = r.transaction_type
SET r.property_type_id = t.id
WHERE r.transaction_type IS NOT NULL;
