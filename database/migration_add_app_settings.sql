-- Migration: generic key-value app settings, starting with the security
-- timers (session auto-logout, login OTP lifetime/resend cooldown, email
-- verification code lifetime) that were previously hardcoded constants in
-- config/security.php. Admin-editable from System Settings > Security & Timers.

CREATE TABLE IF NOT EXISTS app_settings (
    setting_key VARCHAR(100) NOT NULL PRIMARY KEY,
    setting_value VARCHAR(255) NOT NULL,
    updated_by INT UNSIGNED NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_app_settings_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
);
