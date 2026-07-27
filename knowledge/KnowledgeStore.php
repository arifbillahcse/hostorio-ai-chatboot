<?php

declare(strict_types=1);

namespace Hostorio\Knowledge;

use Hostorio\Core\Logger;
use Hostorio\Database\AppDatabase;

/**
 * Persistence for the knowledge base.
 *
 * Deliberately thin: SQL in, arrays out. Ranking lives in Ranker so it can be
 * tested without a database, and this class stays small enough to audit.
 */
class KnowledgeStore
{
    private readonly AppDatabase $db;

    public function __construct(
        ?AppDatabase $db = null,
        private readonly Ranker $ranker = new Ranker(),
    ) {
        $this->db = $db ?? AppDatabase::instance();
    }

    /**
     * Insert or update a document, returning its id and whether the content
     * actually changed.
     *
     * The `changed` flag is the money-saver: an unchanged document skips
     * re-chunking and re-embedding, so a nightly sync over 500 articles costs
     * nothing when nobody edited anything.
     *
     * @return array{id: int, changed: bool}
     */
    public function upsertDocument(Document $document, string $hash): array
    {
        $now = gmdate('Y-m-d H:i:s');

        $existing = $this->db->selectOne(
            sprintf(
                'SELECT id, content_hash FROM `%s` WHERE source = :source AND source_ref = :ref',
                $this->db->table('knowledge_documents')
            ),
            ['source' => $document->source, 'ref' => $document->sourceRef]
        );

        if ($existing === null) {
            $id = $this->db->insert('knowledge_documents', [
                'source'       => $document->source,
                'source_ref'   => $document->sourceRef,
                'title'        => $document->title,
                'body'         => $document->body,
                'url'          => $document->url,
                'priority'     => $document->priority,
                'content_hash' => $hash,
                'is_active'    => 1,
                'expires_at'   => $document->expiresAt,
                'created_at'   => $now,
                'updated_at'   => $now,
                'indexed_at'   => null,
            ]);

            return ['id' => $id, 'changed' => true];
        }

        $id      = (int) $existing['id'];
        $changed = ((string) $existing['content_hash']) !== $hash;

        $this->db->execute(
            sprintf(
                'UPDATE `%s`
                    SET title = :title, body = :body, url = :url, priority = :priority,
                        content_hash = :hash, expires_at = :expires_at, is_active = 1,
                        updated_at = :updated_at
                  WHERE id = :id',
                $this->db->table('knowledge_documents')
            ),
            [
                'title'      => $document->title,
                'body'       => $document->body,
                'url'        => $document->url,
                'priority'   => $document->priority,
                'hash'       => $hash,
                'expires_at' => $document->expiresAt,
                'updated_at' => $now,
                'id'         => $id,
            ]
        );

        return ['id' => $id, 'changed' => $changed];
    }

    /**
     * Replace a document's chunks wholesale.
     *
     * Delete-then-insert rather than a diff: chunk boundaries shift when a
     * document is edited, so matching old chunks to new ones is guesswork that
     * would leave stale text retrievable.
     *
     * @param array<int, string> $chunks
     * @param array<int, array<int, float>> $vectors parallel to $chunks; empty entries allowed
     */
    public function replaceChunks(int $documentId, array $chunks, array $vectors = [], string $model = ''): int
    {
        $now = gmdate('Y-m-d H:i:s');

        $this->db->execute(
            sprintf('DELETE FROM `%s` WHERE document_id = :id', $this->db->table('knowledge_chunks')),
            ['id' => $documentId]
        );

        $written = 0;

        foreach ($chunks as $index => $content) {
            $vector = $vectors[$index] ?? [];

            $this->db->insert('knowledge_chunks', [
                'document_id'     => $documentId,
                'chunk_index'     => $index,
                'content'         => $content,
                'char_count'      => mb_strlen($content, 'UTF-8'),
                'embedding'       => $vector === [] ? null : VectorMath::pack($vector),
                'embedding_model' => $vector === [] ? null : $model,
                'created_at'      => $now,
            ]);

            $written++;
        }

        $this->db->execute(
            sprintf('UPDATE `%s` SET indexed_at = :now WHERE id = :id', $this->db->table('knowledge_documents')),
            ['now' => $now, 'id' => $documentId]
        );

        return $written;
    }

    /**
     * Documents needing (re)indexing: never indexed, or edited since.
     *
     * @return array<int, array<string, mixed>>
     */
    public function pendingDocuments(int $limit = 500): array
    {
        return $this->db->select(
            sprintf(
                'SELECT id, source, source_ref, title, body, url, priority
                   FROM `%s`
                  WHERE is_active = 1
                    AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP())
                    AND (indexed_at IS NULL OR indexed_at < updated_at)
                  ORDER BY priority DESC, id ASC
                  LIMIT %d',
                $this->db->table('knowledge_documents'),
                max(1, $limit)
            )
        );
    }

    /**
     * Retrieve passages relevant to a query.
     *
     * Two stages by necessity: MySQL full-text narrows thousands of chunks to a
     * candidate set, then vectors re-rank only those. Scoring every chunk in
     * PHP would mean loading every embedding into memory, which a shared
     * hosting account cannot afford.
     *
     * @param array<int, float> $queryVector empty for lexical-only mode
     * @return array<int, SearchResult>
     */
    public function search(string $query, array $queryVector = [], ?int $limit = null, int $candidateLimit = 60): array
    {
        $candidates = $this->fetchCandidates($query, $candidateLimit);

        if ($candidates === []) {
            return [];
        }

        return $this->ranker->rank($candidates, $queryVector, $limit);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchCandidates(string $query, int $limit): array
    {
        $chunks    = $this->db->table('knowledge_chunks');
        $documents = $this->db->table('knowledge_documents');

        $select = 'SELECT c.id AS chunk_id, c.document_id, c.content, c.embedding,
                          d.source, d.title, d.url, d.priority';

        $from = sprintf(
            'FROM `%s` c
             INNER JOIN `%s` d ON d.id = c.document_id
             WHERE d.is_active = 1
               AND (d.expires_at IS NULL OR d.expires_at > UTC_TIMESTAMP())',
            $chunks,
            $documents
        );

        $terms = $this->booleanQuery($query);

        if ($terms !== '') {
            try {
                $rows = $this->db->select(
                    $select . ', MATCH(c.content) AGAINST(:q IN BOOLEAN MODE) AS lexical_score ' .
                    $from . ' AND MATCH(c.content) AGAINST(:q2 IN BOOLEAN MODE) ' .
                    sprintf('ORDER BY lexical_score DESC LIMIT %d', max(1, $limit)),
                    ['q' => $terms, 'q2' => $terms]
                );

                if ($rows !== []) {
                    return $rows;
                }
            } catch (\Throwable $e) {
                // Full-text can be unavailable (index not built yet on a fresh
                // install, or a MySQL build without it). Fall through to LIKE
                // rather than failing the customer's question.
                Logger::warning('Full-text search unavailable; falling back to LIKE', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $this->likeFallback($select, $from, $query, $limit);
    }

    /**
     * Last-resort candidate retrieval.
     *
     * Also covers the case full-text genuinely cannot: MySQL ignores words
     * below its minimum token length, so a query that is entirely short words
     * ("is my ftp up") returns nothing from MATCH().
     *
     * @return array<int, array<string, mixed>>
     */
    private function likeFallback(string $select, string $from, string $query, int $limit): array
    {
        $words = $this->significantWords($query);

        if ($words === []) {
            return [];
        }

        $conditions = [];
        $bindings   = [];

        foreach (array_slice($words, 0, 6) as $i => $word) {
            $conditions[]      = 'c.content LIKE :w' . $i;
            $bindings['w' . $i] = '%' . $word . '%';
        }

        return $this->db->select(
            $select . ', 1 AS lexical_score ' . $from .
            ' AND (' . implode(' OR ', $conditions) . ')' .
            sprintf(' ORDER BY d.priority DESC, c.id ASC LIMIT %d', max(1, $limit)),
            $bindings
        );
    }

    /**
     * Build a BOOLEAN MODE query.
     *
     * Terms are OR-ed with a trailing wildcard rather than required, because a
     * customer question contains words the documentation will not
     * ("hey", "my", "urgently"). Requiring all of them returns nothing.
     */
    private function booleanQuery(string $query): string
    {
        $words = $this->significantWords($query);

        if ($words === []) {
            return '';
        }

        $terms = [];

        foreach (array_slice($words, 0, 12) as $word) {
            // Strip characters BOOLEAN MODE treats as operators.
            $safe = preg_replace('/[+\-><()~*"@]+/u', '', $word) ?? '';

            if (mb_strlen($safe, 'UTF-8') >= 3) {
                $terms[] = $safe . '*';
            }
        }

        return implode(' ', $terms);
    }

    /**
     * @return array<int, string>
     */
    private function significantWords(string $query): array
    {
        $lower = mb_strtolower($query, 'UTF-8');
        $parts = preg_split('/[^\p{L}\p{N}_]+/u', $lower, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $stopwords = [
            'the', 'and', 'for', 'are', 'but', 'not', 'you', 'your', 'with', 'this',
            'that', 'have', 'has', 'was', 'were', 'can', 'could', 'would', 'should',
            'how', 'what', 'when', 'where', 'why', 'who', 'does', 'did', 'from',
            'about', 'into', 'please', 'help', 'need', 'want', 'get', 'got',
        ];

        $words = [];

        foreach ($parts as $part) {
            if (mb_strlen($part, 'UTF-8') < 3 || in_array($part, $stopwords, true)) {
                continue;
            }

            $words[] = $part;
        }

        return array_values(array_unique($words));
    }

    /**
     * Mark documents from a source that no longer exist upstream as inactive.
     *
     * Deactivated rather than deleted: a post unpublished by accident should
     * come back on the next sync without losing its history, and an admin can
     * see what disappeared.
     *
     * @param array<int, string> $seenRefs
     */
    public function deactivateMissing(string $source, array $seenRefs): int
    {
        $table = $this->db->table('knowledge_documents');

        if ($seenRefs === []) {
            return $this->db->execute(
                sprintf('UPDATE `%s` SET is_active = 0 WHERE source = :source AND is_active = 1', $table),
                ['source' => $source]
            );
        }

        $placeholders = [];
        $bindings     = ['source' => $source];

        foreach (array_values($seenRefs) as $i => $ref) {
            $placeholders[]     = ':r' . $i;
            $bindings['r' . $i] = $ref;
        }

        return $this->db->execute(
            sprintf(
                'UPDATE `%s` SET is_active = 0
                  WHERE source = :source AND is_active = 1
                    AND source_ref NOT IN (%s)',
                $table,
                implode(', ', $placeholders)
            ),
            $bindings
        );
    }

    /**
     * Drop chunks belonging to expired notes so they stop being retrievable
     * the moment they lapse, without deleting the note itself.
     */
    public function pruneExpired(): int
    {
        return $this->db->execute(
            sprintf(
                'DELETE c FROM `%s` c
                 INNER JOIN `%s` d ON d.id = c.document_id
                 WHERE d.expires_at IS NOT NULL AND d.expires_at <= UTC_TIMESTAMP()',
                $this->db->table('knowledge_chunks'),
                $this->db->table('knowledge_documents')
            )
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function stats(): array
    {
        $documents = $this->db->table('knowledge_documents');
        $chunks    = $this->db->table('knowledge_chunks');

        $bySource = $this->db->select(
            sprintf(
                'SELECT source,
                        COUNT(*) AS documents,
                        SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) AS active,
                        SUM(CASE WHEN indexed_at IS NULL OR indexed_at < updated_at THEN 1 ELSE 0 END) AS pending
                   FROM `%s` GROUP BY source',
                $documents
            )
        );

        $chunkStats = $this->db->selectOne(
            sprintf(
                'SELECT COUNT(*) AS total,
                        SUM(CASE WHEN embedding IS NOT NULL THEN 1 ELSE 0 END) AS embedded
                   FROM `%s`',
                $chunks
            )
        ) ?? [];

        return [
            'by_source'       => $bySource,
            'chunks_total'    => (int) ($chunkStats['total'] ?? 0),
            'chunks_embedded' => (int) ($chunkStats['embedded'] ?? 0),
        ];
    }

    /**
     * @param array<string, mixed> $run
     */
    public function recordRun(array $run): void
    {
        try {
            $this->db->insert('index_runs', $run + ['created_at' => gmdate('Y-m-d H:i:s')]);
        } catch (\Throwable $e) {
            Logger::error('Could not record index run', ['error' => $e->getMessage()]);
        }
    }
}
