DROP DATABASE IF EXISTS cwd_aquasense;
CREATE DATABASE cwd_aquasense;
USE cwd_aquasense;

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

-- --------------------------------------------------------
-- Table structure for table `users`
-- --------------------------------------------------------
CREATE TABLE `users` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(50) NOT NULL,
  `first_name` VARCHAR(50) NOT NULL,
  `middle_name` VARCHAR(50) DEFAULT NULL,
  `last_name` VARCHAR(50) NOT NULL,
  `email` VARCHAR(100) NOT NULL,
  `contact_number` varchar(20) NOT NULL,
  `password` VARCHAR(255) NOT NULL,
  `reset_token` VARCHAR(255) DEFAULT NULL,
  `reset_token_expiry` DATETIME DEFAULT NULL,
  `reset_expiry` DATETIME DEFAULT NULL,  -- Idinagdag mo ito dito
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `profile_picture` VARCHAR(255) DEFAULT NULL,
  `remember_token` VARCHAR(100) DEFAULT NULL,
  `token_expiry` DATETIME DEFAULT NULL,
  `accepted_terms_version` VARCHAR(20) DEFAULT NULL,
  `accepted_terms_at` TIMESTAMP NULL DEFAULT NULL,
  `accepted_terms_ip` VARCHAR(45) DEFAULT NULL,
  `accepted_terms_ua` TEXT DEFAULT NULL,
  `last_login` DATETIME DEFAULT NULL,
  `is_active_session` TINYINT(1) DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Table structure for table `staff`
-- (must be created before complaint_assignments which references it)
-- --------------------------------------------------------
CREATE TABLE `staff` (
  `staff_id` INT(11) NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `profile_picture` VARCHAR(255) DEFAULT NULL,
  `email` VARCHAR(100) NOT NULL,
  `role` ENUM('Admin','Employee','SuperAdmin') NOT NULL,
  `password` VARCHAR(255) NOT NULL,
  `reset_token` VARCHAR(255) DEFAULT NULL, 
  `reset_token_expiry` DATETIME DEFAULT NULL,  
  `reset_expiry` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_login` datetime DEFAULT NULL,
  PRIMARY KEY (`staff_id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Table structure for table `announcements`
CREATE TABLE announcements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    description TEXT NOT NULL,
    start_date DATE NOT NULL,
    start_time TIME NULL,
    end_date DATE NOT NULL,
    end_time TIME NULL,
    affected_areas TEXT,
    image_path VARCHAR(255),
    staff_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (staff_id) REFERENCES staff(staff_id)
);
-- --------------------------------------------------------

-- --------------------------------------------------------
-- Table structure for table `complaints`
-- (created before any complaint-related FK tables)
-- --------------------------------------------------------
CREATE TABLE `complaints` (
  `complaint_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `category` varchar(100) NOT NULL,
  `description` text NOT NULL,
  `sentiment` varchar(20) DEFAULT NULL,
  `status` enum('Pending','In Progress','Resolved','Closed') DEFAULT 'Pending',
  `action_due` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `attachment_path` varchar(255) DEFAULT NULL,
  `location_lat` double(10,8) DEFAULT NULL,
  `location_lng` double(11,8) DEFAULT NULL,
  `location_address` varchar(255) DEFAULT NULL,
  `resolved_at` datetime DEFAULT NULL,
  PRIMARY KEY (`complaint_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Table structure for table `complaint_status_history`
-- (references complaints)
-- --------------------------------------------------------
CREATE TABLE `complaint_status_history` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `complaint_id` INT(11) NOT NULL,
  `status` ENUM('Pending','In Progress','Resolved','Closed') NOT NULL,
  `changed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `complaint_id` (`complaint_id`),
  CONSTRAINT `complaint_status_history_ibfk_1` FOREIGN KEY (`complaint_id`) REFERENCES `complaints` (`complaint_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Table structure for table `complaint_comments`
-- (references complaints)
-- --------------------------------------------------------
CREATE TABLE `complaint_comments` (
  `comment_id` INT(11) NOT NULL AUTO_INCREMENT,
  `complaint_id` INT(11) NOT NULL,
  `commenter_type` ENUM('user','staff') NOT NULL,
  `commenter_id` INT(11) NOT NULL,
  `comment` TEXT NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`comment_id`),
  KEY `complaint_id` (`complaint_id`),
  CONSTRAINT `complaint_comments_ibfk_1` FOREIGN KEY (`complaint_id`) REFERENCES `complaints` (`complaint_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Table structure for table `feedback`
-- (references users)
-- --------------------------------------------------------
CREATE TABLE `feedback` (
  `feedback_id` INT(11) NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) NOT NULL,
  `feedback_text` TEXT NOT NULL,
  `sentiment` VARCHAR(20) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`feedback_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `feedback_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Table structure for table `complaint_assignments`
-- (references complaints and staff — both already created above)
-- --------------------------------------------------------
CREATE TABLE `complaint_assignments` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `complaint_id` INT(11) NOT NULL,
  `staff_id` INT(11) NOT NULL,
  `assigned_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `status` ENUM('Assigned','In Progress','Resolved') DEFAULT 'Assigned',
  PRIMARY KEY (`id`),
  KEY `complaint_id` (`complaint_id`),
  KEY `staff_id` (`staff_id`),
  CONSTRAINT `complaint_assignments_ibfk_1` FOREIGN KEY (`complaint_id`) REFERENCES `complaints` (`complaint_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `complaint_assignments_ibfk_2` FOREIGN KEY (`staff_id`) REFERENCES `staff` (`staff_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Table structure for table `reports`
-- --------------------------------------------------------
CREATE TABLE `reports` (
  `report_id` INT(11) NOT NULL AUTO_INCREMENT,
  `report_date` DATE NOT NULL,
  `total_complaints` INT(11) DEFAULT 0,
  `resolved_complaints` INT(11) DEFAULT 0,
  `avg_resolution_time` FLOAT DEFAULT 0,
  `sentiment_positive` INT(11) DEFAULT 0,
  `sentiment_negative` INT(11) DEFAULT 0,
  `sentiment_neutral` INT(11) DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`report_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Table structure for table `static_content`
-- New table for static texts like history, mission, vision, core values
-- --------------------------------------------------------
CREATE TABLE `static_content` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `content_key` VARCHAR(50) NOT NULL UNIQUE COMMENT 'e.g., history, mission, vision, core_values',
  `title` VARCHAR(100) NOT NULL,
  `content` TEXT NOT NULL,
  `language` VARCHAR(10) DEFAULT 'en' COMMENT 'en for English, tl for Tagalog',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Dumping data for table `static_content`
-- --------------------------------------------------------
INSERT INTO `static_content` (`content_key`, `title`, `content`, `language`) VALUES
('history', 'History', 'In 1926, the water supply system in Calamba was managed by the Municipal Government, under the administration of National Water Sewerage Authority (NAWASA), through the then called Calamba Water Works System with Tigbe Spring as the sole source of water that supplied the whole town. Come 1964, Bucal Spring was utilized with approximately 16.4 kilometer of pipelines and 380 cubic meter concrete reservoir.\nThe creation of The Provincial Water Utilities Act of 1973 also known as the Presidential Decree No. 198 (PD 198) paved the way for birth of various local water districts in the country. On August 07, 1974, the Sangguniang Bayan of Calamba, headed by then Mayor Taciano Rizal, passed the Municipal Board Resolution No. 82 Series of 1974 in pursuant to P.D. 198 as amended which gave rise to the organization of the CALAMBA WATER DISTRICT (CWD). Two years after, Local Water Utilities Administration awarded the Conditional Certificate of Conformance No. 29 on September 04, 1976 which entitled CWD to the rights and privileges authorized under PD 198. This then pronounced the official day of CWD in carrying out its mission, which at that time, provided service to a total of 1,100 active service connections.\nSafe drinking water is what CWD guarantees to its concessionaires by supplying potable water conforming to the standard specified in the PNSDW 2007 and as certified by the City Health Office through our DOH-Accredited Laboratory with Accreditation No. 254 and with the use of the latest technology, aiming mainly toward its commitment to be of good service to the community and to capture satisfaction of its concessionaires.', 'en'),
('mission', 'Mission', 'The District will ensure the Calambeños with sufficient supply of potable water 24/7 along with its commitment to establish sewerage and septage management system as part of our environmental concern.', 'en'),
('vision', 'Vision', 'A District with the highest quality of service that ensures customer satisfaction by providing continuous supply of potable water at an affordable cost and committed to an environmental preservation and protection.', 'en'),
('core_values', 'Core Values', 'Knowledgeability\nDedication\nCommitment\nLoyalty\nIntegrity\nSimple Living', 'en'),
('quality_policy', 'Quality Policy', 'The Calamba Water District is committed to quality by providing a water microbiological testing for a safe drinking water with the objectives to meet the needs of our customer at all times. Accordingly, the management of Calamba Water District is committed to satisfy tha applicable requirements by ensuring the commitment to continual improvement of the quality management system.', 'en');
-- -- --------------------------------------------------------
-- Table structure for table `people`
-- -- --------------------------------------------------------

CREATE TABLE `people` (
  `person_id` int(11) NOT NULL,
  `name` varchar(150) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `people`
--

INSERT INTO `people` (`person_id`, `name`, `created_at`) VALUES
(1, 'Mr. Ronald J. Pua', '2025-11-03 08:13:47'),
(2, 'Atty. Dante Manguiat', '2025-11-03 08:13:47'),
(3, 'Mr. Aldrin Gamilla', '2025-11-03 08:13:47'),
(4, 'Ms. Alicia V. Llamas', '2025-11-03 08:13:47'),
(5, 'Bryan A. Ercia', '2025-11-03 08:13:47'),
(6, 'Exequiel Aguilar Jr.', '2025-11-03 08:13:47'),
(7, 'Edwin L. Cartago', '2025-11-03 08:13:47'),
(8, 'Chona B. Santos', '2025-11-03 08:13:47'),
(9, 'Ma. Carmela M. Elepano', '2025-11-03 08:13:47'),
(10, 'Engr. Ranely S. Cartago', '2025-11-03 08:13:47'),
(11, 'Engr. Joselito A. Gillera', '2025-11-03 08:13:47'),
(12, 'Elenita V. Panganiban', '2025-11-03 08:13:47'),
(13, 'Remedios L. Marfori', '2025-11-03 08:13:47'),
(14, 'Emmanuel T. Salvador', '2025-11-03 08:13:47'),
(15, 'Vacant', '2025-11-03 08:13:47'),
(16, 'Mercedes A. Carreon', '2025-11-03 08:13:47'),
(17, 'Elsa Gillera', '2025-11-03 08:13:47'),
(18, 'Henry B. Junio', '2025-11-03 08:13:47'),
(19, 'Ronnie G. Sierva', '2025-11-03 08:13:47'),
(20, 'Engr. Rolando V. Baro', '2025-11-03 08:13:47'),
(21, 'Engr. Elizaldy O. Novillos', '2025-11-03 08:13:47');

-- --------------------------------------------------------

--
-- Table structure for table `positions`
--

CREATE TABLE `positions` (
  `position_id` int(11) NOT NULL,
  `title` varchar(100) NOT NULL,
  `category` enum('board','management') NOT NULL,
  `department` varchar(100) DEFAULT NULL,
  `division` varchar(100) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `order_index` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `positions`
--

INSERT INTO `positions` (`position_id`, `title`, `category`, `department`, `division`, `is_active`, `order_index`) VALUES
(1, 'Chairperson', 'board', NULL, NULL, 1, 1),
(2, 'Vice-Chairperson', 'board', NULL, NULL, 1, 2),
(3, 'Corporate Secretary', 'board', NULL, NULL, 1, 3),
(4, 'Treasurer', 'board', NULL, NULL, 1, 4),
(5, 'P.R.O.', 'board', NULL, NULL, 1, 5),
(6, 'General Manager', 'management', NULL, NULL, 1, 0),
(7, 'Department Manager', 'management', 'Administrative', NULL, 1, 1),
(8, 'Department Manager', 'management', 'Finance', NULL, 1, 2),
(9, 'Department Manager', 'management', 'Commercial', NULL, 1, 3),
(10, 'Department Manager', 'management', 'Technical Services', NULL, 1, 4),
(11, 'Department Manager', 'management', 'Operations', NULL, 1, 5),
(12, 'Division Manager', 'management', 'Customer Service Division A', 'Human Resource', 1, 1),
(13, 'Division Manager', 'management', 'Customer Service Division A', 'Property & Materials Management', 1, 2),
(14, 'Division Manager', 'management', 'Customer Service Division A', 'General Services', 1, 3),
(15, 'Division Manager', 'management', 'Finance', 'General Accounting', 1, 1),
(16, 'Division Manager', 'management', 'Finance', 'Budget', 1, 2),
(17, 'OIC - Billing and Meter Reading', 'management', 'Commercial', NULL, 1, 1),
(18, 'Division Manager', 'management', 'Commercial', 'Customer Accounts', 1, 2),
(19, 'Division Manager', 'management', 'Commercial', 'Customer Care', 1, 3),
(20, 'Division Manager', 'management', 'Technical Services', 'Pipeline and Appurtenance Maintenance', 1, 1),
(21, 'Division Manager', 'management', 'Operations', 'Production', 1, 1);

-- --------------------------------------------------------

--
-- Table structure for table `position_assignments`
--

CREATE TABLE `position_assignments` (
  `assignment_id` int(11) NOT NULL,
  `position_id` int(11) NOT NULL,
  `person_id` int(11) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date DEFAULT NULL,
  `is_current` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `position_assignments`
--

INSERT INTO `position_assignments` (`assignment_id`, `position_id`, `person_id`, `start_date`, `end_date`, `is_current`, `created_at`) VALUES
(1, 1, 1, '2020-01-01', NULL, 1, '2025-11-03 08:13:47'),
(2, 2, 2, '2020-01-01', NULL, 1, '2025-11-03 08:13:47'),
(3, 3, 3, '2020-01-01', NULL, 1, '2025-11-03 08:13:47'),
(4, 4, 4, '2020-01-01', NULL, 1, '2025-11-03 08:13:47'),
(5, 5, 5, '2020-01-01', NULL, 1, '2025-11-03 08:13:47'),
(6, 6, 6, '2023-01-01', NULL, 1, '2025-11-03 08:13:47'),
(7, 7, 7, '2020-01-01', NULL, 1, '2025-11-03 08:13:47'),
(8, 8, 8, '2020-01-01', NULL, 1, '2025-11-03 08:13:47'),
(9, 9, 9, '2020-01-01', NULL, 1, '2025-11-03 08:13:47'),
(10, 10, 10, '2020-01-01', NULL, 1, '2025-11-03 08:13:47'),
(11, 11, 11, '2020-01-01', NULL, 1, '2025-11-03 08:13:47'),
(12, 12, 12, '2020-01-01', NULL, 1, '2025-11-03 08:13:47'),
(13, 13, 13, '2020-01-01', NULL, 1, '2025-11-03 08:13:47'),
(14, 14, 14, '2020-01-01', NULL, 1, '2025-11-03 08:13:47'),
(15, 15, 15, '2020-01-01', NULL, 1, '2025-11-03 08:13:47'),
(16, 16, 16, '2020-01-01', NULL, 1, '2025-11-03 08:13:47'),
(17, 17, 17, '2020-01-01', NULL, 1, '2025-11-03 08:13:47'),
(18, 18, 18, '2020-01-01', NULL, 1, '2025-11-03 08:13:47'),
(19, 19, 19, '2020-01-01', NULL, 1, '2025-11-03 08:13:47'),
(20, 20, 20, '2020-01-01', NULL, 1, '2025-11-03 08:13:47'),
(21, 21, 21, '2020-01-01', NULL, 1, '2025-11-03 08:13:47');

-- --------------------------------------------------------
-- BAGONG TABLES PARA SA CITIZEN'S CHARTER
-- --------------------------------------------------------

CREATE TABLE `citizen_charter_services` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `slug` VARCHAR(50) NOT NULL UNIQUE COMMENT 'e.g., estimate, connection',
  `sidebar_title` VARCHAR(255) NOT NULL COMMENT 'Title sa sidebar button',
  `main_title` VARCHAR(255) NOT NULL COMMENT 'Main h2 title sa tab',
  `subtitle` VARCHAR(100) DEFAULT NULL COMMENT 'e.g., (Simple Transaction)',
  `transaction_type` ENUM('Simple', 'Complex', 'Simple/Complex', 'Accredited Testing Services') DEFAULT NULL,
  `total_time` VARCHAR(50) DEFAULT NULL COMMENT 'e.g., 27 minutes',
  `total_fee` DECIMAL(10,2) DEFAULT 0 COMMENT 'e.g., 100.00',
  `description` TEXT DEFAULT NULL COMMENT 'Optional overall description',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `service_requirements` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `service_id` INT(11) NOT NULL,
  `section_title` VARCHAR(255) DEFAULT NULL COMMENT 'e.g., Checklist of Requirements o Additional – For ...',
  `requirement_text` TEXT NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `service_id` (`service_id`),
  CONSTRAINT `service_requirements_ibfk_1` FOREIGN KEY (`service_id`) REFERENCES `citizen_charter_services` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `service_procedures` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `service_id` INT(11) NOT NULL,
  `step_number` INT(11) NOT NULL COMMENT 'e.g., 1, 2, 2.1',
  `description` TEXT NOT NULL,
  `processing_time` VARCHAR(50) DEFAULT NULL,
  `fee` DECIMAL(10,2) DEFAULT 0,
  `responsible` VARCHAR(255) DEFAULT NULL COMMENT 'Person/Office',
  `location` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `service_id` (`service_id`),
  CONSTRAINT `service_procedures_ibfk_1` FOREIGN KEY (`service_id`) REFERENCES `citizen_charter_services` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `service_fees` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `service_id` INT(11) NOT NULL,
  `fee_category` VARCHAR(255) DEFAULT NULL COMMENT 'e.g., Residential Connection without Excavation',
  `particular` VARCHAR(255) NOT NULL,
  `amount` DECIMAL(10,2) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `service_id` (`service_id`),
  CONSTRAINT `service_fees_ibfk_1` FOREIGN KEY (`service_id`) REFERENCES `citizen_charter_services` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `service_remarks` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `service_id` INT(11) NOT NULL,
  `remark` TEXT NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `service_id` (`service_id`),
  CONSTRAINT `service_remarks_ibfk_1` FOREIGN KEY (`service_id`) REFERENCES `citizen_charter_services` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- FULL INSERTS PARA SA CITIZEN'S CHARTER SERVICES
-- (12 Services mula sa HTML)
-- --------------------------------------------------------

-- Service 1: Application for Estimate
INSERT INTO `citizen_charter_services` (`slug`, `sidebar_title`, `main_title`, `subtitle`, `transaction_type`, `total_time`, `total_fee`) VALUES
('estimate', 'Application for Estimate', 'Filing of Application for Estimate', '(Simple Transaction)', 'Simple', '27 minutes', 100.00);

INSERT INTO `service_requirements` (`service_id`, `section_title`, `requirement_text`) VALUES
(1, 'Checklist of Requirements', '1. Certificate of Ownership – One (1) Photocopy'),
(1, 'Checklist of Requirements', '2. Sketch of Location – One (1) Original or One (1) Photocopy'),
(1, 'Checklist of Requirements', '3. Water Bill Receipt – One (1) Original or One (1) Photocopy');

INSERT INTO `service_procedures` (`service_id`, `step_number`, `description`, `processing_time`, `fee`, `responsible`, `location`) VALUES
(1, 1, 'Get a queuing ticket from the Lobby Guard for Application for Estimate Location: Lobby', '1 minute', 0, 'Guard on Duty; Security Services', 'Lobby'),
(1, 2, 'Verification of Account on the Billing System Location: One-Stop-Shop', '15 minutes', 0, 'Customer Care Division (Window 5)', 'One-Stop-Shop'),
(1, 3, '2.1 Issuance of Order of Payment Location: One-Stop-Shop', '1 minute', 0, 'Customer Care Division (Window 5)', 'One-Stop-Shop'),
(1, 4, 'Payment of Filing Fee Location: Treasury Section (Lobby)', '5 minutes', 100.00, 'Budget and Cash Management Division (Window 3)', 'Treasury Section (Lobby)'),
(1, 5, 'Submit the official receipt of Filing Fee Location: One-Stop-Shop', '5 minutes', 0, 'Customer Care Division (Window 5)', 'One-Stop-Shop');

-- Service 2: New Water Connection
INSERT INTO `citizen_charter_services` (`slug`, `sidebar_title`, `main_title`, `subtitle`, `transaction_type`, `total_time`, `total_fee`) VALUES
('connection', 'New Water Connection', 'Payment of Application for New Water Service Connection', NULL, 'Simple', '41 minutes', NULL);

INSERT INTO `service_requirements` (`service_id`, `section_title`, `requirement_text`) VALUES
(2, 'Checklist of Standard Requirements', '1. Certificate of Ownership – One (1) Photocopy'),
(2, 'Checklist of Standard Requirements', '2. Barangay Clearance – One (1) Original or One (1) Photocopy'),
(2, 'Checklist of Standard Requirements', '3. Water Bill Receipt – One (1) Original or One (1) Photocopy'),
(2, 'Checklist of Standard Requirements', '4. Certificate of Inspection – One (1) Original or One (1) Photocopy'),
(2, 'Checklist of Standard Requirements', '5. Valid Government Receipt of Purchased Materials – One (1) Photocopy'),
(2, 'Additional – For Authorized Representative of Applicant living in the Philippines', '1. Signed Government ID – One (1) Photocopy'),
(2, 'Additional – For Authorized Representative of Applicant living Abroad', '1. Special Power of Attorney – One (1) Photocopy by Philippine Consul – One (1) Original'),
(2, 'Additional – For Authorized Representative of Corporate Application', '1. Notarized Board Resolution – One (1) Original or One (1) Photocopy'),
(2, 'Additional – For Authorized Representative of Corporate Application', '2. Government ID – One (1) Photocopy'),
(2, 'Additional – For Authorized Representative of Corporate Application', '3. Signed Authorization Letter from the Administrator, General Manager, Branch or School Principal – One (1) Original'),
(2, 'Additional – For Application with Concrete Breaking', '1. Signed Concrete Breaking Permit – One (1) Original with One (1) Photocopy'),
(2, 'Additional – For Series Connection', '1. Letter of Consent – One (1) Original'),
(2, 'Additional – For Series Connection', '2. Valid Government ID – One (1) Photocopy'),
(2, 'For New Water Service Application within a Subdivision As Needs Arises', '1. Homeowners Association Certification – One (1) Original');

INSERT INTO `service_procedures` (`service_id`, `step_number`, `description`, `processing_time`, `fee`, `responsible`, `location`) VALUES
(2, 1, 'Get a queuing ticket from the Lobby Guard for New Connection Location: Lobby', '1 minute', 0, 'Guard on Duty; Security Services', 'Lobby'),
(2, 2, 'Verification of Application on the New Connection System Location: One-Stop-Shop', '3 minutes', 0, 'Customer Care Division (Window 5)', 'One-Stop-Shop'),
(2, 3, '2.1 Evaluation and Acceptance of Documentary Requirements Location: One-Stop-Shop', '5 minutes', 0, 'Customer Care Division (Window 5)', 'One-Stop-Shop'),
(2, 4, 'Issuance of Order of Payment Location: One-Stop-Shop', '1 minute', 0, 'Customer Care Division (Window 5)', 'One-Stop-Shop'),
(2, 5, 'Payment of New Connection Fees Location: Treasury Section (Lobby)', '5 minutes', NULL, 'Budget and Cash Management Division (Window 3)', 'Treasury Section (Lobby)'),
(2, 6, 'Encoding and Contract, Fill-out Customer\'s Information Sheet and Waiver, Signing of Service Connection Contract Location: One-Stop-Shop', '26 minutes', 0, 'Customer Care Division / Applicant (Window 5)', 'One-Stop-Shop');

INSERT INTO `service_fees` (`service_id`, `fee_category`, `particular`, `amount`) VALUES
(2, 'Residential Connection without Excavation', 'Customer\'s Contribution', 3040.00),
(2, 'Residential Connection without Excavation', 'Valve with Tail Piece', 421.30),
(2, 'Residential Connection without Excavation', 'Water Bill Deposit', 1500.00),
(2, 'Residential Connection without Excavation', 'Notary Labor', 100.00),
(2, 'Residential Connection without Excavation', 'Municipal Fee', 10.00),
(2, 'Commercial Connection without Excavation', 'Customer\'s Contribution', 3090.00),
(2, 'Commercial Connection without Excavation', 'Valve with Tail Piece', 342.30),
(2, 'Commercial Connection without Excavation', 'Water Bill Deposit', 1500.00),
(2, 'Commercial Connection without Excavation', 'Notary Labor', 100.00),
(2, 'Commercial Connection without Excavation', 'Municipal Fee', 20.00);

-- Service 3: Filing of Complaint or Request
INSERT INTO `citizen_charter_services` (`slug`, `sidebar_title`, `main_title`, `subtitle`, `transaction_type`, `total_time`, `total_fee`) VALUES
('complaint', 'Filing of Complaint or Request', 'Filing of Complaint or Request', '(Complex Transaction)', 'Complex', '18 minutes', 0.00);

INSERT INTO `service_requirements` (`service_id`, `section_title`, `requirement_text`) VALUES
(3, 'Checklist of Requirements', '1. Water Bill Receipt – One (1) Original Copy or One (1) Photocopy');

INSERT INTO `service_procedures` (`service_id`, `step_number`, `description`, `processing_time`, `fee`, `responsible`, `location`) VALUES
(3, 1, 'Get a queuing ticket from the Lobby Guard for Complaint/Request Location: Lobby', '1 minute', 0, 'Guard on Duty; Security Services', 'Lobby'),
(3, 2, 'Approach the corresponding window when the number is called, Preparation and Printing of Service Request Location: One-Stop-Shop', '17 minutes', 0, 'Customer Care Division (Window 5)', 'One-Stop-Shop');

INSERT INTO `service_remarks` (`service_id`, `remark`) VALUES
(3, 'Reports under major repair (within 24 hours): Leak on Distribution Line / Leak on Transmission Line / Pump & Motor Control Breakdown'),
(3, 'Reports under minor repair (within 2 days): Leak Service Line / Tapping Point / Before the Meter / Leak on Meter / Leak on Disinfection Equipment'),
(3, 'Reports under verification of consumption / meter (within 2 days): High and Low Consumption / Calibration & Replacement of Meter');

-- Service 4: Request for Disconnection
INSERT INTO `citizen_charter_services` (`slug`, `sidebar_title`, `main_title`, `subtitle`, `transaction_type`, `total_time`, `total_fee`) VALUES
('disconnection', 'Request for Disconnection', 'Filing of Request for Disconnection', '(Simple Transaction)', 'Simple', '18 minutes', 0.00);

INSERT INTO `service_requirements` (`service_id`, `section_title`, `requirement_text`) VALUES
(4, 'Checklist of Standard Requirements', '1. Water Bill Receipt – One (1) Original Copy or One (1) Photocopy'),
(4, 'Checklist of Standard Requirements', '2. Signed Letter of Request for Temporary or Permanent Disconnection – One (1) Original Copy'),
(4, 'Checklist of Standard Requirements', '3. Government Issued ID – One (1) Photocopy'),
(4, 'For Authorized Representative of Account Owner living in the Philippines', '1. Signed Written Authorization – One (1) Original Copy'),
(4, 'For Authorized Representative of Account Owner living in the Philippines', '2. Valid Government ID – One (1) Photocopy'),
(4, 'For Authorized Representative of Applicant living Abroad', '1. Special Power of Attorney (SPA) Authenticated by Philippine Consul – One (1) Original'),
(4, 'For Authorized Representative of Applicant living Abroad', '2. Valid Government ID – One (1) Photocopy'),
(4, 'For Authorized Representative of Corporate Account', '1. Notarized Board Resolution – One (1) Original or One (1) Photocopy'),
(4, 'For Authorized Representative of Corporate Account', '2. Notarized Secretary Certificate – One (1) Original or One (1) Photocopy'),
(4, 'For Authorized Representative of Corporate Account', '3. Valid Government ID – One (1) Photocopy'),
(4, 'For Authorized Representative of Government or School Account', '1. Signed Written Authorization from the Administrator, General Manager, Branch Manager or Principal – One (1) Original'),
(4, 'For Authorized Representative of Government or School Account', '2. Valid Government ID – One (1) Photocopy');

INSERT INTO `service_procedures` (`service_id`, `step_number`, `description`, `processing_time`, `fee`, `responsible`, `location`) VALUES
(4, 1, 'Get a queuing ticket from the Lobby Guard for Application for Complaint/Request Location: Lobby', '1 minute', 0, 'Guard on Duty; Security Services', 'Lobby'),
(4, 2, 'Submission of Required Documents once the number is called Location: One-Stop-Shop', '15 minutes', 0, 'Customer Care Division (Window 6)', 'One-Stop-Shop'),
(4, 3, 'Fill-out Request for Disconnection Form Location: One-Stop-Shop', '1 minute', 0, 'Customer Care Division (Window 6)', 'One-Stop-Shop'),
(4, 4, 'Printing of Service Request Location: One-Stop-Shop', '1 minute', 0, 'Customer Care Division (Window 6)', 'One-Stop-Shop');

INSERT INTO `service_remarks` (`service_id`, `remark`) VALUES
(4, 'The outstanding balance on water bill must be settled first prior to acceptance of request.');

-- Service 5: Request for Account Ledger
INSERT INTO `citizen_charter_services` (`slug`, `sidebar_title`, `main_title`, `subtitle`, `transaction_type`, `total_time`, `total_fee`) VALUES
('ledger', 'Request for Account Ledger', 'Filing of Request for a copy of Account Ledger', '(Simple Transaction)', 'Simple', '13 minutes', 0.00);

INSERT INTO `service_requirements` (`service_id`, `section_title`, `requirement_text`) VALUES
(5, 'Checklist of Standard Requirements', '1. Water Bill Receipt – One (1) Original Copy or One (1) Photo Copy'),
(5, 'Checklist of Standard Requirements', '2. Signed Letter of Request – One (1) Original Copy'),
(5, 'Checklist of Standard Requirements', '3. Government Issued ID – One (1) Photo Copy'),
(5, 'For Authorized Representative', '1. Signed Written Authorization – One (1) Original Copy'),
(5, 'For Authorized Representative', '2. Valid Government ID – One (1) Photocopy');

INSERT INTO `service_procedures` (`service_id`, `step_number`, `description`, `processing_time`, `fee`, `responsible`, `location`) VALUES
(5, 1, 'Get a queuing ticket from the Lobby Guard for Public Assistance Complaint Desk Location: Lobby', '1 minute', 0, 'Guard on Duty; Security Services', 'Lobby'),
(5, 2, 'Proceed and Submit the Required Documents Location: One-Stop-Shop', '3 minutes', 0, 'Customer Care Division (Window 4)', 'One-Stop-Shop'),
(5, 3, 'Fill-up Freedom of Information Request Form and Feedback Form Location: One-Stop-Shop', '5 minutes', 0, 'Customer Care Division (Window 4)', 'One-Stop-Shop'),
(5, 4, 'Endorse Request to the Billing and Meter Reading Division Location: One-Stop-Shop', '2 minutes', 0, 'Customer Care Division (Window 4)', 'One-Stop-Shop'),
(5, 5, 'Printing and Stamping of Ledger Location: 3rd Floor', '1 minute', 0, 'Billing and Meter Reading Division', '3rd Floor'),
(5, 6, 'Receiving of Account Ledger Location: One-Stop-Shop', '1 minute', 0, 'Customer Care Division', 'One-Stop-Shop');

INSERT INTO `service_remarks` (`service_id`, `remark`) VALUES
(5, 'Only the Primary or Secondary Registered Name may request a copy of the account ledger.');

-- Service 6: Payment of Water Bill
INSERT INTO `citizen_charter_services` (`slug`, `sidebar_title`, `main_title`, `subtitle`, `transaction_type`, `total_time`, `total_fee`) VALUES
('payment', 'Payment of Water Bill', 'Payment of Water Bill', '(Simple Transaction)', 'Simple', '16 minutes', 0.00);

INSERT INTO `service_requirements` (`service_id`, `section_title`, `requirement_text`) VALUES
(6, 'Checklist of Standard Requirements', '1. Water Bill Receipt – One (1) Original Copy or One (1) Photo Copy'),
(6, 'For Accounts Registered under a Senior Citizen', '1. Senior Citizen\'s ID – One (1) Original Copy or One (1) Photo Copy'),
(6, 'For Authorized Representative of Senior Citizen', '1. Signed Written Authorization – One (1) Original or One (1) Photocopy');

INSERT INTO `service_procedures` (`service_id`, `step_number`, `description`, `processing_time`, `fee`, `responsible`, `location`) VALUES
(6, 1, 'Get a queuing ticket from the Lobby Guard for Public Assistance Location: Lobby', '1 minute', 0, 'Guard on Duty; Security Services', 'Lobby'),
(6, 2, 'Payment of Water Bill Location: Customer Accounts Division (Lobby)', '15 minutes', NULL, 'Customer Accounts Division (Window 1 and 2)', 'Customer Accounts Division (Lobby)');

-- Rate Schedule as Fees
INSERT INTO `service_fees` (`service_id`, `fee_category`, `particular`, `amount`) VALUES
(6, 'List of Formula (Residential/Government)', '1/2" Minimum Charge (1-10 m³)', 183.00),
(6, 'List of Formula (Residential/Government)', '1/2" 11-20 m³ (per m³)', 20.30),
(6, 'List of Formula (Residential/Government)', '1/2" 21-30 m³ (per m³)', 24.05),
(6, 'List of Formula (Residential/Government)', '1/2" 31-40 m³ (per m³)', 30.80),
(6, 'List of Formula (Residential/Government)', '1/2" 41+ m³ (per m³)', 36.45),
(6, 'List of Formula (Residential/Government)', '3/4" Minimum Charge (1-10 m³)', 292.80),
(6, 'List of Formula (Residential/Government)', '3/4" 11-20 m³ (per m³)', 20.30),
(6, 'List of Formula (Residential/Government)', '3/4" 21-30 m³ (per m³)', 24.05),
(6, 'List of Formula (Residential/Government)', '3/4" 31-40 m³ (per m³)', 30.80),
(6, 'List of Formula (Residential/Government)', '3/4" 41+ m³ (per m³)', 36.45),
(6, 'List of Formula (Residential/Government)', '1" Minimum Charge (1-10 m³)', 585.60),
(6, 'List of Formula (Residential/Government)', '1" 11-20 m³ (per m³)', 20.30),
(6, 'List of Formula (Residential/Government)', '1" 21-30 m³ (per m³)', 24.05),
(6, 'List of Formula (Residential/Government)', '1" 31-40 m³ (per m³)', 30.80),
(6, 'List of Formula (Residential/Government)', '1" 41+ m³ (per m³)', 36.45),
(6, 'List of Formula (Residential/Government)', '1 1/2" Minimum Charge (1-10 m³)', 1464.00),
(6, 'List of Formula (Residential/Government)', '1 1/2" 11-20 m³ (per m³)', 20.30),
(6, 'List of Formula (Residential/Government)', '1 1/2" 21-30 m³ (per m³)', 24.05),
(6, 'List of Formula (Residential/Government)', '1 1/2" 31-40 m³ (per m³)', 30.80),
(6, 'List of Formula (Residential/Government)', '1 1/2" 41+ m³ (per m³)', 36.45),
(6, 'List of Formula (Residential/Government)', '2" Minimum Charge (1-10 m³)', 3660.00),
(6, 'List of Formula (Residential/Government)', '2" 11-20 m³ (per m³)', 20.30),
(6, 'List of Formula (Residential/Government)', '2" 21-30 m³ (per m³)', 24.05),
(6, 'List of Formula (Residential/Government)', '2" 31-40 m³ (per m³)', 30.80),
(6, 'List of Formula (Residential/Government)', '2" 41+ m³ (per m³)', 36.45);

INSERT INTO `service_remarks` (`service_id`, `remark`) VALUES
(6, 'Only residential monthly consumptions not exceeding 30 cubic meters may avail 5% discount under RA 9994.'),
(6, 'The discount may be availed through over the counter payment at Calamba Water District Main Office and Extension Offices at Canlubang and Mercado De Calamba.');

-- Service 7: Request for Change of Name
INSERT INTO `citizen_charter_services` (`slug`, `sidebar_title`, `main_title`, `subtitle`, `transaction_type`, `total_time`, `total_fee`) VALUES
('name-change', 'Request for Change of Name', 'Filing of Request for Change of Name', '(Simple Transaction)', 'Simple', '37 minutes', 30.00);

INSERT INTO `service_requirements` (`service_id`, `section_title`, `requirement_text`) VALUES
(7, 'Checklist of Standard Requirements', '1. Notarized Signed Deed of Absolute Sale which includes all improvements – One (1) Photocopy'),
(7, 'Checklist of Standard Requirements', '2. Valid Government ID – One (1) Photocopy'),
(7, 'In the Absence of a Notarized Deed of Absolute Sale', '1. Notarized Signed Affidavit of Waiver – One (1) Original Copy'),
(7, 'For Married Deceased Account Owner', '1. Death Certificate of the Registered Owner – One (1) Photocopy'),
(7, 'For Married Deceased Account Owner', '2. Marriage Contract – One (1) Photocopy'),
(7, 'For Widow/Widower Deceased Account Owner', '1. Death Certificate of the Deceased Account Owner – One (1) Photocopy'),
(7, 'For Widow/Widower Deceased Account Owner', '2. Death Certificate of the Registered Owner – One (1) Photocopy'),
(7, 'For Widow/Widower Deceased Account Owner', '3. Birth Certificate of the Successor – One (1) Photocopy'),
(7, 'For Widow/Widower Deceased Account Owner', '4. Valid Government ID of Successor – One (1) Photocopy'),
(7, 'For Authorized Representative', '1. Authorization Letter – One (1) Original Copy'),
(7, 'For Authorized Representative', '2. Valid Government ID – One (1) Photocopy');

INSERT INTO `service_procedures` (`service_id`, `step_number`, `description`, `processing_time`, `fee`, `responsible`, `location`) VALUES
(7, 1, 'Get a queuing ticket from the Lobby Guard for Public Assistance Complaint Desk Location: Lobby', '1 minute', 0, 'Guard on Duty; Security Services', 'Lobby'),
(7, 2, 'Receive and review the submitted documents, and issue of Order of Payment Location: One-Stop-Shop', '11 minutes', 0, 'Customer Care Division (Window 5/6)', 'One-Stop-Shop'),
(7, 3, 'Payment for Change of Name Location: Lobby', '10 minutes', 30.00, 'Budget and Cash Management Division (Window 3)', 'Lobby'),
(7, 4, 'Return to Window 5 or 6 for Encoding of O.R. number Location: One-Stop-Shop', '15 minutes', 0, 'Customer Care Division (Window 5/6)', 'One-Stop-Shop');

INSERT INTO `service_remarks` (`service_id`, `remark`) VALUES
(7, 'Immediate family refers to husband, wife, children, parent/s or siblings');

-- Service 8: Payment of Bulk Sale
INSERT INTO `citizen_charter_services` (`slug`, `sidebar_title`, `main_title`, `subtitle`, `transaction_type`, `total_time`, `total_fee`) VALUES
('bulk-sale', 'Payment of Bulk Sale', 'Payment of Bulk Sale', '(Simple Transaction)', 'Simple', '23 minutes', 0.00);

INSERT INTO `service_requirements` (`service_id`, `section_title`, `requirement_text`) VALUES
(8, 'Checklist of Requirements', '1. Request to purchase bulk water – (1) Original Copy or (1) Photo Copy');

INSERT INTO `service_procedures` (`service_id`, `step_number`, `description`, `processing_time`, `fee`, `responsible`, `location`) VALUES
(8, 1, 'Approach the Customer Accounts Division Location: Lobby', '3 minutes', 0, 'Customer Accounts Division (Window 1 or 2)', 'Lobby'),
(8, 2, 'Payment of Bulk Water Location: Treasury Section (Lobby)', '5 minutes', NULL, 'Budget and Cash Management Division (Window 3)', 'Treasury Section (Lobby)'),
(8, 3, 'Proceed to Bucal Pump Station for the withdrawal of bulk water Location: Bucal Pump Station - Brgy. Bucal', '15 minutes (for every 6 cubic meter)', 0, 'Operations Department', 'Bucal Pump Station - Brgy. Bucal');

-- Formula as Fees
INSERT INTO `service_fees` (`service_id`, `fee_category`, `particular`, `amount`) VALUES
(8, 'List of Formula', 'Cubic Meter Minimum Charge', 549.00),
(8, 'List of Formula', 'First 10 Cubic Meter', 60.90),
(8, 'List of Formula', '11-20', 72.15),
(8, 'List of Formula', '21-30', 92.40),
(8, 'List of Formula', '31-40', 109.35),
(8, 'List of Formula', '41 Above', 0.00);  -- Fixed to 0.00 since NULL not allowed

-- Service 9: Payment of Ground Water Assessment
INSERT INTO `citizen_charter_services` (`slug`, `sidebar_title`, `main_title`, `subtitle`, `transaction_type`, `total_time`, `total_fee`) VALUES
('ground-water', 'Payment of Ground Water Assessment', 'Payment of Ground Water Assessment', '(Simple Transaction)', 'Simple', '22 minutes', 0.00);

INSERT INTO `service_requirements` (`service_id`, `section_title`, `requirement_text`) VALUES
(9, 'Checklist of Requirements', '1. Water Bill Notice for Ground Water – (1) Original Copy or (1) Photo Copy');

INSERT INTO `service_procedures` (`service_id`, `step_number`, `description`, `processing_time`, `fee`, `responsible`, `location`) VALUES
(9, 1, 'Get a queuing ticket from the Lobby Guard for Public Assistance Location: Lobby', '1 minute', 0, 'Guard on Duty; Security Services', 'Lobby'),
(9, 2, 'Account verification on the billing system Location: One-Stop-Shop', '15 minutes', 0, 'Customer Care Division (Window 4 or 5)', 'One-Stop-Shop'),
(9, 3, 'Issuance of Order of Payment Location: One-Stop-Shop', '1 minute', 0, 'Customer Care Division (Window 4 or 5)', 'One-Stop-Shop'),
(9, 4, 'Payment of Ground Water Assessment Bill Location: Treasury Section (Lobby)', '5 minutes', 0.00, 'Budget and Cash Management Division (Window 3)', 'Treasury Section (Lobby)');

-- Service 10: Application for New Water Connection
INSERT INTO `citizen_charter_services` (`slug`, `sidebar_title`, `main_title`, `subtitle`, `transaction_type`, `total_time`, `total_fee`) VALUES
('new-water-connection', 'Application for New Water Connection', 'Application for New Water Connection', '(Simple/Complex Transaction)', 'Simple/Complex', NULL, 100.00);

INSERT INTO `service_requirements` (`service_id`, `section_title`, `requirement_text`) VALUES
(10, 'Requirements When Filing', '1. Sketch of Location – One (1) Original'),
(10, 'Requirements When Filing', '2. Php 100.00 Filing Fee'),
(10, 'Standard Requirements Upon Approval of Application', '1. Certificate of Inspection – One (1) Original or One (1) Photocopy'),
(10, 'Standard Requirements Upon Approval of Application', '2. Barangay Clearance for Water Connection – One (1) Original or One (1) Photocopy'),
(10, 'Standard Requirements Upon Approval of Application', '3. Copy of any Valid Government ID – One (1) Photocopy'),
(10, 'Standard Requirements Upon Approval of Application', '4. Copy of Certificate of Ownership – One (1) Photocopy'),
(10, 'Standard Requirements Upon Approval of Application', '5. Copy of Water Bill Receipt of Nearest Neighbor – One (1) Original or One (1) Photocopy'),
(10, 'Standard Requirements Upon Approval of Application', '6. Official Receipt of Purchased Materials – One (1) Photocopy');

INSERT INTO `service_procedures` (`service_id`, `step_number`, `description`, `processing_time`, `fee`, `responsible`, `location`) VALUES
(10, 1, 'Filing of Application and Payment of Php 100.00 Filing Fee', NULL, 100.00, 'Customer Care Division', 'Ground Floor (One-Stop-Shop Area)'),
(10, 2, 'Acceptance of Payment', NULL, 0, 'Budget and Cash Management Division', 'Ground Floor'),
(10, 3, 'The assigned estimator will visit the site', NULL, 0, 'Technical Services Department', 'Site Location'),
(10, 4, 'Approval of Application', NULL, 0, 'Technical Services Department', 'Ground Floor'),
(10, 5, 'Evaluation of Documentary Requirements and Issuance of Order of Payment', NULL, 0, 'Customer Care Division', 'Ground Floor (One-Stop-Shop Area)'),
(10, 6, 'Acceptance of Payment', NULL, 0.00, 'Management Division', 'Ground Floor'),
(10, 7, 'Installation of Water Connection', NULL, 0, 'Technical Services Department', 'Site Location');

INSERT INTO `service_remarks` (`service_id`, `remark`) VALUES
(10, 'A) Residential'),
(10, 'B) Commercial Establishment'),
(10, 'Approval of payment will be processed upon completion of requirements.');

-- Service 11: Procedures of Re-connection
INSERT INTO `citizen_charter_services` (`slug`, `sidebar_title`, `main_title`, `subtitle`, `transaction_type`, `total_time`, `total_fee`) VALUES
('reconnection', 'Procedures of Re-connection', 'Procedures of Re-connection', '(Simple Transaction)', 'Simple', NULL, 100.00);

INSERT INTO `service_requirements` (`service_id`, `section_title`, `requirement_text`) VALUES
(11, 'Requirements', '1. Updated Water Bill Receipt'),
(11, 'Requirements', '2. Php 100.00 Reconnection Fee'),
(11, 'Requirements', '3. Authorization Letter and Valid Government ID’s (for representative)');

INSERT INTO `service_procedures` (`service_id`, `step_number`, `description`, `processing_time`, `fee`, `responsible`, `location`) VALUES
(11, 1, 'Filing of request for reconnection and Payment of Php 100.00 Reconnection Fee', NULL, 100.00, 'Customer Care Division', 'Ground Floor (One-Stop-Shop Area)'),
(11, 2, 'Acceptance of Payment', NULL, 0, 'Budget and Cash Management Division', 'Ground Floor'),
(11, 3, 'Preparation of Service Request', NULL, 0, 'Customer Care Division', 'Ground Floor (One-Stop-Shop Area)'),
(11, 4, 'Reconnection of Water Connection', NULL, 0, 'Technical Services Department', 'Site Location');

-- Service 12: Water Analysis
INSERT INTO `citizen_charter_services` (`slug`, `sidebar_title`, `main_title`, `subtitle`, `transaction_type`, `total_time`, `total_fee`) VALUES
('water-analysis', 'Water Analysis', 'Water Analysis', '(Accredited Testing Services)', 'Accredited Testing Services', NULL, NULL);

INSERT INTO `service_requirements` (`service_id`, `section_title`, `requirement_text`) VALUES
(12, NULL, 'Offers microbiological and bacteriological testing for drinking water analysis, accredited by the Department of Health (DOH) with Accreditation No. 254 issued on March 2012.');

INSERT INTO `service_procedures` (`service_id`, `step_number`, `description`, `processing_time`, `fee`, `responsible`, `location`) VALUES
(12, 1, 'Ground Water Assessment A) Metered', NULL, 0, NULL, NULL),
(12, 2, 'Ground Water Assessment B) Fixed Rate', NULL, 0, NULL, NULL),
(12, 3, 'Water Rationing A) Regular Sale', NULL, 0, NULL, NULL),
(12, 4, 'Water Rationing B) Bulk Sale', NULL, 0, NULL, NULL);

INSERT INTO `service_remarks` (`service_id`, `remark`) VALUES
(12, 'For detailed procedures, fees, and scheduling, please visit the One-Stop-Shop or contact Customer Care Division.');

COMMIT;