<?php

declare(strict_types=1);

namespace Hostorio\Llm;

use InvalidArgumentException;

/**
 * A provider-neutral completion request.
 *
 * Deliberately not a chat *session* — conversation state belongs to Phase 5.
 * This is a single call's worth of input, which is what makes the routing
 * engine testable on its own.
 *
 * Note the system prompt is a first-class field rather than a message. Claude
 * takes it as a top-level `system` parameter; putting it in the messages array
 * is a 400. Providers translate as needed.
 */
final class LlmRequest
{
    /** @var array<int, array{role: string, content: string}> */
    public readonly array $messages;

    /** @var array<int, ToolDefinition> */
    public readonly array $tools;

    /**
     * @param array<int, array{role: string, content: string}> $messages
     * @param array<int, ToolDefinition> $tools
     * @param float|null $temperature null means "let the provider decide" —
     *        required for models that reject sampling parameters outright.
     */
    public function __construct(
        array $messages,
        public readonly string $system = '',
        public readonly int $maxTokens = 1024,
        public readonly ?float $temperature = null,
        array $tools = [],
        public readonly bool $thinking = false,
    ) {
        $this->messages = self::validateMessages($messages);
        $this->tools    = array_values($tools);
    }

    /**
     * Convenience constructor for the common single-turn case.
     */
    public static function forPrompt(string $prompt, string $system = '', int $maxTokens = 1024): self
    {
        return new self([['role' => 'user', 'content' => $prompt]], $system, $maxTokens);
    }

    /**
     * Enforce the constraints every provider shares, so a malformed
     * conversation fails locally with a clear message instead of costing a
     * round trip and returning an opaque 400.
     *
     * Content may be a plain string or an array of blocks. Blocks are needed
     * for the tool loop, where an assistant turn carries `tool_use` and the
     * following user turn carries the matching `tool_result`.
     *
     * @param array<int, array{role: string, content: string|array<int, array<string, mixed>>}> $messages
     * @return array<int, array{role: string, content: string|array<int, array<string, mixed>>}>
     */
    private static function validateMessages(array $messages): array
    {
        $messages = array_values($messages);

        if ($messages === []) {
            throw new InvalidArgumentException('An LLM request needs at least one message.');
        }

        $previousRole = null;

        foreach ($messages as $index => $message) {
            $role = $message['role'] ?? '';

            if (!in_array($role, ['user', 'assistant'], true)) {
                throw new InvalidArgumentException(
                    sprintf('Message %d has role "%s"; only "user" and "assistant" are allowed. '
                          . 'Pass the system prompt via the $system argument.', $index, (string) $role)
                );
            }

            $content = $message['content'] ?? null;

            if (!is_string($content) && !is_array($content)) {
                throw new InvalidArgumentException(
                    sprintf('Message %d content must be a string or an array of blocks.', $index)
                );
            }

            if (is_array($content)) {
                if ($content === []) {
                    throw new InvalidArgumentException(
                        sprintf('Message %d has an empty content block list.', $index)
                    );
                }

                self::validateBlocks($content, $index);
            }

            if ($index === 0 && $role !== 'user') {
                throw new InvalidArgumentException('The first message must come from the user.');
            }

            if ($role === $previousRole) {
                throw new InvalidArgumentException(
                    sprintf('Message %d repeats the "%s" role; roles must alternate.', $index, $role)
                );
            }

            $previousRole = $role;
        }

        return $messages;
    }

    /**
     * Check that content blocks are well formed.
     *
     * Worth doing locally: a malformed block reaches the provider as an opaque
     * 400 that says nothing about which message was wrong, and the tool loop
     * builds these programmatically where a shape bug is easy to introduce.
     *
     * @param array<int, mixed> $blocks
     */
    private static function validateBlocks(array $blocks, int $messageIndex): void
    {
        $known = ['text', 'tool_use', 'tool_result', 'thinking', 'image'];

        foreach ($blocks as $position => $block) {
            if (!is_array($block)) {
                throw new InvalidArgumentException(sprintf(
                    'Message %d, block %s: content blocks must be arrays, got %s.',
                    $messageIndex,
                    (string) $position,
                    get_debug_type($block)
                ));
            }

            $type = $block['type'] ?? null;

            if (!is_string($type) || $type === '') {
                throw new InvalidArgumentException(sprintf(
                    'Message %d, block %s: every content block needs a string "type".',
                    $messageIndex,
                    (string) $position
                ));
            }

            if (!in_array($type, $known, true)) {
                throw new InvalidArgumentException(sprintf(
                    'Message %d, block %s: unknown block type "%s". Expected one of: %s.',
                    $messageIndex,
                    (string) $position,
                    $type,
                    implode(', ', $known)
                ));
            }

            // The pairing the tool loop depends on: a tool_result must name the
            // tool_use it answers, or the provider rejects the whole turn.
            if ($type === 'tool_use' && !is_string($block['id'] ?? null)) {
                throw new InvalidArgumentException(sprintf(
                    'Message %d, block %s: a tool_use block needs a string "id".',
                    $messageIndex,
                    (string) $position
                ));
            }

            if ($type === 'tool_result' && !is_string($block['tool_use_id'] ?? null)) {
                throw new InvalidArgumentException(sprintf(
                    'Message %d, block %s: a tool_result block needs a string "tool_use_id".',
                    $messageIndex,
                    (string) $position
                ));
            }
        }
    }

    /**
     * Flatten a message's content to plain text, for logging and estimation.
     *
     * @param string|array<int, array<string, mixed>> $content
     */
    public static function contentToText(string|array $content): string
    {
        if (is_string($content)) {
            return $content;
        }

        $parts = [];

        foreach ($content as $block) {
            if (!is_array($block)) {
                continue;
            }

            if (($block['type'] ?? '') === 'text' && is_string($block['text'] ?? null)) {
                $parts[] = $block['text'];
            }
        }

        return implode("\n", $parts);
    }

    public function requiresTools(): bool
    {
        return $this->tools !== [];
    }

    /**
     * The text of the most recent user message — used for classification and
     * for log/debug output.
     */
    public function latestUserMessage(): string
    {
        for ($i = count($this->messages) - 1; $i >= 0; $i--) {
            if ($this->messages[$i]['role'] === 'user') {
                return self::contentToText($this->messages[$i]['content']);
            }
        }

        return '';
    }

    /**
     * Rough token estimate used for pre-flight budgeting and log context.
     * Deliberately crude — ~4 characters per token is close enough for
     * English and errs high for the multibyte scripts our customers use.
     */
    public function estimatedInputTokens(): int
    {
        $characters = mb_strlen($this->system, 'UTF-8');

        foreach ($this->messages as $message) {
            $characters += mb_strlen(self::contentToText($message['content']), 'UTF-8');
        }

        return (int) ceil($characters / 4);
    }
}
