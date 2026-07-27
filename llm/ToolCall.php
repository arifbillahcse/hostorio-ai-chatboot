<?php

declare(strict_types=1);

namespace Hostorio\Llm;

/**
 * A model's request to invoke a tool, normalised across providers.
 *
 * Claude returns the arguments already decoded in a `tool_use` block's `input`;
 * OpenAI-compatible providers return them as a JSON *string* in
 * `function.arguments`. Both are decoded to an array here so callers never have
 * to care which provider produced the call.
 */
final class ToolCall
{
    /**
     * @param array<string, mixed> $input
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly array $input,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'input' => $this->input];
    }
}
