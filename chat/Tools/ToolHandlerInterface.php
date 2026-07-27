<?php

declare(strict_types=1);

namespace Hostorio\Chat\Tools;

use Hostorio\Context\CustomerIdentity;
use Hostorio\Llm\ToolDefinition;

/**
 * One action the model may take on a customer's behalf.
 *
 * The security model, which every implementation must honour:
 *
 *  - Tools are offered only to a *verified* identity. An anonymous visitor is
 *    given no tools at all, so there is nothing for a prompt injection to
 *    invoke.
 *  - A handler must re-check that the resource named in the arguments actually
 *    belongs to the calling customer. The model chooses those arguments, and a
 *    model can hallucinate a domain or a service id — the registry cannot know
 *    what "their own" means for a given tool, so the handler must.
 *  - Anything destructive returns `needsConfirmation` until the caller passes
 *    an explicit confirmation flag, so a misread question cannot restart a
 *    production server on its own.
 */
interface ToolHandlerInterface
{
    /** Stable name the model calls, e.g. `check_service_status`. */
    public function name(): string;

    /** The schema advertised to the model. */
    public function definition(): ToolDefinition;

    /**
     * True when this changes something. Destructive tools are audited more
     * loudly and require confirmation.
     */
    public function isDestructive(): bool;

    /**
     * Execute.
     *
     * @param array<string, mixed> $arguments as chosen by the model — untrusted
     * @param CustomerIdentity $identity guaranteed verified by the registry
     */
    public function handle(array $arguments, CustomerIdentity $identity): ToolResult;
}
