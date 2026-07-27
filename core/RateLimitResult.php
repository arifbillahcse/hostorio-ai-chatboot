<?php

declare(strict_types=1);

namespace Hostorio\Core;

/**
 * Outcome of a rate-limit check, shaped for direct use in response headers.
 */
final class RateLimitResult
{
    public function __construct(
        public readonly bool $allowed,
        public readonly int $limit,
        public readonly int $remaining,
        public readonly int $retryAfter,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        if ($this->limit === 0) {
            return [];
        }

        $headers = [
            'X-RateLimit-Limit'     => (string) $this->limit,
            'X-RateLimit-Remaining' => (string) $this->remaining,
        ];

        if (!$this->allowed) {
            $headers['Retry-After'] = (string) $this->retryAfter;
        }

        return $headers;
    }
}
