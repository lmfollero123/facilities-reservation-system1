-- Migration: Add 'refunded' status to payments table
-- Run in phpMyAdmin/MySQL against facilities_reservation database
-- This allows tracking refunded payments when reservations are cancelled

USE facilities_reservation;

-- Add refunded status to payments table status ENUM
ALTER TABLE payments
MODIFY COLUMN status ENUM('pending','paid','failed','expired','cancelled','refunded') NOT NULL DEFAULT 'pending';
