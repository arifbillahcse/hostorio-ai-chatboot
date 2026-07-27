-- =============================================================================
-- Hostorio AI Chatbot — Phase 5 schema (conversations and tool audit)
--
-- Application database only.
-- Run: php tools/install.php   (or import here, adjusting the hoai_ prefix)
-- =============================================================================

-- -----------------------------------------------------------------------------
-- One conversation thread.
--
-- `public_id` is what the browser sees. The auto-increment `id` never leaves the
-- server: a sequential id in a URL invites walking the range to read other
-- people's conversations.
--
-- `customer_id` is only ever written from a *verified* identity (see
-- Hostorio\Context\CustomerIdentity), which is what makes the ownership check
-- on resume meaningful.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `hoai_conversations` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `public_id`   CHAR(32)        NOT NULL,
    `customer_id` INT UNSIGNED    NULL,          -- NULL for anonymous visitors
    `client_key`  CHAR(64)        NOT NULL DEFAULT '',  -- hashed IP for anonymous threads
    `title`       VARCHAR(255)    NOT NULL DEFAULT '',
    `message_count` INT UNSIGNED  NOT NULL DEFAULT 0,
    `total_cost_usd` DECIMAL(12, 6) NOT NULL DEFAULT 0.000000,
    `created_at`  DATETIME        NOT NULL,
    `updated_at`  DATETIME        NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_public_id` (`public_id`),
    KEY `idx_customer` (`customer_id`, `updated_at`),
    KEY `idx_updated` (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Turns within a conversation.
--
-- `content_blocks` holds the structured form (tool_use / tool_result) when a
-- turn has one; `content` always holds the plain text so history can be
-- reconstructed cheaply without decoding JSON.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `hoai_messages` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `conversation_id` BIGINT UNSIGNED NOT NULL,
    `role`            VARCHAR(16)     NOT NULL,   -- user | assistant
    `content`         MEDIUMTEXT      NOT NULL,
    `content_blocks`  JSON            NULL,
    `provider`        VARCHAR(32)     NULL,
    `model`           VARCHAR(64)     NULL,
    `query_type`      VARCHAR(20)     NULL,
    `input_tokens`    INT UNSIGNED    NOT NULL DEFAULT 0,
    `output_tokens`   INT UNSIGNED    NOT NULL DEFAULT 0,
    `cost_usd`        DECIMAL(12, 6)  NOT NULL DEFAULT 0.000000,
    `context_tokens`  INT UNSIGNED    NOT NULL DEFAULT 0,
    `created_at`      DATETIME        NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_conversation` (`conversation_id`, `id`),
    CONSTRAINT `fk_message_conversation`
        FOREIGN KEY (`conversation_id`) REFERENCES `hoai_conversations` (`id`)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Audit trail for every tool the model invoked.
--
-- Separate from the message log and deliberately append-only in practice: when
-- a customer's password was reset or a service restarted, "the chatbot did it"
-- is not an acceptable answer. This records who, what, whether it was
-- authorised, and what came back.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `hoai_tool_invocations` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `conversation_id` BIGINT UNSIGNED NULL,
    `request_id`      CHAR(16)        NOT NULL DEFAULT '',
    `customer_id`     INT UNSIGNED    NULL,
    `tool_name`       VARCHAR(64)     NOT NULL,
    `arguments`       JSON            NULL,
    `destructive`     TINYINT(1)      NOT NULL DEFAULT 0,
    `outcome`         VARCHAR(32)     NOT NULL,   -- ok | denied | needs_confirmation | error | unknown_tool
    `detail`          TEXT            NULL,
    `duration_ms`     INT UNSIGNED    NOT NULL DEFAULT 0,
    `created_at`      DATETIME        NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_customer_created` (`customer_id`, `created_at`),
    KEY `idx_tool_created` (`tool_name`, `created_at`),
    KEY `idx_outcome` (`outcome`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `hoai_settings` (`setting_key`, `setting_value`, `updated_at`)
VALUES ('schema_version', '5', UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`), `updated_at` = UTC_TIMESTAMP();
