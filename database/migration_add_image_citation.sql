-- Migration: Add image citation/source field to facilities table
-- This allows proper attribution for images from Google Maps or other sources

ALTER TABLE facilities
    ADD COLUMN image_citation VARCHAR(500) NULL COMMENT 'Image source/citation (e.g., Google Maps, photographer name, etc.)' 
    AFTER image_path;






