<?php

declare(strict_types=1);

namespace Hostorio\Core;

/**
 * Immutable view of the incoming HTTP request.
 *
 * Body parsing is JSON-first (the widget posts JSON) with a form-encoded
 * fallback so the endpoints stay usable from a plain HTML form or curl.
 */
final class Request
{
    /**
     * @param array<string, mixed>  $query
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers
     */
    private function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $body,
        public readonly array $headers,
        public readonly string $ip,
    ) {
    }

    public static function capture(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri    = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path   = parse_url($uri, PHP_URL_PATH);
        $path   = is_string($path) ? $path : '/';

        return new self(
            method: $method,
            path: self::normalizePath($path),
            query: $_GET,
            body: self::parseBody(),
            headers: self::parseHeaders(),
            ip: Security::clientIp(),
        );
    }

    /**
     * Strip the installation subdirectory so routes are position-independent.
     * A customer who installs into /chatbot/ hits the same route names as one
     * who installs at the domain root.
     *
     * The base is derived from SCRIPT_NAME, but only when it actually points at
     * a PHP file. Under PHP's built-in server SCRIPT_NAME mirrors the request
     * path, and treating that as a base directory would eat real route
     * segments (/api/chat would arrive as /chat).
     */
    private static function normalizePath(string $path): string
    {
        $path = '/' . trim($path, '/');
        $base = self::basePath();

        if ($base !== '' && ($path === $base || str_starts_with($path, $base . '/'))) {
            $path = substr($path, strlen($base));
        }

        return '/' . trim($path, '/');
    }

    /**
     * The URL prefix the application is installed under, without a trailing
     * slash. Empty when installed at the domain root.
     *
     * Examples:
     *   /public/index.php          -> ''            (docroot at project root)
     *   /index.php                 -> ''            (docroot at public/)
     *   /chatbot/public/index.php  -> '/chatbot'    (subdirectory install)
     */
    private static function basePath(): string
    {
        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));

        if (!str_ends_with($script, '.php')) {
            return '';
        }

        $directory = rtrim(dirname($script), '/');

        // The front controller lives in public/, which is not part of the
        // public URL when the host rewrites into it.
        if (str_ends_with($directory, '/public')) {
            $directory = substr($directory, 0, -strlen('/public'));
        }

        return ($directory === '/' ) ? '' : $directory;
    }

    /**
     * @return array<string, mixed>
     */
    private static function parseBody(): array
    {
        $contentType = strtolower($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '');

        if (str_contains($contentType, 'application/json')) {
            $raw = file_get_contents('php://input');

            if ($raw === false || $raw === '') {
                return [];
            }

            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : [];
        }

        return $_POST;
    }

    /**
     * @return array<string, string>
     */
    private static function parseHeaders(): array
    {
        $headers = [];

        foreach ($_SERVER as $key => $value) {
            if (!is_string($key) || !str_starts_with($key, 'HTTP_')) {
                continue;
            }

            $name = strtolower(str_replace('_', '-', substr($key, 5)));
            $headers[$name] = (string) $value;
        }

        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }

        return $headers;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function isJson(): bool
    {
        return str_contains(strtolower($this->header('content-type', '') ?? ''), 'application/json');
    }

    public function wantsJson(): bool
    {
        return $this->isJson()
            || str_contains(strtolower($this->header('accept', '') ?? ''), 'application/json');
    }
}
