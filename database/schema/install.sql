-- =============================================================================
-- Hostorio AI Chatbot — Phase 1 schema
--
-- Applies to the APPLICATION database only. Nothing here touches the
-- WordPress or WHMCS databases, which the chatbot only ever reads.
--
-- Replace the `hoai_` prefix below if DB_PREFIX in .env differs.
-- Install via phpMyAdmin, or run: php tools/install.php
-- =============================================================================

-- -----------------------------------------------------------------------------
-- Key/value store for runtime settings edited from the admin panel.
-- Kept separate from .env: .env holds secrets and deployment config, this holds
-- settings a non-technical admin may change without file access.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `hoai_settings` (
    `setting_key`   VARCHAR(100) NOT NULL,
    `setting_value` LONGTEXT     NULL,
    `updated_at`    DATETIME     NOT NULL,
    PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Fixed-window rate limiting counters.
-- `bucket_key` is a hash of identity + window start, so the unique index gives
-- us an atomic INSERT ... ON DUPLICATE KEY UPDATE increment.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `hoai_rate_limits` (
    `bucket_key`   CHAR(64)        NOT NULL,
    `hits`         INT UNSIGNED    NOT NULL DEFAULT 0,
    `window_start` INT UNSIGNED    NOT NULL,
    `expires_at`   INT UNSIGNED    NOT NULL,
    PRIMARY KEY (`bucket_key`),
    KEY `idx_expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Per-call token usage and estimated spend.
-- `cost_usd` is DECIMAL rather than FLOAT so summing thousands of small values
-- does not accumulate rounding error in the admin dashboard.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `hoai_api_costs` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `request_id`    CHAR(16)        NOT NULL,
    `provider`      VARCHAR(32)     NOT NULL,
    `model`         VARCHAR(64)     NOT NULL,
    `input_tokens`  INT UNSIGNED    NOT NULL DEFAULT 0,
    `output_tokens` INT UNSIGNED    NOT NULL DEFAULT 0,
    `cost_usd`      DECIMAL(12, 6)  NOT NULL DEFAULT 0.000000,
    `duration_ms`   INT UNSIGNED    NOT NULL DEFAULT 0,
    `success`       TINYINT(1)      NOT NULL DEFAULT 1,
    `meta`          JSON            NULL,
    `created_at`    DATETIME        NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_created_at` (`created_at`),
    KEY `idx_provider_created` (`provider`, `created_at`),
    KEY `idx_request_id` (`request_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Seed row so AppDatabase::isInstalled() has something to detect.
-- -----------------------------------------------------------------------------
INSERT INTO `hoai_settings` (`setting_key`, `setting_value`, `updated_at`)
VALUES ('schema_version', '1', UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`), `updated_at` = UTC_TIMESTAMP();
