<?php

declare(strict_types=1);

namespace Hostorio\Context;

/**
 * The assembled context handed to the model, plus an account of how it was built.
 *
 * The bookkeeping is not decoration: context is the single biggest cost driver
 * per question, and "why did this answer cost four times the last one" is
 * unanswerable without knowing what was attached and what was dropped.
 */
final class ContextBlock
{
    /**
     * @param array<int, array{name: string, body: string, tokens: int}> $sections
     * @param array<int, string> $sourcesUsed  human-readable citations
     * @param array<int, string> $dropped      sections/passages cut for budget
     */
    public function __construct(
        public readonly string $text,
        public readonly int $estimatedTokens,
        public readonly array $sections = [],
        public readonly array $sourcesUsed = [],
        public readonly array $dropped = [],
        public readonly bool $hasAccountContext = false,
        public readonly int $knowledgeChunks = 0,
        public readonly int $manualNotes = 0,
    ) {
    }

    public static function empty(): self
    {
        return new self('', 0);
    }

    public function isEmpty(): bool
    {
        return trim($this->text) === '';
    }

    /**
     * True when something was cut to fit the budget — worth surfacing in the
     * admin panel, since persistent trimming means the budget is too small for
     * the corpus and answers are being starved of context.
     */
    public function wasTrimmed(): bool
    {
        return $this->dropped !== [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'estimated_tokens'    => $this->estimatedTokens,
            'sections'            => array_map(
                static fn (array $s): array => ['name' => $s['name'], 'tokens' => $s['tokens']],
                $this->sections
            ),
            'sources_used'        => $this->sourcesUsed,
            'dropped'             => $this->dropped,
            'has_account_context' => $this->hasAccountContext,
            'knowledge_chunks'    => $this->knowledgeChunks,
            'manual_notes'        => $this->manualNotes,
            'trimmed'             => $this->wasTrimmed(),
        ];
    }
}
