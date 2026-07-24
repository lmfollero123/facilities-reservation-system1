-- Announcements (system-wide notifications with NULL user_id) can carry an
-- image; the public home page and announcements management select/insert
-- notifications.image_path. The column was only ever added by hand on the
-- production database — this migration closes the schema drift so fresh
-- installs and local environments match production.
ALTER TABLE notifications
    ADD COLUMN IF NOT EXISTS image_path VARCHAR(255) NULL AFTER link;
