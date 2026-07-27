<?php

declare(strict_types=1);

/**
 * Token rates used by CostTracker, in USD per 1,000,000 tokens.
 * Merged into Config under the `pricing.` prefix.
 *
 * These drive *estimates* only — the provider's invoice is authoritative.
 * Rates change; verify against each provider's pricing page and edit here.
 * The `_default` entry is used when a model id is not listed, so an unknown
 * model still produces an approximate figure instead of a silent zero.
 *
 *   Anthropic: https://claude.com/pricing
 *   DeepSeek:  https://api-docs.deepseek.com/quick_start/pricing
 *   OpenAI:    https://openai.com/api/pricing
 */
return [
    'claude' => [
        'claude-opus-4-8'  => ['input' => 5.00, 'output' => 25.00],
        'claude-opus-4-7'  => ['input' => 5.00, 'output' => 25.00],
        'claude-sonnet-5'  => ['input' => 3.00, 'output' => 15.00],
        'claude-haiku-4-5' => ['input' => 1.00, 'output' => 5.00],
        '_default'         => ['input' => 5.00, 'output' => 25.00],
    ],

    'deepseek' => [
        'deepseek-chat'     => ['input' => 0.28, 'output' => 0.42],
        'deepseek-reasoner' => ['input' => 0.28, 'output' => 0.42],
        '_default'          => ['input' => 0.28, 'output' => 0.42],
    ],

    'openai' => [
        // Verify before relying on these for budgeting.
        'gpt-5-nano' => ['input' => 0.05, 'output' => 0.40],
        '_default'   => ['input' => 0.05, 'output' => 0.40],
    ],
];
