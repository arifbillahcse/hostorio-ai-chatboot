<?php

declare(strict_types=1);

namespace Hostorio\Context;

use Hostorio\Core\Config;
use Hostorio\Core\Logger;
use Hostorio\Knowledge\Document;
use Hostorio\Knowledge\Indexer;
use Hostorio\Knowledge\SearchResult;
use Throwable;

/**
 * Finds the passages worth showing the model.
 *
 * Thin on top of the Phase 3 indexer, but it adds the two things raw search
 * results are not ready for:
 *
 *  - a per-document cap, so one long article cannot occupy the whole context
 *    block and crowd out a shorter page that answers the question better;
 *  - separation of manual notes from published articles, because they are
 *    presented differently in the prompt — a current outage notice needs to
 *    read as authoritative, not as one more search hit.
 */
final class Retriever
{
    private readonly Indexer $indexer;

    public function __construct(?Indexer $indexer = null)
    {
        $this->indexer = $indexer ?? new Indexer();
    }

    /**
     * @return array{notes: array<int, SearchResult>, articles: array<int, SearchResult>}
     */
    public function retrieve(string $question): array
    {
        $limit           = max(1, (int) Config::get('context.limits.kb_chunks', 6));
        $perDocument     = max(1, (int) Config::get('context.limits.chunks_per_document', 2));
        $noteLimit       = max(0, (int) Config::get('context.limits.manual_notes', 4));

        try {
            // Over-fetch, because the per-document cap will discard some.
            $results = $this->indexer->search($question, ($limit + $noteLimit) * $perDocument + $limit);
        } catch (Throwable $e) {
            // A knowledge-base outage should degrade the answer, not block it.
            // The model can still respond from the system prompt and account
            // context; returning nothing here is strictly better than 500ing.
            Logger::error('Knowledge retrieval failed; continuing without it', [
                'error' => $e->getMessage(),
            ]);

            return ['notes' => [], 'articles' => []];
        }

        $results = $this->capPerDocument($results, $perDocument);

        $notes    = [];
        $articles = [];

        foreach ($results as $result) {
            if ($result->source === Document::SOURCE_MANUAL) {
                if (count($notes) < $noteLimit) {
                    $notes[] = $result;
                }

                continue;
            }

            if (count($articles) < $limit) {
                $articles[] = $result;
            }
        }

        Logger::debug('Retrieval complete', [
            'notes'    => count($notes),
            'articles' => count($articles),
        ]);

        return ['notes' => $notes, 'articles' => $articles];
    }

    /**
     * Keep at most N chunks from any one document, preserving score order.
     *
     * @param array<int, SearchResult> $results
     * @return array<int, SearchResult>
     */
    private function capPerDocument(array $results, int $perDocument): array
    {
        $seen = [];
        $kept = [];

        foreach ($results as $result) {
            $count = $seen[$result->documentId] ?? 0;

            if ($count >= $perDocument) {
                continue;
            }

            $seen[$result->documentId] = $count + 1;
            $kept[] = $result;
        }

        return $kept;
    }
}
