<?php
/**
 * Settings: API keys, chat behaviour, widget branding.
 *
 * API keys are write-only here. The stored value is never sent to the browser —
 * only whether one is set — so a compromised admin session cannot read the keys
 * back out, and a blank field on save means "leave it alone" rather than
 * "delete it".
 *
 * @var callable $e
 * @var string $base
 * @var string $csrf
 * @var array<string, mixed> $overrides
 * @var array<string, array<string, string>> $providers
 * @var array<string, mixed> $config
 */
$get = static function (string $path) use ($config) {
    $cursor = $config;

    foreach (explode('.', $path) as $segment) {
        if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
            return null;
        }
        $cursor = $cursor[$segment];
    }

    return $cursor;
};

$field = static fn (string $path): string => str_replace('.', '__', $path);
$isOverridden = static fn (string $path): bool => array_key_exists($path, $overrides);
?>
<p class="muted" style="font-size:14px;margin-top:-8px">
    Changes here are stored in the database and layered over your <code>.env</code> file, so nothing
    on disk needs to be writable by the web server. Database credentials and <code>APP_KEY</code>
    stay in <code>.env</code> deliberately — the first is needed to reach these settings at all, and
    changing the second would invalidate every issued identity token.
</p>

<form method="post" action="<?= $e($base . '/settings') ?>">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="action" value="save">

    <h2>Provider API keys</h2>
    <?php foreach ($providers as $slug => $meta): ?>
        <?php
        $keyPath   = $meta['key'] . '.api_key';
        $modelPath = $meta['key'] . '.model';
        $hasKey    = (string) $get($keyPath) !== '';
        ?>
        <div class="card">
            <h2 style="margin-top:0;font-size:16px">
                <?= $e($meta['label']) ?>
                <?php if ($hasKey): ?>
                    <span class="tag ok">key set</span>
                <?php else: ?>
                    <span class="tag">no key</span>
                <?php endif; ?>
                <?php if ($isOverridden($keyPath)): ?>
                    <span class="tag warn">set here</span>
                <?php endif; ?>
            </h2>

            <div class="row">
                <div class="field">
                    <label for="<?= $e($field($keyPath)) ?>">API key</label>
                    <input type="password" id="<?= $e($field($keyPath)) ?>" name="<?= $e($field($keyPath)) ?>"
                           autocomplete="off" placeholder="<?= $hasKey ? 'unchanged' : 'not set' ?>">
                    <div class="hint">Leave blank to keep the current key. Keys are never displayed.</div>
                </div>
                <div class="field">
                    <label for="<?= $e($field($modelPath)) ?>">Model</label>
                    <input type="text" id="<?= $e($field($modelPath)) ?>" name="<?= $e($field($modelPath)) ?>"
                           value="<?= $e((string) $get($modelPath)) ?>">
                </div>
            </div>

            <?php if ($isOverridden($keyPath)): ?>
                <div class="hint">
                    This key was set from the admin panel and overrides your .env file.
                </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>

    <div class="card">
        <h2 style="margin-top:0;font-size:16px">Default provider</h2>
        <div class="field">
            <label for="<?= $e($field('providers.default')) ?>">Used when no routing rule matches</label>
            <select id="<?= $e($field('providers.default')) ?>" name="<?= $e($field('providers.default')) ?>">
                <?php foreach (['deepseek', 'claude', 'openai'] as $option): ?>
                    <option value="<?= $e($option) ?>" <?= $get('providers.default') === $option ? 'selected' : '' ?>>
                        <?= $e($option) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <h2>Embeddings</h2>
    <div class="card">
        <p class="muted" style="font-size:13px;margin-top:0">
            Optional. Anthropic has no embeddings endpoint, so semantic search needs a key from a
            provider that does. Left off, retrieval runs on full-text search alone, which handles a
            hosting FAQ well.
        </p>
        <div class="row">
            <div class="field">
                <label for="<?= $e($field('knowledge.embeddings.driver')) ?>">Driver</label>
                <select id="<?= $e($field('knowledge.embeddings.driver')) ?>"
                        name="<?= $e($field('knowledge.embeddings.driver')) ?>">
                    <?php foreach (['none' => 'Keyword search only', 'openai' => 'OpenAI embeddings'] as $v => $label): ?>
                        <option value="<?= $e($v) ?>" <?= $get('knowledge.embeddings.driver') === $v ? 'selected' : '' ?>>
                            <?= $e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="<?= $e($field('knowledge.embeddings.openai.api_key')) ?>">Embeddings API key</label>
                <input type="password" autocomplete="off"
                       id="<?= $e($field('knowledge.embeddings.openai.api_key')) ?>"
                       name="<?= $e($field('knowledge.embeddings.openai.api_key')) ?>"
                       placeholder="<?= (string) $get('knowledge.embeddings.openai.api_key') !== '' ? 'unchanged' : 'not set' ?>">
            </div>
        </div>
        <div class="hint">Changing the driver requires a re-index before semantic search works.</div>
    </div>

    <h2>Chat behaviour</h2>
    <div class="card">
        <div class="row">
            <div class="field">
                <label for="<?= $e($field('chat.company_name')) ?>">Company name</label>
                <input type="text" id="<?= $e($field('chat.company_name')) ?>"
                       name="<?= $e($field('chat.company_name')) ?>"
                       value="<?= $e((string) $get('chat.company_name')) ?>">
                <div class="hint">How the assistant introduces itself.</div>
            </div>
            <div class="field">
                <label for="<?= $e($field('chat.history_turns')) ?>">Conversation memory (turns)</label>
                <input type="number" min="0" max="50" id="<?= $e($field('chat.history_turns')) ?>"
                       name="<?= $e($field('chat.history_turns')) ?>"
                       value="<?= $e((int) $get('chat.history_turns')) ?>">
                <div class="hint">Every replayed turn is re-billed on each message.</div>
            </div>
            <div class="field">
                <label for="<?= $e($field('context.max_tokens')) ?>">Context budget (tokens)</label>
                <input type="number" min="200" max="50000" id="<?= $e($field('context.max_tokens')) ?>"
                       name="<?= $e($field('context.max_tokens')) ?>"
                       value="<?= $e((int) $get('context.max_tokens')) ?>">
                <div class="hint">Knowledge passages are trimmed first when this is reached.</div>
            </div>
        </div>

        <div class="field">
            <label style="font-weight:400;font-size:14px">
                <input type="checkbox" name="<?= $e($field('chat.tools.enabled')) ?>" value="1"
                    <?= $get('chat.tools.enabled') ? 'checked' : '' ?> style="width:auto;margin-right:6px">
                Let the assistant look things up (service status, etc.)
            </label>
            <div class="hint">Tools are only ever offered to signed-in, verified customers.</div>
        </div>

        <div class="field">
            <label style="font-weight:400;font-size:14px">
                <input type="checkbox" name="<?= $e($field('chat.tools.enable_actions')) ?>" value="1"
                    <?= $get('chat.tools.enable_actions') ? 'checked' : '' ?> style="width:auto;margin-right:6px">
                Allow actions that change things (password reset, service restart)
            </label>
            <div class="hint">
                Requires an ActionExecutorInterface implementation as well. Without one these refuse
                honestly rather than pretending to work. The customer must also confirm each action.
            </div>
        </div>
    </div>

    <h2>Widget appearance</h2>
    <div class="card">
        <div class="row">
            <div class="field">
                <label for="<?= $e($field('widget.title')) ?>">Title</label>
                <input type="text" id="<?= $e($field('widget.title')) ?>" name="<?= $e($field('widget.title')) ?>"
                       value="<?= $e((string) $get('widget.title')) ?>">
            </div>
            <div class="field">
                <label for="<?= $e($field('widget.subtitle')) ?>">Subtitle</label>
                <input type="text" id="<?= $e($field('widget.subtitle')) ?>" name="<?= $e($field('widget.subtitle')) ?>"
                       value="<?= $e((string) $get('widget.subtitle')) ?>">
            </div>
            <div class="field">
                <label for="<?= $e($field('widget.accent')) ?>">Accent colour</label>
                <input type="text" id="<?= $e($field('widget.accent')) ?>" name="<?= $e($field('widget.accent')) ?>"
                       value="<?= $e((string) $get('widget.accent')) ?>" placeholder="#2563eb">
            </div>
            <div class="field">
                <label for="<?= $e($field('widget.position')) ?>">Position</label>
                <select id="<?= $e($field('widget.position')) ?>" name="<?= $e($field('widget.position')) ?>">
                    <?php foreach (['bottom-right' => 'Bottom right', 'bottom-left' => 'Bottom left'] as $v => $label): ?>
                        <option value="<?= $e($v) ?>" <?= $get('widget.position') === $v ? 'selected' : '' ?>>
                            <?= $e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="field">
            <label for="<?= $e($field('widget.welcome')) ?>">Welcome message</label>
            <input type="text" id="<?= $e($field('widget.welcome')) ?>" name="<?= $e($field('widget.welcome')) ?>"
                   value="<?= $e((string) $get('widget.welcome')) ?>">
        </div>

        <div class="field">
            <label for="<?= $e($field('widget.suggestions')) ?>">Suggested questions</label>
            <input type="text" id="<?= $e($field('widget.suggestions')) ?>"
                   name="<?= $e($field('widget.suggestions')) ?>"
                   value="<?= $e(implode(' | ', (array) $get('widget.suggestions'))) ?>">
            <div class="hint">
                Separated by <code>|</code>. Shown on an empty chat — a blank box gets far fewer
                first messages than one that shows what it can answer.
            </div>
        </div>

        <div class="field">
            <label style="font-weight:400;font-size:14px">
                <input type="checkbox" name="<?= $e($field('widget.typewriter')) ?>" value="1"
                    <?= $get('widget.typewriter') ? 'checked' : '' ?> style="width:auto;margin-right:6px">
                Reveal answers progressively
            </label>
        </div>

        <div class="field">
            <label style="font-weight:400;font-size:14px">
                <input type="checkbox" name="<?= $e($field('widget.branding')) ?>" value="1"
                    <?= $get('widget.branding') ? 'checked' : '' ?> style="width:auto;margin-right:6px">
                Show “AI assistant — answers may be imperfect” under the composer
            </label>
        </div>
    </div>

    <div class="actions">
        <button class="btn" type="submit">Save settings</button>
    </div>
</form>

<?php if ($overrides !== []): ?>
    <h2>Overriding your .env file</h2>
    <p class="muted" style="font-size:13px;margin-top:-4px">
        These settings were changed here and take precedence over the file. Revert one to fall back
        to whatever <code>.env</code> says.
    </p>
    <div class="scroll">
        <table>
            <thead><tr><th>Setting</th><th>Value</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($overrides as $path => $value): ?>
                <tr>
                    <td><code><?= $e($path) ?></code></td>
                    <td>
                        <?php if (\Hostorio\Admin\SettingsStore::isSecret($path)): ?>
                            <span class="muted">(hidden)</span>
                        <?php else: ?>
                            <?= $e(is_scalar($value) ? (string) $value : json_encode($value)) ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <form method="post" action="<?= $e($base . '/settings') ?>">
                            <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                            <input type="hidden" name="action" value="reset">
                            <input type="hidden" name="path" value="<?= $e($path) ?>">
                            <button class="btn secondary" type="submit">Revert</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
