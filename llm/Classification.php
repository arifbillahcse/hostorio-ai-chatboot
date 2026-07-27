<?php

declare(strict_types=1);

namespace Hostorio\Llm;

/**
 * The outcome of classifying a customer question.
 *
 * `reason` exists so a support manager reviewing the admin panel can see *why*
 * a question was sent to an expensive model, rather than having to trust the
 * routing blindly.
 */
final class Classification
{
    /** Wants something done to the account — needs a provider that can call tools. */
    public const ACTION = 'action';

    /** Troubleshooting or diagnosis — worth spending a stronger model on. */
    public const COMPLEX = 'complex';

    /** Straightforward informational question — the cheap model handles it. */
    public const SIMPLE = 'simple';

    public function __construct(
        public readonly string $type,
        public readonly string $reason,
        public readonly bool $requiresTools = false,
    ) {
    }

    public static function isValidType(string $type): bool
    {
        return in_array($type, [self::ACTION, self::COMPLEX, self::SIMPLE], true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type'           => $this->type,
            'reason'         => $this->reason,
            'requires_tools' => $this->requiresTools,
        ];
    }
}
