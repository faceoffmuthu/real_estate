ALTER TABLE real_estate_records
    ADD COLUMN area_value DECIMAL(12,2) NULL AFTER area_sqft,
    ADD COLUMN area_unit VARCHAR(20) NULL AFTER area_value,
    ADD COLUMN property_facing VARCHAR(20) NULL AFTER floor_details,
    ADD COLUMN market_price DECIMAL(14,2) NULL AFTER sale_amount;

-- Existing measurements are stored in square feet, so preserve their value and display unit.
UPDATE real_estate_records
   SET area_value = area_sqft,
       area_unit = 'sq_ft'
 WHERE area_sqft IS NOT NULL;
