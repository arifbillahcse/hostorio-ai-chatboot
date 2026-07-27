<?php

declare(strict_types=1);

namespace Hostorio\Knowledge;

use Hostorio\Core\Config;

/**
 * Combines full-text and vector scores into a final ranking.
 *
 * Kept apart from the database layer on purpose: ranking is where retrieval
 * quality is actually decided, and it is only testable if it does not need a
 * live MySQL to run.
 *
 * Scores from the two systems are not comparable as-is — MySQL's full-text
 * relevance is an unbounded positive number while cosine similarity sits in
 * [-1, 1] — so each is normalised into [0, 1] before they are blended.
 */
final class Ranker
{
    /**
     * @param array<int, array<string, mixed>> $candidates rows from the store,
     *        each with: chunk_id, document_id, source, title, content, url,
     *        priority, lexical_score, embedding (packed string or null)
     * @param array<int, float> $queryVector empty in lexical-only mode
     * @return array<int, SearchResult> highest scoring first
     */
    public function rank(array $candidates, array $queryVector = [], ?int $limit = null): array
    {
        if ($candidates === []) {
            return [];
        }

        $limit ??= max(1, (int) Config::get('knowledge.search.results', 6));

        $useVectors = $queryVector !== [];

        $lexicalWeight  = (float) Config::get('knowledge.search.lexical_weight', 0.4);
        $vectorWeight   = (float) Config::get('knowledge.search.vector_weight', 0.6);
        $priorityWeight = (float) Config::get('knowledge.search.priority_weight', 0.02);

        // With no vectors to blend, lexical relevance carries the whole ranking.
        if (!$useVectors) {
            $lexicalWeight = 1.0;
            $vectorWeight  = 0.0;
        }

        $normalizedQuery = $useVectors ? VectorMath::normalize($queryVector) : [];
        $maxLexical      = 0.0;

        foreach ($candidates as $candidate) {
            $maxLexical = max($maxLexical, (float) ($candidate['lexical_score'] ?? 0.0));
        }

        $results = [];

        foreach ($candidates as $candidate) {
            $lexical = $maxLexical > 0.0
                ? ((float) ($candidate['lexical_score'] ?? 0.0)) / $maxLexical
                : 0.0;

            $vector = 0.0;

            if ($useVectors) {
                $packed = $candidate['embedding'] ?? null;

                if (is_string($packed) && $packed !== '') {
                    // Negative cosine means "unrelated", not "worse than
                    // nothing" — clamping avoids a negative term dragging a
                    // strong lexical match below a weak one.
                    $vector = max(0.0, VectorMath::cosineWithNormalized(
                        $normalizedQuery,
                        VectorMath::unpack($packed)
                    ));
                }
            }

            $priority = (int) ($candidate['priority'] ?? 0);

            $score = ($lexical * $lexicalWeight)
                   + ($vector * $vectorWeight)
                   + ($priority * $priorityWeight);

            $results[] = new SearchResult(
                chunkId: (int) ($candidate['chunk_id'] ?? 0),
                documentId: (int) ($candidate['document_id'] ?? 0),
                source: (string) ($candidate['source'] ?? ''),
                title: (string) ($candidate['title'] ?? ''),
                content: (string) ($candidate['content'] ?? ''),
                url: isset($candidate['url']) && is_string($candidate['url']) ? $candidate['url'] : null,
                priority: $priority,
                score: $score,
                lexicalScore: $lexical,
                vectorScore: $vector,
            );
        }

        usort($results, static function (SearchResult $a, SearchResult $b): int {
            // Break exact ties by priority so a manual note wins over an
            // article that scored identically.
            return $b->score <=> $a->score ?: $b->priority <=> $a->priority;
        });

        return array_slice($results, 0, $limit);
    }
}
