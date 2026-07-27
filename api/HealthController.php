<?php

declare(strict_types=1);

namespace Hostorio\Api;

use Hostorio\Core\Config;
use Hostorio\Core\Env;
use Hostorio\Core\Request;
use Hostorio\Core\Response;
use Hostorio\Database\AppDatabase;
use Hostorio\Database\WhmcsDatabase;
use Hostorio\Database\WordPressDatabase;

/**
 * Diagnostics endpoint.
 *
 * This is the Phase 1 deliverable made observable: it proves configuration
 * loaded, the three database connectors behave as expected, and the storage
 * paths are writable — before any chat logic exists to obscure the results.
 */
final class HealthController
{
    /**
     * Public liveness probe. Deliberately reveals nothing about the install.
     */
    public function ping(Request $request): Response
    {
        return Response::ok([
            'service' => 'hostorio-ai-chatbot',
            'version' => Config::get('app.version'),
        ]);
    }

    /**
     * Detailed diagnostics.
     *
     * Guarded: only reachable when APP_DEBUG is on, or when the caller
     * presents the admin token. The output names databases and enabled
     * providers, which is exactly the reconnaissance an attacker wants.
     */
    public function diagnostics(Request $request): Response
    {
        if (!$this->authorized($request)) {
            return Response::error('Not found.', 404, 'not_found');
        }

        $checks = [
            'environment' => $this->checkEnvironment(),
            'storage'     => $this->checkStorage(),
            'databases'   => $this->checkDatabases(),
            'providers'   => $this->checkProviders(),
        ];

        $healthy = $this->isHealthy($checks);

        // `ok` tracks the health verdict, not merely "the request ran", so an
        // uptime monitor watching either the status code or the envelope sees
        // the same answer.
        return new Response(
            [
                'ok'      => $healthy,
                'healthy' => $healthy,
                'version' => Config::get('app.version'),
                'php'     => PHP_VERSION,
                'checks'  => $checks,
            ],
            $healthy ? 200 : 503
        );
    }

    /**
     * Diagnostics are open in debug mode; otherwise they require the admin
     * password hash to be configured and a matching bearer token.
     */
    private function authorized(Request $request): bool
    {
        if (Config::get('app.debug', false)) {
            return true;
        }

        $hash = (string) Config::get('security.admin_password_hash', '');

        if ($hash === '') {
            return false;
        }

        $header = $request->header('authorization', '') ?? '';
        $token  = str_starts_with($header, 'Bearer ') ? substr($header, 7) : '';

        return $token !== '' && password_verify($token, $hash);
    }

    /**
     * @return array<string, mixed>
     */
    private function checkEnvironment(): array
    {
        $appKey = (string) Config::get('app.key', '');

        return [
            'env_file_found'  => Env::has('DB_NAME') || Env::has('APP_KEY'),
            'app_env'         => Config::get('app.env'),
            'debug'           => Config::get('app.debug'),
            'app_key_set'     => $appKey !== '',
            'app_key_strong'  => strlen($appKey) >= 32,
            'timezone'        => date_default_timezone_get(),
            'curl_available'  => function_exists('curl_init'),
            'pdo_mysql'       => in_array('mysql', \PDO::getAvailableDrivers(), true),
            'mbstring'        => extension_loaded('mbstring'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkStorage(): array
    {
        $paths = [
            'logs'  => HOAI_ROOT . '/storage/logs',
            'cache' => HOAI_ROOT . '/storage/cache',
        ];

        $result = [];

        foreach ($paths as $name => $path) {
            if (!is_dir($path)) {
                @mkdir($path, 0750, true);
            }

            $result[$name] = [
                'path'     => $path,
                'exists'   => is_dir($path),
                'writable' => is_dir($path) && is_writable($path),
            ];
        }

        return $result;
    }

    /**
     * Reports each connector's configuration state, reachability, and — for
     * WordPress/WHMCS — whether the schema looks like what we expect.
     *
     * @return array<string, mixed>
     */
    private function checkDatabases(): array
    {
        $app       = AppDatabase::instance();
        $wordpress = WordPressDatabase::instance();
        $whmcs     = WhmcsDatabase::instance();

        return [
            'app' => [
                'configured' => $app->isConfigured(),
                'connected'  => $app->isConfigured() && $app->ping(),
                'read_only'  => $app->isReadOnly(),
                'installed'  => $app->isInstalled(),
                'prefix'     => $app->prefix(),
                'required'   => true,
            ],
            'wordpress' => [
                'configured'    => $wordpress->isConfigured(),
                'connected'     => $wordpress->isConfigured() && $wordpress->ping(),
                'read_only'     => $wordpress->isReadOnly(),
                'schema_valid'  => $wordpress->looksLikeWordPress(),
                'table_prefix'  => $wordpress->tablePrefix(),
                'required'      => false,
            ],
            'whmcs' => [
                'configured'   => $whmcs->isConfigured(),
                'connected'    => $whmcs->isConfigured() && $whmcs->ping(),
                'read_only'    => $whmcs->isReadOnly(),
                'schema_valid' => $whmcs->looksLikeWhmcs(),
                'required'     => false,
            ],
        ];
    }

    /**
     * Reports which LLM providers have credentials. Never echoes the keys.
     *
     * @return array<string, mixed>
     */
    private function checkProviders(): array
    {
        $result = ['default' => Config::get('providers.default')];

        foreach (['claude', 'deepseek', 'openai'] as $provider) {
            $result[$provider] = [
                'enabled'        => (bool) Config::get('providers.' . $provider . '.enabled', false),
                'model'          => Config::get('providers.' . $provider . '.model'),
                'supports_tools' => (bool) Config::get('providers.' . $provider . '.supports_tools', false),
            ];
        }

        $anyEnabled = false;

        foreach (['claude', 'deepseek', 'openai'] as $provider) {
            if ($result[$provider]['enabled']) {
                $anyEnabled = true;
                break;
            }
        }

        $result['any_enabled'] = $anyEnabled;

        return $result;
    }

    /**
     * The install is healthy when the required pieces work. A missing
     * WordPress or WHMCS connection degrades the knowledge base but does not
     * stop the chatbot from answering, so it is not fatal here.
     *
     * @param array<string, mixed> $checks
     */
    private function isHealthy(array $checks): bool
    {
        return $checks['databases']['app']['connected'] === true
            && $checks['storage']['logs']['writable'] === true
            && $checks['environment']['curl_available'] === true
            && $checks['environment']['pdo_mysql'] === true;
    }
}
