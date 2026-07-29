<?php

declare(strict_types=1);

namespace Hostorio\Chat;

use Hostorio\Context\CustomerIdentity;
use Hostorio\Core\Config;
use Hostorio\Core\Logger;
use Hostorio\Database\AppDatabase;

/**
 * Persists conversations and their turns.
 *
 * The ownership rule is the important part. A conversation id travels in the
 * request, so resuming one has to prove entitlement or it becomes a way to read
 * other people's support chats:
 *
 *  - A verified customer may resume only their own threads.
 *  - An anonymous visitor may resume only threads created from the same client
 *    key (a hashed IP) — weak, but it is the strongest signal available for
 *    someone who has not signed in, and it is never used to release account
 *    data because anonymous threads never had any.
 *
 * When the check fails a *new* conversation is started rather than an error
 * returned: the customer gets a working chat instead of a dead end, and the
 * attempt is logged.
 */
class ConversationStore
{
    private readonly AppDatabase $db;

    public function __construct(?AppDatabase $db = null)
    {
        $this->db = $db ?? AppDatabase::instance();
    }

    /**
     * Resume a conversation the caller is entitled to, or start a new one.
     *
     * @return array{id: int, public_id: string, resumed: bool}
     */
    public function resumeOrCreate(?string $publicId, CustomerIdentity $identity, string $clientKey): array
    {
        if ($publicId !== null && $publicId !== '') {
            $existing = $this->findOwned($publicId, $identity, $clientKey);

            if ($existing !== null) {
                return [
                    'id'        => (int) $existing['id'],
                    'public_id' => (string) $existing['public_id'],
                    'resumed'   => true,
                ];
            }

            Logger::warning('Conversation resume refused; starting a new thread', [
                'public_id'   => $publicId,
                'customer_id' => $identity->customerId,
            ]);
        }

        return $this->create($identity, $clientKey) + ['resumed' => false];
    }

    /**
     * @return array{id: int, public_id: string}
     */
    public function create(CustomerIdentity $identity, string $clientKey): array
    {
        $now      = gmdate('Y-m-d H:i:s');
        $publicId = bin2hex(random_bytes(16));

        $id = $this->db->insert('conversations', [
            'public_id'     => $publicId,
            'customer_id'   => $identity->customerId,
            'client_key'    => $clientKey,
            'title'         => '',
            'message_count' => 0,
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);

        return ['id' => $id, 'public_id' => $publicId];
    }

    /**
     * Fetch a conversation only if this caller owns it.
     *
     * @return array<string, mixed>|null
     */
    private function findOwned(string $publicId, CustomerIdentity $identity, string $clientKey): ?array
    {
        $row = $this->db->selectOne(
            sprintf('SELECT * FROM `%s` WHERE public_id = :pid', $this->db->table('conversations')),
            ['pid' => $publicId]
        );

        if ($row === null) {
            return null;
        }

        $ownerId = $row['customer_id'] === null ? null : (int) $row['customer_id'];

        if ($identity->isVerified()) {
            // A verified customer may only resume their own threads. They may
            // also adopt an anonymous thread they started before signing in —
            // that thread never contained account data, so nothing leaks.
            if ($ownerId === $identity->customerId) {
                return $row;
            }

            if ($ownerId === null && hash_equals((string) $row['client_key'], $clientKey)) {
                $this->adopt((int) $row['id'], (int) $identity->customerId);

                return $row;
            }

            return null;
        }

        // Anonymous: a thread that belongs to a signed-in customer is never
        // resumable without proving that identity again.
        if ($ownerId !== null) {
            return null;
        }

        return hash_equals((string) $row['client_key'], $clientKey) ? $row : null;
    }

    private function adopt(int $conversationId, int $customerId): void
    {
        $this->db->execute(
            sprintf('UPDATE `%s` SET customer_id = :cid WHERE id = :id', $this->db->table('conversations')),
            ['cid' => $customerId, 'id' => $conversationId]
        );

        Logger::info('Anonymous conversation adopted after sign-in', [
            'conversation_id' => $conversationId,
            'customer_id'     => $customerId,
        ]);
    }

    /**
     * Recent turns, oldest first, ready to hand to a provider.
     *
     * Only plain text turns are replayed. Tool exchanges are deliberately not
     * reconstructed across requests: a stored `tool_use` block whose matching
     * `tool_result` fell outside the history window is a 400 from the API, and
     * the tool's *outcome* is already described in the assistant's reply.
     *
     * @return array<int, array{role: string, content: string}>
     */
    public function history(int $conversationId, ?int $limit = null): array
    {
        $limit ??= max(0, (int) Config::get('chat.history_turns', 10));

        if ($limit === 0) {
            return [];
        }

        $rows = $this->db->select(
            sprintf(
                'SELECT role, content FROM `%s`
                  WHERE conversation_id = :cid AND content <> \'\'
                  ORDER BY id DESC
                  LIMIT %d',
                $this->db->table('messages'),
                $limit
            ),
            ['cid' => $conversationId]
        );

        $rows = array_reverse($rows);

        $history = [];
        $expected = 'user';

        foreach ($rows as $row) {
            $role = (string) $row['role'];

            // The window may start mid-exchange, and a turn may be missing if a
            // request failed after saving the question but before the answer.
            // Providers reject a history that does not strictly alternate from
            // a user turn, so skip anything out of sequence rather than sending
            // a request that 400s.
            if ($role !== $expected) {
                continue;
            }

            $history[]= ['role' => $role, 'content' => (string) $row['content']];
            $expected = $role === 'user' ? 'assistant' : 'user';
        }

        // History must end on an assistant turn, since the caller appends the
        // new user message next.
        if ($history !== [] && $history[count($history) - 1]['role'] === 'user') {
            array_pop($history);
        }

        return $history;
    }

    /**
     * Turns for redisplay in the widget after a page navigation, if the caller
     * owns the conversation.
     *
     * Unlike history(), this is not filtered for strict user/assistant
     * alternation — that trimming exists only to keep a provider's turn
     * sequencing happy, and would silently hide real messages from a customer
     * looking at their own chat.
     *
     * @return array<int, array{role: string, content: string}>|null null when
     *         the conversation does not exist or this caller does not own it
     */
    public function displayHistory(
        string $publicId,
        CustomerIdentity $identity,
        string $clientKey,
        int $limit = 50
    ): ?array {
        $row = $this->findOwned($publicId, $identity, $clientKey);

        if ($row === null) {
            return null;
        }

        $rows = $this->db->select(
            sprintf(
                'SELECT role, content FROM `%s`
                  WHERE conversation_id = :cid AND content <> \'\'
                  ORDER BY id ASC
                  LIMIT %d',
                $this->db->table('messages'),
                max(1, $limit)
            ),
            ['cid' => (int) $row['id']]
        );

        return array_map(
            static fn (array $r): array => ['role' => (string) $r['role'], 'content' => (string) $r['content']],
            $rows
        );
    }

    /**
     * Append a turn.
     *
     * @param array<string, mixed> $extra
     */
    public function addMessage(int $conversationId, string $role, string $content, array $extra = []): int
    {
        $id = $this->db->insert('messages', array_merge([
            'conversation_id' => $conversationId,
            'role'            => $role,
            'content'         => $content,
            'content_blocks'  => null,
            'created_at'      => gmdate('Y-m-d H:i:s'),
        ], $extra));

        $this->db->execute(
            sprintf(
                'UPDATE `%s`
                    SET message_count = message_count + 1,
                        total_cost_usd = total_cost_usd + :cost,
                        updated_at = :now
                  WHERE id = :id',
                $this->db->table('conversations')
            ),
            [
                'cost' => (float) ($extra['cost_usd'] ?? 0),
                'now'  => gmdate('Y-m-d H:i:s'),
                'id'   => $conversationId,
            ]
        );

        return $id;
    }

    /**
     * Set a title from the opening question, so the admin panel has something
     * readable in its conversation list.
     */
    public function ensureTitle(int $conversationId, string $firstMessage): void
    {
        $title = mb_substr(trim(preg_replace('/\s+/u', ' ', $firstMessage) ?? ''), 0, 120, 'UTF-8');

        if ($title === '') {
            return;
        }

        $this->db->execute(
            sprintf(
                'UPDATE `%s` SET title = :title WHERE id = :id AND title = \'\'',
                $this->db->table('conversations')
            ),
            ['title' => $title, 'id' => $conversationId]
        );
    }

    /**
     * Stable pseudonymous key for an anonymous visitor.
     *
     * Hashed with APP_KEY so the conversations table does not become a log of
     * raw IP addresses.
     */
    public static function clientKey(string $ip): string
    {
        return hash_hmac('sha256', $ip, (string) Config::get('app.key', 'hoai'));
    }
}
