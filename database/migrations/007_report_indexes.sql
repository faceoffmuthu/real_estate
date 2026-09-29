-- Report filters query transaction_type directly; property_type_id remains indexed too.
ALTER TABLE real_estate_records
    ADD KEY idx_records_transaction_type (transaction_type, created_at);
