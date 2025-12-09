-- Migration: Add performance-focused indexes for common queries

-- Users
CREATE INDEX IF NOT EXISTS idx_users_status ON users(status);
CREATE INDEX IF NOT EXISTS idx_users_role ON users(role);
CREATE INDEX IF NOT EXISTS idx_users_locked_until ON users(locked_until);
CREATE INDEX IF NOT EXISTS idx_users_otp_expires ON users(otp_expires_at);

-- Facilities
CREATE INDEX IF NOT EXISTS idx_facilities_status ON facilities(status);
CREATE INDEX IF NOT EXISTS idx_facilities_created ON facilities(created_at);

-- Reservations (optimize listing by date/status/facility/user)
CREATE INDEX IF NOT EXISTS idx_reservations_status_date ON reservations(status, reservation_date);
CREATE INDEX IF NOT EXISTS idx_reservations_facility_date ON reservations(facility_id, reservation_date);
CREATE INDEX IF NOT EXISTS idx_reservations_user ON reservations(user_id);

-- Reservation history
CREATE INDEX IF NOT EXISTS idx_reservation_history_reservation ON reservation_history(reservation_id);
CREATE INDEX IF NOT EXISTS idx_reservation_history_created ON reservation_history(created_at);

-- User documents (filter by type and user)
CREATE INDEX IF NOT EXISTS idx_user_documents_type ON user_documents(document_type);

-- Notifications (filter by type/read state)
CREATE INDEX IF NOT EXISTS idx_notifications_type_read ON notifications(type, is_read);

-- Rate limits (expire sweep and lookup)
CREATE INDEX IF NOT EXISTS idx_rate_limits_expires ON rate_limits(expires_at);

-- Security logs (time-based queries)
CREATE INDEX IF NOT EXISTS idx_security_logs_created ON security_logs(created_at);


