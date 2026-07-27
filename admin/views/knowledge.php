<?php
/**
 * Knowledge base manager: re-index, and the manual notes UI promised in Phase 3.
 *
 * @var callable $e
 * @var string $base
 * @var string $csrf
 * @var array<string, mixed> $stats
 * @var array<int, array<string, mixed>> $notes
 * @var array<int, array<string, mixed>> $runs
 * @var string $embedder
 */
$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$editing = null;

foreach ($notes as $note) {
    if ((int) $note['id'] === $editId) {
        $editing = $note;
        break;
    }
}

$pending = 0;
foreach (($stats['by_source'] ?? []) as $row) {
    $pending += (int) $row['pending'];
}
?>
<div class="grid">
    <div class="stat">
        <div class="label">Chunks indexed</div>
        <div class="value"><?= $e(number_format((int) ($stats['chunks_total'] ?? 0))) ?></div>
        <div class="sub">
            <?= $e(number_format((int) ($stats['chunks_embedded'] ?? 0))) ?> with embeddings
        </div>
    </div>
    <div class="stat">
        <div class="label">Awaiting indexing</div>
        <div class="value"><?= $e(number_format($pending)) ?></div>
        <div class="sub">documents changed since last run</div>
    </div>
    <div class="stat">
        <div class="label">Search mode</div>
        <div class="value" style="font-size:19px">
            <?= $embedder === 'none' ? 'Keyword' : 'Hybrid' ?>
        </div>
        <div class="sub">
            <?= $embedder === 'none'
                ? 'full-text only — no embeddings key set'
                : 'full-text + ' . $e($embedder) . ' embeddings' ?>
        </div>
    </div>
</div>

<div class="card">
    <h2 style="margin-top:0">Re-index</h2>
    <p class="muted" style="font-size:13px">
        Pulls the latest WordPress content and indexes anything that changed. Unchanged documents
        are skipped, so running this when nothing has been edited costs nothing.
    </p>
    <form method="post" action="<?= $e($base . '/knowledge') ?>">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <input type="hidden" name="action" value="reindex">
        <button class="btn" type="submit">Re-index now</button>
    </form>
</div>

<?php if (($stats['by_source'] ?? []) !== []): ?>
    <h2>What is indexed</h2>
    <div class="scroll">
        <table>
            <thead><tr><th>Source</th><th class="num">Documents</th><th class="num">Active</th><th class="num">Pending</th></tr></thead>
            <tbody>
            <?php foreach ($stats['by_source'] as $row): ?>
                <tr>
                    <td><span class="tag"><?= $e($row['source']) ?></span></td>
                    <td class="num"><?= $e(number_format((int) $row['documents'])) ?></td>
                    <td class="num"><?= $e(number_format((int) $row['active'])) ?></td>
                    <td class="num"><?= $e(number_format((int) $row['pending'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<h2><?= $editing ? 'Edit note #' . $e((int) $editing['id']) : 'Add a note' ?></h2>
<p class="muted" style="font-size:13px;margin-top:-4px">
    Notes are for what the website has not caught up with — an outage that started twenty minutes
    ago, a promo that ends Friday, a policy that changed this morning. They outrank published
    articles, so a note saying a datacentre is down beats a guide saying everything is fine.
</p>

<div class="card">
    <form method="post" action="<?= $e($base . '/knowledge') ?>">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <input type="hidden" name="action" value="<?= $editing ? 'note-edit' : 'note-add' ?>">
        <?php if ($editing): ?>
            <input type="hidden" name="id" value="<?= $e((int) $editing['id']) ?>">
        <?php endif; ?>

        <div class="field">
            <label for="title">Title</label>
            <input type="text" id="title" name="title"
                   value="<?= $e($editing['title'] ?? '') ?>"
                   placeholder="Frankfurt datacentre maintenance">
        </div>

        <div class="field">
            <label for="body">What the assistant should know</label>
            <textarea id="body" name="body" required
                      placeholder="DC3 is offline for scheduled maintenance until 14:00 UTC. Sites on web05–web09 are affected. No action is needed from customers."><?= $e($editing['body'] ?? '') ?></textarea>
        </div>

        <div class="row">
            <div class="field">
                <label for="priority">Priority</label>
                <input type="number" id="priority" name="priority" min="0" max="100"
                       value="<?= $e((int) ($editing['priority'] ?? 10)) ?>">
                <div class="hint">Higher wins. Articles are 0; notes default to 10.</div>
            </div>
            <div class="field">
                <label for="expires_at">Expires (optional)</label>
                <input type="text" id="expires_at" name="expires_at"
                       value="<?= $e($editing['expires_at'] ?? '') ?>"
                       placeholder="+6 hours, or 2026-08-01 14:00">
                <div class="hint">Leave blank to keep it until you retire it.</div>
            </div>
        </div>

        <div class="actions">
            <button class="btn" type="submit"><?= $editing ? 'Save changes' : 'Add note' ?></button>
            <?php if ($editing): ?>
                <a class="btn secondary" href="<?= $e($base . '/knowledge') ?>">Cancel</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<h2>Notes</h2>
<?php if ($notes === []): ?>
    <div class="empty">No notes yet.</div>
<?php else: ?>
    <div class="scroll">
        <table>
            <thead>
            <tr><th>Note</th><th class="num">Priority</th><th>Status</th><th>Indexed</th><th>Expires</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($notes as $note): ?>
                <?php $active = (int) $note['is_active'] === 1; ?>
                <tr>
                    <td>
                        <strong><?= $e($note['title']) ?></strong><br>
                        <span class="muted"><?= $e(mb_strimwidth((string) $note['body'], 0, 110, '…')) ?></span>
                    </td>
                    <td class="num"><?= $e((int) $note['priority']) ?></td>
                    <td>
                        <span class="tag <?= $active ? 'ok' : '' ?>"><?= $active ? 'active' : 'retired' ?></span>
                    </td>
                    <td>
                        <?php if ($note['indexed_at'] === null): ?>
                            <span class="tag warn">pending</span>
                        <?php else: ?>
                            <span class="muted"><?= $e($note['indexed_at']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="muted"><?= $note['expires_at'] === null ? '—' : $e($note['expires_at']) ?></td>
                    <td>
                        <div class="actions" style="margin-top:0">
                            <a class="btn secondary"
                               href="<?= $e($base . '/knowledge?edit=' . (int) $note['id']) ?>">Edit</a>

                            <form method="post" action="<?= $e($base . '/knowledge') ?>">
                                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                                <input type="hidden" name="id" value="<?= $e((int) $note['id']) ?>">
                                <input type="hidden" name="action" value="<?= $active ? 'note-expire' : 'note-restore' ?>">
                                <button class="btn secondary" type="submit"><?= $active ? 'Retire' : 'Restore' ?></button>
                            </form>

                            <form method="post" action="<?= $e($base . '/knowledge') ?>"
                                  onsubmit="return confirm('Delete this note permanently? Retiring it instead keeps the text and is reversible.')">
                                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                                <input type="hidden" name="id" value="<?= $e((int) $note['id']) ?>">
                                <input type="hidden" name="action" value="note-delete">
                                <button class="btn danger" type="submit">Delete</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php if ($runs !== []): ?>
    <h2>Recent index runs</h2>
    <div class="scroll">
        <table>
            <thead>
            <tr><th>When</th><th class="num">Indexed</th><th class="num">Skipped</th><th class="num">Chunks</th>
                <th class="num">Embedded</th><th class="num">Cost</th><th class="num">Duration</th></tr>
            </thead>
            <tbody>
            <?php foreach ($runs as $run): ?>
                <tr>
                    <td class="muted"><?= $e($run['created_at']) ?></td>
                    <td class="num"><?= $e((int) $run['documents_indexed']) ?></td>
                    <td class="num"><?= $e((int) $run['documents_skipped']) ?></td>
                    <td class="num"><?= $e((int) $run['chunks_written']) ?></td>
                    <td class="num"><?= $e((int) $run['chunks_embedded']) ?></td>
                    <td class="num">$<?= $e(number_format((float) $run['embedding_cost_usd'], 4)) ?></td>
                    <td class="num"><?= $e(number_format((int) $run['duration_ms'])) ?> ms</td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
