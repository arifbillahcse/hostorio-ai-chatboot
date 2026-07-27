<?php

declare(strict_types=1);

namespace Hostorio\Chat;

use Hostorio\Chat\Tools\ToolRegistry;
use Hostorio\Chat\Tools\ToolResult;
use Hostorio\Context\ContextBuilder;
use Hostorio\Context\CustomerIdentity;
use Hostorio\Core\Config;
use Hostorio\Core\Logger;
use Hostorio\Llm\Classification;
use Hostorio\Llm\LlmException;
use Hostorio\Llm\LlmRequest;
use Hostorio\Llm\LlmResponse;
use Hostorio\Llm\LlmRouter;
use Hostorio\Llm\QueryClassifier;
use Hostorio\Llm\ToolCall;
use Throwable;

/**
 * The chat pipeline: question in, grounded answer out.
 *
 * Order of work, and why:
 *
 *  1. Resolve identity (Phase 4) — everything downstream depends on whether
 *     account data and tools are permitted.
 *  2. Load history — so follow-ups like "and the price?" make sense.
 *  3. Classify (Phase 2) — but with tools folded in: a question that could
 *     lead to an action must go to a tool-capable provider regardless of how
 *     the keywords read.
 *  4. Build context (Phase 4).
 *  5. Call the model, running the tool loop if it asks for tools.
 *  6. Persist both turns.
 *
 * Persistence deliberately happens after a successful answer. Saving the
 * question before the call would leave orphaned user turns whenever a provider
 * fails, and those corrupt the alternation of future history windows.
 */
final class ChatEngine
{
    private readonly LlmRouter $router;
    private readonly ContextBuilder $contextBuilder;
    private readonly ToolRegistry $tools;
    private readonly SystemPrompt $systemPrompt;
    private readonly QueryClassifier $classifier;
    private readonly ?ConversationStore $conversations;

    public function __construct(
        ?LlmRouter $router = null,
        ?ContextBuilder $contextBuilder = null,
        ?ToolRegistry $tools = null,
        ?ConversationStore $conversations = null,
        ?SystemPrompt $systemPrompt = null,
        ?QueryClassifier $classifier = null,
    ) {
        $this->router         = $router ?? new LlmRouter();
        $this->contextBuilder = $contextBuilder ?? new ContextBuilder();
        $this->tools          = $tools ?? ToolRegistry::make();
        $this->systemPrompt   = $systemPrompt ?? new SystemPrompt();
        $this->classifier     = $classifier ?? new QueryClassifier();
        $this->conversations  = $conversations;
    }

    /**
     * Answer a question.
     *
     * @throws LlmException when no provider could produce an answer
     */
    public function ask(
        string $message,
        CustomerIdentity $identity,
        ?string $conversationPublicId = null,
        string $clientIp = ''
    ): ChatReply {
        $conversationId = null;
        $publicId       = null;
        $history        = [];

        if ($this->conversations !== null) {
            try {
                $conversation = $this->conversations->resumeOrCreate(
                    $conversationPublicId,
                    $identity,
                    ConversationStore::clientKey($clientIp)
                );

                $conversationId = $conversation['id'];
                $publicId       = $conversation['public_id'];

                if ($conversation['resumed']) {
                    $history = $this->conversations->history($conversationId);
                }
            } catch (Throwable $e) {
                // Persistence is an enhancement, not a prerequisite. A database
                // that is down should cost the customer their history, not their
                // answer — so carry on stateless rather than failing the request.
                Logger::error('Conversation storage failed; answering without history', [
                    'error' => $e->getMessage(),
                ]);

                $conversationId = null;
                $publicId       = null;
                $history        = [];
            }
        }

        $context = $this->contextBuilder->build($message, $identity);

        $toolDefinitions = $this->tools->definitionsFor($identity);

        $system = $this->systemPrompt->build($context, $identity, $toolDefinitions !== []);

        $messages = array_merge($history, [['role' => 'user', 'content' => $message]]);

        $classification = $this->classify($message, $toolDefinitions !== []);

        $rule = Config::get('routing.rules.' . $classification->type, []);

        $request = new LlmRequest(
            messages: $messages,
            system: $system,
            maxTokens: (int) (is_array($rule) ? ($rule['max_tokens'] ?? 1024) : 1024),
            temperature: $this->temperature(),
            tools: $toolDefinitions,
            thinking: (bool) (is_array($rule) ? ($rule['thinking'] ?? false) : false),
        );

        $outcome = $this->runWithTools($request, $classification, $identity, $conversationId);

        $reply = new ChatReply(
            text: $outcome['response']->text,
            conversationId: $publicId,
            provider: $outcome['response']->provider,
            model: $outcome['response']->model,
            queryType: $classification->type,
            costUsd: $outcome['cost'],
            inputTokens: $outcome['input_tokens'],
            outputTokens: $outcome['output_tokens'],
            contextTokens: $context->estimatedTokens,
            sources: $context->sourcesUsed,
            toolCalls: $outcome['tool_calls'],
            truncated: $outcome['response']->wasTruncated(),
        );

        $this->persist($conversationId, $message, $reply, $context->estimatedTokens);

        return $reply;
    }

    /**
     * Classify, then force a tool-capable route when tools are on the table.
     *
     * Without this a question like "my site is down, can you restart it?" could
     * classify as troubleshooting and land on a provider that cannot call
     * tools — which would answer in prose while the action silently never
     * happened. Offering tools and then routing somewhere that ignores them is
     * worse than not offering them at all.
     */
    private function classify(string $message, bool $hasTools): Classification
    {
        $classification = $this->classifier->classify($message);

        if ($hasTools && $classification->type === Classification::SIMPLE) {
            return new Classification(
                Classification::COMPLEX,
                $classification->reason . '; upgraded because tools are available'
            );
        }

        return $classification;
    }

    /**
     * Call the model, servicing tool calls until it produces a final answer.
     *
     * The iteration cap is a cost control as much as a safety one: each round
     * is a full paid request, and a model that keeps re-calling a failing tool
     * would otherwise bill indefinitely.
     *
     * @return array{response: LlmResponse, cost: float, input_tokens: int, output_tokens: int, tool_calls: array<int, array<string, mixed>>}
     */
    private function runWithTools(
        LlmRequest $request,
        Classification $classification,
        CustomerIdentity $identity,
        ?int $conversationId
    ): array {
        $maxRounds = max(1, (int) Config::get('chat.tools.max_rounds', 3));

        $cost         = 0.0;
        $inputTokens  = 0;
        $outputTokens = 0;
        $toolCalls    = [];

        $messages = $request->messages;

        for ($round = 1; $round <= $maxRounds; $round++) {
            $current = new LlmRequest(
                messages: $messages,
                system: $request->system,
                maxTokens: $request->maxTokens,
                temperature: $request->temperature,
                tools: $request->tools,
                thinking: $request->thinking,
            );

            $response = $this->router->route($current, $classification);

            $cost         += $response->costUsd;
            $inputTokens  += $response->inputTokens;
            $outputTokens += $response->outputTokens;

            if (!$response->hasToolCalls()) {
                return [
                    'response'      => $response,
                    'cost'          => $cost,
                    'input_tokens'  => $inputTokens,
                    'output_tokens' => $outputTokens,
                    'tool_calls'    => $toolCalls,
                ];
            }

            if ($round === $maxRounds) {
                // Out of rounds with tool calls still pending. Returning the
                // partial text would imply the actions happened, so say
                // plainly that they did not.
                Logger::warning('Tool loop hit its round limit', ['rounds' => $maxRounds]);

                return [
                    'response' => $response->with([
                        'text' => trim($response->text) !== ''
                            ? $response->text
                            : 'I was not able to finish that. Nothing has been changed. '
                            . 'Please open a support ticket so a human can take a look.',
                    ]),
                    'cost'          => $cost,
                    'input_tokens'  => $inputTokens,
                    'output_tokens' => $outputTokens,
                    'tool_calls'    => $toolCalls,
                ];
            }

            // Echo the assistant turn back verbatim — a tool_result must be
            // preceded by the tool_use it answers, or the API rejects the turn.
            $assistantBlocks = [];

            if (trim($response->text) !== '') {
                $assistantBlocks[] = ['type' => 'text', 'text' => $response->text];
            }

            $resultBlocks = [];

            foreach ($response->toolCalls as $call) {
                $assistantBlocks[] = [
                    'type'  => 'tool_use',
                    'id'    => $call->id,
                    'name'  => $call->name,
                    'input' => $call->input,
                ];

                $result = $this->tools->execute($call->name, $call->input, $identity, $conversationId);

                $toolCalls[] = [
                    'name'    => $call->name,
                    'outcome' => $result->outcome,
                ];

                $resultBlocks[] = [
                    'type'        => 'tool_result',
                    'tool_use_id' => $call->id,
                    'content'     => $result->message,
                    'is_error'    => $result->isError,
                ];
            }

            if ($assistantBlocks === []) {
                // Nothing to echo back means we cannot form a valid next turn.
                return [
                    'response'      => $response,
                    'cost'          => $cost,
                    'input_tokens'  => $inputTokens,
                    'output_tokens' => $outputTokens,
                    'tool_calls'    => $toolCalls,
                ];
            }

            $messages[] = ['role' => 'assistant', 'content' => $assistantBlocks];
            $messages[] = ['role' => 'user', 'content' => $resultBlocks];
        }

        throw new LlmException('Tool loop ended without a response.', 'chat', 0, 'tool_loop_failed', false);
    }

    private function persist(?int $conversationId, string $question, ChatReply $reply, int $contextTokens): void
    {
        if ($this->conversations === null || $conversationId === null) {
            return;
        }

        try {
            $this->conversations->addMessage($conversationId, 'user', $question);

            $this->conversations->addMessage($conversationId, 'assistant', $reply->text, [
                'provider'       => $reply->provider,
                'model'          => $reply->model,
                'query_type'     => $reply->queryType,
                'input_tokens'   => $reply->inputTokens,
                'output_tokens'  => $reply->outputTokens,
                'cost_usd'       => $reply->costUsd,
                'context_tokens' => $contextTokens,
            ]);

            $this->conversations->ensureTitle($conversationId, $question);
        } catch (Throwable $e) {
            // The customer already has their answer; losing the transcript is
            // not worth turning a good reply into an error.
            Logger::error('Could not persist conversation turn', ['error' => $e->getMessage()]);
        }
    }

    private function temperature(): ?float
    {
        $temperature = Config::get('routing.temperature');

        return is_numeric($temperature) ? (float) $temperature : null;
    }
}
