<?php

declare(strict_types=1);

/**
 * Query classification and provider routing.
 * Merged into Config under the `routing.` prefix.
 *
 * The whole point of this file is cost. A hosting support inbox is mostly
 * repetitive questions that a cheap model answers perfectly well; only a
 * minority need an expensive one. Routing on that split is what makes running
 * the chatbot affordable at volume.
 *
 * Everything here is editable without touching code — Phase 7 exposes it in
 * the admin panel.
 */
return [
    /*
     * Provider preference per query type, in order. The router walks the list
     * and uses the first provider that is enabled and capable, so these double
     * as the fallback chain.
     *
     * On `max_tokens`: this is a ceiling, not a charge — you pay for what is
     * actually generated, so a generous limit costs nothing on an ordinary
     * model. It has to be generous because *reasoning* models (OpenAI's gpt-5
     * family, and anything else that thinks before it answers) bill their
     * internal reasoning against this same budget while producing no visible
     * text. Set it too low and the model spends the entire allowance thinking,
     * then returns an empty answer with a "length" finish reason — a blank
     * chat bubble that still costs money. Leave room for the thinking as well
     * as the reply.
     */
    'rules' => [
        // Anything that changes the customer's account. Tool-calling
        // reliability matters far more than price here: a model that fumbles
        // the arguments to a password reset costs a support ticket, which is
        // more expensive than every token it saved.
        'action' => [
            'providers'  => ['claude', 'openai'],
            'max_tokens' => 4096,
            'thinking'   => false,
        ],

        // Troubleshooting. Worth a stronger model and extended thinking: a
        // wrong diagnosis sends the customer in the wrong direction and
        // generates the ticket the chatbot existed to prevent.
        'complex' => [
            'providers'  => ['claude', 'deepseek', 'openai'],
            'max_tokens' => 8192,
            'thinking'   => true,
        ],

        // Ordinary questions — the bulk of the volume, on the cheap model.
        'simple' => [
            'providers'  => ['deepseek', 'openai', 'claude'],
            'max_tokens' => 4096,
            'thinking'   => false,
        ],
    ],

    /*
     * Classifier signals. Matched case-insensitively as whole words against the
     * customer's message.
     *
     * Deliberately keyword-based rather than an LLM call: classifying with a
     * model would add a paid round trip to every single question, which is the
     * cost this routing exists to avoid.
     */
    'keywords' => [
        // Checked first — highest stakes.
        'action' => [
            'reset my password', 'reset password', 'change my password',
            'restart', 'reboot', 'reinstall',
            'suspend', 'unsuspend', 'terminate', 'cancel my', 'cancel the',
            'upgrade my', 'downgrade my', 'renew my',
            'create a', 'delete my', 'remove my',
            'enable', 'disable', 'activate', 'deactivate',
            'refund', 'issue a refund',
            'please do', 'can you do', 'do it for me',
        ],

        // Checked second.
        'complex' => [
            'why', 'not working', "doesn't work", 'does not work',
            'error', 'failed', 'failure', 'broken', 'crash',
            "can't", 'cannot', 'unable to',
            'slow', 'down', 'offline', 'unreachable', 'timeout', 'timed out',
            'troubleshoot', 'diagnose', 'debug',
            '500', '502', '503', '504', 'internal server error',
            'ssl', 'certificate', 'dns', 'propagation',
            'database connection', 'permission denied',
        ],
    ],

    /*
     * A message longer than this is treated as complex regardless of keywords —
     * customers write at length when something is actually wrong.
     */
    'complex_length_threshold' => 320,

    /*
     * Fall back to this classification when nothing matches.
     */
    'default_type' => 'simple',

    /*
     * Temperature sent to providers that accept one. Low, because support
     * answers should be consistent and grounded rather than creative.
     * Set to null to send no temperature at all.
     */
    'temperature' => 0.3,
];
