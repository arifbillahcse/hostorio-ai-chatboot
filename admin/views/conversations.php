<?php
/**
 * Conversation list, with search and paging.
 *
 * @var callable $e
 * @var string $base
 * @var string $search
 * @var int $page
 * @var int $perPage
 * @var int $total
 * @var array<int, array<string, mixed>> $rows
 */
$pages = max(1, (int) ceil($total / max(1, $perPage)));
?>
<form method="get" action="<?= $e($base . '/conversations') ?>" class="card">
    <div class="row">
        <div class="field" style="margin-bottom:0">
            <label for="q">Search conversations</label>
            <input type="search" id="q" name="q" value="<?= $e($search) ?>"
                   placeholder="Search titles and message text">
        </div>
        <div class="field" style="margin-bottom:0;align-self:end">
            <button class="btn" type="submit">Search</button>
            <?php if ($search !== ''): ?>
                <a class="btn secondary" href="<?= $e($base . '/conversations') ?>">Clear</a>
            <?php endif; ?>
        </div>
    </div>
</form>

<p class="muted" style="font-size:13px">
    <?= $e(number_format($total)) ?> conversation<?= $total === 1 ? '' : 's' ?><?php
        echo $search !== '' ? ' matching “' . $e($search) . '”' : ''; ?>.
</p>

<?php if ($rows === []): ?>
    <div class="empty">No conversations<?= $search !== '' ? ' matched that search' : ' yet' ?>.</div>
<?php else: ?>
    <div class="scroll">
        <table>
            <thead>
            <tr>
                <th>Started</th><th>Title</th><th>Customer</th>
                <th class="num">Messages</th><th class="num">Cost</th><th>Last activity</th><th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td class="muted"><?= $e($row['created_at']) ?></td>
                    <td><?= $row['title'] === '' ? '<span class="muted">(untitled)</span>' : $e($row['title']) ?></td>
                    <td>
                        <?php if ($row['customer_id'] === null): ?>
                            <span class="muted">anonymous</span>
                        <?php else: ?>
                            <span class="tag">#<?= $e((int) $row['customer_id']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="num"><?= $e((int) $row['message_count']) ?></td>
                    <td class="num">$<?= $e(number_format((float) $row['total_cost_usd'], 4)) ?></td>
                    <td class="muted"><?= $e($row['updated_at']) ?></td>
                    <td>
                        <a class="btn secondary"
                           href="<?= $e($base . '/conversation?id=' . urlencode((string) $row['public_id'])) ?>">Open</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($pages > 1): ?>
        <div class="actions">
            <?php if ($page > 1): ?>
                <a class="btn secondary"
                   href="<?= $e($base . '/conversations?page=' . ($page - 1) . '&q=' . urlencode($search)) ?>">Previous</a>
            <?php endif; ?>
            <span class="muted">Page <?= $e($page) ?> of <?= $e($pages) ?></span>
            <?php if ($page < $pages): ?>
                <a class="btn secondary"
                   href="<?= $e($base . '/conversations?page=' . ($page + 1) . '&q=' . urlencode($search)) ?>">Next</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>
