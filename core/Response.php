<?php

declare(strict_types=1);

namespace Hostorio\Core;

/**
 * JSON response builder with a consistent envelope.
 *
 * Every endpoint returns {ok: bool, ...} so the widget has a single shape to
 * branch on regardless of which layer produced the response.
 */
final class Response
{
    /**
     * Raw body for non-JSON responses (the admin panel renders HTML). When set,
     * it is sent verbatim and $payload is ignored.
     */
    private ?string $body = null;

    private string $contentType = 'application/json; charset=utf-8';

    /**
     * @param array<string, mixed>  $payload
     * @param array<string, string> $headers
     */
    public function __construct(
        private array $payload,
        private int $status = 200,
        private array $headers = [],
    ) {
    }

    /**
     * An HTML page.
     *
     * Admin pages are never cacheable: they show customer conversations, and a
     * shared proxy holding one is a disclosure waiting to happen.
     */
    public static function html(string $body, int $status = 200): self
    {
        $response = new self([], $status);
        $response->body = $body;
        $response->contentType = 'text/html; charset=utf-8';

        return $response;
    }

    /**
     * Redirect. Used after every successful admin POST so a refresh cannot
     * replay the action.
     */
    public static function redirect(string $location, int $status = 303): self
    {
        $response = new self([], $status, ['Location' => $location]);
        $response->body = '';
        $response->contentType = 'text/html; charset=utf-8';

        return $response;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function ok(array $data = [], int $status = 200): self
    {
        return new self(['ok' => true] + $data, $status);
    }

    /**
     * @param array<string, mixed> $extra
     */
    public static function error(string $message, int $status = 400, string $code = 'error', array $extra = []): self
    {
        return new self([
            'ok'         => false,
            'error'      => ['code' => $code, 'message' => $message],
            'request_id' => Logger::requestId(),
        ] + $extra, $status);
    }

    /**
     * @param array<string, string> $headers
     */
    public function withHeaders(array $headers): self
    {
        $this->headers = array_merge($this->headers, $headers);

        return $this;
    }

    public function send(): void
    {
        if (headers_sent()) {
            Logger::warning('Headers already sent; response may be malformed');
        } else {
            http_response_code($this->status);

            header('Content-Type: ' . $this->contentType);
            header('X-Content-Type-Options: nosniff');
            header('X-Request-Id: ' . Logger::requestId());
            // Chat responses are per-customer; never let a proxy cache them.
            header('Cache-Control: no-store, private');

            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value);
            }
        }

        if ($this->body !== null) {
            echo $this->body;

            return;
        }

        echo json_encode(
            $this->payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR
        );
    }

    public function status(): int
    {
        return $this->status;
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->payload;
    }
}
