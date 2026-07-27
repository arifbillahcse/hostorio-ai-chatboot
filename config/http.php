<?php

declare(strict_types=1);

use Hostorio\Core\Env;

/**
 * Outbound HTTP settings for LLM API calls.
 * Merged into Config under the `http.` prefix.
 *
 * Timeouts matter more than usual here: shared hosting typically kills PHP at
 * 30-60s, so the LLM timeout must stay below that or the customer sees a blank
 * page instead of an error message.
 */
return [
    'timeout'         => Env::int('HTTP_TIMEOUT', 60),
    'connect_timeout' => Env::int('HTTP_CONNECT_TIMEOUT', 10),
    'max_retries'     => Env::int('HTTP_MAX_RETRIES', 2),
    'user_agent'      => 'Hostorio-AI-Chatbot/0.1',
];
