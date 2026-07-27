<?php
/**
 * Routing rules editor.
 *
 * @var callable $e
 * @var string $base
 * @var string $csrf
 * @var array<string, mixed> $rules
 * @var array<int, string> $types
 * @var array<int, string> $providers
 * @var array<string, bool> $enabled
 * @var mixed $temperature
 * @var int $threshold
 */
$descriptions = [
    'action'  => 'Questions asking you to <em>do</em> something — reset a password, restart a service. '
               . 'Needs a provider that can call tools reliably; a fumbled action costs a support ticket, '
               . 'which is worth far more than the tokens saved.',
    'complex' => 'Troubleshooting — “why…”, error codes, long messages. A wrong diagnosis creates the '
               . 'ticket the chatbot existed to prevent, so it is worth a stronger model.',
    'simple'  => 'Everything else. This is the bulk of the volume and belongs on the cheapest model '
               . 'that can answer it.',
];
?>
<p class="muted" style="font-size:14px;margin-top:-8px">
    Providers are tried in the order listed. The first one that is enabled and capable answers;
    if it fails, the next takes over. Classification itself is keyword-based and costs nothing —
    see <code>config/routing.php</code> for the keyword lists.
</p>

<form method="post" action="<?= $e($base . '/routing') ?>">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">

    <?php foreach ($types as $type): ?>
        <?php
        $rule = is_array($rules[$type] ?? null) ? $rules[$type] : [];
        $order = array_values(array_filter((array) ($rule['providers'] ?? []), 'is_string'));
        ?>
        <div class="card">
            <h2 style="margin-top:0"><?= $e(ucfirst($type)) ?> questions</h2>
            <p class="muted" style="font-size:13px"><?= $descriptions[$type] ?? '' ?></p>

            <div class="field">
                <label for="providers_<?= $e($type) ?>">Provider order</label>
                <input type="text" id="providers_<?= $e($type) ?>" name="providers_<?= $e($type) ?>"
                       value="<?= $e(implode(', ', $order)) ?>">
                <div class="hint">
                    Comma separated, first choice first. Available:
                    <?php foreach ($providers as $i => $name): ?>
                        <?= $i > 0 ? ', ' : '' ?>
                        <code><?= $e($name) ?></code><?= ($enabled[$name] ?? false) ? '' : ' <span class="tag bad">no API key</span>' ?>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="row">
                <div class="field">
                    <label for="max_tokens_<?= $e($type) ?>">Answer length limit (tokens)</label>
                    <input type="number" id="max_tokens_<?= $e($type) ?>" name="max_tokens_<?= $e($type) ?>"
                           min="64" max="8192" value="<?= $e((int) ($rule['max_tokens'] ?? 1024)) ?>">
                    <div class="hint">Answers cut off at this length are flagged as incomplete.</div>
                </div>
                <div class="field">
                    <label>Extended thinking</label>
                    <label style="font-weight:400;font-size:14px">
                        <input type="checkbox" name="thinking_<?= $e($type) ?>" value="1"
                            <?= ($rule['thinking'] ?? false) ? 'checked' : '' ?>
                               style="width:auto;margin-right:6px">
                        Let the model reason before answering
                    </label>
                    <div class="hint">Better on hard diagnoses; slower and more expensive.</div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <div class="card">
        <h2 style="margin-top:0">Global</h2>
        <div class="row">
            <div class="field">
                <label for="temperature">Temperature</label>
                <input type="text" id="temperature" name="temperature"
                       value="<?= $e($temperature === null ? '' : (string) $temperature) ?>"
                       placeholder="0.3">
                <div class="hint">
                    Blank sends none at all. Low values keep support answers consistent.
                    Ignored by models that reject the parameter.
                </div>
            </div>
            <div class="field">
                <label for="threshold">Long-message threshold (characters)</label>
                <input type="number" id="threshold" name="threshold" min="0" max="4000"
                       value="<?= $e($threshold) ?>">
                <div class="hint">
                    Longer messages are treated as troubleshooting — people write at length when
                    something is actually wrong. 0 disables it.
                </div>
            </div>
        </div>
    </div>

    <div class="actions">
        <button class="btn" type="submit">Save routing rules</button>
    </div>
</form>
