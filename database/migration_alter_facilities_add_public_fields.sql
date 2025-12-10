ALTER TABLE facilities
    ADD COLUMN image_path VARCHAR(255) NULL AFTER base_rate,
    ADD COLUMN location VARCHAR(190) NULL AFTER image_path,
    ADD COLUMN capacity VARCHAR(100) NULL AFTER location,
    ADD COLUMN amenities TEXT NULL AFTER capacity,
    ADD COLUMN rules TEXT NULL AFTER amenities;








