-- Split the former single property type into independent transaction and category values.
-- Ambiguous legacy Residential/Commercial rows retain their category but remain
-- transaction_type NULL unless the old purpose provides a clear mapping.
ALTER TABLE real_estate_records
    ADD COLUMN transaction_type ENUM('rental','sale') NULL AFTER property_type_id,
    ADD COLUMN property_category ENUM('residential','commercial') NULL AFTER transaction_type;

UPDATE real_estate_records r
JOIN property_types t ON t.id = r.property_type_id
SET r.transaction_type = 'rental'
WHERE t.slug = 'rental';

UPDATE real_estate_records r
JOIN property_types t ON t.id = r.property_type_id
SET r.property_category = t.slug
WHERE t.slug IN ('residential','commercial');

UPDATE real_estate_records
SET transaction_type = CASE
    WHEN purpose IN ('sale','purchase') THEN 'sale'
    WHEN purpose IN ('rent','lease') THEN 'rental'
    ELSE NULL
END
WHERE property_category IS NOT NULL;
