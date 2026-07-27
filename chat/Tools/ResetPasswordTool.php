<?php

declare(strict_types=1);

namespace Hostorio\Chat\Tools;

use Hostorio\Context\CustomerIdentity;
use Hostorio\Llm\ToolDefinition;

/**
 * Resets the control-panel password for one of the customer's services.
 *
 * Destructive: it locks the customer out of their existing credentials and
 * breaks anything using them (FTP clients, deploy scripts, backup jobs). So it
 * runs behind three gates:
 *
 *  1. Identity must be verified — enforced by the registry.
 *  2. The service must belong to the caller — enforced here.
 *  3. The customer must have said yes — enforced by the `confirmed` flag,
 *     which the model may only set after actually asking.
 *
 * The third gate matters because the model is choosing to call this from
 * free text. "I forgot my password" is a question; "reset my password" is an
 * instruction; the difference is easy to misread, and the cost of guessing
 * wrong is a customer locked out of a live site.
 */
final class ResetPasswordTool implements ToolHandlerInterface
{
    public function __construct(
        private readonly ServiceLookup $lookup,
        private readonly ActionExecutorInterface $executor,
    ) {
    }

    public function name(): string
    {
        return 'reset_hosting_password';
    }

    public function isDestructive(): bool
    {
        return true;
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            $this->name(),
            'Reset the control panel password for one of this customer\'s hosting services. '
            . 'This immediately invalidates the current password and breaks any FTP client, '
            . 'deploy script or backup job using it. Never call this with confirmed=true unless '
            . 'the customer has explicitly agreed in this conversation after being told what it '
            . 'will break.',
            [
                'type'       => 'object',
                'properties' => [
                    'domain' => [
                        'type'        => 'string',
                        'description' => 'Domain of the service whose password should be reset.',
                    ],
                    'confirmed' => [
                        'type'        => 'boolean',
                        'description' => 'Set to true only after the customer has explicitly '
                                       . 'confirmed in this conversation. Defaults to false.',
                    ],
                ],
                'required'   => ['domain'],
            ]
        );
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function handle(array $arguments, CustomerIdentity $identity): ToolResult
    {
        $domain = is_string($arguments['domain'] ?? null) ? $arguments['domain'] : '';

        $resolved = $this->lookup->resolve((int) $identity->customerId, $domain);
        $service  = $resolved['service'];

        if ($service === null) {
            return ToolResult::denied(sprintf(
                'No service matching "%s" belongs to this customer, so nothing was changed.%s',
                $domain,
                $resolved['owned'] === []
                    ? ''
                    : ' Their services are: ' . implode(', ', array_filter($resolved['owned'])) . '.'
            ));
        }

        // Only an explicit boolean true counts. A model that emits the string
        // "false" or omits the field must not be read as consent.
        if (($arguments['confirmed'] ?? false) !== true) {
            return ToolResult::needsConfirmation(sprintf(
                'Not done yet — confirmation required. Ask the customer to confirm they want the '
                . 'control panel password for %s reset, and warn them it will immediately break '
                . 'any FTP client, deploy script or backup job using the current password. '
                . 'Only call this tool again with confirmed=true after they say yes.',
                (string) ($service['domain'] ?? $domain)
            ));
        }

        return $this->executor->resetPassword((int) $identity->customerId, $service);
    }
}
