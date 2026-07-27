<?php

declare(strict_types=1);

namespace Hostorio\Llm;

use RuntimeException;

/**
 * A failure from an LLM provider.
 *
 * Carries whether the failure is worth retrying, which is what the router uses
 * to decide between "wait and try the same provider again" and "give up on this
 * provider and fall back to the next one".
 */
final class LlmException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $provider,
        public readonly int $statusCode = 0,
        public readonly string $errorType = '',
        public readonly bool $retryable = false,
        public readonly int $retryAfter = 0,
    ) {
        parent::__construct($message);
    }

    /**
     * Network-level failure: no HTTP response was received at all.
     * Always worth retrying — a dropped connection says nothing about the
     * validity of the request.
     */
    public static function connection(string $provider, string $detail): self
    {
        return new self(
            "Could not reach the {$provider} API: {$detail}",
            $provider,
            0,
            'connection_error',
            true
        );
    }

    /**
     * Build from an HTTP error response.
     *
     * Retryable set follows the documented Claude error codes, which DeepSeek
     * and OpenAI share the shape of: 429 (rate limited), 5xx (server side),
     * and 529 (overloaded). Everything else is a client mistake that will fail
     * identically on retry.
     */
    public static function fromStatus(
        string $provider,
        int $status,
        string $errorType,
        string $message,
        int $retryAfter = 0
    ): self {
        $retryable = $status === 429 || $status >= 500;

        return new self(
            sprintf('%s API error %d: %s', $provider, $status, $message),
            $provider,
            $status,
            $errorType,
            $retryable,
            $retryAfter
        );
    }

    /**
     * The provider returned 2xx but the body was not the shape we expect.
     * Not retryable — a malformed body usually means a changed API contract,
     * and hammering it will not help.
     */
    public static function malformed(string $provider, string $detail): self
    {
        return new self(
            "Unexpected response from {$provider}: {$detail}",
            $provider,
            0,
            'malformed_response',
            false
        );
    }

    public static function notConfigured(string $provider): self
    {
        return new self(
            "The {$provider} provider has no API key configured.",
            $provider,
            0,
            'not_configured',
            false
        );
    }
}
