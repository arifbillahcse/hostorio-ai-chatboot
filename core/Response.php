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

            header('Content-Type: application/json; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
            header('X-Request-Id: ' . Logger::requestId());
            // Chat responses are per-customer; never let a proxy cache them.
            header('Cache-Control: no-store, private');

            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value);
            }
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
