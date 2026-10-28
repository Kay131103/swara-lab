-- MeloMaster database schema (MySQL / MariaDB).
-- Import with:  mysql -u root -p < login_email_verification.sql

CREATE DATABASE IF NOT EXISTS `login_email_verification`
  DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE `login_email_verification`;

CREATE TABLE IF NOT EXISTS `tbl_user` (
  `tbl_user_id` int(11) NOT NULL AUTO_INCREMENT,
  `first_name` varchar(255) NOT NULL,
  `last_name` varchar(255) NOT NULL,
  `contact_number` varchar(32) NOT NULL DEFAULT '',
  `email` varchar(255) NOT NULL,
  `username` varchar(64) NOT NULL,
  `password` varchar(255) NOT NULL COMMENT 'password_hash() output, never plain text',
  `verification_code` int(6) NOT NULL,
  `verified` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`tbl_user_id`),
  UNIQUE KEY `uniq_username` (`username`),
  UNIQUE KEY `uniq_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Upgrading an existing database from the original version? Run this instead:
--   ALTER TABLE `tbl_user`
--     ADD `verified` tinyint(1) NOT NULL DEFAULT 0,
--     ADD `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
--     MODIFY `contact_number` varchar(32) NOT NULL DEFAULT '',
--     ADD UNIQUE KEY `uniq_username` (`username`),
--     ADD UNIQUE KEY `uniq_email` (`email`);
-- Old accounts stored plain-text passwords and cannot log in any more;
-- delete them and register again.
