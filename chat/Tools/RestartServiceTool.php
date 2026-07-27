<?php

declare(strict_types=1);

namespace Hostorio\Chat\Tools;

use Hostorio\Context\CustomerIdentity;
use Hostorio\Llm\ToolDefinition;

/**
 * Restarts a component of one of the customer's services.
 *
 * Destructive: a restart drops in-flight requests and open database
 * connections, and on a busy site that is visible to real users. Same three
 * gates as the password reset — verified identity, proven ownership, explicit
 * confirmation.
 */
final class RestartServiceTool implements ToolHandlerInterface
{
    private const COMPONENTS = ['web', 'database', 'all'];

    public function __construct(
        private readonly ServiceLookup $lookup,
        private readonly ActionExecutorInterface $executor,
    ) {
    }

    public function name(): string
    {
        return 'restart_hosting_service';
    }

    public function isDestructive(): bool
    {
        return true;
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            $this->name(),
            'Restart a component of one of this customer\'s hosting services. This drops '
            . 'in-flight requests and open database connections, which is visible to visitors '
            . 'on a busy site. Never call with confirmed=true unless the customer has explicitly '
            . 'agreed in this conversation.',
            [
                'type'       => 'object',
                'properties' => [
                    'domain' => [
                        'type'        => 'string',
                        'description' => 'Domain of the service to restart.',
                    ],
                    'component' => [
                        'type'        => 'string',
                        'enum'        => self::COMPONENTS,
                        'description' => 'Which component to restart. Prefer the narrowest that '
                                       . 'could fix the problem; use "all" only as a last resort.',
                    ],
                    'confirmed' => [
                        'type'        => 'boolean',
                        'description' => 'Set to true only after the customer has explicitly '
                                       . 'confirmed in this conversation. Defaults to false.',
                    ],
                ],
                'required'   => ['domain', 'component'],
            ]
        );
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function handle(array $arguments, CustomerIdentity $identity): ToolResult
    {
        $domain    = is_string($arguments['domain'] ?? null) ? $arguments['domain'] : '';
        $component = is_string($arguments['component'] ?? null) ? strtolower($arguments['component']) : '';

        // Validate against the enum rather than trusting the model to honour it.
        if (!in_array($component, self::COMPONENTS, true)) {
            return ToolResult::error(sprintf(
                'Unknown component "%s". Valid components are: %s. Nothing was restarted.',
                $component,
                implode(', ', self::COMPONENTS)
            ));
        }

        $resolved = $this->lookup->resolve((int) $identity->customerId, $domain);
        $service  = $resolved['service'];

        if ($service === null) {
            return ToolResult::denied(sprintf(
                'No service matching "%s" belongs to this customer, so nothing was restarted.%s',
                $domain,
                $resolved['owned'] === []
                    ? ''
                    : ' Their services are: ' . implode(', ', array_filter($resolved['owned'])) . '.'
            ));
        }

        if (($arguments['confirmed'] ?? false) !== true) {
            return ToolResult::needsConfirmation(sprintf(
                'Not done yet — confirmation required. Ask the customer to confirm they want the '
                . '%s component of %s restarted, and warn them it will briefly interrupt their '
                . 'site. Only call this tool again with confirmed=true after they say yes.',
                $component,
                (string) ($service['domain'] ?? $domain)
            ));
        }

        return $this->executor->restartService((int) $identity->customerId, $service, $component);
    }
}
