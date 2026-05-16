-- Migration: Bring back pencil-booking + payments
-- Run in phpMyAdmin/MySQL against facilities_reservation database

USE facilities_reservation;

-- 1) Expand reservation status values to support payment-gated flow
ALTER TABLE reservations
MODIFY COLUMN status ENUM('pending_payment','pending','approved','denied','cancelled','on_hold','postponed')
NOT NULL DEFAULT 'pending_payment';

ALTER TABLE reservation_history
MODIFY COLUMN status ENUM('pending_payment','pending','approved','denied','cancelled','on_hold','postponed')
NOT NULL;

-- 2) Add hold expiration for pencil bookings if missing
ALTER TABLE reservations
ADD COLUMN IF NOT EXISTS payment_due_at DATETIME NULL AFTER expires_at;

-- 3) Recreate payments table
CREATE TABLE IF NOT EXISTS payments (
  id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  reservation_id INT(10) UNSIGNED NOT NULL,
  user_id INT(10) UNSIGNED NOT NULL,
  provider VARCHAR(30) NOT NULL DEFAULT 'paymongo',
  provider_checkout_id VARCHAR(120) DEFAULT NULL,
  provider_payment_intent_id VARCHAR(120) DEFAULT NULL,
  provider_event_id VARCHAR(120) DEFAULT NULL,
  amount DECIMAL(10,2) NOT NULL,
  currency VARCHAR(10) NOT NULL DEFAULT 'PHP',
  status ENUM('pending','paid','failed','expired','cancelled') NOT NULL DEFAULT 'pending',
  reference_no VARCHAR(60) DEFAULT NULL,
  paid_at DATETIME DEFAULT NULL,
  expires_at DATETIME DEFAULT NULL,
  payload_json LONGTEXT DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_payment_reservation (reservation_id),
  KEY idx_payment_status (status),
  KEY idx_payment_checkout (provider_checkout_id),
  KEY idx_payment_event (provider_event_id),
  CONSTRAINT fk_payment_reservation FOREIGN KEY (reservation_id) REFERENCES reservations(id) ON DELETE CASCADE,
  CONSTRAINT fk_payment_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
