<?php

declare(strict_types=1);

namespace Hostorio\Knowledge\Embeddings;

/**
 * The no-embeddings mode, and the default.
 *
 * Not a stub for tests — it is a supported production configuration. A hosting
 * FAQ is a small, vocabulary-consistent corpus where customers use the same
 * words as the documentation ("SSL", "cPanel", "nameserver"), which is exactly
 * where full-text search performs well. Requiring a third API key to answer
 * "how do I reset my password" would be a poor trade.
 *
 * Everything else in the pipeline is unchanged; only re-ranking is skipped.
 */
final class NullEmbedder implements EmbedderInterface
{
    public function name(): string
    {
        return 'none';
    }

    public function model(): string
    {
        return '';
    }

    public function isEnabled(): bool
    {
        return false;
    }

    /**
     * @param array<int, string> $texts
     * @return array<int, array<int, float>>
     */
    public function embedBatch(array $texts): array
    {
        return array_fill(0, count($texts), []);
    }

    /**
     * @return array<int, float>
     */
    public function embedQuery(string $text): array
    {
        return [];
    }

    public function costUsd(): float
    {
        return 0.0;
    }
}
