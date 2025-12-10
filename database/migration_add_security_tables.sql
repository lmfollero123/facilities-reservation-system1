-- Migration: Add security-related tables
-- Rate limiting, security logs, and account lockout

-- Rate limiting table
CREATE TABLE IF NOT EXISTS rate_limits (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    action VARCHAR(50) NOT NULL,
    identifier VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_rate_limit (action, identifier, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Security logs table
CREATE TABLE IF NOT EXISTS security_logs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event VARCHAR(100) NOT NULL,
    details TEXT NULL,
    severity ENUM('info', 'warning', 'error', 'critical') NOT NULL DEFAULT 'info',
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    user_id INT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_security_logs (event, severity, created_at),
    INDEX idx_security_user (user_id),
    CONSTRAINT fk_security_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Login attempts table (for account lockout)
CREATE TABLE IF NOT EXISTS login_attempts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(190) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    success TINYINT(1) NOT NULL DEFAULT 0,
    attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_login_attempts (email, attempted_at),
    INDEX idx_login_ip (ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add account lockout fields to users table
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS failed_login_attempts INT UNSIGNED DEFAULT 0,
    ADD COLUMN IF NOT EXISTS locked_until DATETIME NULL,
    ADD COLUMN IF NOT EXISTS last_login_at DATETIME NULL,
    ADD COLUMN IF NOT EXISTS last_login_ip VARCHAR(45) NULL;



