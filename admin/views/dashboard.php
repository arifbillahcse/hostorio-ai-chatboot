<?php
/**
 * Dashboard.
 *
 * @var callable $e
 * @var string $base
 * @var array<string, mixed> $summary
 * @var array<int, array<string, mixed>> $providers
 * @var array<int, array<string, mixed>> $days
 * @var array<int, array<string, mixed>> $types
 * @var array<int, array<string, mixed>> $questions
 * @var array<int, array<string, mixed>> $tools
 */

$maxDayCost = 0.0;
foreach ($days as $d) {
    $maxDayCost = max($maxDayCost, (float) $d['cost']);
}
?>
<div class="grid">
    <div class="stat">
        <div class="label">Spend (30 days)</div>
        <div class="value">$<?= $e(number_format($summary['cost'], 2)) ?></div>
        <div class="sub"><?= $e(number_format($summary['calls'])) ?> model calls</div>
    </div>
    <div class="stat">
        <div class="label">Cost per answer</div>
        <div class="value">$<?= $e(number_format($summary['cost_per_call'], 4)) ?></div>
        <div class="sub">average across all providers</div>
    </div>
    <div class="stat">
        <div class="label">Conversations</div>
        <div class="value"><?= $e(number_format($summary['conversations'])) ?></div>
        <div class="sub"><?= $e(number_format($summary['messages'])) ?> messages</div>
    </div>
    <div class="stat">
        <div class="label">Failed calls</div>
        <div class="value"><?= $e(number_format($summary['failures'])) ?></div>
        <div class="sub">avg <?= $e(number_format($summary['avg_ms'])) ?> ms</div>
    </div>
</div>

<h2>Spend by provider</h2>
<?php if ($providers === []): ?>
    <div class="empty">Nothing recorded yet. Spend appears here once the chatbot answers its first question.</div>
<?php else: ?>
    <div class="scroll">
        <table>
            <thead>
            <tr>
                <th>Provider</th>
                <th class="num">Calls</th>
                <th class="num">Tokens in</th>
                <th class="num">Tokens out</th>
                <th class="num">Failures</th>
                <th class="num">Avg ms</th>
                <th class="num">Cost</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($providers as $row): ?>
                <tr>
                    <td><strong><?= $e($row['provider']) ?></strong></td>
                    <td class="num"><?= $e(number_format((int) $row['calls'])) ?></td>
                    <td class="num"><?= $e(number_format((int) $row['input_tokens'])) ?></td>
                    <td class="num"><?= $e(number_format((int) $row['output_tokens'])) ?></td>
                    <td class="num">
                        <?php if ((int) $row['failures'] > 0): ?>
                            <span class="tag bad"><?= $e((int) $row['failures']) ?></span>
                        <?php else: ?>
                            <span class="muted">0</span>
                        <?php endif; ?>
                    </td>
                    <td class="num"><?= $e(number_format((float) $row['avg_ms'])) ?></td>
                    <td class="num">$<?= $e(number_format((float) $row['cost'], 4)) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<h2>Daily spend</h2>
<?php if ($days === []): ?>
    <div class="empty">No activity in the last 14 days.</div>
<?php else: ?>
    <div class="card">
        <?php foreach ($days as $row): ?>
            <?php $width = $maxDayCost > 0 ? ((float) $row['cost'] / $maxDayCost) * 100 : 0; ?>
            <div style="margin-bottom:10px">
                <div style="display:flex;justify-content:space-between;font-size:13px">
                    <span><?= $e($row['day']) ?></span>
                    <span class="muted">
                        <?= $e(number_format((int) $row['calls'])) ?> calls ·
                        $<?= $e(number_format((float) $row['cost'], 4)) ?>
                    </span>
                </div>
                <div class="bar"><span style="width:<?= $e(number_format($width, 2)) ?>%"></span></div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<h2>Where the questions go</h2>
<?php if ($types === []): ?>
    <div class="empty">No classified answers yet.</div>
<?php else: ?>
    <div class="scroll">
        <table>
            <thead><tr><th>Question type</th><th class="num">Answers</th><th class="num">Cost</th></tr></thead>
            <tbody>
            <?php foreach ($types as $row): ?>
                <tr>
                    <td><span class="tag"><?= $e($row['query_type']) ?></span></td>
                    <td class="num"><?= $e(number_format((int) $row['messages'])) ?></td>
                    <td class="num">$<?= $e(number_format((float) $row['cost'], 4)) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<h2>Most asked</h2>
<p class="muted" style="font-size:13px;margin-top:-4px">
    Repeated questions grouped by their opening words. A theme here with no article behind it is
    usually worth writing one for.
</p>
<?php if ($questions === []): ?>
    <div class="empty">Nothing repeated yet.</div>
<?php else: ?>
    <div class="scroll">
        <table>
            <thead><tr><th>Question</th><th class="num">Asked</th><th>Last asked</th></tr></thead>
            <tbody>
            <?php foreach ($questions as $row): ?>
                <tr>
                    <td><?= $e($row['question']) ?></td>
                    <td class="num"><?= $e((int) $row['asked']) ?></td>
                    <td class="muted"><?= $e($row['last_asked']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<h2>Recent tool activity</h2>
<?php if ($tools === []): ?>
    <div class="empty">No tools have been called.</div>
<?php else: ?>
    <div class="scroll">
        <table>
            <thead>
            <tr><th>When</th><th>Tool</th><th>Customer</th><th>Outcome</th><th>Detail</th></tr>
            </thead>
            <tbody>
            <?php foreach ($tools as $row): ?>
                <?php
                $outcome = (string) $row['outcome'];
                $class = match ($outcome) {
                    'ok'      => (int) $row['destructive'] === 1 ? 'warn' : 'ok',
                    'denied'  => 'bad',
                    'error'   => 'bad',
                    default   => '',
                };
                ?>
                <tr>
                    <td class="muted"><?= $e($row['created_at']) ?></td>
                    <td>
                        <?= $e($row['tool_name']) ?>
                        <?php if ((int) $row['destructive'] === 1): ?>
                            <span class="tag warn">changes data</span>
                        <?php endif; ?>
                    </td>
                    <td><?= $row['customer_id'] === null ? '<span class="muted">—</span>' : $e((int) $row['customer_id']) ?></td>
                    <td><span class="tag <?= $e($class) ?>"><?= $e($outcome) ?></span></td>
                    <td class="muted"><?= $e(mb_strimwidth((string) $row['detail'], 0, 90, '…')) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
