-- FixMyRoad (Kansanga, Makindye Division) - MySQL Database Schema & Seed Data
-- Compatible with XAMPP (MySQL / MariaDB 10.4+) via phpMyAdmin Import

CREATE DATABASE IF NOT EXISTS `fixmyroad_db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `fixmyroad_db`;

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `report_upvotes`;
DROP TABLE IF EXISTS `report_comments`;
DROP TABLE IF EXISTS `report_updates`;
DROP TABLE IF EXISTS `reports`;
DROP TABLE IF EXISTS `official_notices`;
DROP TABLE IF EXISTS `maintenance_tasks`;
DROP TABLE IF EXISTS `audit_logs`;
DROP TABLE IF EXISTS `notifications`;
DROP TABLE IF EXISTS `officers`;
DROP TABLE IF EXISTS `settings`;
DROP TABLE IF EXISTS `users`;

SET FOREIGN_KEY_CHECKS = 1;

-- 1. Users (Citizens, Field Officers, Administrators)
CREATE TABLE `users` (
  `id` VARCHAR(40) NOT NULL PRIMARY KEY,
  `name` VARCHAR(120) NOT NULL,
  `email` VARCHAR(160) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `phone` VARCHAR(40) DEFAULT '',
  `role` ENUM('Community Member', 'Field Officer', 'Supervisor', 'Page Moderator', 'Administrator') NOT NULL DEFAULT 'Community Member',
  `division` VARCHAR(120) DEFAULT 'Kansanga (Makindye)',
  `street_address` VARCHAR(200) DEFAULT '',
  `avatar` VARCHAR(500) DEFAULT '',
  `account_status` ENUM('active', 'blocked') NOT NULL DEFAULT 'active',
  `blocked_reason` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Field Officers & Engineering Crews
CREATE TABLE `officers` (
  `id` VARCHAR(40) NOT NULL PRIMARY KEY,
  `name` VARCHAR(120) NOT NULL,
  `division` VARCHAR(120) NOT NULL DEFAULT 'Kansanga (Makindye)',
  `specialty` VARCHAR(160) NOT NULL DEFAULT 'Road & Drainage Maintenance',
  `max_capacity` INT NOT NULL DEFAULT 5,
  `status` ENUM('Available', 'Field Deployed', 'At Capacity') NOT NULL DEFAULT 'Available',
  `avatar` VARCHAR(500) DEFAULT '',
  `avg_resolution_hours` INT NOT NULL DEFAULT 20
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Road & Drainage Fault Reports
CREATE TABLE `reports` (
  `id` VARCHAR(32) NOT NULL PRIMARY KEY,
  `title` VARCHAR(180) NOT NULL,
  `category` ENUM('Pothole', 'Blocked Drainage', 'Flooding', 'Damaged Road', 'Broken Streetlight', 'Other') NOT NULL DEFAULT 'Pothole',
  `description` TEXT NOT NULL,
  `location` VARCHAR(220) NOT NULL,
  `area` VARCHAR(120) NOT NULL DEFAULT 'Kansanga (Makindye)',
  `lat` DECIMAL(10, 6) NOT NULL DEFAULT 0.283500,
  `lng` DECIMAL(10, 6) NOT NULL DEFAULT 32.609500,
  `priority` ENUM('Urgent', 'High', 'Medium', 'Low') NOT NULL DEFAULT 'Medium',
  `status` ENUM('Pending', 'In Progress', 'Resolved', 'Rejected') NOT NULL DEFAULT 'Pending',
  `reported_by` VARCHAR(120) NOT NULL,
  `reported_by_email` VARCHAR(160) NOT NULL,
  `report_date` VARCHAR(32) NOT NULL,
  `sla_deadline` VARCHAR(32) NOT NULL,
  `escalated` TINYINT(1) NOT NULL DEFAULT 0,
  `escalation_reason` VARCHAR(255) DEFAULT NULL,
  `assigned_officer` VARCHAR(120) DEFAULT NULL,
  `upvotes` INT NOT NULL DEFAULT 1,
  `before_photo` VARCHAR(500) DEFAULT NULL,
  `after_photo` VARCHAR(500) DEFAULT NULL,
  `resolved_at` DATETIME DEFAULT NULL,
  `confirmation_state` ENUM('none', 'awaiting_confirmation', 'confirmed', 'reopened') NOT NULL DEFAULT 'none',
  `confirmation_expires_at` DATETIME DEFAULT NULL,
  `confirmed_by` VARCHAR(120) DEFAULT NULL,
  `reopen_reason` VARCHAR(500) DEFAULT NULL,
  `feedback_rating` TINYINT DEFAULT NULL,
  `feedback_comment` TEXT DEFAULT NULL,
  `feedback_author` VARCHAR(120) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Report Endorsements ("Me Too" Upvotes)
CREATE TABLE `report_upvotes` (
  `report_id` VARCHAR(32) NOT NULL,
  `user_id` VARCHAR(40) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`report_id`, `user_id`),
  CONSTRAINT `fk_upvote_report` FOREIGN KEY (`report_id`) REFERENCES `reports` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Report Timeline Progress Updates
CREATE TABLE `report_updates` (
  `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `report_id` VARCHAR(32) NOT NULL,
  `timestamp_label` VARCHAR(32) NOT NULL,
  `text` TEXT NOT NULL,
  `author` VARCHAR(120) NOT NULL,
  `update_type` ENUM('verification', 'assignment', 'work_progress', 'resolved', 'photo', 'escalation') NOT NULL DEFAULT 'work_progress',
  `photo_url` VARCHAR(500) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_update_report` FOREIGN KEY (`report_id`) REFERENCES `reports` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Public Q&A Comments per Report
CREATE TABLE `report_comments` (
  `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `report_id` VARCHAR(32) NOT NULL,
  `author_id` VARCHAR(40) NOT NULL,
  `author_name` VARCHAR(120) NOT NULL,
  `author_role` ENUM('Community Member', 'Field Officer', 'Supervisor', 'Page Moderator', 'Administrator') NOT NULL DEFAULT 'Community Member',
  `text` TEXT NOT NULL,
  `is_official_question` TINYINT(1) NOT NULL DEFAULT 0,
  `hidden` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_comment_report` FOREIGN KEY (`report_id`) REFERENCES `reports` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Official Municipal Notices (with Categories, Expiry Dates & Archive Status)
CREATE TABLE `official_notices` (
  `id` VARCHAR(32) NOT NULL PRIMARY KEY,
  `title` VARCHAR(140) NOT NULL,
  `message` TEXT NOT NULL,
  `category` ENUM('Emergency', 'Maintenance', 'General Announcement') NOT NULL DEFAULT 'General Announcement',
  `status` ENUM('active', 'archived') NOT NULL DEFAULT 'active',
  `expires_at` DATE DEFAULT NULL,
  `posted_by` VARCHAR(120) NOT NULL,
  `posted_by_id` VARCHAR(40) DEFAULT NULL,
  `archived_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. Preventive Maintenance & Scheduled Drainage Inspections
CREATE TABLE `maintenance_tasks` (
  `id` VARCHAR(32) NOT NULL PRIMARY KEY,
  `title` VARCHAR(180) NOT NULL,
  `task_type` VARCHAR(80) NOT NULL DEFAULT 'Culvert Clearing',
  `division` VARCHAR(120) NOT NULL DEFAULT 'Kansanga (Makindye)',
  `corridor` VARCHAR(200) NOT NULL,
  `frequency` ENUM('Weekly', 'Bi-Weekly', 'Monthly', 'Quarterly') NOT NULL DEFAULT 'Monthly',
  `status` ENUM('Scheduled', 'Due Soon', 'Overdue', 'In Progress', 'Completed', 'Paused') NOT NULL DEFAULT 'Scheduled',
  `assigned_officer` VARCHAR(120) NOT NULL,
  `next_due_date` DATE NOT NULL,
  `last_completed_date` DATE DEFAULT NULL,
  `estimated_hours` DECIMAL(5, 1) NOT NULL DEFAULT 4.0,
  `silt_cleared_kg` INT NOT NULL DEFAULT 0,
  `notes` TEXT DEFAULT NULL,
  `created_by` VARCHAR(120) NOT NULL DEFAULT 'Administrator',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. Notifications
CREATE TABLE `notifications` (
  `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `title` VARCHAR(160) NOT NULL,
  `message` TEXT NOT NULL,
  `notif_type` ENUM('info', 'status', 'escalation', 'resolved', 'assignment') NOT NULL DEFAULT 'info',
  `target_role` ENUM('All', 'Community Member', 'Field Officer', 'Supervisor', 'Page Moderator', 'Administrator') NOT NULL DEFAULT 'All',
  `report_id` VARCHAR(32) DEFAULT NULL,
  `is_read` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. System Audit Log
CREATE TABLE `audit_logs` (
  `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `actor_name` VARCHAR(120) NOT NULL,
  `actor_role` VARCHAR(40) NOT NULL,
  `action` VARCHAR(160) NOT NULL,
  `target_id` VARCHAR(64) DEFAULT NULL,
  `details` TEXT NOT NULL,
  `category` ENUM('report', 'assignment', 'status', 'escalation', 'feedback', 'auth', 'maintenance', 'notice') NOT NULL DEFAULT 'report',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11. Portal Settings (SLA & Hotline Support)
CREATE TABLE `settings` (
  `setting_key` VARCHAR(64) NOT NULL PRIMARY KEY,
  `setting_value` TEXT NOT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- SEED DATA (Kansanga, Makindye Division Pilot)
-- Seed accounts are disabled until an administrator sets unique passwords.
-- ---------------------------------------------------------------------------

INSERT INTO `users` (`id`, `name`, `email`, `password_hash`, `phone`, `role`, `division`, `street_address`, `avatar`, `account_status`) VALUES
('usr-1', 'Ipavu Roy', 'alaruroy@gmail.com', '!', '+256 701 234 567', 'Community Member', 'Kansanga (Makindye)', 'Plot 14, Ggaba Road, Kansanga', '', 'active'),
('usr-2', 'David Mukasa', 'dmukasa@roads.gov.ug', '!', '+256 772 987 654', 'Field Officer', 'Kiwafu / Kansanga', 'Kansanga Works Outpost, Kiwafu Road', '', 'active'),
('usr-3', 'Sarah Nsubuga', 'admin@roads.gov.ug', '!', '+256 754 112 233', 'Administrator', 'Makindye Division (Kansanga Pilot)', 'Makindye - Kansanga Municipal Works Office, Ggaba Road', '', 'active');

INSERT INTO `officers` (`id`, `name`, `division`, `specialty`, `max_capacity`, `status`, `avg_resolution_hours`) VALUES
('off-1', 'David Mukasa', 'Kansanga & Kiwafu', 'Asphalt & Road Base Lead', 6, 'Field Deployed', 19),
('off-2', 'Rapid Drainage Unit 4', 'Nabutiti & Ggaba Rd', 'Culvert Clearing & Storm Pumps', 5, 'At Capacity', 14),
('off-3', 'Grace Namono', 'Makindye & Kansanga Hill', 'Curb & Masonry Restoration', 5, 'Available', 22),
('off-4', 'Asphalt Patching Crew B', 'KIU & Kansanga Market', 'Hot-Mix Pothole Patching', 6, 'Available', 26);

INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES
('sla_urgent_days', '1'),
('sla_high_days', '3'),
('sla_medium_days', '7'),
('sla_low_days', '14'),
('support_toll_free', '0800 900 112'),
('support_city_line', '+256 414 901 000'),
('support_dispatch_email', 'works@roads.gov.ug');

INSERT INTO `official_notices` (`id`, `title`, `message`, `category`, `status`, `expires_at`, `posted_by`, `posted_by_id`) VALUES
('#NTC-001', 'Current Service Area', 'FixMyRoad currently serves users in Kansanga, Makindye Division, Kampala only (rather than the whole of Kampala City right now). In the future, we will expand coverage to the whole of Kampala City.', 'General Announcement', 'active', NULL, 'Sarah Nsubuga', 'usr-3'),
('#NTC-002', 'Ggaba Road Culvert Desilting Schedule', 'Municipal storm drainage crews will clear culverts between Kansanga Junction and Kiwafu Stage from 08:00 to 16:00. Expect single-lane traffic cones near market crossings.', 'Maintenance', 'active', DATE_ADD(CURDATE(), INTERVAL 14 DAY), 'Sarah Nsubuga', 'usr-3'),
('#NTC-003', 'Heavy Rain Flash-Flood Advisory (Nabutiti Channel)', 'Residents around Nabutiti low-lying lane and KIU access road should avoid flooded box culverts during peak storms and report blocked grates immediately.', 'Emergency', 'active', DATE_ADD(CURDATE(), INTERVAL 7 DAY), 'Sarah Nsubuga', 'usr-3');

INSERT INTO `reports` (`id`, `title`, `category`, `description`, `location`, `area`, `lat`, `lng`, `priority`, `status`, `reported_by`, `reported_by_email`, `report_date`, `sla_deadline`, `escalated`, `escalation_reason`, `assigned_officer`, `upvotes`, `resolved_at`, `confirmation_state`, `confirmation_expires_at`, `confirmed_by`, `feedback_rating`, `feedback_comment`, `feedback_author`) VALUES
('#RPT-00123', 'Large pothole on Ggaba Road', 'Pothole', 'Deep pothole at Kansanga Junction is damaging vehicles and widening during evening rains.', 'Kansanga Junction (Ggaba Rd), Makindye Division', 'Kansanga (Makindye)', 0.283500, 32.609500, 'High', 'In Progress', 'Ipavu Roy', 'alaruroy@gmail.com', '2026-11-02 14:20', '2026-11-05 14:20', 1, 'High-priority arterial hazard exceeded 3-day SLA threshold.', 'David Mukasa', 34, NULL, 'none', NULL, NULL, NULL, NULL, NULL),
('#RPT-00122', 'Blocked drainage channel near Nabutiti', 'Blocked Drainage', 'Plastic bottles and silt are backing up storm runoff near Nabutiti trading lane.', 'Nabutiti Road, Kansanga, Makindye Division', 'Kansanga (Makindye)', 0.284800, 32.612200, 'Urgent', 'Pending', 'Ipavu Roy', 'alaruroy@gmail.com', '2026-11-07 11:15', '2026-11-08 11:15', 1, 'Urgent drainage blockage exceeded 24h SLA window.', 'Rapid Drainage Unit 4', 19, NULL, 'none', NULL, NULL, NULL, NULL, NULL),
('#RPT-00118', 'Pothole cluster on Kiwafu side road', 'Pothole', 'Series of surface cavities along Kiwafu link road beside residential gate.', 'Kiwafu Road, Kansanga, Makindye Division', 'Kansanga (Makindye)', 0.286900, 32.607800, 'Medium', 'Resolved', 'Ipavu Roy', 'alaruroy@gmail.com', '2026-11-04 09:30', '2026-11-11 09:30', 0, NULL, 'Grace Namono', 15, NOW(), 'awaiting_confirmation', DATE_ADD(NOW(), INTERVAL 45 HOUR), NULL, NULL, NULL, NULL),
('#RPT-00109', 'Stormwater washout near KIU main gate', 'Flooding', 'Overflowing storm grate flooding pedestrian shoulder outside KIU gate.', 'KIU Gate (Ggaba Rd), Kansanga, Makindye Division', 'Kansanga (Makindye)', 0.281400, 32.610900, 'High', 'In Progress', 'Ipavu Roy', 'alaruroy@gmail.com', '2026-11-05 16:00', '2026-11-08 16:00', 0, NULL, 'David Mukasa', 25, NULL, 'none', NULL, NULL, NULL, NULL, NULL),
('#RPT-00107', 'Pothole outside Kansanga Medical Centre Gate', 'Pothole', 'Ambulance access lane crater patched and compacted with hot-mix asphalt.', 'Ggaba Rd - Kansanga Medical Centre, Makindye', 'Kansanga (Makindye)', 0.283900, 32.608800, 'Urgent', 'Resolved', 'Dr. Alice Kigozi', 'akigozi@kansangamed.ug', '2026-11-03 07:30', '2026-11-04 07:30', 0, NULL, 'David Mukasa', 41, DATE_SUB(NOW(), INTERVAL 3 DAY), 'confirmed', DATE_SUB(NOW(), INTERVAL 1 DAY), 'Dr. Alice Kigozi', 5, 'Patched promptly! Safe passage restored for ambulances.', 'Dr. Alice Kigozi');

INSERT INTO `report_upvotes` (`report_id`, `user_id`) VALUES
('#RPT-00123', 'usr-1'),
('#RPT-00122', 'usr-1'),
('#RPT-00109', 'usr-1');

INSERT INTO `report_updates` (`report_id`, `timestamp_label`, `text`, `author`, `update_type`) VALUES
('#RPT-00123', '2026-11-03 10:15', 'Report verified by admin. Site inspection scheduled.', 'Sarah Nsubuga (Admin)', 'verification'),
('#RPT-00123', '2026-11-03 14:30', 'Assigned to maintenance team (Lead: David Mukasa).', 'Works Dispatch', 'assignment'),
('#RPT-00123', '2026-11-08 09:20', 'Asphalt patching team deployed on site at Kansanga Junction.', 'David Mukasa (Field Officer)', 'work_progress'),
('#RPT-00118', '2026-11-09 16:40', 'Pothole cluster resurfaced and compacted. Awaiting citizen 48h verification.', 'Grace Namono (Field Officer)', 'resolved');

INSERT INTO `report_comments` (`report_id`, `author_id`, `author_name`, `author_role`, `text`, `is_official_question`) VALUES
('#RPT-00123', 'usr-2', 'David Mukasa', 'Field Officer', 'Which side of Ggaba Rd at Kansanga Junction is the deepest edge on — outbound toward Bunga or inbound?', 1),
('#RPT-00123', 'usr-1', 'Ipavu Roy', 'Community Member', 'Outbound lane heading toward Bunga right beside the pedestrian zebra crossing.', 0);

INSERT INTO `maintenance_tasks` (`id`, `title`, `task_type`, `division`, `corridor`, `frequency`, `status`, `assigned_officer`, `next_due_date`, `last_completed_date`, `estimated_hours`, `silt_cleared_kg`, `notes`, `created_by`) VALUES
('#PM-001', 'Ggaba Rd Kansanga Junction Culvert Desilting', 'Culvert Clearing', 'Kansanga (Makindye)', 'Ggaba Rd - Kansanga Junction to KIU', 'Weekly', 'Due Soon', 'Rapid Drainage Unit 4', DATE_ADD(CURDATE(), INTERVAL 2 DAY), DATE_SUB(CURDATE(), INTERVAL 5 DAY), 3.5, 420, 'Check grate hinges and bag cleared plastic waste immediately.', 'Sarah Nsubuga'),
('#PM-002', 'Nabutiti Storm Channel Sediment Removal', 'Drainage Flushing', 'Nabutiti / Kansanga', 'Nabutiti Trading Lane - Main Box Drain', 'Bi-Weekly', 'Scheduled', 'David Mukasa', DATE_ADD(CURDATE(), INTERVAL 6 DAY), DATE_SUB(CURDATE(), INTERVAL 8 DAY), 5.0, 680, 'Inspect retaining walls after heavy evening storms.', 'Sarah Nsubuga'),
('#PM-003', 'Kiwafu Road Shoulder & Swale Grading', 'Road Shoulder Grading', 'Kiwafu / Kansanga', 'Kiwafu Road Estate Loop', 'Monthly', 'Overdue', 'Grace Namono', DATE_SUB(CURDATE(), INTERVAL 2 DAY), DATE_SUB(CURDATE(), INTERVAL 32 DAY), 6.0, 310, 'Maintain cross-fall drainage slope along gravel shoulders.', 'Sarah Nsubuga');

INSERT INTO `notifications` (`title`, `message`, `notif_type`, `target_role`, `report_id`, `is_read`) VALUES
('Confirm Fix Window Open (#RPT-00118)', 'Grace Namono marked "Pothole cluster on Kiwafu side road" as Resolved. Confirm within 48 hours.', 'resolved', 'Community Member', '#RPT-00118', 0),
('SLA Escalation Active (#RPT-00122)', 'Blocked drainage channel near Nabutiti exceeded its 24h urgent SLA target.', 'escalation', 'Administrator', '#RPT-00122', 0),
('Assigned Work Order (#RPT-00123)', 'High-priority pothole on Ggaba Road assigned to David Mukasa.', 'assignment', 'Field Officer', '#RPT-00123', 0);

INSERT INTO `audit_logs` (`actor_name`, `actor_role`, `action`, `target_id`, `details`, `category`) VALUES
('Sarah Nsubuga', 'Administrator', 'Published Official Notice', '#NTC-001', '[General Announcement] "Current Service Area" (Ongoing)', 'notice'),
('Sarah Nsubuga', 'Administrator', 'Published Official Notice', '#NTC-002', '[Maintenance] "Ggaba Road Culvert Desilting Schedule"', 'notice'),
('David Mukasa', 'Field Officer', 'Field Progress Logged', '#RPT-00123', 'Deployed asphalt patching crew at Kansanga Junction.', 'status'),
('SLA Escalation Engine', 'System', 'Auto-Escalated Overdue Fault', '#RPT-00122', 'Urgent drainage blockage exceeded 24h SLA target.', 'escalation');
