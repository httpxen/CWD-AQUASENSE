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
  `role` ENUM('Admin','Employee') NOT NULL,
  `password` VARCHAR(255) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_login` datetime DEFAULT NULL,
  PRIMARY KEY (`staff_id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Table structure for table `complaints`
-- (created before any complaint-related FK tables)
-- --------------------------------------------------------
CREATE TABLE `complaints` (
  `complaint_id` INT(11) NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) NOT NULL,
  `category` VARCHAR(100) NOT NULL,
  `description` TEXT NOT NULL,
  `sentiment` VARCHAR(20) DEFAULT NULL,
  `status` ENUM('Pending','In Progress','Resolved','Closed') DEFAULT 'Pending',
  `action_due` DATE DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `attachment_path` VARCHAR(255) DEFAULT NULL,
  `resolved_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`complaint_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `complaints_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
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
COMMIT;