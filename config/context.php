<?php

declare(strict_types=1);

use Hostorio\Core\Env;

/**
 * Retrieval and context assembly. Merged into Config under the `context.` prefix.
 */
return [
    /*
     * Token budget for the retrieved context block — not the whole prompt.
     *
     * This is the main cost lever in the whole system. Every question pays for
     * every token of context attached to it, so an unbounded context block
     * turns a cheap DeepSeek answer into an expensive one.
     */
    'max_tokens' => 3000,

    /*
     * Rough characters-per-token divisor used for budgeting.
     *
     * Deliberately conservative: ~4 is right for English and over-estimates
     * token count for the multibyte scripts our customers write in, which is
     * the safe direction to be wrong in — over-estimating trims a chunk we
     * could have afforded, under-estimating overflows the model's window.
     */
    'chars_per_token' => 4,

    /*
     * Per-section caps, applied before the global budget.
     *
     * Account context is small and high-value per token, so it is never the
     * thing that gets trimmed; knowledge-base passages are trimmed first.
     */
    'limits' => [
        'kb_chunks'        => 6,
        'chunks_per_document' => 2,   // stops one long article filling the block
        'manual_notes'     => 4,
        'services'         => 8,
        'tickets'          => 3,
        'invoices'         => 3,
        'chunk_chars'      => 1500,   // hard cap on any single retrieved passage
    ],

    /*
     * Customer identity.
     *
     * A customer id arriving in a request body is a claim, not a fact. Account
     * context is only ever attached for an identity proved by a signed token or
     * a server-side session — otherwise any visitor could read any customer's
     * billing data by changing a number.
     */
    'identity' => [
        // Seconds a signed identity token stays valid. Short by design: the
        // token is minted per page load by the hosting company's own template.
        'token_ttl'     => Env::int('CHAT_TOKEN_TTL', 3600),
        // Allow a PHP session to establish identity (for same-server installs
        // where WHMCS/WordPress already authenticated the visitor).
        'allow_session' => Env::bool('CHAT_ALLOW_SESSION_IDENTITY', true),
        'session_key'   => 'hoai_customer_id',
    ],

    /*
     * Which account sections to include when identity is proved.
     */
    'include' => [
        'services' => true,
        'domains'  => true,
        'tickets'  => true,
        'invoices' => true,
    ],
];
