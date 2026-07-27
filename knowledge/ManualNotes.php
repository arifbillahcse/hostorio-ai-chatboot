<?php

declare(strict_types=1);

namespace Hostorio\Knowledge;

use Hostorio\Core\Logger;
use Hostorio\Database\AppDatabase;
use InvalidArgumentException;

/**
 * The manual knowledge channel: things you type in directly.
 *
 * This exists because the website is always behind. An outage started twenty
 * minutes ago, a promo runs until Friday, a policy changed this morning — none
 * of that is a published article, and all of it is what customers are asking
 * about right now.
 *
 * Notes default to a higher priority than WordPress articles precisely so they
 * win when they contradict one. That is the point: a note saying "the Frankfurt
 * datacentre is down" must outrank a guide saying everything is fine.
 *
 * Notes are stored as ordinary documents, so they go through the same chunking,
 * embedding and retrieval path as everything else — no separate code path to
 * keep in sync.
 */
final class ManualNotes
{
    private readonly AppDatabase $db;

    public function __construct(?AppDatabase $db = null)
    {
        $this->db = $db ?? AppDatabase::instance();
    }

    /**
     * Create a note.
     *
     * @param string|null $expiresAt 'Y-m-d H:i:s' UTC, or null to never expire
     */
    public function add(string $title, string $body, int $priority = 10, ?string $expiresAt = null): int
    {
        $title = trim($title);
        $body  = trim($body);

        if ($body === '') {
            throw new InvalidArgumentException('A note needs a body.');
        }

        if ($title === '') {
            // Derive one so retrieved chunks are not headless in the prompt.
            $title = mb_substr($body, 0, 60, 'UTF-8') . (mb_strlen($body, 'UTF-8') > 60 ? '…' : '');
        }

        $expiresAt = $this->validateExpiry($expiresAt);
        $now       = gmdate('Y-m-d H:i:s');

        // Manual notes have no upstream id, so one is minted here to satisfy
        // the (source, source_ref) uniqueness constraint.
        $reference = 'note_' . bin2hex(random_bytes(8));

        $id = $this->db->insert('knowledge_documents', [
            'source'       => Document::SOURCE_MANUAL,
            'source_ref'   => $reference,
            'title'        => $title,
            'body'         => $body,
            'url'          => null,
            'priority'     => $priority,
            'content_hash' => (new TextNormalizer())->hash($title, $body),
            'is_active'    => 1,
            'expires_at'   => $expiresAt,
            'created_at'   => $now,
            'updated_at'   => $now,
            'indexed_at'   => null,
        ]);

        Logger::info('Manual note added', ['id' => $id, 'priority' => $priority, 'expires_at' => $expiresAt]);

        return $id;
    }

    /**
     * Edit a note. Only the fields passed are changed.
     *
     * Touching `updated_at` without `indexed_at` is what marks the note for
     * re-indexing on the next run.
     *
     * @param array{title?: string, body?: string, priority?: int, expires_at?: string|null} $changes
     */
    public function update(int $id, array $changes): bool
    {
        $note = $this->find($id);

        if ($note === null) {
            return false;
        }

        $title    = array_key_exists('title', $changes) ? trim((string) $changes['title']) : (string) $note['title'];
        $body     = array_key_exists('body', $changes) ? trim((string) $changes['body']) : (string) $note['body'];
        $priority = array_key_exists('priority', $changes) ? (int) $changes['priority'] : (int) $note['priority'];

        if ($body === '') {
            throw new InvalidArgumentException('A note needs a body.');
        }

        $expiresAt = array_key_exists('expires_at', $changes)
            ? $this->validateExpiry($changes['expires_at'] === null ? null : (string) $changes['expires_at'])
            : ($note['expires_at'] === null ? null : (string) $note['expires_at']);

        $this->db->execute(
            sprintf(
                'UPDATE `%s`
                    SET title = :title, body = :body, priority = :priority,
                        expires_at = :expires_at, content_hash = :hash, updated_at = :now
                  WHERE id = :id AND source = :source',
                $this->db->table('knowledge_documents')
            ),
            [
                'title'      => $title,
                'body'       => $body,
                'priority'   => $priority,
                'expires_at' => $expiresAt,
                'hash'       => (new TextNormalizer())->hash($title, $body),
                'now'        => gmdate('Y-m-d H:i:s'),
                'id'         => $id,
                'source'     => Document::SOURCE_MANUAL,
            ]
        );

        Logger::info('Manual note updated', ['id' => $id]);

        return true;
    }

    /**
     * Retire a note without destroying it.
     *
     * Preferred over deletion for outage notices and promos: the text is worth
     * keeping for reference, and an accidental retirement is reversible.
     */
    public function expire(int $id): bool
    {
        $affected = $this->db->execute(
            sprintf(
                'UPDATE `%s` SET is_active = 0, updated_at = :now
                  WHERE id = :id AND source = :source',
                $this->db->table('knowledge_documents')
            ),
            ['now' => gmdate('Y-m-d H:i:s'), 'id' => $id, 'source' => Document::SOURCE_MANUAL]
        );

        if ($affected > 0) {
            // Remove its chunks so it stops being retrievable immediately,
            // rather than at the next index run.
            $this->db->execute(
                sprintf('DELETE FROM `%s` WHERE document_id = :id', $this->db->table('knowledge_chunks')),
                ['id' => $id]
            );

            Logger::info('Manual note expired', ['id' => $id]);
        }

        return $affected > 0;
    }

    public function restore(int $id): bool
    {
        return $this->db->execute(
            sprintf(
                'UPDATE `%s` SET is_active = 1, indexed_at = NULL, updated_at = :now
                  WHERE id = :id AND source = :source',
                $this->db->table('knowledge_documents')
            ),
            ['now' => gmdate('Y-m-d H:i:s'), 'id' => $id, 'source' => Document::SOURCE_MANUAL]
        ) > 0;
    }

    /**
     * Permanently remove a note. Chunks go with it via the foreign key.
     */
    public function delete(int $id): bool
    {
        return $this->db->execute(
            sprintf('DELETE FROM `%s` WHERE id = :id AND source = :source', $this->db->table('knowledge_documents')),
            ['id' => $id, 'source' => Document::SOURCE_MANUAL]
        ) > 0;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->db->selectOne(
            sprintf('SELECT * FROM `%s` WHERE id = :id AND source = :source', $this->db->table('knowledge_documents')),
            ['id' => $id, 'source' => Document::SOURCE_MANUAL]
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(bool $includeInactive = false): array
    {
        $where = $includeInactive ? '' : ' AND is_active = 1';

        return $this->db->select(
            sprintf(
                'SELECT id, title, body, priority, is_active, expires_at, created_at, updated_at, indexed_at
                   FROM `%s`
                  WHERE source = :source%s
                  ORDER BY priority DESC, updated_at DESC',
                $this->db->table('knowledge_documents'),
                $where
            ),
            ['source' => Document::SOURCE_MANUAL]
        );
    }

    /**
     * Accept a date the admin typed, or reject it clearly.
     */
    private function validateExpiry(?string $expiresAt): ?string
    {
        if ($expiresAt === null || trim($expiresAt) === '') {
            return null;
        }

        $timestamp = strtotime($expiresAt);

        if ($timestamp === false) {
            throw new InvalidArgumentException(
                sprintf('Could not understand the expiry date "%s". Try "2026-08-01" or "+7 days".', $expiresAt)
            );
        }

        return gmdate('Y-m-d H:i:s', $timestamp);
    }
}
