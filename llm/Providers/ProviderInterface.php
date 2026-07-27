<?php

declare(strict_types=1);

namespace Hostorio\Llm\Providers;

use Hostorio\Llm\LlmRequest;
use Hostorio\Llm\LlmResponse;

/**
 * One LLM backend. Adapters translate the neutral LlmRequest into the
 * provider's wire format and flatten its reply back into an LlmResponse.
 */
interface ProviderInterface
{
    /** Short stable key: claude | deepseek | openai. Used in config and logs. */
    public function name(): string;

    /** The model id this provider will call. */
    public function model(): string;

    /** True when an API key is present. */
    public function isEnabled(): bool;

    /** True when this provider can be given tool definitions. */
    public function supportsTools(): bool;

    /**
     * Execute a completion.
     *
     * @throws \Hostorio\Llm\LlmException
     */
    public function complete(LlmRequest $request): LlmResponse;
}
