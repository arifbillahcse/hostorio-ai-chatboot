<?php

declare(strict_types=1);

namespace Hostorio\Knowledge\Embeddings;

use Hostorio\Core\Config;
use Hostorio\Core\Logger;
use Hostorio\Llm\HttpClient;
use Hostorio\Llm\LlmException;

/**
 * OpenAI embeddings adapter (`/v1/embeddings`).
 *
 * Reuses the Phase 2 HttpClient so retries, backoff and the shared-hosting
 * execution-time clamp all apply here too — indexing a few thousand chunks is
 * exactly the workload most likely to hit a rate limit.
 */
final class OpenAiEmbedder implements EmbedderInterface
{
    private float $costUsd = 0.0;

    public function __construct(private readonly HttpClient $http = new HttpClient())
    {
    }

    public function name(): string
    {
        return 'openai';
    }

    public function model(): string
    {
        return (string) Config::get('knowledge.embeddings.openai.model', 'text-embedding-3-small');
    }

    public function isEnabled(): bool
    {
        return $this->apiKey() !== '';
    }

    private function apiKey(): string
    {
        return (string) Config::get('knowledge.embeddings.openai.api_key', '');
    }

    /**
     * @param array<int, string> $texts
     * @return array<int, array<int, float>>
     */
    public function embedBatch(array $texts): array
    {
        $texts = array_values($texts);

        if ($texts === []) {
            return [];
        }

        if (!$this->isEnabled()) {
            throw LlmException::notConfigured('openai-embeddings');
        }

        $result = $this->http->postJson(
            'openai-embeddings',
            (string) Config::get('knowledge.embeddings.openai.endpoint', 'https://api.openai.com/v1/embeddings'),
            ['Authorization' => 'Bearer ' . $this->apiKey()],
            ['model' => $this->model(), 'input' => $texts]
        );

        return $this->parse($result['body'], count($texts));
    }

    /**
     * @return array<int, float>
     */
    public function embedQuery(string $text): array
    {
        $vectors = $this->embedBatch([$text]);

        return $vectors[0] ?? [];
    }

    public function costUsd(): float
    {
        return round($this->costUsd, 6);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<int, array<int, float>>
     */
    private function parse(array $body, int $expected): array
    {
        $data = $body['data'] ?? null;

        if (!is_array($data)) {
            throw LlmException::malformed('openai-embeddings', 'response had no data array');
        }

        $vectors = [];

        foreach ($data as $item) {
            if (!is_array($item) || !is_array($item['embedding'] ?? null)) {
                continue;
            }

            // The API documents that results carry an `index`; trust it rather
            // than array order, since a reordered batch would silently attach
            // every vector to the wrong chunk.
            $index = isset($item['index']) && is_int($item['index']) ? $item['index'] : count($vectors);

            $vectors[$index] = array_map('floatval', $item['embedding']);
        }

        if (count($vectors) !== $expected) {
            throw LlmException::malformed(
                'openai-embeddings',
                sprintf('expected %d vectors, received %d', $expected, count($vectors))
            );
        }

        ksort($vectors);

        $usage  = is_array($body['usage'] ?? null) ? $body['usage'] : [];
        $tokens = (int) ($usage['prompt_tokens'] ?? $usage['total_tokens'] ?? 0);

        if ($tokens > 0) {
            $rate = (float) Config::get('knowledge.embeddings.openai.cost_per_million', 0.02);
            $this->costUsd += ($tokens / 1_000_000) * $rate;
        }

        Logger::api('Embedding batch completed', [
            'model'   => $this->model(),
            'vectors' => count($vectors),
            'tokens'  => $tokens,
        ]);

        return array_values($vectors);
    }
}
