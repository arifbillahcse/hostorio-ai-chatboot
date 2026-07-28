<?php
/**
 * Diagnostics: a readable version of what /api/health/diagnostics reports,
 * for verifying (or debugging) database connections without a terminal.
 *
 * @var callable $e
 * @var string $php
 * @var array<string, bool> $extensions
 * @var array<string, bool> $storage
 * @var array<string, array<string, mixed>> $databases
 * @var array<string, bool> $providers
 */
$pill = static function (bool $ok, string $okLabel, string $failLabel) use ($e): string {
    $class = $ok ? 'ok' : 'bad';
    $label = $ok ? $okLabel : $failLabel;

    return '<span class="tag ' . $class . '">' . $e($label) . '</span>';
};
?>
<p class="muted" style="margin-top:-8px">
    Checked fresh on every page load — reload this page any time to re-test.
    This is the same information as <code>GET /api/health/diagnostics</code>, read here without needing a terminal.
</p>

<h2>Databases</h2>
<div class="grid">
    <?php foreach ($databases as $db): ?>
        <div class="stat">
            <div class="label"><?= $e($db['label']) ?><?= $db['required'] ? '' : ' (optional)' ?></div>
            <div class="value" style="font-size:16px;margin-top:8px">
                <?php if (!$db['configured']): ?>
                    <span class="tag"><?= $db['required'] ? 'not configured' : 'disabled' ?></span>
                <?php elseif ($db['connected']): ?>
                    <span class="tag ok">connected</span>
                <?php else: ?>
                    <span class="tag bad">cannot connect</span>
                <?php endif; ?>
                <?php if ($db['configured'] && $db['connected']): ?>
                    <?= $pill($db['installed'], 'schema ready', 'schema missing') ?>
                <?php endif; ?>
            </div>
            <?php if ($db['configured']): ?>
                <div class="sub" style="margin-top:8px;line-height:1.9">
                    host: <code><?= $e($db['config']['host']) ?>:<?= $e($db['config']['port']) ?></code><br>
                    database: <code><?= $e($db['config']['name']) ?></code><br>
                    user: <code><?= $e($db['config']['user']) ?></code><br>
                    password: <?= $db['config']['pass_set'] ? 'set' : '<span class="tag warn">blank</span>' ?>
                </div>
            <?php else: ?>
                <div class="sub" style="margin-top:8px">
                    <?= $db['required']
                        ? 'Fill in DB_HOST / DB_NAME / DB_USER / DB_PASS in .env.'
                        : 'Leave blank on purpose, or fill in the matching *_DB_* values in .env to enable it.' ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>

<?php
$anyMisconfigured = false;
foreach ($databases as $db) {
    if ($db['required'] && (!$db['configured'] || !$db['connected'] || !$db['installed'])) {
        $anyMisconfigured = true;
    }
}
?>
<?php if ($anyMisconfigured): ?>
    <div class="card" style="border-color:#fecaca;background:#fef2f2">
        <strong>The application database isn't fully working yet.</strong>
        <p class="muted" style="font-size:13px;margin-bottom:0">
            Common causes: <code>DB_USER</code> accidentally set to the database name instead of a real
            MySQL user (e.g. <code>root</code>), a wrong <code>DB_PASS</code>, or the schema not installed yet
            (run <code>php tools/install.php</code>). Check
            <code>storage/logs/app-YYYY-MM-DD.log</code> for the exact MySQL error if this doesn't explain it.
        </p>
    </div>
<?php endif; ?>

<h2>Environment</h2>
<div class="card">
    <div class="row">
        <div>PHP version<br><strong><?= $e($php) ?></strong></div>
        <div>cURL extension<br><?= $pill($extensions['curl'], 'available', 'missing') ?></div>
        <div>PDO MySQL driver<br><?= $pill($extensions['pdo_mysql'], 'available', 'missing') ?></div>
        <div>mbstring extension<br><?= $pill($extensions['mbstring'], 'available', 'missing') ?></div>
    </div>
</div>

<h2>Storage</h2>
<div class="card">
    <div class="row">
        <div>storage/logs<br><?= $pill($storage['logs'], 'writable', 'not writable') ?></div>
        <div>storage/cache<br><?= $pill($storage['cache'], 'writable', 'not writable') ?></div>
    </div>
</div>

<h2>AI providers</h2>
<div class="card">
    <div class="row">
        <div>Claude<br><?= $pill($providers['claude'], 'key set', 'no key') ?></div>
        <div>DeepSeek<br><?= $pill($providers['deepseek'], 'key set', 'no key') ?></div>
        <div>OpenAI<br><?= $pill($providers['openai'], 'key set', 'no key') ?></div>
    </div>
    <p class="muted" style="font-size:13px;margin-bottom:0">
        Add or change keys under <a href="<?= $e($base . '/settings') ?>">Settings</a>.
    </p>
</div>
