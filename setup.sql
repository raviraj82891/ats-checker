-- ============================================================
-- ATS Resume Checker — Database Setup
-- Run this in phpMyAdmin > SQL tab
-- ============================================================

-- 1. Create database
CREATE DATABASE IF NOT EXISTS `ats_checker`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `ats_checker`;

-- 2. Create table
CREATE TABLE IF NOT EXISTS `resume_checks` (
  `id`                INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `filename`          VARCHAR(255)    NOT NULL,
  `score`             TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `keywords_found`    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `keywords_missing`  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `sections_found`    TINYINT UNSIGNED  NOT NULL DEFAULT 0,
  `section_scores`    JSON            NULL,
  `improvements`      JSON            NULL,
  `job_desc_provided` TINYINT(1)      NOT NULL DEFAULT 0,
  `created_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_score`      (`score`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Done! Now open config.php and set your credentials.
-- ============================================================
