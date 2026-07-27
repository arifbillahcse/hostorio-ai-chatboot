<?php

declare(strict_types=1);

namespace Hostorio\Admin;

use Hostorio\Core\Logger;
use Hostorio\Database\AppDatabase;
use Throwable;

/**
 * Read-only queries behind the dashboard.
 *
 * Every method returns an empty result rather than throwing when the database
 * is unreachable, so a partial outage shows an empty panel instead of a stack
 * trace — the admin can still reach Settings to fix whatever is wrong.
 */
final class Metrics
{
    private readonly AppDatabase $db;

    public function __construct(?AppDatabase $db = null)
    {
        $this->db = $db ?? AppDatabase::instance();
    }

    /**
     * Headline numbers for a period.
     *
     * @return array<string, mixed>
     */
    public function summary(int $days = 30): array
    {
        $since = gmdate('Y-m-d H:i:s', time() - ($days * 86400));

        $costs = $this->one(
            sprintf(
                'SELECT COUNT(*) AS calls,
                        SUM(cost_usd) AS cost,
                        SUM(input_tokens + output_tokens) AS tokens,
                        SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END) AS failures,
                        AVG(duration_ms) AS avg_ms
                   FROM `%s` WHERE created_at >= :since',
                $this->db->table('api_costs')
            ),
            ['since' => $since]
        );

        $conversations = $this->one(
            sprintf(
                'SELECT COUNT(*) AS conversations, SUM(message_count) AS messages
                   FROM `%s` WHERE created_at >= :since',
                $this->db->table('conversations')
            ),
            ['since' => $since]
        );

        $calls = (int) ($costs['calls'] ?? 0);
        $cost  = (float) ($costs['cost'] ?? 0);

        return [
            'days'          => $days,
            'calls'         => $calls,
            'cost'          => $cost,
            'tokens'        => (int) ($costs['tokens'] ?? 0),
            'failures'      => (int) ($costs['failures'] ?? 0),
            'avg_ms'        => (int) round((float) ($costs['avg_ms'] ?? 0)),
            'conversations' => (int) ($conversations['conversations'] ?? 0),
            'messages'      => (int) ($conversations['messages'] ?? 0),
            // The number that actually matters when judging the routing rules.
            'cost_per_call' => $calls > 0 ? $cost / $calls : 0.0,
        ];
    }

    /**
     * Spend and volume per provider — the evidence for whether the routing
     * rules are earning their keep.
     *
     * @return array<int, array<string, mixed>>
     */
    public function byProvider(int $days = 30): array
    {
        return $this->many(
            sprintf(
                'SELECT provider,
                        COUNT(*) AS calls,
                        SUM(cost_usd) AS cost,
                        SUM(input_tokens) AS input_tokens,
                        SUM(output_tokens) AS output_tokens,
                        SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END) AS failures,
                        AVG(duration_ms) AS avg_ms
                   FROM `%s`
                  WHERE created_at >= :since
                  GROUP BY provider
                  ORDER BY cost DESC',
                $this->db->table('api_costs')
            ),
            ['since' => gmdate('Y-m-d H:i:s', time() - ($days * 86400))]
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function byDay(int $days = 14): array
    {
        return $this->many(
            sprintf(
                'SELECT DATE(created_at) AS day,
                        COUNT(*) AS calls,
                        SUM(cost_usd) AS cost
                   FROM `%s`
                  WHERE created_at >= :since
                  GROUP BY DATE(created_at)
                  ORDER BY day DESC',
                $this->db->table('api_costs')
            ),
            ['since' => gmdate('Y-m-d H:i:s', time() - ($days * 86400))]
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function byQueryType(int $days = 30): array
    {
        return $this->many(
            sprintf(
                'SELECT query_type, COUNT(*) AS messages, SUM(cost_usd) AS cost
                   FROM `%s`
                  WHERE role = \'assistant\' AND query_type IS NOT NULL AND created_at >= :since
                  GROUP BY query_type
                  ORDER BY messages DESC',
                $this->db->table('messages')
            ),
            ['since' => gmdate('Y-m-d H:i:s', time() - ($days * 86400))]
        );
    }

    /**
     * The questions customers actually ask.
     *
     * Grouped on a normalised prefix rather than the exact string: no two people
     * phrase a question identically, and exact grouping would return a list of
     * one-offs. Crude, but it surfaces the themes worth writing an article
     * about, which is the point.
     *
     * @return array<int, array<string, mixed>>
     */
    public function commonQuestions(int $days = 30, int $limit = 20): array
    {
        return $this->many(
            sprintf(
                'SELECT LOWER(LEFT(TRIM(content), 60)) AS question,
                        COUNT(*) AS asked,
                        MAX(created_at) AS last_asked
                   FROM `%s`
                  WHERE role = \'user\' AND created_at >= :since AND CHAR_LENGTH(TRIM(content)) > 8
                  GROUP BY question
                  HAVING asked > 1
                  ORDER BY asked DESC, last_asked DESC
                  LIMIT %d',
                $this->db->table('messages'),
                max(1, $limit)
            ),
            ['since' => gmdate('Y-m-d H:i:s', time() - ($days * 86400))]
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function recentConversations(int $limit = 40, int $offset = 0, string $search = ''): array
    {
        $conversations = $this->db->table('conversations');
        $messages      = $this->db->table('messages');

        $bindings = [];
        $where    = '';

        if ($search !== '') {
            // Match the title or any message in the thread.
            $where = sprintf(
                'WHERE c.title LIKE :q
                    OR EXISTS (SELECT 1 FROM `%s` m WHERE m.conversation_id = c.id AND m.content LIKE :q2)',
                $messages
            );
            $bindings['q']  = '%' . $search . '%';
            $bindings['q2'] = '%' . $search . '%';
        }

        return $this->many(
            sprintf(
                'SELECT c.id, c.public_id, c.customer_id, c.title, c.message_count,
                        c.total_cost_usd, c.created_at, c.updated_at
                   FROM `%s` c
                   %s
                  ORDER BY c.updated_at DESC
                  LIMIT %d OFFSET %d',
                $conversations,
                $where,
                max(1, $limit),
                max(0, $offset)
            ),
            $bindings
        );
    }

    public function countConversations(string $search = ''): int
    {
        $conversations = $this->db->table('conversations');
        $messages      = $this->db->table('messages');

        if ($search === '') {
            $row = $this->one(sprintf('SELECT COUNT(*) AS c FROM `%s`', $conversations));

            return (int) ($row['c'] ?? 0);
        }

        $row = $this->one(
            sprintf(
                'SELECT COUNT(*) AS c FROM `%s` c
                  WHERE c.title LIKE :q
                     OR EXISTS (SELECT 1 FROM `%s` m WHERE m.conversation_id = c.id AND m.content LIKE :q2)',
                $conversations,
                $messages
            ),
            ['q' => '%' . $search . '%', 'q2' => '%' . $search . '%']
        );

        return (int) ($row['c'] ?? 0);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function conversation(string $publicId): ?array
    {
        $row = $this->one(
            sprintf('SELECT * FROM `%s` WHERE public_id = :pid', $this->db->table('conversations')),
            ['pid' => $publicId]
        );

        return $row === [] ? null : $row;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function conversationMessages(int $conversationId): array
    {
        return $this->many(
            sprintf(
                'SELECT role, content, provider, model, query_type, input_tokens, output_tokens,
                        cost_usd, context_tokens, created_at
                   FROM `%s`
                  WHERE conversation_id = :cid
                  ORDER BY id ASC',
                $this->db->table('messages')
            ),
            ['cid' => $conversationId]
        );
    }

    /**
     * Tool activity. Denials and destructive successes are what an operator
     * needs to see here, so they are surfaced separately in the view.
     *
     * @return array<int, array<string, mixed>>
     */
    public function recentToolCalls(int $limit = 25): array
    {
        return $this->many(
            sprintf(
                'SELECT tool_name, customer_id, destructive, outcome, detail, created_at
                   FROM `%s`
                  ORDER BY id DESC
                  LIMIT %d',
                $this->db->table('tool_invocations'),
                max(1, $limit)
            )
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function recentIndexRuns(int $limit = 5): array
    {
        return $this->many(
            sprintf(
                'SELECT source, documents_indexed, documents_skipped, chunks_written, chunks_embedded,
                        embedding_cost_usd, duration_ms, success, created_at
                   FROM `%s` ORDER BY id DESC LIMIT %d',
                $this->db->table('index_runs'),
                max(1, $limit)
            )
        );
    }

    /**
     * @param array<string, mixed> $bindings
     * @return array<string, mixed>
     */
    private function one(string $sql, array $bindings = []): array
    {
        try {
            return $this->db->selectOne($sql, $bindings) ?? [];
        } catch (Throwable $e) {
            Logger::error('Admin metric query failed', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * @param array<string, mixed> $bindings
     * @return array<int, array<string, mixed>>
     */
    private function many(string $sql, array $bindings = []): array
    {
        try {
            return $this->db->select($sql, $bindings);
        } catch (Throwable $e) {
            Logger::error('Admin metric query failed', ['error' => $e->getMessage()]);

            return [];
        }
    }
}
