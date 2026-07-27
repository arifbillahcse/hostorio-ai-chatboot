<?php

declare(strict_types=1);

namespace Hostorio\Core;

use Throwable;

/**
 * Small exact-match router for the JSON API.
 *
 * No pattern matching or route parameters — the chatbot surface is a handful
 * of fixed endpoints, and keeping the matcher trivial keeps the front
 * controller auditable.
 */
final class Router
{
    /** @var array<string, callable(Request): Response> */
    private array $routes = [];

    /**
     * @param callable(Request): Response $handler
     */
    public function get(string $path, callable $handler): self
    {
        return $this->add('GET', $path, $handler);
    }

    /**
     * @param callable(Request): Response $handler
     */
    public function post(string $path, callable $handler): self
    {
        return $this->add('POST', $path, $handler);
    }

    /**
     * @param callable(Request): Response $handler
     */
    private function add(string $method, string $path, callable $handler): self
    {
        $this->routes[$method . ' ' . '/' . trim($path, '/')] = $handler;

        return $this;
    }

    public function dispatch(Request $request): Response
    {
        // Browsers preflight the widget's cross-origin POST.
        if ($request->method === 'OPTIONS') {
            return Response::ok()->withHeaders($this->corsHeaders($request));
        }

        $key = $request->method . ' ' . $request->path;

        if (!isset($this->routes[$key])) {
            Logger::info('Route not found', ['method' => $request->method, 'path' => $request->path]);

            return Response::error('Endpoint not found.', 404, 'not_found');
        }

        try {
            $response = ($this->routes[$key])($request);
        } catch (Throwable $e) {
            return $this->handleException($e);
        }

        return $response->withHeaders($this->corsHeaders($request));
    }

    /**
     * Turn an uncaught exception into a safe JSON error.
     *
     * The real message goes to the log; the client only sees it when
     * APP_DEBUG is on, so a stack trace can never leak on a production site.
     */
    private function handleException(Throwable $e): Response
    {
        Logger::error('Unhandled exception', [
            'exception' => $e::class,
            'message'   => $e->getMessage(),
            'file'      => $e->getFile(),
            'line'      => $e->getLine(),
        ]);

        if (Config::get('app.debug', false)) {
            return Response::error($e->getMessage(), 500, 'server_error', [
                'debug' => [
                    'exception' => $e::class,
                    'file'      => $e->getFile(),
                    'line'      => $e->getLine(),
                    'trace'     => explode("\n", $e->getTraceAsString()),
                ],
            ]);
        }

        return Response::error(
            'An internal error occurred. Please try again.',
            500,
            'server_error'
        );
    }

    /**
     * The widget is embedded on the customer's own site, so allowed origins
     * are an explicit allowlist rather than a wildcard.
     *
     * @return array<string, string>
     */
    private function corsHeaders(Request $request): array
    {
        /** @var array<int, string> $allowed */
        $allowed = (array) Config::get('security.allowed_origins', []);
        $origin  = $request->header('origin');

        if ($origin === null || $allowed === []) {
            return [];
        }

        if (!in_array($origin, $allowed, true) && !in_array('*', $allowed, true)) {
            return [];
        }

        return [
            'Access-Control-Allow-Origin'  => in_array('*', $allowed, true) ? '*' : $origin,
            'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, X-Requested-With',
            'Access-Control-Max-Age'       => '600',
            'Vary'                         => 'Origin',
        ];
    }

    /**
     * @return array<int, string>
     */
    public function registeredRoutes(): array
    {
        return array_keys($this->routes);
    }
}
