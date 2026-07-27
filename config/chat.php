<?php

declare(strict_types=1);

use Hostorio\Core\Env;

/**
 * Chat engine settings. Merged into Config under the `chat.` prefix.
 */
return [
    /* Used in the system prompt so the assistant introduces itself correctly. */
    'company_name' => Env::get('CHAT_COMPANY_NAME', Env::get('APP_NAME', 'our hosting company')),

    /*
     * Prior turns replayed into each request.
     *
     * Every turn is re-sent and re-billed on every message, so this is a direct
     * multiplier on cost. Ten turns is enough for a support exchange to stay
     * coherent without paying to re-read an hour-old conversation.
     */
    'history_turns' => Env::int('CHAT_HISTORY_TURNS', 10),

    'tools' => [
        /* Master switch. Tools are never offered to unverified visitors. */
        'enabled' => Env::bool('CHAT_TOOLS_ENABLED', true),

        /*
         * Destructive tools (password reset, service restart).
         *
         * Off by default, and they need more than this flag: an
         * ActionExecutorInterface implementation must also be registered, or
         * they refuse honestly rather than pretending to work. Turning this on
         * without one only changes what the model is told it can attempt.
         */
        'enable_actions' => Env::bool('CHAT_ENABLE_ACTIONS', false),

        /*
         * Maximum model round trips per question when tools are in play.
         *
         * Each round is a full paid request. Three allows: call a tool, read
         * the result, answer — with one spare for a follow-up lookup.
         */
        'max_rounds' => Env::int('CHAT_TOOL_MAX_ROUNDS', 3),
    ],
];
