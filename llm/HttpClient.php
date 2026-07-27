<?php

declare(strict_types=1);

namespace Hostorio\Llm;

use Hostorio\Core\Config;
use Hostorio\Core\Logger;

/**
 * cURL JSON client for provider APIs.
 *
 * Retries retryable failures with exponential backoff, honouring `Retry-After`
 * when the provider sends one.
 *
 * The awkward constraint here is shared hosting: PHP is usually killed at 30s.
 * A 60s HTTP timeout would therefore never fire — the whole process dies first
 * and the customer sees a blank page instead of an error. So every timeout is
 * clamped to the time actually remaining in the request.
 */
class HttpClient
{
    /** Leave this much of the execution budget for rendering the response. */
    private const RESERVED_SECONDS = 3;

    /**
     * POST a JSON body and return the decoded response.
     *
     * @param array<string, string> $headers
     * @param array<string, mixed>  $body
     * @return array{status:int, body:array<string, mixed>, raw:string, duration_ms:int}
     *
     * @throws LlmException on network failure or a non-2xx response
     */
    public function postJson(string $provider, string $url, array $headers, array $body): array
    {
        $payload = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($payload === false) {
            throw LlmException::malformed($provider, 'request body could not be encoded as JSON');
        }

        $maxAttempts = max(1, (int) Config::get('http.max_retries', 2) + 1);
        $lastError   = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                return $this->send($provider, $url, $headers, $payload);
            } catch (LlmException $e) {
                $lastError = $e;

                if (!$e->retryable || $attempt === $maxAttempts) {
                    throw $e;
                }

                $delay = $this->backoffSeconds($attempt, $e->retryAfter);

                // Never sleep past the point where we could still finish.
                if ($delay >= $this->secondsRemaining()) {
                    Logger::warning('Giving up retry; not enough execution time left', [
                        'provider' => $provider,
                        'attempt'  => $attempt,
                    ]);

                    throw $e;
                }

                Logger::warning('Retrying provider call', [
                    'provider'    => $provider,
                    'attempt'     => $attempt,
                    'status'      => $e->statusCode,
                    'error_type'  => $e->errorType,
                    'sleeping_s'  => $delay,
                ]);

                sleep($delay);
            }
        }

        // Unreachable: the loop either returns or throws.
        throw $lastError ?? LlmException::connection($provider, 'no attempt was made');
    }

    /**
     * @param array<string, string> $headers
     * @return array{status:int, body:array<string, mixed>, raw:string, duration_ms:int}
     */
    private function send(string $provider, string $url, array $headers, string $payload): array
    {
        $timeout = $this->effectiveTimeout();

        if ($timeout < 1) {
            throw LlmException::connection($provider, 'no execution time left to make the request');
        }

        $handle = curl_init();

        $headerLines = ['Content-Type: application/json'];

        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $responseHeaders = [];

        curl_setopt_array($handle, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => min((int) Config::get('http.connect_timeout', 10), $timeout),
            CURLOPT_USERAGENT      => (string) Config::get('http.user_agent', 'Hostorio-AI-Chatbot'),
            // Certificate verification is non-negotiable: these requests carry
            // API keys and customer data.
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADERFUNCTION => static function ($_, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);

                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
        ]);

        $started = microtime(true);
        $raw     = curl_exec($handle);
        $errno   = curl_errno($handle);
        $error   = curl_error($handle);
        $status  = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);

        curl_close($handle);

        $durationMs = (int) round((microtime(true) - $started) * 1000);

        if ($raw === false || $errno !== 0) {
            throw LlmException::connection($provider, $error !== '' ? $error : 'cURL error ' . $errno);
        }

        /** @var string $raw */
        $decoded = json_decode($raw, true);

        if ($status < 200 || $status >= 300) {
            throw $this->buildHttpError($provider, $status, is_array($decoded) ? $decoded : [], $raw, $responseHeaders);
        }

        if (!is_array($decoded)) {
            throw LlmException::malformed($provider, 'response body was not valid JSON');
        }

        return ['status' => $status, 'body' => $decoded, 'raw' => $raw, 'duration_ms' => $durationMs];
    }

    /**
     * Extract the provider's error type and message.
     *
     * Anthropic returns {error: {type, message}}; OpenAI and DeepSeek return
     * the same shape, so one parser covers all three.
     *
     * @param array<string, mixed>  $decoded
     * @param array<string, string> $responseHeaders
     */
    private function buildHttpError(
        string $provider,
        int $status,
        array $decoded,
        string $raw,
        array $responseHeaders
    ): LlmException {
        $error     = is_array($decoded['error'] ?? null) ? $decoded['error'] : [];
        $errorType = is_string($error['type'] ?? null) ? $error['type'] : '';
        $message   = is_string($error['message'] ?? null)
            ? $error['message']
            : substr(trim($raw), 0, 200);

        $retryAfter = (int) ($responseHeaders['retry-after'] ?? 0);

        return LlmException::fromStatus($provider, $status, $errorType, $message, $retryAfter);
    }

    /**
     * Exponential backoff, with the provider's Retry-After taking precedence.
     */
    private function backoffSeconds(int $attempt, int $retryAfter): int
    {
        if ($retryAfter > 0) {
            return min($retryAfter, 30);
        }

        return min(2 ** ($attempt - 1), 8);
    }

    /**
     * The configured timeout, clamped to the execution time actually left.
     */
    private function effectiveTimeout(): int
    {
        $configured = max(1, (int) Config::get('http.timeout', 60));

        return (int) min($configured, $this->secondsRemaining());
    }

    /**
     * Seconds of PHP execution budget remaining, minus a small reserve.
     * Returns the configured timeout when the limit is 0 (CLI / unlimited).
     */
    private function secondsRemaining(): float
    {
        $limit = (int) ini_get('max_execution_time');

        if ($limit <= 0) {
            return (float) max(1, (int) Config::get('http.timeout', 60));
        }

        $elapsed = microtime(true) - (defined('HOAI_START') ? HOAI_START : microtime(true));

        return max(0.0, $limit - $elapsed - self::RESERVED_SECONDS);
    }
}
