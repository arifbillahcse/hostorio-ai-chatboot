<?php

declare(strict_types=1);

namespace Hostorio\Chat\Tools;

use Hostorio\Knowledge\WhmcsContext;

/**
 * Resolves a service named by the model to a service the customer actually owns.
 *
 * This is the authorisation choke point for every tool. The model picks the
 * domain from conversation text, and a model can hallucinate one — or be
 * talked into naming someone else's by a crafted message. Matching against the
 * caller's own service list means a wrong or hostile guess simply fails to
 * resolve, rather than acting on a stranger's server.
 */
final class ServiceLookup
{
    public function __construct(private readonly WhmcsContext $whmcs)
    {
    }

    /**
     * Find one of this customer's services by domain.
     *
     * @return array{service: array<string, mixed>|null, owned: array<int, string>}
     */
    public function resolve(int $customerId, string $domain): array
    {
        $account = $this->whmcs->forCustomer($customerId);
        $services = is_array($account['services'] ?? null) ? $account['services'] : [];

        $owned = [];

        foreach ($services as $service) {
            $owned[] = (string) ($service['domain'] ?? '');
        }

        $needle = $this->normalizeDomain($domain);

        if ($needle === '') {
            // A single service is unambiguous, so there is nothing to guess at.
            return ['service' => count($services) === 1 ? $services[0] : null, 'owned' => $owned];
        }

        foreach ($services as $service) {
            if ($this->normalizeDomain((string) ($service['domain'] ?? '')) === $needle) {
                return ['service' => $service, 'owned' => $owned];
            }
        }

        return ['service' => null, 'owned' => $owned];
    }

    /**
     * Compare domains the way a person would: case-insensitively, ignoring a
     * scheme, a `www.` prefix, a path, and a trailing dot.
     */
    private function normalizeDomain(string $domain): string
    {
        $clean = trim(mb_strtolower($domain, 'UTF-8'));

        if ($clean === '') {
            return '';
        }

        $clean = preg_replace('#^[a-z]+://#', '', $clean) ?? $clean;
        $clean = explode('/', $clean)[0];
        $clean = preg_replace('/^www\./', '', $clean) ?? $clean;

        return rtrim($clean, '.');
    }
}
