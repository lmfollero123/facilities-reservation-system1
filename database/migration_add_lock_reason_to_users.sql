-- Add lock_reason to users for admin lock notes
ALTER TABLE users
ADD COLUMN lock_reason TEXT NULL AFTER locked_until;


