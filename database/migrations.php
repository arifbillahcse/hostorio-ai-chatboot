<?php

declare(strict_types=1);

/**
 * Schema migrations, in order.
 *
 * Each entry moves the database from the previous version to its own. The
 * runner applies every migration above the recorded schema version, so an
 * install two releases behind catches up in one pass.
 *
 * Rules for adding one:
 *
 *  - Statements must be safe to re-run. An update interrupted by a shared-host
 *    timeout will be resumed by the customer, and it will start from whatever
 *    version was last recorded — which may be before a partly-applied step.
 *    Use IF NOT EXISTS, and guard ADD COLUMN with a lookup.
 *  - Never destroy data in a migration. Dropping a column that a rolled-back
 *    release still reads turns a bad update into an unrecoverable one. Add,
 *    backfill, and remove it a release later once nothing reads it.
 *
 * `{prefix}` is replaced with the install's configured table prefix.
 */

return [
    /*
     * Versions 1, 3 and 5 are the baseline created by install.sql, phase3.sql
     * and phase5.sql. They are listed so a fresh install and an upgraded one
     * converge on the same recorded version.
     */
    5 => [
        'description' => 'Baseline schema (conversations, knowledge base, costs, tools)',
        'statements'  => [],
    ],

    /*
     * Example of the shape a real migration takes. Kept deliberately trivial so
     * the runner is exercised on a fresh install without changing anything.
     */
    6 => [
        'description' => 'Index conversations by customer for faster admin filtering',
        'statements'  => [
            // MySQL has no CREATE INDEX IF NOT EXISTS, so the runner tolerates
            // a duplicate-key error on index creation specifically.
            'CREATE INDEX `idx_customer_updated` ON `{prefix}conversations` (`customer_id`, `updated_at`)',
        ],
        // Errors matching these fragments mean "already applied" rather than
        // "failed", which is what makes re-running safe.
        'ignore_errors' => ['Duplicate key name', 'already exists'],
    ],
];
