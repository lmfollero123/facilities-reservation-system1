-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Dec 30, 2025 at 06:32 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `facilities_reservation`
--

-- --------------------------------------------------------

--
-- Table structure for table `audit_log`
--

CREATE TABLE `audit_log` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `module` varchar(50) NOT NULL,
  `details` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `audit_log`
--

INSERT INTO `audit_log` (`id`, `user_id`, `action`, `module`, `details`, `ip_address`, `user_agent`, `created_at`) VALUES
(1, 1, 'Created reservation request', 'Reservations', 'RES-3 – Community Convention Hall (2025-11-29 Morning (8AM - 12PM))', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-11-28 16:18:58'),
(2, 1, 'Approved reservation', 'Reservations', 'RES-3 – Community Convention Hall (2025-11-29 Morning (8AM - 12PM)) – Note: Sige nga UwU', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-11-28 16:19:59'),
(3, 1, 'Approved user account', 'User Management', 'Luis Miguel Follero (follero.luismiguel.noora@gmail.com)', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-12-03 13:05:58'),
(4, 2, 'Created reservation request', 'Reservations', 'RES-4 – Community Convention Hall (2025-12-04 Morning (8AM - 12PM))', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-12-03 13:26:25'),
(5, 1, 'Updated facility', 'Facility Management', 'Covered Court', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-12-03 14:18:57'),
(6, 2, 'Created reservation request', 'Reservations', 'RES-5 – Covered Court (2025-12-11 Morning (8AM - 12PM))', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-12-05 08:02:45'),
(7, 1, 'Auto-denied expired reservation', 'Reservations', 'RES-4 – Past reservation time without approval', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-12-05 09:08:39'),
(8, 1, 'Approved reservation', 'Reservations', 'RES-5 – Covered Court (2025-12-11 Morning (8AM - 12PM)) – Note: Sige na nga UwU', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-12-05 09:09:31'),
(9, 1, 'Created reservation request', 'Reservations', 'RES-6 – Covered Court (2025-12-14 Morning (8AM - 12PM))', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-12-07 07:44:05'),
(10, 1, 'Updated facility', 'Facility Management', 'Covered Court', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-12-07 08:00:53'),
(11, 1, 'Updated facility', 'Facility Management', 'Community Convention Hall', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-12-07 08:08:59'),
(12, 1, 'Updated facility', 'Facility Management', 'Municipal Sports Complex', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-12-07 08:18:38'),
(13, 1, 'Updated facility', 'Facility Management', 'People\'s Park Amphitheater', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-12-07 08:19:19'),
(14, 1, 'Updated facility', 'Facility Management', 'Covered Court', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-12-07 08:20:54'),
(15, 1, 'Created facility', 'Facility Management', 'Cassanova Multipurpose Building (available)', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-12-07 13:23:26'),
(16, 1, 'Updated facility', 'Facility Management', 'Sanville Covered Court w/ Multipurpose BLDG', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-12-07 13:36:23'),
(17, 1, 'Updated facility', 'Facility Management', 'Pael Multipurpose BLDG/ Burial Site', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-12-07 13:50:47'),
(18, 1, 'Updated facility', 'Facility Management', 'Culiat Highschool', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-12-07 13:54:17'),
(19, 1, 'Updated facility', 'Facility Management', 'Bernardo Court', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-12-07 13:56:05'),
(20, 1, 'Approved user account', 'User Management', 'Admin User (user@lgu.gov.ph)', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-12-07 14:21:34'),
(21, 1, 'Updated facility', 'Facility Management', 'Culiat Highschool', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-12-08 06:29:03'),
(22, 1, 'Updated facility', 'Facility Management', 'Bernardo Court', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-12-08 06:29:49'),
(23, 1, 'Updated facility', 'Facility Management', 'Pael Multipurpose BLDG/ Burial Site', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-12-08 06:30:32'),
(24, 1, 'Updated facility', 'Facility Management', 'Sanville Covered Court w/ Multipurpose BLDG', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-12-08 06:35:17'),
(25, 1, 'Updated facility', 'Facility Management', 'Cassanova Multipurpose Building', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-12-08 06:38:26'),
(26, 1, 'Approved user account', 'User Management', 'Marites Gonzales (morpquasar@gmail.com)', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-12-08 13:45:43'),
(27, 1, 'Locked user account', 'User Management', 'Marites Gonzales (morpquasar@gmail.com)', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-12-09 06:30:37'),
(28, 1, 'Approved reservation', 'Reservations', 'RES-6 – Bernardo Court (2025-12-14 Morning (8AM - 12PM))', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-12-09 06:42:42'),
(29, 1, 'Unlocked user account', 'User Management', 'Marites Gonzales (morpquasar@gmail.com)', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-12-10 10:50:03'),
(30, 1, 'Locked user account', 'User Management', 'Marites Gonzales (morpquasar@gmail.com)', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-12-10 10:50:23'),
(31, 1, 'Postponed approved reservation', 'Reservations', 'RES-6 – Bernardo Court – Postponed from 2025-12-14 Morning (8AM - 12PM) to 2025-12-17 Morning (8:00 AM - 12:00 PM). Reason: Will be used as evacuation center', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', '2025-12-13 14:11:44'),
(32, 1, 'Approved reservation', 'Reservations', 'RES-6 – Bernardo Court (2025-12-17 Morning (8:00 AM - 12:00 PM)) – Note: Sge na nga hehehe', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', '2025-12-13 14:35:23'),
(33, 1, 'Updated facility', 'Facility Management', 'Cassanova Multipurpose Building', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', '2025-12-18 11:39:07'),
(34, 1, 'Created reservation request', 'Reservations', 'RES-7 – Cassanova Multipurpose Building (2025-12-18 08:00 - 10:30) [Auto-approved]', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', '2025-12-18 11:40:38'),
(35, 1, 'User data exported', 'Data Export', 'User ID: 1, Export Type: full, Created By: User', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', '2025-12-18 14:19:26'),
(36, 1, 'Unlocked user account', 'User Management', 'Marites Gonzales (morpquasar@gmail.com)', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', '2025-12-28 05:10:18'),
(37, 4, 'Created reservation request', 'Reservations', 'RES-8 – Culiat Highschool (2025-12-31 08:00 - 20:00)', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', '2025-12-28 05:14:34'),
(38, 1, 'Document #1 accessed (type: view)', 'Document Access', 'Document #1 accessed by user #1', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', '2025-12-28 05:48:25'),
(39, 1, 'Exported audit trail to CSV', 'Audit Trail', 'Filters: {\"module\":\"\",\"user\":\"\",\"date_from\":\"\",\"date_to\":\"\"} | Total records: 38', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', '2025-12-28 06:40:46'),
(40, 1, 'Exported audit trail to CSV', 'Audit Trail', 'Filters: {\"module\":\"\",\"user\":\"\",\"date_from\":\"\",\"date_to\":\"\"} | Total records: 39', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', '2025-12-28 06:40:46');

-- --------------------------------------------------------

--
-- Table structure for table `contact_inquiries`
--

CREATE TABLE `contact_inquiries` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(150) NOT NULL,
  `email` varchar(190) NOT NULL,
  `organization` varchar(255) DEFAULT NULL,
  `message` text NOT NULL,
  `status` enum('new','in_progress','resolved','closed') NOT NULL DEFAULT 'new',
  `admin_notes` text DEFAULT NULL,
  `responded_by` int(10) UNSIGNED DEFAULT NULL,
  `responded_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `data_exports`
--

CREATE TABLE `data_exports` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `export_type` enum('full','reservations','profile','documents') NOT NULL,
  `file_path` varchar(255) NOT NULL COMMENT 'Path to exported file (with expiration)',
  `expires_at` datetime NOT NULL COMMENT 'Export files expire after 7 days for security',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_by` int(10) UNSIGNED DEFAULT NULL COMMENT 'Admin who created export (NULL = user self-export)'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `data_exports`
--

INSERT INTO `data_exports` (`id`, `user_id`, `export_type`, `file_path`, `expires_at`, `created_at`, `created_by`) VALUES
(1, 1, 'full', 'storage/exports/user_1_2025-12-18_151925_full.json', '2025-12-25 15:19:26', '2025-12-18 14:19:26', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `document_access_log`
--

CREATE TABLE `document_access_log` (
  `id` int(10) UNSIGNED NOT NULL,
  `document_id` int(10) UNSIGNED NOT NULL COMMENT 'ID of accessed document',
  `user_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'Owner of the document',
  `accessed_by` int(10) UNSIGNED NOT NULL COMMENT 'User who accessed the document',
  `access_type` enum('view','download','view_thumbnail') NOT NULL DEFAULT 'view',
  `ip_address` varchar(45) DEFAULT NULL COMMENT 'IP address of requester',
  `user_agent` text DEFAULT NULL COMMENT 'User agent string',
  `accessed_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `document_access_log`
--

INSERT INTO `document_access_log` (`id`, `document_id`, `user_id`, `accessed_by`, `access_type`, `ip_address`, `user_agent`, `accessed_at`) VALUES
(1, 1, 3, 1, 'view', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', '2025-12-28 05:42:30'),
(2, 1, 3, 1, 'view', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', '2025-12-28 05:48:25');

-- --------------------------------------------------------

--
-- Table structure for table `document_retention_policy`
--

CREATE TABLE `document_retention_policy` (
  `id` int(10) UNSIGNED NOT NULL,
  `document_type` enum('user_document','reservation','audit_log','security_log','reservation_history') NOT NULL,
  `retention_days` int(10) UNSIGNED NOT NULL COMMENT 'Total retention period in days',
  `archive_after_days` int(10) UNSIGNED NOT NULL COMMENT 'Archive after this many days',
  `auto_delete_after_days` int(10) UNSIGNED DEFAULT NULL COMMENT 'Auto-delete after this many days (NULL = never auto-delete, requires manual review)',
  `description` text DEFAULT NULL COMMENT 'Policy description and legal basis',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `document_retention_policy`
--

INSERT INTO `document_retention_policy` (`id`, `document_type`, `retention_days`, `archive_after_days`, `auto_delete_after_days`, `description`, `created_at`, `updated_at`) VALUES
(1, 'user_document', 2555, 1095, 2555, '7 years retention for identity documents (BIR/NBI requirements, Data Privacy Act). Archive after 3 years, auto-delete after 7 years.', '2025-12-18 14:11:11', '2025-12-18 14:11:11'),
(2, 'reservation', 1825, 1095, 1825, '5 years retention for reservation records (Local Government records retention). Archive after 3 years, auto-delete after 5 years.', '2025-12-18 14:11:11', '2025-12-18 14:11:11'),
(3, 'audit_log', 2555, 1825, NULL, '7 years retention for audit logs (accountability, audit trail requirements). Archive after 5 years, never auto-delete (requires manual review).', '2025-12-18 14:11:11', '2025-12-18 14:11:11'),
(4, 'security_log', 1095, 730, NULL, '3 years retention for security logs (security incident investigation). Archive after 2 years, never auto-delete.', '2025-12-18 14:11:11', '2025-12-18 14:11:11'),
(5, 'reservation_history', 1825, 1095, 1825, '5 years retention for reservation history (matches reservation retention). Archive after 3 years, auto-delete after 5 years.', '2025-12-18 14:11:11', '2025-12-18 14:11:11');

-- --------------------------------------------------------

--
-- Table structure for table `facilities`
--

CREATE TABLE `facilities` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `base_rate` varchar(100) DEFAULT NULL,
  `image_path` varchar(255) DEFAULT NULL,
  `image_citation` varchar(500) DEFAULT NULL COMMENT 'Image source/citation (e.g., Google Maps, photographer name, etc.)',
  `location` varchar(190) DEFAULT NULL,
  `latitude` decimal(10,8) DEFAULT NULL,
  `longitude` decimal(11,8) DEFAULT NULL,
  `capacity` varchar(100) DEFAULT NULL,
  `amenities` text DEFAULT NULL,
  `rules` text DEFAULT NULL,
  `status` enum('available','maintenance','offline') NOT NULL DEFAULT 'available',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `auto_approve` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Enable auto-approval for this facility when conditions are met',
  `capacity_threshold` int(10) UNSIGNED DEFAULT NULL COMMENT 'Maximum expected attendees allowed for auto-approval (NULL = no limit)',
  `max_duration_hours` decimal(4,2) DEFAULT NULL COMMENT 'Maximum reservation duration in hours for auto-approval (NULL = no limit)'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `facilities`
--

INSERT INTO `facilities` (`id`, `name`, `description`, `base_rate`, `image_path`, `image_citation`, `location`, `latitude`, `longitude`, `capacity`, `amenities`, `rules`, `status`, `created_at`, `updated_at`, `auto_approve`, `capacity_threshold`, `max_duration_hours`) VALUES
(1, 'Culiat Highschool', 'School. Covered Court. Multipurpose BLDG. 1000. Evacuation center. Voting Center', 'Free', '/public/img/facilities/culiat-highschool-1765115657.png', 'Google Maps. (n.d.). Street View of Culiat High School [Image]. Retrieved December 7, 2025, from. https://www.google.com/maps/place/Culiat+High+School/@14.6681738,121.0563685,3a,75y,90t/data=!3m8!1e2!3m6!1sCIHM0ogKEICAgICKnammUA!2e10!3e12!6shttps:%2F%2Flh3.googleusercontent.com%2Fgps-cs-s%2FAG0ilSwJ0GY6BfKZDX-Yv6qyg_m7ndJ8Bhtu-U9B1E7y_-2BO_3rTzJMaIy8Rn2jrxuYx_HKQKKE2cuQTlxqcYlSYNl492MHlnVblCLpclrbYLScWcluUHthms4_sLol7N7FGmumuEI%3Dw203-h114-k-no!7i4000!8i2250!4m7!3m6!1s0x3397b7389ff58c17:0xa185c1', 'Culiat Quezon City', 14.66817380, 121.05636850, '1000', 'Restrooms, Electric Fans, Sound System. Water.', 'Clean As You Go. No Littering. No unauthorize events', 'available', '2025-11-26 17:58:26', '2025-12-08 06:29:03', 0, NULL, NULL),
(2, 'Pael Multipurpose BLDG/ Burial Site', 'Burial Site, Meetings, 50 Persons Maximum Occupancy', 'Free', '/public/img/facilities/pael-multipurpose-bldg-burial-site-1765115447.png', 'Google Maps. (n.d.). Street View of CebuPael Multipurpose Building [Image]. Retrieved December 7, 2025, from.  https://www.google.com/maps/@14.6601544,121.0485225,3a,75y,151.16h,91.52t/data=!3m7!1e1!3m5!1s4zFAlqP4ZGJiaCZl9vGDpw!2e0!6shttps:%2F%2Fstreetviewpixels-pa.googleapis.com%2Fv1%2Fthumbnail%3Fcb_client%3Dmaps_sv.tactile%26w%3D900%26h%3D600%26pitch%3D-1.5249013731608443%26panoid%3D4zFAlqP4ZGJiaCZl9vGDpw%26yaw%3D151.15990918514552!7i16384!8i8192?entry=ttu&g_ep=EgoyMDI1MTIwMi4wIKXMDSoASAFQAw%', '27 Dumaguete Rd Quezon City, Metro Manila', 14.66015440, 121.04852250, '50', 'Sound System, Monobloc Chairs, Electric Fans, Restrooms. Etc', 'No Littering, Clean As You Go', 'available', '2025-11-26 17:58:26', '2025-12-08 06:30:32', 0, NULL, NULL),
(3, 'Sanville Covered Court w/ Multipurpose BLDG', 'Large court, 200 Maximum Occupancy, 2 Multipurpose BLDG outside for meetings/assembly', 'Free', '/public/img/facilities/sanville-covered-court-w-multipurpose-bldg-1765114580.png', 'Google Maps. (n.d.). Street View of Sanville Covered Basketball Court [Image]. Retrieved December 7, 2025, from. https://www.google.com/maps/place/Sanville+Covered+Basketball+Court/@14.6690945,121.0488002,16z/data=!4m6!3m5!1s0x3397b7374f0f6e1b:0x8bc654395fb0ebb6!8m2!3d14.6690921!4d121.048813!16s%2Fg%2F11ff21pvgr?hl=en-US&entry=ttu&g_ep=EgoyMDI1MTIwMi4wIKXMDSoASAFQAw%3D%3D', 'M29X+JGP, Quezon City, Metro Manila', 14.66909210, 121.04881300, '200', 'Sound System, Projector, Chairs, Etc..', 'No Littering, Loud Noises, Etc..', 'available', '2025-11-26 17:58:26', '2025-12-08 06:35:17', 0, NULL, NULL),
(4, 'Bernardo Court', 'Covered Court, Large, Big Space, Audience, 50 Persons', 'Free', '/public/img/facilities/bernardo-court-1765115764.png', 'Google Maps. (n.d.). Street View of Bernardo Covered Court (QC Gas 2) [Image]. Retrieved December 7, 2025, from. https://www.google.com/maps/place/QC+Gas+2/@14.6621408,121.0471,3a,75y,132.05h,88.23t/data=!3m7!1e1!3m5!1slk7CVKn-I73xMwc4MtsO2g!2e0!6shttps:%2F%2Fstreetviewpixels-pa.googleapis.com%2Fv1%2Fthumbnail%3Fcb_client%3Dmaps_sv.tactile%26w%3D900%26h%3D600%26pitch%3D1.769999999999996%26panoid%3Dlk7CVKn-I73xMwc4MtsO2g%26yaw%3D132.05!7i16384!8i8192!4m10!1m2!2m1!1sbernardo+covert+court!3m6!1s0x3', '31 Central Ave Quezon City, Metro Manila', 14.66214080, 121.04710000, '200', 'Sound System, Monobloc Chairs, Restrooms, Free Water', 'Clean as you go. No littering. No unauthorized events', 'available', '2025-11-26 18:06:39', '2025-12-08 06:29:49', 0, NULL, NULL),
(5, 'Cassanova Multipurpose Building', 'Multipurpose Building, 30 Maximum Occupancy,', 'Free', '/public/img/facilities/cassanova-multipurpose-building-1765113805.png', 'Google Maps. (n.d.). Street View of Cassanova Multipurpose Building [Image]. Retrieved December 7, 2025, from.  https://www.google.com/local/place/fid/0x3397b7c83870b82f:0xe028fd0e41f88caa/photosphere?iu=https://streetviewpixels-pa.googleapis.com/v1/thumbnail?panoid%3DuzMxBq-VvrNpRpvMSGGg5w%26cb_client%3Dsearch.gws-prod.gps%26yaw%3D293.4869%26pitch%3D0%26thumbfov%3D100%26w%3D0%26h%3D0&ik=CAISFnV6TXhCcS1WdnJOcFJwdk1TR0dnNXc%3D&sa=X&ved=2ahUKEwjN4_-7jauRAxWEoa8BHXd1K18Qpx96BAhLEAU', 'Cassanova St. Culiat, QC', 14.66693750, 121.05273440, '30', 'Speakers, Chairs, Water, Restrooms', 'No Unauthorized Events allowed. Keep area clean after use', 'available', '2025-12-07 13:23:25', '2025-12-18 11:39:07', 1, 10, 6.00);

-- --------------------------------------------------------

--
-- Table structure for table `facility_blackout_dates`
--

CREATE TABLE `facility_blackout_dates` (
  `id` int(10) UNSIGNED NOT NULL,
  `facility_id` int(10) UNSIGNED NOT NULL,
  `blackout_date` date NOT NULL,
  `reason` varchar(255) DEFAULT NULL COMMENT 'Reason for blackout (e.g., maintenance, special event)',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_by` int(10) UNSIGNED DEFAULT NULL COMMENT 'Admin/Staff who created this blackout'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `login_attempts`
--

CREATE TABLE `login_attempts` (
  `id` int(10) UNSIGNED NOT NULL,
  `email` varchar(190) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `success` tinyint(1) NOT NULL DEFAULT 0,
  `attempted_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `login_attempts`
--

INSERT INTO `login_attempts` (`id`, `email`, `ip_address`, `success`, `attempted_at`) VALUES
(1, 'follero.luismiguel.noora@gmail.com', '127.0.0.1', 0, '2025-12-08 06:05:15'),
(2, 'follero.luismiguel.noora@gmail.com', '127.0.0.1', 0, '2025-12-08 06:05:17'),
(3, 'follero.luismiguel.noora@gmail.com', '127.0.0.1', 0, '2025-12-08 06:05:19'),
(4, 'follero.luismiguel.noora@gmail.com', '127.0.0.1', 0, '2025-12-08 06:05:21'),
(5, 'follero.luismiguel.noora@gmail.com', '127.0.0.1', 0, '2025-12-08 06:05:22'),
(6, 'admin@lgu.gov.ph', '127.0.0.1', 1, '2025-12-08 06:23:58'),
(7, 'follero.luismiguel.noora@gmail.com', '127.0.0.1', 1, '2025-12-08 06:38:45'),
(8, 'admin@lgu.gov.ph', '127.0.0.1', 1, '2025-12-08 06:58:11'),
(9, 'follero.luismiguel.noora@gmail.com', '127.0.0.1', 1, '2025-12-08 07:04:43'),
(10, 'admin@lgu.gov.ph', '127.0.0.1', 0, '2025-12-08 07:05:02'),
(11, 'admin@lgu.gov.ph', '127.0.0.1', 1, '2025-12-08 07:05:06'),
(12, 'admin@lgu.gov.ph', '127.0.0.1', 0, '2025-12-08 08:40:49'),
(13, 'admin@lgu.gov.ph', '127.0.0.1', 1, '2025-12-08 08:40:56'),
(14, 'admin@lgu.gov.ph', '127.0.0.1', 0, '2025-12-08 09:52:01'),
(15, 'admin@lgu.gov.ph', '127.0.0.1', 1, '2025-12-08 09:52:05'),
(16, 'admin@lgu.gov.ph', '127.0.0.1', 1, '2025-12-08 13:14:16'),
(17, 'follero.luismiguel.noora@gmail.com', '127.0.0.1', 1, '2025-12-08 13:15:10'),
(18, 'folleroluismiguel@gmail.com', '127.0.0.1', 1, '2025-12-08 13:37:43'),
(19, 'follero.luismiguel.noora@gmail.com', '127.0.0.1', 0, '2025-12-09 04:43:03'),
(20, 'follero.luismiguel.noora@gmail.com', '127.0.0.1', 0, '2025-12-09 04:43:07'),
(21, 'follero.luismiguel.noora@gmail.com', '127.0.0.1', 1, '2025-12-09 04:43:23'),
(22, 'folleroluismiguel@gmail.com', '127.0.0.1', 1, '2025-12-09 06:10:25'),
(23, 'folleroluismiguel@gmail.com', '127.0.0.1', 0, '2025-12-09 06:15:17'),
(24, 'folleroluismiguel@gmail.com', '127.0.0.1', 1, '2025-12-09 06:24:57'),
(25, 'morpquasar@gmail.com', '127.0.0.1', 0, '2025-12-09 06:31:03'),
(26, 'morpquasar@gmail.com', '127.0.0.1', 0, '2025-12-09 06:31:11'),
(27, 'folleroluismiguel@gmail.com', '127.0.0.1', 1, '2025-12-09 06:34:43'),
(28, 'folleroluismiguel@gmail.com', '127.0.0.1', 1, '2025-12-09 12:26:27'),
(29, 'folleroluismiguel@gmail.com', '127.0.0.1', 1, '2025-12-10 07:19:02'),
(30, 'folleroluismiguel@gmail.com', '127.0.0.1', 1, '2025-12-10 09:51:39'),
(31, 'folleroluismiguel@gmail.com', '127.0.0.1', 1, '2025-12-10 10:46:24'),
(32, 'folleroluismiguel@gmail.com', '127.0.0.1', 1, '2025-12-10 10:48:48'),
(33, 'folleroluismiguel@gmail.com', '127.0.0.1', 1, '2025-12-10 11:33:16'),
(35, 'folleroluismiguel@gmail.com', '127.0.0.1', 1, '2025-12-13 12:22:39'),
(36, 'folleroluismiguel@gmail.com', '127.0.0.1', 0, '2025-12-13 14:10:23'),
(37, 'folleroluismiguel@gmail.com', '127.0.0.1', 1, '2025-12-13 14:10:27'),
(38, 'folleroluismiguel@gmail.com', '127.0.0.1', 0, '2025-12-13 15:42:10'),
(39, 'folleroluismiguel@gmail.com', '127.0.0.1', 1, '2025-12-13 15:42:14'),
(40, 'folleroluismiguel@gmail.com', '127.0.0.1', 0, '2025-12-18 03:56:34'),
(41, 'folleroluismiguel@gmail.com', '127.0.0.1', 1, '2025-12-18 03:56:39'),
(42, 'folleroluismiguel@gmail.com', '127.0.0.1', 1, '2025-12-18 04:13:46'),
(43, 'folleroluismiguel@gmail.com', '127.0.0.1', 1, '2025-12-18 04:14:48'),
(44, 'folleroluismiguel@gmail.com', '127.0.0.1', 1, '2025-12-18 07:40:08'),
(45, 'folleroluismiguel@gmail.com', '127.0.0.1', 1, '2025-12-18 11:41:02'),
(46, 'folleroluismiguel@gmail.com', '127.0.0.1', 1, '2025-12-18 14:11:22'),
(47, 'folleroluismiguel@gmail.com', '127.0.0.1', 1, '2025-12-28 05:05:41'),
(48, 'folleroluismiguel@gmail.com', '127.0.0.1', 1, '2025-12-28 05:06:50'),
(49, 'morpquasar@gmail.com', '127.0.0.1', 1, '2025-12-28 05:10:36'),
(50, 'folleroluismiguel@gmail.com', '127.0.0.1', 1, '2025-12-28 05:14:52'),
(51, 'folleroluismiguel@gmail.com', '127.0.0.1', 1, '2025-12-28 05:41:47');

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'NULL = system-wide notification',
  `type` enum('booking','system','reminder') NOT NULL DEFAULT 'system',
  `title` varchar(150) NOT NULL,
  `message` text NOT NULL,
  `link` varchar(255) DEFAULT NULL COMMENT 'Optional link to related page',
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`id`, `user_id`, `type`, `title`, `message`, `link`, `is_read`, `created_at`) VALUES
(1, 1, 'booking', 'New Reservation Request', 'A new reservation request has been submitted for Community Convention Hall on December 4, 2025 (Morning (8AM - 12PM)).', '/resources/views/pages/dashboard/reservations_manage.php', 1, '2025-12-03 13:26:25'),
(2, 2, 'booking', 'Reservation Submitted', 'Your reservation request for Community Convention Hall has been submitted and is pending review.', '/resources/views/pages/dashboard/my_reservations.php', 1, '2025-12-03 13:26:25'),
(3, 1, 'booking', 'New Reservation Request', 'A new reservation request has been submitted for Covered Court on December 11, 2025 (Morning (8AM - 12PM)).', '/resources/views/pages/dashboard/reservations_manage.php', 0, '2025-12-05 08:02:45'),
(4, 2, 'booking', 'Reservation Submitted', 'Your reservation request for Covered Court has been submitted and is pending review.', '/resources/views/pages/dashboard/my_reservations.php', 1, '2025-12-05 08:02:45'),
(5, 2, 'booking', 'Reservation Automatically Denied', 'Your reservation request for Community Convention Hall on December 4, 2025 (Morning (8AM - 12PM)) has been automatically denied because the reservation time has passed without approval.', '/resources/views/pages/dashboard/my_reservations.php', 0, '2025-12-05 09:08:39'),
(6, 2, 'booking', 'Reservation Approved', 'Your reservation request for Covered Court on December 11, 2025 (Morning (8AM - 12PM)) has been approved. Note: Sige na nga UwU', '/resources/views/pages/dashboard/my_reservations.php', 0, '2025-12-05 09:09:31'),
(7, 1, 'booking', 'New Reservation Request', 'A new reservation request has been submitted for Covered Court on December 14, 2025 (Morning (8AM - 12PM)).', '/resources/views/pages/dashboard/reservations_manage.php', 0, '2025-12-07 07:44:05'),
(8, 1, 'booking', 'Reservation Submitted', 'Your reservation request for Covered Court has been submitted and is pending review.', '/resources/views/pages/dashboard/my_reservations.php', 1, '2025-12-07 07:44:06'),
(9, 1, 'booking', 'Reservation Approved', 'Your reservation request for Bernardo Court on December 14, 2025 (Morning (8AM - 12PM)) has been approved.', '/resources/views/pages/dashboard/my_reservations.php', 0, '2025-12-09 06:42:42'),
(10, 1, 'booking', 'Reservation Postponed', 'Your approved reservation for Bernardo Court has been postponed from December 14, 2025 (Morning (8AM - 12PM)) to December 17, 2025 (Morning (8:00 AM - 12:00 PM)). The new date requires re-approval. Reason: Will be used as evacuation center', '/resources/views/pages/dashboard/my_reservations.php', 0, '2025-12-13 14:11:44'),
(11, 1, 'booking', 'Reservation Approved', 'Your reservation request for Bernardo Court on December 17, 2025 (Morning (8:00 AM - 12:00 PM)) has been approved. Note: Sge na nga hehehe', '/resources/views/pages/dashboard/my_reservations.php', 0, '2025-12-13 14:35:23'),
(12, 1, 'booking', 'Reservation Approved', 'Your reservation request for Cassanova Multipurpose Building on December 18, 2025 (08:00 - 10:30) has been automatically approved.', '/resources/views/pages/dashboard/my_reservations.php', 0, '2025-12-18 11:40:38'),
(13, 1, 'booking', 'New Reservation Request', 'A new reservation request has been submitted for Culiat Highschool on December 31, 2025 (08:00 - 20:00).', '/resources/views/pages/dashboard/reservations_manage.php', 0, '2025-12-28 05:14:34'),
(14, 2, 'booking', 'New Reservation Request', 'A new reservation request has been submitted for Culiat Highschool on December 31, 2025 (08:00 - 20:00).', '/resources/views/pages/dashboard/reservations_manage.php', 0, '2025-12-28 05:14:34'),
(15, 4, 'booking', 'Reservation Submitted', 'Your reservation request for Culiat Highschool has been submitted and is pending review.', '/resources/views/pages/dashboard/my_reservations.php', 0, '2025-12-28 05:14:34');

-- --------------------------------------------------------

--
-- Table structure for table `password_reset_tokens`
--

CREATE TABLE `password_reset_tokens` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `token_hash` varchar(255) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `password_reset_tokens`
--

INSERT INTO `password_reset_tokens` (`id`, `user_id`, `token_hash`, `expires_at`, `used_at`, `created_at`) VALUES
(1, 1, '8413a6635c2000de82ea71155c8d4917d27b6805eef1273a30d224441a4018b8', '2025-12-10 13:33:44', NULL, '2025-12-10 11:33:44');

-- --------------------------------------------------------

--
-- Table structure for table `rate_limits`
--

CREATE TABLE `rate_limits` (
  `id` int(10) UNSIGNED NOT NULL,
  `action` varchar(50) NOT NULL,
  `identifier` varchar(255) NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `rate_limits`
--

INSERT INTO `rate_limits` (`id`, `action`, `identifier`, `expires_at`, `created_at`) VALUES
(61, 'login', 'folleroluismiguel@gmail.com', '2025-12-28 13:56:47', '2025-12-28 05:41:47');

-- --------------------------------------------------------

--
-- Table structure for table `reservations`
--

CREATE TABLE `reservations` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `facility_id` int(10) UNSIGNED NOT NULL,
  `reservation_date` date NOT NULL,
  `time_slot` varchar(50) NOT NULL,
  `purpose` text NOT NULL,
  `status` enum('pending','approved','denied','cancelled') NOT NULL DEFAULT 'pending',
  `reschedule_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `expected_attendees` int(10) UNSIGNED DEFAULT NULL COMMENT 'Expected number of attendees',
  `is_commercial` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Whether the reservation is for commercial purposes',
  `auto_approved` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Whether this reservation was auto-approved by the system'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `reservations`
--

INSERT INTO `reservations` (`id`, `user_id`, `facility_id`, `reservation_date`, `time_slot`, `purpose`, `status`, `reschedule_count`, `created_at`, `updated_at`, `expected_attendees`, `is_commercial`, `auto_approved`) VALUES
(1, 1, 1, '2025-11-28', 'Morning (8AM - 12PM)', 'Feeding Program', 'denied', 0, '2025-11-26 18:00:54', '2025-11-28 14:19:51', NULL, 0, 0),
(2, 1, 4, '2025-10-27', 'Afternoon (1PM - 5PM)', 'gg', 'denied', 0, '2025-11-27 05:18:47', '2025-11-28 14:19:53', NULL, 0, 0),
(3, 1, 1, '2025-11-29', 'Morning (8AM - 12PM)', 'Barangay General Assembly', 'approved', 0, '2025-11-28 16:18:58', '2025-11-28 16:19:59', NULL, 0, 0),
(4, 2, 1, '2025-12-04', 'Morning (8AM - 12PM)', 'Feeding Program', 'denied', 0, '2025-12-03 13:26:25', '2025-12-05 09:08:39', NULL, 0, 0),
(5, 2, 4, '2025-12-11', 'Morning (8AM - 12PM)', 'Zumba', 'approved', 0, '2025-12-05 08:02:45', '2025-12-05 09:09:31', NULL, 0, 0),
(6, 1, 4, '2025-12-17', 'Morning (8:00 AM - 12:00 PM)', 'Zumba', 'approved', 0, '2025-12-07 07:44:05', '2025-12-13 14:35:23', NULL, 0, 0),
(7, 1, 5, '2025-12-18', '08:00 - 10:30', 'Zumba', 'approved', 0, '2025-12-18 11:40:38', '2025-12-18 11:40:38', 10, 0, 1),
(8, 4, 1, '2025-12-31', '08:00 - 20:00', 'Zumba', 'pending', 0, '2025-12-28 05:14:34', '2025-12-28 05:14:34', 50, 0, 0);

-- --------------------------------------------------------

--
-- Table structure for table `reservation_history`
--

CREATE TABLE `reservation_history` (
  `id` int(10) UNSIGNED NOT NULL,
  `reservation_id` int(10) UNSIGNED NOT NULL,
  `status` enum('pending','approved','denied','cancelled') NOT NULL,
  `note` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_by` int(10) UNSIGNED DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `reservation_history`
--

INSERT INTO `reservation_history` (`id`, `reservation_id`, `status`, `note`, `created_at`, `created_by`) VALUES
(1, 3, 'approved', 'Sige nga UwU', '2025-11-28 16:19:59', 1),
(2, 4, 'denied', 'Automatically denied: Reservation time has passed without approval.', '2025-12-05 09:08:39', NULL),
(3, 5, 'approved', 'Sige na nga UwU', '2025-12-05 09:09:31', 1),
(4, 6, 'approved', '', '2025-12-09 06:42:42', 1),
(5, 6, 'pending', 'Postponed from 2025-12-14 Morning (8AM - 12PM) to 2025-12-17 Morning (8:00 AM - 12:00 PM). Reason: Will be used as evacuation center', '2025-12-13 14:11:44', 1),
(6, 6, 'approved', 'Sge na nga hehehe', '2025-12-13 14:35:23', 1),
(7, 7, 'approved', 'Automatically approved by system - all conditions met', '2025-12-18 11:40:38', NULL),
(8, 8, 'pending', 'Pending manual review by staff', '2025-12-28 05:14:34', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `security_logs`
--

CREATE TABLE `security_logs` (
  `id` int(10) UNSIGNED NOT NULL,
  `event` varchar(100) NOT NULL,
  `details` text DEFAULT NULL,
  `severity` enum('info','warning','error','critical') NOT NULL DEFAULT 'info',
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `user_id` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `security_logs`
--

INSERT INTO `security_logs` (`id`, `event`, `details`, `severity`, `ip_address`, `user_agent`, `user_id`, `created_at`) VALUES
(1, 'login_failed', 'Failed login attempt: follero.luismiguel.noora@gmail.com', 'warning', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', NULL, '2025-12-08 06:05:15'),
(2, 'login_failed', 'Failed login attempt: follero.luismiguel.noora@gmail.com', 'warning', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', NULL, '2025-12-08 06:05:17'),
(3, 'login_failed', 'Failed login attempt: follero.luismiguel.noora@gmail.com', 'warning', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', NULL, '2025-12-08 06:05:19'),
(4, 'login_failed', 'Failed login attempt: follero.luismiguel.noora@gmail.com', 'warning', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', NULL, '2025-12-08 06:05:21'),
(5, 'account_locked', 'Account locked due to failed attempts: follero.luismiguel.noora@gmail.com', 'warning', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', NULL, '2025-12-08 06:05:22'),
(6, 'login_failed', 'Failed login attempt: follero.luismiguel.noora@gmail.com', 'warning', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', NULL, '2025-12-08 06:05:22'),
(7, 'login_success', 'User logged in: admin@lgu.gov.ph', 'info', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', NULL, '2025-12-08 06:23:58'),
(8, 'login_success', 'User logged in: follero.luismiguel.noora@gmail.com', 'info', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', NULL, '2025-12-08 06:38:45'),
(9, 'login_success', 'User logged in: admin@lgu.gov.ph', 'info', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', NULL, '2025-12-08 06:58:11'),
(10, 'login_success', 'User logged in: follero.luismiguel.noora@gmail.com', 'info', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', NULL, '2025-12-08 07:04:43'),
(11, 'login_failed', 'Failed login attempt: admin@lgu.gov.ph', 'warning', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', NULL, '2025-12-08 07:05:02'),
(12, 'login_success', 'User logged in: admin@lgu.gov.ph', 'info', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', NULL, '2025-12-08 07:05:06'),
(13, 'login_failed', 'Failed login attempt: admin@lgu.gov.ph', 'warning', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', NULL, '2025-12-08 08:40:49'),
(14, 'login_success', 'User logged in: admin@lgu.gov.ph', 'info', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', NULL, '2025-12-08 08:40:56'),
(15, 'login_failed', 'Failed login attempt: admin@lgu.gov.ph', 'warning', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', NULL, '2025-12-08 09:52:01'),
(16, 'login_success', 'User logged in: admin@lgu.gov.ph', 'info', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', NULL, '2025-12-08 09:52:05'),
(17, 'registration_success', 'New user registered: morpquasar@gmail.com', 'info', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', NULL, '2025-12-08 13:14:01'),
(18, 'login_attempt_invalid_email', 'Login attempt with non-existent email: admin@lgu.gov.ph', 'info', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', NULL, '2025-12-09 04:41:34'),
(19, 'login_attempt_invalid_email', 'Login attempt with non-existent email: admin@lgu.gov.ph', 'info', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', NULL, '2025-12-09 04:41:50'),
(20, 'login_attempt_invalid_email', 'Login attempt with non-existent email: admin@lgu.gov.ph', 'info', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', NULL, '2025-12-09 04:41:56'),
(21, 'login_attempt_invalid_email', 'Login attempt with non-existent email: admin@lgu.gov.ph', 'info', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', NULL, '2025-12-09 04:42:48'),
(22, 'login_failed', 'Failed login attempt: follero.luismiguel.noora@gmail.com', 'warning', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', NULL, '2025-12-09 04:43:03'),
(23, 'login_failed', 'Failed login attempt: follero.luismiguel.noora@gmail.com', 'warning', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', NULL, '2025-12-09 04:43:07'),
(24, 'login_failed', 'Failed login attempt: folleroluismiguel@gmail.com', 'warning', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', NULL, '2025-12-09 06:15:17'),
(25, 'login_attempt_invalid_email', 'Login attempt with non-existent email: admin@lgu.gov.ph', 'info', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', NULL, '2025-12-09 06:21:39'),
(26, 'login_attempt_invalid_email', 'Login attempt with non-existent email: admin@lgu.gov.ph', 'info', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', NULL, '2025-12-09 06:22:40'),
(27, 'login_attempt_invalid_email', 'Login attempt with non-existent email: admin@lgu.gov.ph', 'info', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', NULL, '2025-12-09 06:24:04'),
(28, 'login_failed', 'Failed login attempt: morpquasar@gmail.com', 'warning', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', NULL, '2025-12-09 06:31:03'),
(29, 'login_failed', 'Failed login attempt: morpquasar@gmail.com', 'warning', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', NULL, '2025-12-09 06:31:11'),
(30, 'login_attempt_invalid_email', 'Login attempt with non-existent email: umorpquasar@gmail.com', 'info', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', NULL, '2025-12-09 06:31:24'),
(31, 'login_attempt_invalid_email', 'Login attempt with non-existent email: umorpquasar@gmail.com', 'info', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', NULL, '2025-12-09 06:33:50'),
(32, 'login_failed', 'Failed login attempt: folleroluismiguel@gmail.com', 'warning', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', NULL, '2025-12-13 14:10:23'),
(33, 'login_failed', 'Failed login attempt: folleroluismiguel@gmail.com', 'warning', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', NULL, '2025-12-13 15:42:10'),
(34, 'login_failed', 'Failed login attempt: folleroluismiguel@gmail.com', 'warning', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', NULL, '2025-12-18 03:56:34'),
(35, 'document_not_found', 'Document #5 file not found on filesystem', 'error', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', NULL, '2025-12-28 05:42:26');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(150) NOT NULL,
  `email` varchar(190) NOT NULL,
  `mobile` varchar(20) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `latitude` decimal(10,8) DEFAULT NULL,
  `longitude` decimal(11,8) DEFAULT NULL,
  `profile_picture` varchar(255) DEFAULT NULL COMMENT 'Path to user profile picture',
  `password_hash` varchar(255) NOT NULL,
  `role` enum('Admin','Staff','Resident') NOT NULL DEFAULT 'Resident',
  `status` enum('pending','active','locked') NOT NULL DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `failed_login_attempts` int(10) UNSIGNED DEFAULT 0,
  `locked_until` datetime DEFAULT NULL,
  `lock_reason` text DEFAULT NULL,
  `otp_code_hash` varchar(255) DEFAULT NULL,
  `otp_expires_at` datetime DEFAULT NULL,
  `otp_attempts` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `otp_last_sent_at` datetime DEFAULT NULL,
  `last_login_at` datetime DEFAULT NULL,
  `last_login_ip` varchar(45) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `name`, `email`, `mobile`, `address`, `latitude`, `longitude`, `profile_picture`, `password_hash`, `role`, `status`, `created_at`, `updated_at`, `failed_login_attempts`, `locked_until`, `lock_reason`, `otp_code_hash`, `otp_expires_at`, `otp_attempts`, `otp_last_sent_at`, `last_login_at`, `last_login_ip`) VALUES
(1, 'Admin - Luis', 'folleroluismiguel@gmail.com', '+63 911 044 599', 'Sanville Subdivision, Barangay Culiat, Quezon City, Metro Manila, Philippines', 14.66803390, 121.05666160, '/public/uploads/profile_pictures/profile-1-1765168936.jpg', '$2y$10$GnEYiWc66L.fbOl9cmqAReq06nr2YMi7gVDW51cvLCfm6buDBmh0a', 'Admin', 'active', '2025-11-26 17:47:57', '2025-12-28 05:42:01', 0, NULL, NULL, NULL, NULL, 0, '2025-12-28 13:41:47', '2025-12-08 17:52:05', '127.0.0.1'),
(2, 'Luis Miguel Follero', 'follero.luismiguel.noora@gmail.com', '+63 911 044 599', 'Barangay Culiat, Quezon City, Philippines', 14.66803390, 121.05666160, '/public/uploads/profile_pictures/profile-2-1765176479.jpg', '$2y$10$6X3RGsOxpeNxykH8p14lp.0EC5S6Ns40siFDddfdG/iNBRxaj74he', 'Admin', 'active', '2025-12-03 13:05:03', '2025-12-09 04:43:23', 0, NULL, NULL, '$2y$10$otWmSQy9b2yGlrdc9HHFOe6P9L3YRDbKiSlrYlsy64Bx05yLcpsxa', '2025-12-09 05:53:23', 0, '2025-12-09 12:43:23', '2025-12-08 15:04:43', '127.0.0.1'),
(3, 'Admin User', 'user@lgu.gov.ph', '+63 911 044 599', NULL, NULL, NULL, '/public/uploads/profile_pictures/profile-3-1765119634.png', '$2y$10$m1Uq/Qoo.tnz/duMTnee/OHDenRW6BrWG3FNV52VT1h6miARInPjC', 'Resident', 'active', '2025-12-07 14:21:05', '2025-12-07 15:00:34', 0, NULL, NULL, NULL, NULL, 0, NULL, NULL, NULL),
(4, 'Marites Gonzales', 'morpquasar@gmail.com', '+63911044599', 'Barangay Culiat, Quezon City', 14.66803390, 121.05666160, NULL, '$2y$10$1iHCKoGSstERsQKVaVgkROBrh5B4JCrkc96npjxDHYTHwnRDE6N2O', 'Resident', 'active', '2025-12-08 13:14:01', '2025-12-28 05:12:15', 0, NULL, NULL, NULL, NULL, 0, '2025-12-28 13:10:36', NULL, '127.0.0.1');

-- --------------------------------------------------------

--
-- Table structure for table `user_documents`
--

CREATE TABLE `user_documents` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `document_type` enum('birth_certificate','valid_id','brgy_id','resident_id','other') NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_size` int(10) UNSIGNED NOT NULL COMMENT 'File size in bytes',
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `archived_at` datetime DEFAULT NULL COMMENT 'When document was archived',
  `archived_by` int(10) UNSIGNED DEFAULT NULL COMMENT 'Admin/System who archived',
  `archive_path` varchar(255) DEFAULT NULL COMMENT 'Path to archived file (relative to archive storage root)',
  `is_archived` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Whether document is archived'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `user_documents`
--

INSERT INTO `user_documents` (`id`, `user_id`, `document_type`, `file_path`, `file_name`, `file_size`, `uploaded_at`, `archived_at`, `archived_by`, `archive_path`, `is_archived`) VALUES
(1, 3, 'birth_certificate', 'storage/private/documents/3/admin-user-birth_certificate-1765117265.jpg', 'amphitheater.jpg', 319133, '2025-12-07 14:21:05', NULL, NULL, NULL, 0),
(2, 3, 'valid_id', 'storage/private/documents/3/admin-user-valid_id-1765117265.png', 'BernardoCourt.png', 3355577, '2025-12-07 14:21:05', NULL, NULL, NULL, 0),
(3, 3, 'brgy_id', 'storage/private/documents/3/admin-user-brgy_id-1765117265.png', 'CebuPael-MPB.png', 3673124, '2025-12-07 14:21:05', NULL, NULL, NULL, 0),
(4, 3, 'other', 'storage/private/documents/3/admin-user-other_document-1765117265.jpg', 'sports-complex.jpg', 295352, '2025-12-07 14:21:05', NULL, NULL, NULL, 0),
(5, 4, 'birth_certificate', '/public/uploads/documents/logocityhall_1765199641.png', 'logocityhall_1765199641.png', 205109, '2025-12-08 13:14:01', NULL, NULL, NULL, 0);

-- --------------------------------------------------------

--
-- Table structure for table `user_violations`
--

CREATE TABLE `user_violations` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `reservation_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'Related reservation if applicable',
  `violation_type` enum('no_show','late_cancellation','policy_violation','damage','other') NOT NULL,
  `description` text DEFAULT NULL,
  `severity` enum('low','medium','high','critical') NOT NULL DEFAULT 'medium',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_by` int(10) UNSIGNED DEFAULT NULL COMMENT 'Admin/Staff who recorded the violation'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `audit_log`
--
ALTER TABLE `audit_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_audit_module` (`module`),
  ADD KEY `idx_audit_created` (`created_at`),
  ADD KEY `idx_audit_user` (`user_id`),
  ADD KEY `idx_audit_log_created_module` (`created_at`,`module`);

--
-- Indexes for table `contact_inquiries`
--
ALTER TABLE `contact_inquiries`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_inquiry_admin` (`responded_by`),
  ADD KEY `idx_inquiry_status` (`status`),
  ADD KEY `idx_inquiry_created` (`created_at`);

--
-- Indexes for table `data_exports`
--
ALTER TABLE `data_exports`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_export_creator` (`created_by`),
  ADD KEY `idx_data_exports_user` (`user_id`),
  ADD KEY `idx_data_exports_expires` (`expires_at`);

--
-- Indexes for table `document_access_log`
--
ALTER TABLE `document_access_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_doc_access_document` (`document_id`),
  ADD KEY `idx_doc_access_user` (`user_id`),
  ADD KEY `idx_doc_access_accessed_by` (`accessed_by`),
  ADD KEY `idx_doc_access_time` (`accessed_at`);

--
-- Indexes for table `document_retention_policy`
--
ALTER TABLE `document_retention_policy`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_document_type` (`document_type`);

--
-- Indexes for table `facilities`
--
ALTER TABLE `facilities`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_facilities_coordinates` (`latitude`,`longitude`),
  ADD KEY `idx_facilities_status_location` (`status`,`latitude`,`longitude`),
  ADD KEY `idx_facilities_status` (`status`),
  ADD KEY `idx_facilities_created` (`created_at`),
  ADD KEY `idx_facilities_auto_approve` (`auto_approve`,`status`);

--
-- Indexes for table `facility_blackout_dates`
--
ALTER TABLE `facility_blackout_dates`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_facility_date` (`facility_id`,`blackout_date`),
  ADD KEY `fk_blackout_user` (`created_by`),
  ADD KEY `idx_blackout_facility_date` (`facility_id`,`blackout_date`),
  ADD KEY `idx_blackout_dates_date_range` (`blackout_date`,`facility_id`);

--
-- Indexes for table `login_attempts`
--
ALTER TABLE `login_attempts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_login_attempts` (`email`,`attempted_at`),
  ADD KEY `idx_login_ip` (`ip_address`,`attempted_at`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_notif_user` (`user_id`),
  ADD KEY `idx_notif_read` (`is_read`),
  ADD KEY `idx_notif_created` (`created_at`),
  ADD KEY `idx_notifications_type_read` (`type`,`is_read`);

--
-- Indexes for table `password_reset_tokens`
--
ALTER TABLE `password_reset_tokens`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_reset_token` (`token_hash`),
  ADD KEY `idx_reset_user` (`user_id`),
  ADD KEY `idx_reset_expires` (`expires_at`);

--
-- Indexes for table `rate_limits`
--
ALTER TABLE `rate_limits`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_rate_limit` (`action`,`identifier`,`expires_at`),
  ADD KEY `idx_rate_limits_expires` (`expires_at`);

--
-- Indexes for table `reservations`
--
ALTER TABLE `reservations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_res_user` (`user_id`),
  ADD KEY `fk_res_facility` (`facility_id`),
  ADD KEY `idx_reservations_status_date` (`status`,`reservation_date`),
  ADD KEY `idx_reservations_facility_date` (`facility_id`,`reservation_date`),
  ADD KEY `idx_reservations_user` (`user_id`),
  ADD KEY `idx_res_reschedule_count` (`reschedule_count`),
  ADD KEY `idx_reservations_date_status` (`reservation_date`,`status`),
  ADD KEY `idx_reservations_user_status` (`user_id`,`status`),
  ADD KEY `idx_reservations_auto_approved` (`auto_approved`,`status`),
  ADD KEY `idx_reservations_composite` (`status`,`reservation_date`,`facility_id`);

--
-- Indexes for table `reservation_history`
--
ALTER TABLE `reservation_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_hist_reservation` (`reservation_id`),
  ADD KEY `fk_hist_user` (`created_by`),
  ADD KEY `idx_reservation_history_reservation` (`reservation_id`),
  ADD KEY `idx_reservation_history_created` (`created_at`);

--
-- Indexes for table `security_logs`
--
ALTER TABLE `security_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_security_logs` (`event`,`severity`,`created_at`),
  ADD KEY `idx_security_user` (`user_id`),
  ADD KEY `idx_security_logs_created` (`created_at`),
  ADD KEY `idx_security_logs_created_severity` (`created_at`,`severity`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `idx_users_coordinates` (`latitude`,`longitude`),
  ADD KEY `idx_users_status` (`status`),
  ADD KEY `idx_users_role` (`role`),
  ADD KEY `idx_users_locked_until` (`locked_until`),
  ADD KEY `idx_users_otp_expires` (`otp_expires_at`);

--
-- Indexes for table `user_documents`
--
ALTER TABLE `user_documents`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_documents` (`user_id`),
  ADD KEY `idx_user_documents_type` (`document_type`),
  ADD KEY `idx_user_documents_archived` (`is_archived`,`archived_at`),
  ADD KEY `idx_user_documents_user_archived` (`user_id`,`is_archived`);

--
-- Indexes for table `user_violations`
--
ALTER TABLE `user_violations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_violation_reservation` (`reservation_id`),
  ADD KEY `fk_violation_creator` (`created_by`),
  ADD KEY `idx_violations_user` (`user_id`),
  ADD KEY `idx_violations_created` (`created_at`),
  ADD KEY `idx_violations_auto_approval_check` (`user_id`,`severity`,`created_at`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `audit_log`
--
ALTER TABLE `audit_log`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=41;

--
-- AUTO_INCREMENT for table `contact_inquiries`
--
ALTER TABLE `contact_inquiries`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `data_exports`
--
ALTER TABLE `data_exports`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `document_access_log`
--
ALTER TABLE `document_access_log`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `document_retention_policy`
--
ALTER TABLE `document_retention_policy`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `facilities`
--
ALTER TABLE `facilities`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `facility_blackout_dates`
--
ALTER TABLE `facility_blackout_dates`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `login_attempts`
--
ALTER TABLE `login_attempts`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=52;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `password_reset_tokens`
--
ALTER TABLE `password_reset_tokens`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `rate_limits`
--
ALTER TABLE `rate_limits`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=62;

--
-- AUTO_INCREMENT for table `reservations`
--
ALTER TABLE `reservations`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `reservation_history`
--
ALTER TABLE `reservation_history`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `security_logs`
--
ALTER TABLE `security_logs`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=36;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `user_documents`
--
ALTER TABLE `user_documents`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `user_violations`
--
ALTER TABLE `user_violations`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `audit_log`
--
ALTER TABLE `audit_log`
  ADD CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `contact_inquiries`
--
ALTER TABLE `contact_inquiries`
  ADD CONSTRAINT `fk_inquiry_admin` FOREIGN KEY (`responded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `data_exports`
--
ALTER TABLE `data_exports`
  ADD CONSTRAINT `fk_export_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_export_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `document_access_log`
--
ALTER TABLE `document_access_log`
  ADD CONSTRAINT `fk_doc_access_accessed_by` FOREIGN KEY (`accessed_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_doc_access_document` FOREIGN KEY (`document_id`) REFERENCES `user_documents` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_doc_access_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `facility_blackout_dates`
--
ALTER TABLE `facility_blackout_dates`
  ADD CONSTRAINT `fk_blackout_facility` FOREIGN KEY (`facility_id`) REFERENCES `facilities` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_blackout_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `fk_notif_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `password_reset_tokens`
--
ALTER TABLE `password_reset_tokens`
  ADD CONSTRAINT `fk_reset_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `reservations`
--
ALTER TABLE `reservations`
  ADD CONSTRAINT `fk_res_facility` FOREIGN KEY (`facility_id`) REFERENCES `facilities` (`id`),
  ADD CONSTRAINT `fk_res_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `reservation_history`
--
ALTER TABLE `reservation_history`
  ADD CONSTRAINT `fk_hist_reservation` FOREIGN KEY (`reservation_id`) REFERENCES `reservations` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_hist_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `security_logs`
--
ALTER TABLE `security_logs`
  ADD CONSTRAINT `fk_security_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `user_documents`
--
ALTER TABLE `user_documents`
  ADD CONSTRAINT `fk_doc_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `user_violations`
--
ALTER TABLE `user_violations`
  ADD CONSTRAINT `fk_violation_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_violation_reservation` FOREIGN KEY (`reservation_id`) REFERENCES `reservations` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_violation_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
