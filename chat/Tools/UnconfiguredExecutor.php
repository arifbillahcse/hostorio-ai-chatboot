<?php

declare(strict_types=1);

namespace Hostorio\Chat\Tools;

use Hostorio\Core\Logger;

/**
 * The default executor: refuses honestly.
 *
 * This is what ships, because no generic implementation can know how a given
 * hosting company's WHM/cPanel estate is wired. It is the safe default in the
 * strongest sense — an unconfigured install cannot accidentally act on
 * production infrastructure.
 *
 * To enable real actions, implement ActionExecutorInterface against your own
 * API and register it with the ToolRegistry.
 */
final class UnconfiguredExecutor implements ActionExecutorInterface
{
    public function isConfigured(): bool
    {
        return false;
    }

    /**
     * @param array<string, mixed> $service
     */
    public function resetPassword(int $customerId, array $service): ToolResult
    {
        return $this->refuse('reset_password', $customerId);
    }

    /**
     * @param array<string, mixed> $service
     */
    public function restartService(int $customerId, array $service, string $component): ToolResult
    {
        return $this->refuse('restart_service', $customerId);
    }

    private function refuse(string $action, int $customerId): ToolResult
    {
        Logger::warning('Action requested but no executor is configured', [
            'action'      => $action,
            'customer_id' => $customerId,
        ]);

        // Phrased for the model to relay: it must tell the customer plainly
        // that nothing happened, rather than implying success.
        return ToolResult::error(
            'This action is not enabled on this installation. Nothing has been changed. '
            . 'Tell the customer you cannot perform it yourself and that they should '
            . 'open a support ticket or use their control panel.'
        );
    }
}
