-- AI Image Detector Database Schema
-- Creates the database 'aidb' and the 'scan_logs' table for logging image analyses

CREATE DATABASE IF NOT EXISTS `aidb`
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `aidb`;

CREATE TABLE IF NOT EXISTS `scan_logs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `file_name` VARCHAR(255) NOT NULL,
    `file_path` VARCHAR(255) DEFAULT NULL,
    `file_size` BIGINT DEFAULT NULL,
    `mime_type` VARCHAR(100) DEFAULT NULL,
    `verdict` VARCHAR(50) NOT NULL,
    `confidence_score` DECIMAL(6, 4) DEFAULT NULL,
    `raw_response` LONGTEXT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
