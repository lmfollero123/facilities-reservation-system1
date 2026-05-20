-- Notification preferences (JSON) and booking reminder tracking

-- Run once in phpMyAdmin or mysql CLI (MariaDB 10.5.2+ supports IF NOT EXISTS on columns)

ALTER TABLE users
  ADD COLUMN IF NOT EXISTS notification_preferences JSON NULL
    COMMENT 'User opt-in for in-app, email, SMS by category';

ALTER TABLE reservations
  ADD COLUMN IF NOT EXISTS reminder_sent_at TIMESTAMP NULL DEFAULT NULL
    COMMENT 'When 24h reminder was sent';
