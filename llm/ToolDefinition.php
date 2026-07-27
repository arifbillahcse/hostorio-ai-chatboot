<?php

declare(strict_types=1);

namespace Hostorio\Llm;

/**
 * A tool the model may call — e.g. "check_server_status", "reset_password".
 *
 * Declared provider-neutrally here and translated to each provider's wire
 * format by its adapter. Claude nests the schema under `input_schema`;
 * OpenAI-compatible providers wrap it in `{type: "function", function: {…}}`.
 *
 * The tools themselves are implemented in Phase 5; this type exists now so the
 * routing engine can already reason about which providers can serve a request
 * that needs one.
 */
final class ToolDefinition
{
    /**
     * @param array<string, mixed> $schema JSON Schema for the tool's parameters
     */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly array $schema,
    ) {
    }

    /**
     * Anthropic Messages API shape.
     *
     * @return array<string, mixed>
     */
    public function toClaudeFormat(): array
    {
        return [
            'name'         => $this->name,
            'description'  => $this->description,
            'input_schema' => $this->schema,
        ];
    }

    /**
     * OpenAI / DeepSeek chat-completions shape.
     *
     * @return array<string, mixed>
     */
    public function toOpenAiFormat(): array
    {
        return [
            'type'     => 'function',
            'function' => [
                'name'        => $this->name,
                'description' => $this->description,
                'parameters'  => $this->schema,
            ],
        ];
    }
}
