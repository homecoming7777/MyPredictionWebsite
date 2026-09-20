-- STEP 1 — FOUNDATION DATABASE CHANGES
-- Run this once on your MySQL/MariaDB database.
-- Safe to re-run: uses IF NOT EXISTS / conditional column checks where possible.

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

-- ---------------------------------------------------------------------------
-- users: role + prediction rating
-- ---------------------------------------------------------------------------

SET @role_col_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'role'
);

SET @sql_role = IF(
    @role_col_exists = 0,
    "ALTER TABLE `users` ADD COLUMN `role` VARCHAR(20) NOT NULL DEFAULT 'user' AFTER `password`",
    "SELECT 'users.role already exists' AS info"
);

PREPARE stmt_role FROM @sql_role;
EXECUTE stmt_role;
DEALLOCATE PREPARE stmt_role;

SET @rating_col_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'prediction_rating'
);

SET @sql_rating = IF(
    @rating_col_exists = 0,
    "ALTER TABLE `users` ADD COLUMN `prediction_rating` INT NOT NULL DEFAULT 1500 AFTER `role`",
    "SELECT 'users.prediction_rating already exists' AS info"
);

PREPARE stmt_rating FROM @sql_rating;
EXECUTE stmt_rating;
DEALLOCATE PREPARE stmt_rating;

UPDATE `users`
SET `role` = 'admin'
WHERE `id` = 1;

UPDATE `users`
SET `prediction_rating` = 1500
WHERE `prediction_rating` IS NULL OR `prediction_rating` <= 0;

-- ---------------------------------------------------------------------------
-- user_rating_history
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `user_rating_history` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int(10) UNSIGNED NOT NULL,
  `match_id` int(10) UNSIGNED DEFAULT NULL,
  `rating_before` int NOT NULL DEFAULT 1500,
  `rating_after` int NOT NULL DEFAULT 1500,
  `rating_change` int NOT NULL DEFAULT 0,
  `reason` varchar(50) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_rating_history_user` (`user_id`),
  KEY `idx_rating_history_match` (`match_id`),
  KEY `idx_rating_history_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- match_intelligence_cache
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `match_intelligence_cache` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `cache_key` varchar(191) NOT NULL,
  `match_id` int(10) UNSIGNED DEFAULT NULL,
  `team_name` varchar(100) DEFAULT NULL,
  `payload_json` longtext NOT NULL,
  `source` varchar(50) DEFAULT NULL,
  `fetched_at` timestamp NULL DEFAULT current_timestamp(),
  `expires_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_cache_key` (`cache_key`),
  KEY `idx_intel_match` (`match_id`),
  KEY `idx_intel_team` (`team_name`),
  KEY `idx_intel_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- match_difficulty
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `match_difficulty` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `match_id` int(10) UNSIGNED NOT NULL,
  `difficulty_score` tinyint UNSIGNED DEFAULT NULL,
  `difficulty_label` varchar(20) DEFAULT NULL,
  `factors_json` longtext DEFAULT NULL,
  `community_correct_pct` decimal(5,2) DEFAULT NULL,
  `calculated_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_match_difficulty` (`match_id`),
  KEY `idx_difficulty_label` (`difficulty_label`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- match_reactions
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `match_reactions` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int(10) UNSIGNED NOT NULL,
  `match_id` int(10) UNSIGNED NOT NULL,
  `reaction_type` varchar(20) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_match_reaction` (`user_id`, `match_id`, `reaction_type`),
  KEY `idx_reactions_match` (`match_id`),
  KEY `idx_reactions_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
