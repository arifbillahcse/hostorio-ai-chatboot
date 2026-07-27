<?php

declare(strict_types=1);

namespace Hostorio\Chat\Tools;

/**
 * The outcome of running one tool.
 *
 * `outcome` is deliberately richer than a boolean because the three failure
 * modes need different handling: a denial is a security event worth alerting
 * on, a confirmation request is a normal step in a safe workflow, and an error
 * is an operational problem.
 */
final class ToolResult
{
    public const OK                 = 'ok';
    public const DENIED             = 'denied';
    public const NEEDS_CONFIRMATION = 'needs_confirmation';
    public const ERROR              = 'error';

    private function __construct(
        public readonly string $outcome,
        public readonly string $message,
        public readonly bool $isError,
    ) {
    }

    public static function ok(string $message): self
    {
        return new self(self::OK, $message, false);
    }

    /**
     * The customer is not allowed to do this — most importantly, the resource
     * does not belong to them.
     */
    public static function denied(string $message): self
    {
        return new self(self::DENIED, $message, true);
    }

    /**
     * The action is real and reversible only with effort, so the customer has
     * to say yes first. The message tells the model exactly what to ask.
     */
    public static function needsConfirmation(string $message): self
    {
        return new self(self::NEEDS_CONFIRMATION, $message, false);
    }

    public static function error(string $message): self
    {
        return new self(self::ERROR, $message, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['outcome' => $this->outcome, 'message' => $this->message];
    }
}
