-- =============================================================================
-- Hostorio AI Chatbot — Phase 3 schema (knowledge base)
--
-- Applies to the APPLICATION database only. WordPress and WHMCS are read from,
-- never written to.
--
-- Replace the `hoai_` prefix if DB_PREFIX in .env differs, or run:
--   php tools/install.php
-- =============================================================================

-- -----------------------------------------------------------------------------
-- One row per knowledge item.
--
-- Holds the canonical text for manual notes, and a synced copy for WordPress
-- articles. `content_hash` is what makes re-indexing cheap: an unchanged
-- document skips chunking and, more importantly, skips paying to re-embed it.
--
-- `priority` lets a hand-written note outrank a published article — the point
-- of the manual channel is to say things the website has not caught up with.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `hoai_knowledge_documents` (
    `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `source`       VARCHAR(20)     NOT NULL,           -- wordpress | manual
    `source_ref`   VARCHAR(64)     NOT NULL DEFAULT '',-- WP post id, or '' for manual
    `title`        VARCHAR(255)    NOT NULL DEFAULT '',
    `body`         LONGTEXT        NOT NULL,
    `url`          VARCHAR(500)    NULL,
    `priority`     TINYINT         NOT NULL DEFAULT 0, -- higher wins ties; manual notes default 10
    `content_hash` CHAR(64)        NOT NULL DEFAULT '',
    `is_active`    TINYINT(1)      NOT NULL DEFAULT 1,
    `expires_at`   DATETIME        NULL,               -- manual notes only; NULL = never
    `created_at`   DATETIME        NOT NULL,
    `updated_at`   DATETIME        NOT NULL,
    `indexed_at`   DATETIME        NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_source_ref` (`source`, `source_ref`),
    KEY `idx_active` (`is_active`, `expires_at`),
    KEY `idx_source` (`source`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Retrievable pieces of a document.
--
-- `embedding` is a packed little-endian float32 array, or NULL when running in
-- lexical-only mode (no embeddings API key configured). Storing it as a BLOB
-- rather than JSON keeps a 1536-dimension vector at 6 KB instead of ~20 KB,
-- which matters when a shared-hosting account has a disk quota.
--
-- The FULLTEXT index drives candidate retrieval; vector similarity re-ranks
-- those candidates. Doing it in that order means we never load every embedding
-- in the table into PHP memory.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `hoai_knowledge_chunks` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `document_id`     BIGINT UNSIGNED NOT NULL,
    `chunk_index`     INT UNSIGNED    NOT NULL DEFAULT 0,
    `content`         MEDIUMTEXT      NOT NULL,
    `char_count`      INT UNSIGNED    NOT NULL DEFAULT 0,
    `embedding`       BLOB            NULL,
    `embedding_model` VARCHAR(64)     NULL,
    `created_at`      DATETIME        NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_document` (`document_id`),
    FULLTEXT KEY `ft_content` (`content`),
    CONSTRAINT `fk_chunk_document`
        FOREIGN KEY (`document_id`) REFERENCES `hoai_knowledge_documents` (`id`)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Audit trail for indexing runs, so an admin can see when the knowledge base
-- last synced and whether it failed silently.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `hoai_index_runs` (
    `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `source`         VARCHAR(20)     NOT NULL,
    `documents_seen` INT UNSIGNED    NOT NULL DEFAULT 0,
    `documents_indexed` INT UNSIGNED NOT NULL DEFAULT 0,
    `documents_skipped` INT UNSIGNED NOT NULL DEFAULT 0,
    `chunks_written` INT UNSIGNED    NOT NULL DEFAULT 0,
    `chunks_embedded` INT UNSIGNED   NOT NULL DEFAULT 0,
    `embedding_cost_usd` DECIMAL(12, 6) NOT NULL DEFAULT 0.000000,
    `duration_ms`    INT UNSIGNED    NOT NULL DEFAULT 0,
    `success`        TINYINT(1)      NOT NULL DEFAULT 1,
    `message`        TEXT            NULL,
    `created_at`     DATETIME        NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `hoai_settings` (`setting_key`, `setting_value`, `updated_at`)
VALUES ('schema_version', '3', UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`), `updated_at` = UTC_TIMESTAMP();
