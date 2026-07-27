<?php

declare(strict_types=1);

namespace Hostorio\Llm\Providers;

use Hostorio\Core\Config;
use Hostorio\Llm\HttpClient;
use Hostorio\Llm\LlmException;
use Hostorio\Llm\LlmRequest;
use Hostorio\Llm\LlmResponse;
use Hostorio\Llm\ToolCall;
use Hostorio\Llm\ToolDefinition;

/**
 * Anthropic Messages API adapter.
 *
 * Used for anything that must actually work: tool calls against the hosting
 * APIs, and complex troubleshooting. Costs more than DeepSeek, which is why
 * the router only sends it what needs it.
 */
final class ClaudeProvider implements ProviderInterface
{
    /**
     * Models that reject `temperature` / `top_p` / `top_k` with a 400.
     * Sending a sampling parameter to one of these fails the whole request,
     * so the parameter is dropped rather than passed through.
     */
    private const NO_SAMPLING_PARAMS = [
        'claude-fable-5',
        'claude-mythos-5',
        'claude-opus-4-8',
        'claude-opus-4-7',
    ];

    /**
     * Models taking `thinking: {type: "adaptive"}`. Older models use the
     * deprecated `budget_tokens` form; rather than maintain both, thinking is
     * simply not requested from them.
     */
    private const ADAPTIVE_THINKING = [
        'claude-fable-5',
        'claude-mythos-5',
        'claude-opus-4-8',
        'claude-opus-4-7',
        'claude-opus-4-6',
        'claude-sonnet-5',
        'claude-sonnet-4-6',
    ];

    /** Thinking shares the output budget, so give it room to work. */
    private const MIN_TOKENS_WITH_THINKING = 4096;

    public function __construct(private readonly HttpClient $http = new HttpClient())
    {
    }

    public function name(): string
    {
        return 'claude';
    }

    public function model(): string
    {
        return (string) Config::get('providers.claude.model', 'claude-opus-4-8');
    }

    public function isEnabled(): bool
    {
        return (bool) Config::get('providers.claude.enabled', false);
    }

    public function supportsTools(): bool
    {
        return true;
    }

    public function complete(LlmRequest $request): LlmResponse
    {
        $apiKey = (string) Config::get('providers.claude.api_key', '');

        if ($apiKey === '') {
            throw LlmException::notConfigured($this->name());
        }

        $model = $this->model();

        $body = [
            'model'      => $model,
            'max_tokens' => $this->resolveMaxTokens($request),
            'messages'   => $request->messages,
        ];

        // Anthropic takes the system prompt as a top-level parameter; putting
        // it in the messages array is a 400.
        if ($request->system !== '') {
            $body['system'] = $request->system;
        }

        if ($request->temperature !== null && !in_array($model, self::NO_SAMPLING_PARAMS, true)) {
            $body['temperature'] = $request->temperature;
        }

        if ($request->thinking && in_array($model, self::ADAPTIVE_THINKING, true)) {
            // Adaptive lets the model decide how much to think. Omitted rather
            // than set to "disabled" when off — some models 400 on an explicit
            // disable.
            $body['thinking'] = ['type' => 'adaptive'];
        }

        if ($request->requiresTools()) {
            $body['tools'] = array_map(
                static fn (ToolDefinition $tool): array => $tool->toClaudeFormat(),
                $request->tools
            );
        }

        $result = $this->http->postJson(
            $this->name(),
            (string) Config::get('providers.claude.endpoint', 'https://api.anthropic.com/v1/messages'),
            [
                'x-api-key'         => $apiKey,
                'anthropic-version' => (string) Config::get('providers.claude.version', '2023-06-01'),
            ],
            $body
        );

        return $this->parse($result['body'], $result['duration_ms']);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function parse(array $body, int $durationMs): LlmResponse
    {
        $content = $body['content'] ?? null;

        if (!is_array($content)) {
            throw LlmException::malformed($this->name(), 'response had no content array');
        }

        $text      = '';
        $toolCalls = [];

        foreach ($content as $block) {
            if (!is_array($block)) {
                continue;
            }

            // Thinking blocks are also present when adaptive thinking is on;
            // they are reasoning, not an answer, so they are skipped.
            if (($block['type'] ?? '') === 'text' && is_string($block['text'] ?? null)) {
                $text .= $block['text'];
                continue;
            }

            if (($block['type'] ?? '') === 'tool_use') {
                $toolCalls[] = new ToolCall(
                    (string) ($block['id'] ?? ''),
                    (string) ($block['name'] ?? ''),
                    is_array($block['input'] ?? null) ? $block['input'] : []
                );
            }
        }

        $usage = is_array($body['usage'] ?? null) ? $body['usage'] : [];

        return new LlmResponse(
            text: trim($text),
            provider: $this->name(),
            // Echo back the model the API says served the request rather than
            // the one we asked for — they can differ.
            model: (string) ($body['model'] ?? $this->model()),
            inputTokens: (int) ($usage['input_tokens'] ?? 0),
            outputTokens: (int) ($usage['output_tokens'] ?? 0),
            stopReason: (string) ($body['stop_reason'] ?? ''),
            toolCalls: $toolCalls,
            durationMs: $durationMs
        );
    }

    private function resolveMaxTokens(LlmRequest $request): int
    {
        $configured = max(1, $request->maxTokens);

        if ($request->thinking && in_array($this->model(), self::ADAPTIVE_THINKING, true)) {
            return max($configured, self::MIN_TOKENS_WITH_THINKING);
        }

        return $configured;
    }
}
