<?php

declare(strict_types=1);

namespace Hostorio\Llm;

/**
 * A normalised completion result.
 *
 * Every provider's response is flattened into this shape, so Phase 5 can treat
 * a DeepSeek answer and a Claude answer identically.
 */
final class LlmResponse
{
    /**
     * @param array<int, ToolCall> $toolCalls
     * @param array<int, string>   $attempts providers tried before this one succeeded
     */
    public function __construct(
        public readonly string $text,
        public readonly string $provider,
        public readonly string $model,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly string $stopReason,
        public readonly array $toolCalls = [],
        public readonly float $costUsd = 0.0,
        public readonly int $durationMs = 0,
        public readonly string $queryType = '',
        public readonly array $attempts = [],
    ) {
    }

    public function hasToolCalls(): bool
    {
        return $this->toolCalls !== [];
    }

    /**
     * True when the model stopped because it ran out of output budget, which
     * means `text` is a sentence fragment. Worth surfacing rather than showing
     * a truncated answer to a customer as if it were complete.
     */
    public function wasTruncated(): bool
    {
        return in_array($this->stopReason, ['max_tokens', 'length'], true);
    }

    public function totalTokens(): int
    {
        return $this->inputTokens + $this->outputTokens;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    public function with(array $overrides): self
    {
        return new self(
            $overrides['text']         ?? $this->text,
            $overrides['provider']     ?? $this->provider,
            $overrides['model']        ?? $this->model,
            $overrides['inputTokens']  ?? $this->inputTokens,
            $overrides['outputTokens'] ?? $this->outputTokens,
            $overrides['stopReason']   ?? $this->stopReason,
            $overrides['toolCalls']    ?? $this->toolCalls,
            $overrides['costUsd']      ?? $this->costUsd,
            $overrides['durationMs']   ?? $this->durationMs,
            $overrides['queryType']    ?? $this->queryType,
            $overrides['attempts']     ?? $this->attempts,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'text'          => $this->text,
            'provider'      => $this->provider,
            'model'         => $this->model,
            'input_tokens'  => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'stop_reason'   => $this->stopReason,
            'tool_calls'    => array_map(static fn (ToolCall $c): array => $c->toArray(), $this->toolCalls),
            'cost_usd'      => $this->costUsd,
            'duration_ms'   => $this->durationMs,
            'query_type'    => $this->queryType,
            'attempts'      => $this->attempts,
            'truncated'     => $this->wasTruncated(),
        ];
    }
}
