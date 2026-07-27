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
 * Shared adapter for providers speaking the OpenAI chat-completions protocol.
 *
 * DeepSeek implements it deliberately, which is most of why it is cheap to
 * support alongside OpenAI: one adapter, two backends, differing only in
 * endpoint, credentials, and a couple of parameter quirks handled through
 * config rather than subclass code.
 */
abstract class OpenAiCompatibleProvider implements ProviderInterface
{
    public function __construct(protected readonly HttpClient $http = new HttpClient())
    {
    }

    /** Config prefix for this provider, e.g. `providers.deepseek`. */
    abstract protected function configPrefix(): string;

    public function model(): string
    {
        return (string) Config::get($this->configPrefix() . '.model', '');
    }

    public function isEnabled(): bool
    {
        return (bool) Config::get($this->configPrefix() . '.enabled', false);
    }

    public function supportsTools(): bool
    {
        return (bool) Config::get($this->configPrefix() . '.supports_tools', false);
    }

    public function complete(LlmRequest $request): LlmResponse
    {
        $apiKey = (string) Config::get($this->configPrefix() . '.api_key', '');

        if ($apiKey === '') {
            throw LlmException::notConfigured($this->name());
        }

        // This protocol carries the system prompt as the first message rather
        // than a separate field.
        $messages = $request->system === ''
            ? $request->messages
            : array_merge([['role' => 'system', 'content' => $request->system]], $request->messages);

        $body = [
            'model'    => $this->model(),
            'messages' => $messages,
        ];

        // Newer OpenAI models renamed `max_tokens` to `max_completion_tokens`
        // and reject the old name; DeepSeek still uses `max_tokens`. Driven by
        // config so a provider-side rename does not need a code change.
        $tokenParam = (string) Config::get($this->configPrefix() . '.token_param', 'max_tokens');
        $body[$tokenParam] = max(1, $request->maxTokens);

        // Some models accept only their default temperature and 400 on anything
        // else, so this is opt-in per provider.
        if ($request->temperature !== null
            && (bool) Config::get($this->configPrefix() . '.supports_temperature', true)) {
            $body['temperature'] = $request->temperature;
        }

        if ($request->requiresTools()) {
            $body['tools'] = array_map(
                static fn (ToolDefinition $tool): array => $tool->toOpenAiFormat(),
                $request->tools
            );
        }

        $result = $this->http->postJson(
            $this->name(),
            (string) Config::get($this->configPrefix() . '.endpoint', ''),
            ['Authorization' => 'Bearer ' . $apiKey],
            $body
        );

        return $this->parse($result['body'], $result['duration_ms']);
    }

    /**
     * @param array<string, mixed> $body
     */
    protected function parse(array $body, int $durationMs): LlmResponse
    {
        $choices = $body['choices'] ?? null;

        if (!is_array($choices) || !isset($choices[0]) || !is_array($choices[0])) {
            throw LlmException::malformed($this->name(), 'response had no choices array');
        }

        $choice  = $choices[0];
        $message = is_array($choice['message'] ?? null) ? $choice['message'] : [];

        // `content` is null when the model replied with tool calls only.
        $text = is_string($message['content'] ?? null) ? $message['content'] : '';

        $toolCalls = [];

        foreach ((array) ($message['tool_calls'] ?? []) as $call) {
            if (!is_array($call)) {
                continue;
            }

            $function = is_array($call['function'] ?? null) ? $call['function'] : [];

            // Arguments arrive as a JSON *string* here, unlike Claude's decoded
            // object. Decode so callers see one consistent shape.
            $arguments = [];

            if (is_string($function['arguments'] ?? null) && $function['arguments'] !== '') {
                $decoded   = json_decode($function['arguments'], true);
                $arguments = is_array($decoded) ? $decoded : [];
            }

            $toolCalls[] = new ToolCall(
                (string) ($call['id'] ?? ''),
                (string) ($function['name'] ?? ''),
                $arguments
            );
        }

        $usage = is_array($body['usage'] ?? null) ? $body['usage'] : [];

        return new LlmResponse(
            text: trim($text),
            provider: $this->name(),
            model: (string) ($body['model'] ?? $this->model()),
            inputTokens: (int) ($usage['prompt_tokens'] ?? 0),
            outputTokens: (int) ($usage['completion_tokens'] ?? 0),
            stopReason: (string) ($choice['finish_reason'] ?? ''),
            toolCalls: $toolCalls,
            durationMs: $durationMs
        );
    }
}
