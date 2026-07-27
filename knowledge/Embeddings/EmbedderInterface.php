<?php

declare(strict_types=1);

namespace Hostorio\Knowledge\Embeddings;

/**
 * Turns text into vectors for semantic search.
 *
 * Kept behind an interface because the choice is genuinely optional: Anthropic
 * has no embeddings endpoint, so a customer running only Claude and DeepSeek
 * has no embedding provider at all. NullEmbedder covers that case and the
 * knowledge base falls back to full-text search.
 */
interface EmbedderInterface
{
    public function name(): string;

    public function model(): string;

    /** False when no credentials are configured — retrieval stays lexical. */
    public function isEnabled(): bool;

    /**
     * Embed a batch of texts, preserving order.
     *
     * @param array<int, string> $texts
     * @return array<int, array<int, float>> one vector per input, in the same order
     *
     * @throws \Hostorio\Llm\LlmException
     */
    public function embedBatch(array $texts): array;

    /**
     * Embed a single query string.
     *
     * @return array<int, float>
     */
    public function embedQuery(string $text): array;

    /** Estimated USD spent by this instance so far. */
    public function costUsd(): float;
}
