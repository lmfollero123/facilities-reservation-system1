-- Performance Optimization Indexes
-- Run these SQL commands to improve query performance for conflict detection and recommendations
-- This script will drop existing indexes if they exist, then recreate them

-- Index for conflict detection queries (most critical for performance)
-- Speeds up: detectBookingConflict() - finding reservations by facility, date, and status
DROP INDEX IF EXISTS idx_reservations_conflict_check ON reservations;
CREATE INDEX idx_reservations_conflict_check 
ON reservations(facility_id, reservation_date, status, time_slot);

-- Index for historical booking patterns (date-based queries)
-- Speeds up: calculateConflictRiskSimple() - finding historical bookings
DROP INDEX IF EXISTS idx_reservations_historical ON reservations;
CREATE INDEX idx_reservations_historical 
ON reservations(facility_id, reservation_date, status, time_slot);

-- Index for user booking counts
-- Speeds up: facility_recommendations_api.php - counting user bookings
-- Optimizes: SELECT COUNT(*) FROM reservations WHERE user_id = :user_id
-- Using user_id first allows efficient filtering, status/reservation_date help with filtered counts
DROP INDEX IF EXISTS idx_reservations_user ON reservations;
CREATE INDEX idx_reservations_user 
ON reservations(user_id, status, reservation_date);

-- Index for facility status lookups
-- Speeds up: facility queries in recommendations
DROP INDEX IF EXISTS idx_facilities_status ON facilities;
CREATE INDEX idx_facilities_status 
ON facilities(status);

-- Composite index for facility availability queries
-- Speeds up: finding available facilities with capacity
DROP INDEX IF EXISTS idx_facilities_available ON facilities;
CREATE INDEX idx_facilities_available 
ON facilities(status, capacity);

-- Note: After adding indexes, run ANALYZE TABLE to update statistics
ANALYZE TABLE reservations;
ANALYZE TABLE facilities;
