<?php

declare(strict_types=1);

namespace Hostorio\Admin;

use Hostorio\Core\Config;
use Hostorio\Core\CostGuard;
use Hostorio\Core\Logger;
use Hostorio\Core\Request;
use Hostorio\Core\Response;
use Hostorio\Core\Security;
use Hostorio\Knowledge\Indexer;
use Hostorio\Knowledge\KnowledgeStore;
use Hostorio\Knowledge\ManualNotes;
use Hostorio\Llm\Classification;
use Throwable;

/**
 * The admin panel.
 *
 * Everything is behind one password and every mutation is a POST carrying a
 * CSRF token, then redirected — so a refresh cannot replay an action and a
 * third-party page cannot trigger one.
 */
final class AdminController
{
    private readonly View $view;

    public function __construct(?View $view = null)
    {
        $this->view = $view ?? new View();
    }

    /**
     * Single entry point for every /admin/* request.
     */
    public function handle(Request $request, string $page): Response
    {
        AdminAuth::startSession($request);

        // Login and logout are the only routes reachable while signed out.
        if ($page === 'login') {
            return $request->method === 'POST'
                ? $this->doLogin($request)
                : $this->loginPage();
        }

        if ($page === 'logout') {
            if ($request->method === 'POST' && AdminAuth::verifyCsrf($request)) {
                AdminAuth::logout();
            }

            return Response::redirect(View::basePath() . '/login');
        }

        if (!AdminAuth::isLoggedIn()) {
            return Response::redirect(View::basePath() . '/login');
        }

        // Every mutation must prove it came from the panel.
        if ($request->method === 'POST' && !AdminAuth::verifyCsrf($request)) {
            Logger::warning('Admin CSRF check failed', ['page' => $page, 'ip' => $request->ip]);

            return $this->page($page, $request, [
                'error' => 'That form has expired. Please try again.',
            ]);
        }

        try {
            if ($request->method === 'POST') {
                return $this->handlePost($request, $page);
            }

            if ($page === 'conversation/export') {
                return $this->exportConversation($request);
            }

            return $this->page($page, $request);
        } catch (Throwable $e) {
            Logger::error('Admin page failed', [
                'page'      => $page,
                'exception' => $e::class,
                'message'   => $e->getMessage(),
            ]);

            return $this->page($page === '' ? '' : $page, $request, [
                'error' => Config::get('app.debug', false)
                    ? $e->getMessage()
                    : 'Something went wrong loading that page. Check the logs for details.',
            ]);
        }
    }

    // ── Login ────────────────────────────────────────────────────────────────

    private function loginPage(string $error = ''): Response
    {
        return Response::html($this->view->renderBare('login', [
            'error'      => $error,
            'configured' => AdminAuth::isConfigured(),
        ]));
    }

    private function doLogin(Request $request): Response
    {
        // The login form is the one POST without an established session, so its
        // token is issued on the GET and checked here.
        if (!AdminAuth::verifyCsrf($request)) {
            return $this->loginPage('That form has expired. Please try again.');
        }

        $password = $request->input('password', '');

        $result = AdminAuth::attempt(is_string($password) ? $password : '', $request);

        if (!$result['ok']) {
            return $this->loginPage($result['message']);
        }

        return Response::redirect(View::basePath());
    }

    // ── Page dispatch ────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $extra
     */
    private function page(string $page, Request $request, array $extra = []): Response
    {
        $data = match ($page) {
            ''              => $this->dashboardData(),
            'conversations' => $this->conversationsData($request),
            'conversation'  => $this->conversationData($request),
            'knowledge'     => $this->knowledgeData(),
            'routing'       => $this->routingData(),
            'settings'      => $this->settingsData(),
            default         => ['title' => 'Not found', 'template' => 'notfound', 'active' => ''],
        };

        $flash = $this->takeFlash();

        $data = array_merge($data, array_filter($extra, static fn ($v): bool => $v !== null));

        if ($flash !== '' && !isset($data['flash'])) {
            $data['flash'] = $flash;
        }

        return Response::html($this->view->render((string) $data['template'], $data));
    }

    /**
     * @return array<string, mixed>
     */
    private function dashboardData(): array
    {
        $metrics = new Metrics();

        $budget = ['status' => 'ok', 'reasons' => [], 'hourly' => [], 'daily' => [], 'blocked' => false];

        try {
            $budget = CostGuard::check();
        } catch (Throwable $e) {
            Logger::warning('Could not evaluate spend budgets', ['error' => $e->getMessage()]);
        }

        return [
            'title'     => 'Dashboard',
            'template'  => 'dashboard',
            'active'    => '',
            'budget'    => $budget,
            'summary'   => $metrics->summary(30),
            'providers' => $metrics->byProvider(30),
            'days'      => $metrics->byDay(14),
            'types'     => $metrics->byQueryType(30),
            'questions' => $metrics->commonQuestions(30, 15),
            'tools'     => $metrics->recentToolCalls(10),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function conversationsData(Request $request): array
    {
        $metrics = new Metrics();

        $search = trim((string) $request->input('q', ''));
        $page   = max(1, (int) $request->input('page', 1));
        $perPage = 30;

        $total = $metrics->countConversations($search);

        return [
            'title'    => 'Conversations',
            'template' => 'conversations',
            'active'   => 'conversations',
            'search'   => $search,
            'page'     => $page,
            'perPage'  => $perPage,
            'total'    => $total,
            'rows'     => $metrics->recentConversations($perPage, ($page - 1) * $perPage, $search),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function conversationData(Request $request): array
    {
        $metrics = new Metrics();

        $publicId = Security::sanitizeIdentifier((string) $request->input('id', ''));
        $conversation = $publicId === '' ? null : $metrics->conversation($publicId);

        return [
            'title'        => 'Conversation',
            'template'     => 'conversation',
            'active'       => 'conversations',
            'conversation' => $conversation,
            'messages'     => $conversation === null ? [] : $metrics->conversationMessages((int) $conversation['id']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function knowledgeData(): array
    {
        $store = new KnowledgeStore();
        $notes = new ManualNotes();

        $stats = [];
        $noteRows = [];

        try {
            $stats = $store->stats();
        } catch (Throwable $e) {
            Logger::error('Could not read knowledge stats', ['error' => $e->getMessage()]);
        }

        try {
            $noteRows = $notes->all(true);
        } catch (Throwable $e) {
            Logger::error('Could not read manual notes', ['error' => $e->getMessage()]);
        }

        return [
            'title'    => 'Knowledge base',
            'template' => 'knowledge',
            'active'   => 'knowledge',
            'stats'    => $stats,
            'notes'    => $noteRows,
            'runs'     => (new Metrics())->recentIndexRuns(5),
            'embedder' => Indexer::resolveEmbedder()->name(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function routingData(): array
    {
        return [
            'title'     => 'Routing',
            'template'  => 'routing',
            'active'    => 'routing',
            'rules'     => (array) Config::get('routing.rules', []),
            'types'     => [Classification::ACTION, Classification::COMPLEX, Classification::SIMPLE],
            'providers' => ['claude', 'deepseek', 'openai'],
            'temperature' => Config::get('routing.temperature'),
            'threshold'   => (int) Config::get('routing.complex_length_threshold', 320),
            'enabled'     => [
                'claude'   => (bool) Config::get('providers.claude.enabled', false),
                'deepseek' => (bool) Config::get('providers.deepseek.enabled', false),
                'openai'   => (bool) Config::get('providers.openai.enabled', false),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function settingsData(): array
    {
        $overrides = [];

        try {
            $overrides = (new SettingsStore())->all();
        } catch (Throwable $e) {
            Logger::error('Could not read settings overrides', ['error' => $e->getMessage()]);
        }

        return [
            'title'     => 'Settings',
            'template'  => 'settings',
            'active'    => 'settings',
            'overrides' => $overrides,
            'providers' => [
                'claude'   => ['label' => 'Claude (Anthropic)', 'key' => 'providers.claude'],
                'deepseek' => ['label' => 'DeepSeek',           'key' => 'providers.deepseek'],
                'openai'   => ['label' => 'OpenAI',             'key' => 'providers.openai'],
            ],
            'config'    => $this->safeSettingsConfig(),
        ];
    }

    /**
     * The configuration values the settings page may see.
     *
     * Deliberately not Config::all(). That array holds every provider API key
     * *and* the database passwords, and putting it in template scope means one
     * careless `<?= json_encode($config) ?>` — or a debugging dump left in —
     * publishes the lot to whoever loads the page. Secrets are reduced to a
     * boolean here, so the value cannot be rendered even by accident.
     *
     * @return array<string, mixed>
     */
    private function safeSettingsConfig(): array
    {
        $config = [];

        foreach (SettingsStore::editablePaths() as $path) {
            $value = Config::get($path);

            if (SettingsStore::isSecret($path)) {
                // Only "is one set?" ever reaches the browser.
                $value = is_string($value) && $value !== '' ? '__set__' : '';
            }

            $this->assign($config, $path, $value);
        }

        return $config;
    }

    /**
     * @param array<string, mixed> $target
     */
    private function assign(array &$target, string $path, mixed $value): void
    {
        $segments = explode('.', $path);
        $cursor   = &$target;

        foreach ($segments as $index => $segment) {
            if ($index === count($segments) - 1) {
                $cursor[$segment] = $value;
                break;
            }

            if (!isset($cursor[$segment]) || !is_array($cursor[$segment])) {
                $cursor[$segment] = [];
            }

            $cursor = &$cursor[$segment];
        }
    }

    /**
     * Export one conversation as CSV.
     *
     * Every field is passed through a formula guard. Spreadsheet software
     * executes a cell beginning with =, +, - or @, so a customer who types
     * `=cmd|'/c calc'!A1` into the chat would otherwise get code execution on
     * the machine of whoever opens the export. Prefixing with an apostrophe
     * makes the cell inert while leaving it readable.
     */
    private function exportConversation(Request $request): Response
    {
        $metrics  = new Metrics();
        $publicId = Security::sanitizeIdentifier((string) $request->input('id', ''));

        $conversation = $publicId === '' ? null : $metrics->conversation($publicId);

        if ($conversation === null) {
            return $this->page('conversations', $request, ['error' => 'That conversation does not exist.']);
        }

        $rows = $metrics->conversationMessages((int) $conversation['id']);

        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            return $this->page('conversations', $request, ['error' => 'Could not build the export.']);
        }

        fputcsv($handle, ['time', 'role', 'content', 'provider', 'model', 'query_type',
                          'input_tokens', 'output_tokens', 'context_tokens', 'cost_usd']);

        foreach ($rows as $row) {
            fputcsv($handle, array_map([$this, 'csvSafe'], [
                $row['created_at'], $row['role'], $row['content'],
                $row['provider'] ?? '', $row['model'] ?? '', $row['query_type'] ?? '',
                (int) $row['input_tokens'], (int) $row['output_tokens'],
                (int) $row['context_tokens'], (float) $row['cost_usd'],
            ]));
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        $filename = 'conversation-' . preg_replace('/[^a-z0-9]/i', '', $publicId) . '.csv';

        $response = Response::html($csv);

        return $response->withHeaders([
            'Content-Type'        => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    /**
     * Neutralise spreadsheet formula injection.
     */
    private function csvSafe(mixed $value): string
    {
        $text = (string) $value;

        if ($text !== '' && str_contains("=+-@\t\r", $text[0])) {
            return "'" . $text;
        }

        return $text;
    }

    // ── Mutations ────────────────────────────────────────────────────────────

    private function handlePost(Request $request, string $page): Response
    {
        $action = (string) $request->input('action', '');

        return match ($page) {
            'knowledge' => $this->knowledgeAction($request, $action),
            'routing'   => $this->routingAction($request),
            'settings'  => $this->settingsAction($request, $action),
            default     => Response::redirect(View::basePath()),
        };
    }

    private function knowledgeAction(Request $request, string $action): Response
    {
        $notes = new ManualNotes();
        $base  = View::basePath() . '/knowledge';

        switch ($action) {
            case 'reindex':
                // Synchronous on purpose: shared hosting has no worker process,
                // and a run that silently never happened is worse than one the
                // admin waits a few seconds for. Large corpora resume on the
                // next run, since indexing is driven by content hashes.
                $summary = (new Indexer())->run();

                $this->flash(sprintf(
                    'Re-index finished: %d document(s) indexed, %d chunk(s) written, %d embedded ($%.4f).',
                    $summary['documents_indexed'],
                    $summary['chunks_written'],
                    $summary['chunks_embedded'],
                    $summary['embedding_cost_usd']
                ));
                break;

            case 'note-add':
                $id = $notes->add(
                    (string) $request->input('title', ''),
                    (string) $request->input('body', ''),
                    (int) $request->input('priority', 10),
                    $this->nullableInput($request, 'expires_at')
                );

                $this->flash(sprintf('Note #%d saved. Re-index to make it searchable.', $id));
                break;

            case 'note-edit':
                $id = (int) $request->input('id', 0);

                $changes = [
                    'title'      => (string) $request->input('title', ''),
                    'body'       => (string) $request->input('body', ''),
                    'priority'   => (int) $request->input('priority', 10),
                    'expires_at' => $this->nullableInput($request, 'expires_at'),
                ];

                $this->flash($notes->update($id, $changes)
                    ? sprintf('Note #%d updated. Re-index to apply the change.', $id)
                    : sprintf('No note #%d found.', $id));
                break;

            case 'note-expire':
                $id = (int) $request->input('id', 0);
                $this->flash($notes->expire($id)
                    ? sprintf('Note #%d retired and removed from search immediately.', $id)
                    : sprintf('No note #%d found.', $id));
                break;

            case 'note-restore':
                $id = (int) $request->input('id', 0);
                $this->flash($notes->restore($id)
                    ? sprintf('Note #%d restored. Re-index to make it searchable again.', $id)
                    : sprintf('No note #%d found.', $id));
                break;

            case 'note-delete':
                $id = (int) $request->input('id', 0);
                $this->flash($notes->delete($id)
                    ? sprintf('Note #%d deleted permanently.', $id)
                    : sprintf('No note #%d found.', $id));
                break;
        }

        return Response::redirect($base);
    }

    private function routingAction(Request $request): Response
    {
        $store = new SettingsStore();

        /** @var array<string, mixed> $rules */
        $rules = (array) Config::get('routing.rules', []);

        foreach ([Classification::ACTION, Classification::COMPLEX, Classification::SIMPLE] as $type) {
            $order = $request->input('providers_' . $type, []);

            if (is_string($order)) {
                $order = array_filter(array_map('trim', explode(',', $order)));
            }

            if (!is_array($order)) {
                continue;
            }

            // Only known provider names, de-duplicated, order preserved.
            $clean = [];

            foreach ($order as $name) {
                $name = strtolower(trim((string) $name));

                if (in_array($name, ['claude', 'deepseek', 'openai'], true) && !in_array($name, $clean, true)) {
                    $clean[] = $name;
                }
            }

            if ($clean === []) {
                // Refuse to save a class with nowhere to go — that would take
                // the chatbot offline for those questions.
                return $this->page('routing', $request, [
                    'error' => sprintf('"%s" questions need at least one provider.', $type),
                ]);
            }

            $rules[$type] = [
                'providers'  => $clean,
                'max_tokens' => max(64, min(8192, (int) $request->input('max_tokens_' . $type, 1024))),
                'thinking'   => $request->input('thinking_' . $type) !== null,
            ];
        }

        $store->set('routing.rules', $rules);

        $temperature = trim((string) $request->input('temperature', ''));

        if ($temperature === '') {
            $store->set('routing.temperature', null);
        } elseif (is_numeric($temperature)) {
            $store->set('routing.temperature', max(0.0, min(1.0, (float) $temperature)));
        }

        $store->set(
            'routing.complex_length_threshold',
            max(0, (int) $request->input('threshold', 320))
        );

        $this->flash('Routing rules saved.');

        return Response::redirect(View::basePath() . '/routing');
    }

    private function settingsAction(Request $request, string $action): Response
    {
        $store = new SettingsStore();

        if ($action === 'reset') {
            $path = (string) $request->input('path', '');

            if (SettingsStore::isEditable($path)) {
                $store->forget($path);
                $this->flash(sprintf('"%s" reverted to the value in your .env file.', $path));
            }

            return Response::redirect(View::basePath() . '/settings');
        }

        $saved = 0;

        foreach (SettingsStore::editablePaths() as $path) {
            // Rules are edited on their own page, not here.
            if ($path === 'routing.rules') {
                continue;
            }

            $field = str_replace('.', '__', $path);
            $value = $request->input($field);

            if ($value === null) {
                continue;
            }

            $value = is_string($value) ? trim($value) : $value;

            // A blank secret means "leave it alone", never "erase it" — the
            // field is rendered empty because the value is never sent to the
            // browser, so treating blank as a delete would wipe the key on
            // every save.
            if (SettingsStore::isSecret($path) && $value === '') {
                continue;
            }

            if ($value === '') {
                $store->forget($path);
                continue;
            }

            $store->set($path, $this->coerce($path, $value));
            $saved++;
        }

        // Checkboxes are absent from the body when unticked, so they are read
        // explicitly rather than through the loop above.
        foreach (['chat.tools.enabled', 'chat.tools.enable_actions', 'widget.typewriter', 'widget.branding'] as $flag) {
            $field = str_replace('.', '__', $flag);
            $store->set($flag, $request->input($field) !== null);
        }

        $this->flash('Settings saved.');

        return Response::redirect(View::basePath() . '/settings');
    }

    /**
     * Cast a submitted string to the type the setting expects.
     */
    private function coerce(string $path, mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        $integers = [
            'chat.history_turns', 'chat.tools.max_rounds',
            'context.max_tokens', 'routing.complex_length_threshold',
        ];

        if (in_array($path, $integers, true)) {
            return max(0, (int) $value);
        }

        if ($path === 'widget.suggestions') {
            return array_values(array_filter(array_map('trim', explode('|', $value))));
        }

        if ($path === 'routing.temperature') {
            return is_numeric($value) ? (float) $value : null;
        }

        return $value;
    }

    private function nullableInput(Request $request, string $field): ?string
    {
        $value = trim((string) $request->input($field, ''));

        return $value === '' ? null : $value;
    }

    // ── Flash messages ───────────────────────────────────────────────────────

    private function flash(string $message): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['hoai_flash'] = $message;
        }
    }

    private function takeFlash(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE || empty($_SESSION['hoai_flash'])) {
            return '';
        }

        $message = (string) $_SESSION['hoai_flash'];
        unset($_SESSION['hoai_flash']);

        return $message;
    }
}
