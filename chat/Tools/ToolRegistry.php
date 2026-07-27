<?php

declare(strict_types=1);

namespace Hostorio\Chat\Tools;

use Hostorio\Context\CustomerIdentity;
use Hostorio\Core\Config;
use Hostorio\Core\Logger;
use Hostorio\Database\AppDatabase;
use Hostorio\Knowledge\WhmcsContext;
use Hostorio\Llm\ToolDefinition;
use Throwable;

/**
 * Holds the available tools, decides which are offered, and runs them safely.
 *
 * Two invariants live here rather than in the individual handlers, because a
 * handler added later must not be able to forget them:
 *
 *  1. **No tools without a verified identity.** An anonymous visitor is offered
 *     an empty tool list, so a prompt injection in retrieved content has
 *     nothing to invoke. This is the single most important control in the
 *     phase: retrieved text is untrusted, and the strongest defence against it
 *     driving an action is that no action is reachable.
 *
 *  2. **Every invocation is audited**, including denials and errors. When a
 *     customer asks why their password changed, "the chatbot did it" is not an
 *     answer; the audit row is.
 */
final class ToolRegistry
{
    /** @var array<string, ToolHandlerInterface> */
    private array $handlers = [];

    private ?AppDatabase $db;

    /**
     * @param array<int, ToolHandlerInterface> $handlers
     */
    public function __construct(array $handlers = [], ?AppDatabase $db = null)
    {
        foreach ($handlers as $handler) {
            $this->handlers[$handler->name()] = $handler;
        }

        $this->db = $db;
    }

    /**
     * Build the default set: read-only tools plus destructive ones behind an
     * executor that refuses until configured.
     */
    public static function make(?ActionExecutorInterface $executor = null): self
    {
        $executor ??= new UnconfiguredExecutor();

        try {
            $lookup = new ServiceLookup(WhmcsContext::make());
        } catch (Throwable $e) {
            Logger::warning('WHMCS unavailable; tools disabled', ['error' => $e->getMessage()]);

            return new self([]);
        }

        $handlers = [new CheckServiceStatusTool($lookup)];

        // Destructive tools are only registered when explicitly enabled, so an
        // install that never wires an executor never even advertises them.
        if ((bool) Config::get('chat.tools.enable_actions', false)) {
            $handlers[] = new ResetPasswordTool($lookup, $executor);
            $handlers[] = new RestartServiceTool($lookup, $executor);
        }

        return new self($handlers);
    }

    private function db(): ?AppDatabase
    {
        if ($this->db === null) {
            try {
                $this->db = AppDatabase::instance();
            } catch (Throwable) {
                return null;
            }
        }

        return $this->db;
    }

    /**
     * Tool definitions to advertise for this caller.
     *
     * @return array<int, ToolDefinition>
     */
    public function definitionsFor(CustomerIdentity $identity): array
    {
        if (!$identity->isVerified()) {
            return [];
        }

        if (!Config::get('chat.tools.enabled', true)) {
            return [];
        }

        return array_values(array_map(
            static fn (ToolHandlerInterface $h): ToolDefinition => $h->definition(),
            $this->handlers
        ));
    }

    public function hasTools(CustomerIdentity $identity): bool
    {
        return $this->definitionsFor($identity) !== [];
    }

    /**
     * Run a tool the model asked for.
     *
     * @param array<string, mixed> $arguments
     */
    public function execute(
        string $name,
        array $arguments,
        CustomerIdentity $identity,
        ?int $conversationId = null
    ): ToolResult {
        $started = microtime(true);

        // Belt and braces: the model should never see a tool it cannot use,
        // but a request could be replayed with a forged history.
        if (!$identity->isVerified()) {
            $result = ToolResult::denied(
                'This action needs a signed-in customer. Ask them to sign in to their account first.'
            );

            $this->audit($name, $arguments, $identity, $result, false, $conversationId, $started);

            return $result;
        }

        $handler = $this->handlers[$name] ?? null;

        if ($handler === null) {
            Logger::warning('Model called an unknown tool', ['tool' => $name]);

            $result = ToolResult::error(sprintf('There is no tool called "%s".', $name));

            $this->audit($name, $arguments, $identity, $result, false, $conversationId, $started, 'unknown_tool');

            return $result;
        }

        try {
            $result = $handler->handle($arguments, $identity);
        } catch (Throwable $e) {
            Logger::error('Tool threw', ['tool' => $name, 'error' => $e->getMessage()]);

            // The exception text may name internal hosts or paths, so the model
            // gets a generic message and the detail goes to the log.
            $result = ToolResult::error(
                'That action could not be completed because of an internal error. Nothing was changed.'
            );
        }

        $this->audit($name, $arguments, $identity, $result, $handler->isDestructive(), $conversationId, $started);

        return $result;
    }

    /**
     * Record what happened. Never allowed to break the conversation — a failed
     * audit write is logged loudly but does not surface to the customer.
     *
     * @param array<string, mixed> $arguments
     */
    private function audit(
        string $name,
        array $arguments,
        CustomerIdentity $identity,
        ToolResult $result,
        bool $destructive,
        ?int $conversationId,
        float $started,
        ?string $outcomeOverride = null
    ): void {
        $outcome = $outcomeOverride ?? $result->outcome;

        $context = [
            'tool'        => $name,
            'customer_id' => $identity->customerId,
            'destructive' => $destructive,
            'outcome'     => $outcome,
            'arguments'   => $arguments,
        ];

        // A denial on a destructive tool is a security-relevant event: either a
        // hallucinated resource or someone probing for another customer's.
        if ($outcome === ToolResult::DENIED) {
            Logger::warning('Tool call denied', $context);
        } elseif ($destructive && $outcome === ToolResult::OK) {
            Logger::warning('Destructive action executed', $context);
        } else {
            Logger::info('Tool call completed', $context);
        }

        $db = $this->db();

        if ($db === null) {
            return;
        }

        try {
            $db->insert('tool_invocations', [
                'conversation_id' => $conversationId,
                'request_id'      => Logger::requestId(),
                'customer_id'     => $identity->customerId,
                'tool_name'       => $name,
                'arguments'       => json_encode($arguments, JSON_UNESCAPED_SLASHES),
                'destructive'     => $destructive ? 1 : 0,
                'outcome'         => $outcome,
                'detail'          => mb_substr($result->message, 0, 2000, 'UTF-8'),
                'duration_ms'     => (int) round((microtime(true) - $started) * 1000),
                'created_at'      => gmdate('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
            Logger::error('Could not write tool audit row', ['error' => $e->getMessage()]);
        }
    }
}
