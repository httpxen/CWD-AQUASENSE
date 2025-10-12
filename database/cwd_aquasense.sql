DROP DATABASE IF EXISTS cwd_aquasense;
CREATE DATABASE cwd_aquasense;
USE cwd_aquasense;

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
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Table structure for table `complaints`
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
  PRIMARY KEY (`complaint_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `complaints_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Table structure for table `feedback`
-- --------------------------------------------------------
CREATE TABLE `feedback` (
  `feedback_id` INT(11) NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) NOT NULL,
  `feedback_text` TEXT NOT NULL,
  `sentiment` VARCHAR(20) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`feedback_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `feedback_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Table structure for table `staff`
-- --------------------------------------------------------
CREATE TABLE `staff` (
  `staff_id` INT(11) NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `profile_picture` VARCHAR(255) DEFAULT NULL,
  `email` VARCHAR(100) NOT NULL,
  `role` ENUM('Admin','Support','Manager') NOT NULL,
  `password` VARCHAR(255) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`staff_id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Table structure for table `complaint_assignments`
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
  CONSTRAINT `complaint_assignments_ibfk_1` FOREIGN KEY (`complaint_id`) REFERENCES `complaints` (`complaint_id`),
  CONSTRAINT `complaint_assignments_ibfk_2` FOREIGN KEY (`staff_id`) REFERENCES `staff` (`staff_id`)
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
