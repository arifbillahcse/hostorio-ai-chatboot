<?php

declare(strict_types=1);

namespace Hostorio\Llm;

use Hostorio\Core\Config;
use Hostorio\Core\CostTracker;
use Hostorio\Core\Logger;
use Hostorio\Llm\Providers\ClaudeProvider;
use Hostorio\Llm\Providers\DeepSeekProvider;
use Hostorio\Llm\Providers\OpenAiProvider;
use Hostorio\Llm\Providers\ProviderInterface;

/**
 * Picks a provider per question and falls back when one fails.
 *
 * This is the Phase 2 deliverable: `ask()` is the single entry point the rest
 * of the application uses, and everything behind it — classification, provider
 * selection, retries, fallback, cost accounting — is an implementation detail.
 *
 * Fallback policy: a provider that throws is abandoned for the *rest of this
 * request* and the next candidate is tried. Retrying the same provider on a
 * transient error already happened one layer down in HttpClient; by the time an
 * exception reaches here, that provider is considered unavailable.
 */
final class LlmRouter
{
    /** @var array<string, ProviderInterface> */
    private array $providers;

    /**
     * @param array<int, ProviderInterface>|null $providers defaults to all three adapters
     */
    public function __construct(
        ?array $providers = null,
        private readonly QueryClassifier $classifier = new QueryClassifier(),
    ) {
        $providers ??= [new ClaudeProvider(), new DeepSeekProvider(), new OpenAiProvider()];

        $this->providers = [];

        foreach ($providers as $provider) {
            $this->providers[$provider->name()] = $provider;
        }
    }

    /**
     * Answer a question, choosing the model automatically.
     *
     * @param array{
     *     type?: string,
     *     history?: array<int, array{role: string, content: string}>,
     *     tools?: array<int, ToolDefinition>,
     *     max_tokens?: int,
     *     thinking?: bool
     * } $options
     *
     * @throws LlmException when no provider could answer
     */
    public function ask(string $message, string $system = '', array $options = []): LlmResponse
    {
        $classification = isset($options['type']) && Classification::isValidType($options['type'])
            ? new Classification($options['type'], 'explicitly requested by caller')
            : $this->classifier->classify($message);

        $rule = $this->ruleFor($classification->type);

        $history  = $options['history'] ?? [];
        $messages = array_merge($history, [['role' => 'user', 'content' => $message]]);

        $request = new LlmRequest(
            messages: $messages,
            system: $system,
            maxTokens: (int) ($options['max_tokens'] ?? $rule['max_tokens'] ?? 1024),
            temperature: $this->temperature(),
            tools: $options['tools'] ?? [],
            thinking: (bool) ($options['thinking'] ?? $rule['thinking'] ?? false),
        );

        return $this->route($request, $classification);
    }

    /**
     * Route a pre-built request. Used when the caller has already assembled the
     * conversation — Phase 5 builds one with RAG context attached.
     *
     * @throws LlmException
     */
    public function route(LlmRequest $request, ?Classification $classification = null): LlmResponse
    {
        $classification ??= $this->classifier->classify($request->latestUserMessage());

        $candidates = $this->candidatesFor($classification, $request);

        Logger::info('Routing decision', [
            'query_type'     => $classification->type,
            'reason'         => $classification->reason,
            'candidates'     => array_map(static fn (ProviderInterface $p): string => $p->name(), $candidates),
            'requires_tools' => $request->requiresTools(),
            'est_input_tokens' => $request->estimatedInputTokens(),
        ]);

        if ($candidates === []) {
            throw new LlmException(
                $this->noCandidatesMessage($classification, $request),
                'router',
                0,
                'no_provider_available',
                false
            );
        }

        $attempts = [];
        $lastError = null;

        foreach ($candidates as $provider) {
            $startedAt = microtime(true);

            try {
                $response = $provider->complete($request);
            } catch (LlmException $e) {
                $attempts[] = $provider->name() . ':' . ($e->errorType !== '' ? $e->errorType : 'error');
                $lastError  = $e;

                Logger::error('Provider failed; falling back', [
                    'provider'   => $provider->name(),
                    'status'     => $e->statusCode,
                    'error_type' => $e->errorType,
                    'message'    => $e->getMessage(),
                ]);

                // Record the failure so the admin dashboard shows provider
                // reliability, not just spend.
                CostTracker::record(
                    $provider->name(),
                    $provider->model(),
                    0,
                    0,
                    (int) round((microtime(true) - $startedAt) * 1000),
                    false,
                    ['query_type' => $classification->type, 'error_type' => $e->errorType]
                );

                continue;
            }

            $cost = CostTracker::record(
                $provider->name(),
                $response->model,
                $response->inputTokens,
                $response->outputTokens,
                $response->durationMs,
                true,
                [
                    'query_type'  => $classification->type,
                    'reason'      => $classification->reason,
                    'fell_back'   => $attempts !== [],
                    'stop_reason' => $response->stopReason,
                ]
            );

            if ($response->wasTruncated()) {
                // The caller gets a sentence fragment. Surfaced here because
                // silently showing a half-answer to a customer is worse than
                // knowing the budget was too small.
                Logger::warning('Response hit the output token limit', [
                    'provider'   => $provider->name(),
                    'max_tokens' => $request->maxTokens,
                ]);
            }

            $attempts[] = $provider->name() . ':ok';

            return $response->with([
                'costUsd'   => $cost,
                'queryType' => $classification->type,
                'attempts'  => $attempts,
            ]);
        }

        Logger::error('All providers failed', [
            'query_type' => $classification->type,
            'attempts'   => $attempts,
        ]);

        throw new LlmException(
            sprintf(
                'Every provider failed for this request (tried: %s). Last error: %s',
                implode(', ', $attempts),
                $lastError?->getMessage() ?? 'unknown'
            ),
            'router',
            $lastError?->statusCode ?? 0,
            'all_providers_failed',
            false
        );
    }

    /**
     * Report what the router *would* do, without spending anything.
     *
     * Exists so the routing rules can be tested and reviewed — both by the
     * test suite and by an admin who wants to check where their money goes
     * before turning the chatbot loose on customers.
     *
     * @return array<string, mixed>
     */
    public function plan(string $message): array
    {
        $classification = $this->classifier->classify($message);
        $rule           = $this->ruleFor($classification->type);

        $configured = (array) ($rule['providers'] ?? []);
        $chosen     = $this->candidatesFor($classification, LlmRequest::forPrompt($message === '' ? '.' : $message));

        return [
            'query_type'          => $classification->type,
            'reason'              => $classification->reason,
            'configured_order'    => $configured,
            'available_order'     => array_map(static fn (ProviderInterface $p): string => $p->name(), $chosen),
            'selected'            => $chosen === [] ? null : $chosen[0]->name(),
            'model'               => $chosen === [] ? null : $chosen[0]->model(),
            'max_tokens'          => (int) ($rule['max_tokens'] ?? 1024),
            'thinking'            => (bool) ($rule['thinking'] ?? false),
        ];
    }

    /**
     * Providers eligible for this request, in preference order.
     *
     * @return array<int, ProviderInterface>
     */
    private function candidatesFor(Classification $classification, LlmRequest $request): array
    {
        $rule  = $this->ruleFor($classification->type);
        $names = (array) ($rule['providers'] ?? []);

        $needsTools = $request->requiresTools();

        $candidates = [];

        foreach ($names as $name) {
            if (!is_string($name) || !isset($this->providers[$name])) {
                continue;
            }

            $provider = $this->providers[$name];

            if (!$provider->isEnabled()) {
                continue;
            }

            // A provider that cannot call tools is not a valid fallback for a
            // request carrying tool definitions — it would answer in prose and
            // the action would silently never happen.
            if ($needsTools && !$provider->supportsTools()) {
                continue;
            }

            $candidates[] = $provider;
        }

        return $candidates;
    }

    /**
     * @return array<string, mixed>
     */
    private function ruleFor(string $type): array
    {
        $rule = Config::get('routing.rules.' . $type);

        if (is_array($rule)) {
            return $rule;
        }

        // An unknown type must still route somewhere rather than throw.
        $fallback = Config::get('routing.rules.simple');

        return is_array($fallback) ? $fallback : ['providers' => ['deepseek'], 'max_tokens' => 1024];
    }

    private function temperature(): ?float
    {
        $temperature = Config::get('routing.temperature');

        return is_numeric($temperature) ? (float) $temperature : null;
    }

    /**
     * Explain *why* nothing was available, since "no provider" is almost always
     * a configuration mistake and a vague message wastes the admin's time.
     */
    private function noCandidatesMessage(Classification $classification, LlmRequest $request): string
    {
        $rule      = $this->ruleFor($classification->type);
        $configured = implode(', ', array_map('strval', (array) ($rule['providers'] ?? [])));

        if ($request->requiresTools()) {
            return sprintf(
                'No tool-capable provider is enabled for "%s" questions. Configured order: %s. '
                . 'Add an ANTHROPIC_API_KEY — Claude handles tool calls.',
                $classification->type,
                $configured
            );
        }

        return sprintf(
            'No provider is enabled for "%s" questions. Configured order: %s. '
            . 'Add an API key for at least one of them in .env.',
            $classification->type,
            $configured
        );
    }
}
