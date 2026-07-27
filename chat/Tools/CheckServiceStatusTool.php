<?php

declare(strict_types=1);

namespace Hostorio\Chat\Tools;

use Hostorio\Context\CustomerIdentity;
use Hostorio\Llm\ToolDefinition;

/**
 * Reports the status of one of the customer's own hosting services.
 *
 * Read-only, so no confirmation is needed — but ownership is still checked,
 * because "is example.com suspended?" is itself information that should not be
 * available about someone else's domain.
 */
final class CheckServiceStatusTool implements ToolHandlerInterface
{
    public function __construct(private readonly ServiceLookup $lookup)
    {
    }

    public function name(): string
    {
        return 'check_service_status';
    }

    public function isDestructive(): bool
    {
        return false;
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            $this->name(),
            'Check the current status of one of this customer\'s hosting services, including '
            . 'whether it is active or suspended, the product name, the server it runs on, and '
            . 'the next due date. Use this before speculating about why a site is down.',
            [
                'type'       => 'object',
                'properties' => [
                    'domain' => [
                        'type'        => 'string',
                        'description' => 'The domain of the service to check. Omit only if the '
                                       . 'customer has exactly one service.',
                    ],
                ],
                'required'   => [],
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
            // Naming what they *do* own turns a dead end into a useful reply,
            // and reveals nothing they could not already see in their portal.
            return ToolResult::denied(
                $resolved['owned'] === []
                    ? 'This customer has no active hosting services.'
                    : sprintf(
                        'No service matching "%s" belongs to this customer. Their services are: %s. '
                        . 'Ask which one they mean.',
                        $domain,
                        implode(', ', array_filter($resolved['owned']))
                    )
            );
        }

        $parts = [
            'Domain: ' . (string) ($service['domain'] ?? ''),
            'Product: ' . (string) ($service['product'] ?? 'unknown'),
            'Status: ' . (string) ($service['status'] ?? 'unknown'),
        ];

        if (($service['server'] ?? '') !== '') {
            $parts[] = 'Server: ' . (string) $service['server'];
        }

        if (($service['next_due'] ?? '') !== '') {
            $parts[] = 'Next due: ' . (string) $service['next_due'];
        }

        if (strcasecmp((string) ($service['status'] ?? ''), 'Suspended') === 0) {
            $parts[] = 'Note: a suspended service is usually caused by an unpaid invoice. '
                     . 'Check the billing information in the account context before advising.';
        }

        return ToolResult::ok(implode("\n", $parts));
    }
}
