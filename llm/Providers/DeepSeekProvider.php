<?php

declare(strict_types=1);

namespace Hostorio\Llm\Providers;

/**
 * DeepSeek adapter.
 *
 * The cost workhorse — roughly an order of magnitude cheaper than Claude — so
 * the router sends it every straightforward question. Speaks the OpenAI
 * chat-completions protocol, so it needs no code of its own beyond naming.
 */
final class DeepSeekProvider extends OpenAiCompatibleProvider
{
    public function name(): string
    {
        return 'deepseek';
    }

    protected function configPrefix(): string
    {
        return 'providers.deepseek';
    }
}
