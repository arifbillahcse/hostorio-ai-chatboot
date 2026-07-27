<?php

declare(strict_types=1);

namespace Hostorio\Knowledge;

/**
 * A retrieved chunk with the scores that got it there.
 *
 * The component scores are kept rather than collapsed into one number so the
 * admin panel can show *why* a passage was chosen — which is the difference
 * between a retrieval system you can tune and one you have to trust blindly.
 */
final class SearchResult
{
    public function __construct(
        public readonly int $chunkId,
        public readonly int $documentId,
        public readonly string $source,
        public readonly string $title,
        public readonly string $content,
        public readonly ?string $url,
        public readonly int $priority,
        public readonly float $score,
        public readonly float $lexicalScore = 0.0,
        public readonly float $vectorScore = 0.0,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'chunk_id'      => $this->chunkId,
            'document_id'   => $this->documentId,
            'source'        => $this->source,
            'title'         => $this->title,
            'url'           => $this->url,
            'priority'      => $this->priority,
            'score'         => round($this->score, 4),
            'lexical_score' => round($this->lexicalScore, 4),
            'vector_score'  => round($this->vectorScore, 4),
            'content'       => $this->content,
        ];
    }
}
