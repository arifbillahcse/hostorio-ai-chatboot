<?php

declare(strict_types=1);

namespace Hostorio\Llm\Providers;

/**
 * OpenAI adapter.
 *
 * Configured as a fallback rather than a primary: it sits between DeepSeek and
 * Claude on price without being clearly better than either at this workload.
 * Its value is availability — a third option when a provider has an outage.
 */
final class OpenAiProvider extends OpenAiCompatibleProvider
{
    public function name(): string
    {
        return 'openai';
    }

    protected function configPrefix(): string
    {
        return 'providers.openai';
    }
}
