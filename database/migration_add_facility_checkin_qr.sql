-- Facility check-in QR tokens (one persistent QR per facility for on-site scan)

ALTER TABLE facilities
    ADD COLUMN checkin_qr_token VARCHAR(64) NULL COMMENT 'Persistent token encoded in facility QR poster',
    ADD UNIQUE KEY uniq_facility_checkin_qr (checkin_qr_token);
