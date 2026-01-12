-- Add image_path column to notifications table for announcements with images

ALTER TABLE notifications 
ADD COLUMN image_path VARCHAR(255) NULL COMMENT 'Path to announcement image' AFTER link;
