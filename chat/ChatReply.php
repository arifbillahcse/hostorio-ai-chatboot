<?php

declare(strict_types=1);

namespace Hostorio\Chat;

/**
 * A finished answer, plus what it took to produce.
 */
final class ChatReply
{
    /**
     * @param array<int, string> $sources    citations that survived context trimming
     * @param array<int, array<string, mixed>> $toolCalls name and outcome per tool invoked
     */
    public function __construct(
        public readonly string $text,
        public readonly ?string $conversationId,
        public readonly string $provider,
        public readonly string $model,
        public readonly string $queryType,
        public readonly float $costUsd,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly int $contextTokens,
        public readonly array $sources = [],
        public readonly array $toolCalls = [],
        public readonly bool $truncated = false,
    ) {
    }

    /**
     * Shape returned to the widget.
     *
     * Cost and token counts are omitted deliberately — they are operator
     * metrics, and a customer-facing endpoint should not publish what each
     * answer costs to produce. The admin panel reads them from the database.
     *
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'answer'          => $this->text,
            'conversation_id' => $this->conversationId,
            'sources'         => $this->sources,
            'actions'         => array_map(
                static fn (array $c): array => ['name' => $c['name'], 'outcome' => $c['outcome']],
                $this->toolCalls
            ),
            'truncated'       => $this->truncated,
        ];
    }

    /**
     * Full detail, for the CLI and the admin panel.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->toPublicArray() + [
            'provider'       => $this->provider,
            'model'          => $this->model,
            'query_type'     => $this->queryType,
            'cost_usd'       => $this->costUsd,
            'input_tokens'   => $this->inputTokens,
            'output_tokens'  => $this->outputTokens,
            'context_tokens' => $this->contextTokens,
        ];
    }
}
