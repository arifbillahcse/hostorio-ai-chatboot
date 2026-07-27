<?php

declare(strict_types=1);

namespace Hostorio\Knowledge;

use Hostorio\Core\Config;
use Hostorio\Core\Logger;
use Hostorio\Knowledge\Embeddings\EmbedderInterface;
use Hostorio\Knowledge\Embeddings\NullEmbedder;
use Hostorio\Knowledge\Embeddings\OpenAiEmbedder;
use Hostorio\Knowledge\Sources\WordPressSource;
use Throwable;

/**
 * Builds and refreshes the knowledge base.
 *
 * Two separable stages, on purpose:
 *
 *  1. Sync — pull documents from their source into our tables. Cheap, safe to
 *     run often.
 *  2. Index — chunk and embed anything whose content changed. This is the stage
 *     that costs money, so it is driven strictly by content hashes: a sync that
 *     finds nothing edited performs no embedding calls at all.
 *
 * Splitting them also means a sync interrupted by a shared-hosting timeout can
 * be resumed — the next run picks up whatever is still pending rather than
 * starting over.
 */
class Indexer
{
    private readonly KnowledgeStore $store;
    private readonly EmbedderInterface $embedder;

    public function __construct(
        ?KnowledgeStore $store = null,
        ?EmbedderInterface $embedder = null,
        private readonly Chunker $chunker = new Chunker(),
        private readonly TextNormalizer $normalizer = new TextNormalizer(),
    ) {
        $this->store    = $store ?? new KnowledgeStore();
        $this->embedder = $embedder ?? self::resolveEmbedder();
    }

    /**
     * Pick an embedder from config. Falls back to lexical-only mode rather than
     * failing, so a missing embeddings key degrades retrieval instead of
     * breaking the chatbot.
     */
    public static function resolveEmbedder(): EmbedderInterface
    {
        $driver = strtolower((string) Config::get('knowledge.embeddings.driver', 'none'));

        if ($driver === 'openai') {
            $embedder = new OpenAiEmbedder();

            if ($embedder->isEnabled()) {
                return $embedder;
            }

            Logger::warning('Embedding driver is "openai" but no key is configured; using lexical search only');
        }

        return new NullEmbedder();
    }

    public function embedder(): EmbedderInterface
    {
        return $this->embedder;
    }

    /**
     * Full refresh: sync every source, index what changed, drop expired chunks.
     *
     * @return array<string, mixed>
     */
    public function run(): array
    {
        $started = microtime(true);

        $sync  = $this->syncWordPress();
        $index = $this->indexPending();
        $pruned = 0;

        try {
            $pruned = $this->store->pruneExpired();
        } catch (Throwable $e) {
            Logger::warning('Could not prune expired chunks', ['error' => $e->getMessage()]);
        }

        $summary = [
            'wordpress'         => $sync,
            'documents_indexed' => $index['documents'],
            'chunks_written'    => $index['chunks'],
            'chunks_embedded'   => $index['embedded'],
            'embedding_cost_usd' => $index['cost_usd'],
            'expired_chunks_pruned' => $pruned,
            'embedder'          => $this->embedder->name(),
            'duration_ms'       => (int) round((microtime(true) - $started) * 1000),
        ];

        $this->store->recordRun([
            'source'             => 'all',
            'documents_seen'     => (int) ($sync['fetched'] ?? 0),
            'documents_indexed'  => $index['documents'],
            'documents_skipped'  => (int) ($sync['unchanged'] ?? 0),
            'chunks_written'     => $index['chunks'],
            'chunks_embedded'    => $index['embedded'],
            'embedding_cost_usd' => $index['cost_usd'],
            'duration_ms'        => $summary['duration_ms'],
            'success'            => 1,
            'message'            => null,
        ]);

        Logger::info('Index run finished', $summary);

        return $summary;
    }

    /**
     * Pull WordPress content into our documents table.
     *
     * @return array<string, mixed>
     */
    public function syncWordPress(): array
    {
        $source = WordPressSource::make();

        if (!$source->isAvailable()) {
            return ['available' => false, 'fetched' => 0, 'changed' => 0, 'unchanged' => 0, 'deactivated' => 0];
        }

        try {
            $documents = $source->fetch();
        } catch (Throwable $e) {
            Logger::error('WordPress sync failed', ['error' => $e->getMessage()]);

            return ['available' => true, 'error' => $e->getMessage(), 'fetched' => 0, 'changed' => 0, 'unchanged' => 0, 'deactivated' => 0];
        }

        $changed   = 0;
        $unchanged = 0;
        $seenRefs  = [];

        foreach ($documents as $document) {
            $seenRefs[] = $document->sourceRef;

            $result = $this->store->upsertDocument($document, $document->hash($this->normalizer));

            if ($result['changed']) {
                $changed++;
            } else {
                $unchanged++;
            }
        }

        // Articles deleted or unpublished upstream stop being retrievable.
        $deactivated = $this->store->deactivateMissing(Document::SOURCE_WORDPRESS, $seenRefs);

        return [
            'available'   => true,
            'fetched'     => count($documents),
            'changed'     => $changed,
            'unchanged'   => $unchanged,
            'deactivated' => $deactivated,
        ];
    }

    /**
     * Chunk and embed every document awaiting indexing.
     *
     * @return array{documents: int, chunks: int, embedded: int, cost_usd: float}
     */
    public function indexPending(int $limit = 500): array
    {
        $pending = $this->store->pendingDocuments($limit);

        $documents = 0;
        $chunkCount = 0;
        $embeddedCount = 0;

        foreach ($pending as $row) {
            $documentId = (int) ($row['id'] ?? 0);

            if ($documentId <= 0) {
                continue;
            }

            $document = new Document(
                source: (string) ($row['source'] ?? ''),
                sourceRef: (string) ($row['source_ref'] ?? ''),
                title: (string) ($row['title'] ?? ''),
                body: (string) ($row['body'] ?? ''),
                url: isset($row['url']) && is_string($row['url']) ? $row['url'] : null,
                priority: (int) ($row['priority'] ?? 0),
            );

            $chunks = $this->chunker->chunk($document->indexableText());

            if ($chunks === []) {
                // Still mark it done so an empty document is not retried forever.
                $this->store->replaceChunks($documentId, []);
                $documents++;
                continue;
            }

            $vectors = $this->embed($chunks);

            // Only claim a model when one actually produced vectors, so a
            // lexical-mode row never carries a misleading embedding_model.
            $model = $this->embedder->isEnabled() ? $this->embedder->model() : '';

            $written = $this->store->replaceChunks($documentId, $chunks, $vectors, $model);

            $documents++;
            $chunkCount += $written;
            $embeddedCount += count(array_filter($vectors, static fn (array $v): bool => $v !== []));
        }

        return [
            'documents' => $documents,
            'chunks'    => $chunkCount,
            'embedded'  => $embeddedCount,
            'cost_usd'  => $this->embedder->costUsd(),
        ];
    }

    /**
     * Embed chunks in batches.
     *
     * A failed batch degrades to no vectors for those chunks rather than
     * aborting the run: a knowledge base searchable by keyword is far better
     * than one that failed to build because the embeddings API had a bad
     * minute.
     *
     * @param array<int, string> $chunks
     * @return array<int, array<int, float>>
     */
    private function embed(array $chunks): array
    {
        if (!$this->embedder->isEnabled()) {
            return array_fill(0, count($chunks), []);
        }

        $batchSize = max(1, (int) Config::get('knowledge.embeddings.batch_size', 32));
        $vectors   = [];

        foreach (array_chunk($chunks, $batchSize, true) as $batch) {
            try {
                $embedded = $this->embedder->embedBatch(array_values($batch));
            } catch (Throwable $e) {
                Logger::error('Embedding batch failed; chunks stored without vectors', [
                    'error' => $e->getMessage(),
                    'chunks' => count($batch),
                ]);

                foreach (array_keys($batch) as $index) {
                    $vectors[$index] = [];
                }

                continue;
            }

            $position = 0;

            foreach (array_keys($batch) as $index) {
                $vectors[$index] = $embedded[$position] ?? [];
                $position++;
            }
        }

        ksort($vectors);

        return $vectors;
    }

    /**
     * Search the knowledge base.
     *
     * Embeds the query only when embeddings are actually in use; in lexical
     * mode this costs nothing and makes no network call.
     *
     * @return array<int, SearchResult>
     */
    public function search(string $query, ?int $limit = null): array
    {
        $queryVector = [];

        if ($this->embedder->isEnabled()) {
            try {
                $queryVector = $this->embedder->embedQuery($query);
            } catch (Throwable $e) {
                Logger::warning('Query embedding failed; falling back to lexical search', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $this->store->search(
            $query,
            $queryVector,
            $limit,
            max(1, (int) Config::get('knowledge.search.candidates', 60))
        );
    }
}
