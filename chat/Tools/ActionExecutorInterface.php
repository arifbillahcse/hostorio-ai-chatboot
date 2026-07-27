<?php

declare(strict_types=1);

namespace Hostorio\Chat\Tools;

/**
 * Performs the actual change against hosting infrastructure.
 *
 * Kept behind an interface because this project cannot ship a working
 * implementation: resetting a cPanel password or restarting a service needs
 * WHM/cPanel credentials and knowledge of a specific hosting estate.
 *
 * The alternative — pretending the action worked — would be worse than not
 * shipping it. A chatbot that confidently tells a customer their password has
 * been reset when nothing happened costs more trust than one that says the
 * feature is not enabled.
 *
 * Until a real executor is configured, {@see UnconfiguredExecutor} refuses
 * clearly and the model relays a truthful "I can't do that from here" instead.
 */
interface ActionExecutorInterface
{
    public function isConfigured(): bool;

    /**
     * Reset the control-panel password for a service.
     *
     * Ownership has already been verified by the calling tool.
     *
     * @param array<string, mixed> $service the WHMCS service row
     */
    public function resetPassword(int $customerId, array $service): ToolResult;

    /**
     * Restart a service (web server, database, or the whole container).
     *
     * @param array<string, mixed> $service the WHMCS service row
     */
    public function restartService(int $customerId, array $service, string $component): ToolResult;
}
