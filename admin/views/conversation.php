<?php
/**
 * A single conversation transcript.
 *
 * Message content is customer- and model-written, so every field goes through
 * $e() — this page is the most likely place for stored XSS to surface.
 *
 * @var callable $e
 * @var string $base
 * @var array<string, mixed>|null $conversation
 * @var array<int, array<string, mixed>> $messages
 */
?>
<p><a href="<?= $e($base . '/conversations') ?>">&larr; All conversations</a></p>

<?php if ($conversation === null): ?>
    <div class="empty">That conversation does not exist.</div>
<?php else: ?>
    <div class="card">
        <div class="row">
            <div>
                <div class="label">Customer</div>
                <?= $conversation['customer_id'] === null
                    ? '<span class="muted">anonymous</span>'
                    : '<span class="tag">#' . $e((int) $conversation['customer_id']) . '</span>' ?>
            </div>
            <div>
                <div class="label">Messages</div>
                <?= $e((int) $conversation['message_count']) ?>
            </div>
            <div>
                <div class="label">Total cost</div>
                $<?= $e(number_format((float) $conversation['total_cost_usd'], 4)) ?>
            </div>
            <div>
                <div class="label">Started</div>
                <span class="muted"><?= $e($conversation['created_at']) ?></span>
            </div>
        </div>
        <div class="actions">
            <a class="btn secondary"
               href="<?= $e($base . '/conversation/export?id=' . urlencode((string) $conversation['public_id'])) ?>">
                Export CSV
            </a>
        </div>
    </div>

    <?php if ($messages === []): ?>
        <div class="empty">This conversation has no messages.</div>
    <?php else: ?>
        <div class="card">
            <?php foreach ($messages as $message): ?>
                <div class="bubble <?= $e($message['role']) ?>"><?= $e($message['content']) ?></div>
                <?php if ($message['role'] === 'assistant'): ?>
                    <div class="meta">
                        <?= $e($message['provider'] ?? '?') ?>
                        (<?= $e($message['model'] ?? '?') ?>)
                        · <?= $e($message['query_type'] ?? '?') ?>
                        · <?= $e((int) $message['input_tokens']) ?> in /
                        <?= $e((int) $message['output_tokens']) ?> out
                        · context <?= $e((int) $message['context_tokens']) ?>
                        · $<?= $e(number_format((float) $message['cost_usd'], 6)) ?>
                        · <?= $e($message['created_at']) ?>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>
