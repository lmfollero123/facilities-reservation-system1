-- Create contact_info table for managing Barangay Culiat contact information

CREATE TABLE IF NOT EXISTS contact_info (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    field_name VARCHAR(100) NOT NULL UNIQUE COMMENT 'e.g., office_name, address, phone, email',
    field_value TEXT NOT NULL COMMENT 'The actual contact information',
    display_order INT UNSIGNED DEFAULT 0 COMMENT 'Order for display',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default contact information
INSERT INTO contact_info (field_name, field_value, display_order) VALUES
('office_name', 'Barangay Culiat Facilities Management Office', 1),
('address', 'Barangay Culiat, Quezon City, Metro Manila', 2),
('phone', '(02) 1234-5678', 3),
('mobile', '0912-345-6789', 4),
('email', 'facilities@barangayculiat.gov.ph', 5),
('office_hours', 'Monday - Friday: 8:00 AM - 5:00 PM<br>Saturday: 8:00 AM - 12:00 PM<br>Sunday: Closed', 6)
ON DUPLICATE KEY UPDATE field_value = VALUES(field_value);
