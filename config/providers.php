<?php

declare(strict_types=1);

use Hostorio\Core\Env;

/**
 * LLM provider credentials and endpoints.
 * Merged into Config under the `providers.` prefix.
 *
 * Phase 1 only stores and validates these. The adapters that consume them are
 * built in Phase 2 (multi-LLM routing engine).
 */
return [
    'default' => Env::get('DEFAULT_PROVIDER', 'deepseek'),

    'claude' => [
        'enabled'  => Env::has('ANTHROPIC_API_KEY'),
        'api_key'  => Env::get('ANTHROPIC_API_KEY', ''),
        'model'    => Env::get('CLAUDE_MODEL', 'claude-opus-4-8'),
        'endpoint' => 'https://api.anthropic.com/v1/messages',
        // Anthropic requires an explicit API version header on every request.
        'version'  => '2023-06-01',
        // Claude handles tool calls; the routing engine sends anything that
        // needs a real hosting action here regardless of cost.
        'supports_tools' => true,
    ],

    'deepseek' => [
        'enabled'        => Env::has('DEEPSEEK_API_KEY'),
        'api_key'        => Env::get('DEEPSEEK_API_KEY', ''),
        'model'          => Env::get('DEEPSEEK_MODEL', 'deepseek-chat'),
        'endpoint'       => 'https://api.deepseek.com/v1/chat/completions',
        // DeepSeek implements the OpenAI chat-completions protocol, which is
        // why it shares an adapter with OpenAI.
        'supports_tools'       => false,
        'token_param'          => 'max_tokens',
        'supports_temperature' => true,
    ],

    'openai' => [
        'enabled'        => Env::has('OPENAI_API_KEY'),
        'api_key'        => Env::get('OPENAI_API_KEY', ''),
        'model'          => Env::get('OPENAI_MODEL', 'gpt-5-nano'),
        'endpoint'       => 'https://api.openai.com/v1/chat/completions',
        'supports_tools' => true,

        /*
         * Newer OpenAI models renamed `max_tokens` to `max_completion_tokens`
         * and reject the old name, and accept only their default temperature.
         * These two settings exist so that can be corrected from config if the
         * defaults below are wrong for the model you configure — verify
         * against OpenAI's current API reference before relying on them.
         */
        'token_param'          => 'max_completion_tokens',
        'supports_temperature' => false,
    ],
];
