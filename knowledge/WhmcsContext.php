<?php

declare(strict_types=1);

namespace Hostorio\Knowledge;

use Hostorio\Core\Logger;
use Hostorio\Database\WhmcsDatabase;
use Throwable;

/**
 * Reads a customer's live account state from WHMCS.
 *
 * Structured, never embedded — and that distinction is the important one.
 * Account data is small, per-customer, and changes by the minute; embedding it
 * would be expensive, instantly stale, and would leak one customer's details
 * into another's search results. It is fetched on demand for the specific
 * customer asking, and only that customer.
 *
 * READ-ONLY: the connection rejects writes before they reach MySQL.
 *
 * Note on encrypted columns — WHMCS encrypts several client fields with its own
 * key, so they are unreadable over a raw SQL connection by design. Nothing here
 * depends on those; anything that needs them must go through the WHMCS API.
 */
final class WhmcsContext
{
    /** Cap on rows pulled per section, to bound prompt size and query cost. */
    private const MAX_SERVICES = 20;
    private const MAX_TICKETS  = 5;
    private const MAX_INVOICES = 5;

    public function __construct(private readonly WhmcsDatabase $whmcs)
    {
    }

    public static function make(): self
    {
        return new self(WhmcsDatabase::instance());
    }

    public function isAvailable(): bool
    {
        return $this->whmcs->isConfigured();
    }

    /**
     * Everything known about one customer, as structured data.
     *
     * Returns an empty array when WHMCS is not configured, so callers can treat
     * "no account context" as a normal state rather than an error — a visitor
     * who is not logged in is the common case.
     *
     * @return array<string, mixed>
     */
    public function forCustomer(int $customerId): array
    {
        if (!$this->isAvailable() || $customerId <= 0) {
            return [];
        }

        try {
            $client = $this->client($customerId);

            if ($client === null) {
                return [];
            }

            return [
                'client'          => $client,
                'services'        => $this->services($customerId),
                'domains'         => $this->domains($customerId),
                'recent_tickets'  => $this->recentTickets($customerId),
                'unpaid_invoices' => $this->unpaidInvoices($customerId),
            ];
        } catch (Throwable $e) {
            // Account context is an enhancement. Losing it should degrade the
            // answer, not fail the customer's question.
            Logger::error('WHMCS context lookup failed', [
                'customer_id' => $customerId,
                'error'       => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function client(int $customerId): ?array
    {
        $row = $this->whmcs->selectOne(
            'SELECT id, firstname, lastname, companyname, email, status, datecreated, credit, currency
               FROM tblclients
              WHERE id = :id
              LIMIT 1',
            ['id' => $customerId]
        );

        if ($row === null) {
            return null;
        }

        return [
            'id'      => (int) $row['id'],
            'name'    => trim(((string) $row['firstname']) . ' ' . ((string) $row['lastname'])),
            'company' => (string) ($row['companyname'] ?? ''),
            'email'   => (string) ($row['email'] ?? ''),
            'status'  => (string) ($row['status'] ?? ''),
            'since'   => (string) ($row['datecreated'] ?? ''),
            'credit'  => (float) ($row['credit'] ?? 0),
        ];
    }

    /**
     * Active hosting services. Cancelled ones are excluded — they are a source
     * of confusing answers about products the customer no longer has.
     *
     * @return array<int, array<string, mixed>>
     */
    private function services(int $customerId): array
    {
        $rows = $this->whmcs->select(
            sprintf(
                'SELECT h.id, h.domain, h.domainstatus, h.regdate, h.nextduedate,
                        h.dedicatedip, h.username, p.name AS product_name, s.name AS server_name
                   FROM tblhosting h
                   LEFT JOIN tblproducts p ON p.id = h.packageid
                   LEFT JOIN tblservers  s ON s.id = h.server
                  WHERE h.userid = :id
                    AND h.domainstatus NOT IN (%s)
                  ORDER BY h.regdate DESC
                  LIMIT %d',
                "'Cancelled', 'Fraud'",
                self::MAX_SERVICES
            ),
            ['id' => $customerId]
        );

        return array_map(static fn (array $r): array => [
            'id'          => (int) $r['id'],
            'domain'      => (string) ($r['domain'] ?? ''),
            'product'     => (string) ($r['product_name'] ?? ''),
            'status'      => (string) ($r['domainstatus'] ?? ''),
            'server'      => (string) ($r['server_name'] ?? ''),
            'dedicated_ip' => (string) ($r['dedicatedip'] ?? ''),
            'next_due'    => (string) ($r['nextduedate'] ?? ''),
        ], $rows);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function domains(int $customerId): array
    {
        $rows = $this->whmcs->select(
            sprintf(
                'SELECT id, domain, status, registrar, expirydate, nextduedate
                   FROM tbldomains
                  WHERE userid = :id AND status NOT IN (%s)
                  ORDER BY expirydate ASC
                  LIMIT %d',
                "'Cancelled', 'Fraud'",
                self::MAX_SERVICES
            ),
            ['id' => $customerId]
        );

        return array_map(static fn (array $r): array => [
            'domain'    => (string) ($r['domain'] ?? ''),
            'status'    => (string) ($r['status'] ?? ''),
            'registrar' => (string) ($r['registrar'] ?? ''),
            'expires'   => (string) ($r['expirydate'] ?? ''),
        ], $rows);
    }

    /**
     * Recent support history.
     *
     * Worth including even when unrelated to the current question: a customer
     * who raised the same issue last week should not be told the same thing
     * that already failed to fix it.
     *
     * @return array<int, array<string, mixed>>
     */
    private function recentTickets(int $customerId): array
    {
        $rows = $this->whmcs->select(
            sprintf(
                'SELECT id, tid, date, title, status, lastreply
                   FROM tbltickets
                  WHERE userid = :id
                  ORDER BY date DESC
                  LIMIT %d',
                self::MAX_TICKETS
            ),
            ['id' => $customerId]
        );

        return array_map(static fn (array $r): array => [
            'reference' => (string) ($r['tid'] ?? ''),
            'subject'   => (string) ($r['title'] ?? ''),
            'status'    => (string) ($r['status'] ?? ''),
            'opened'    => (string) ($r['date'] ?? ''),
            'last_reply' => (string) ($r['lastreply'] ?? ''),
        ], $rows);
    }

    /**
     * Outstanding invoices.
     *
     * Directly actionable: a suspended service usually has an unpaid invoice
     * behind it, and knowing that turns "your site is down" into "your site is
     * down because invoice #123 is overdue".
     *
     * @return array<int, array<string, mixed>>
     */
    private function unpaidInvoices(int $customerId): array
    {
        $rows = $this->whmcs->select(
            sprintf(
                "SELECT id, invoicenum, date, duedate, total, status
                   FROM tblinvoices
                  WHERE userid = :id AND status = 'Unpaid'
                  ORDER BY duedate ASC
                  LIMIT %d",
                self::MAX_INVOICES
            ),
            ['id' => $customerId]
        );

        return array_map(static fn (array $r): array => [
            'number'  => (string) ($r['invoicenum'] !== '' ? $r['invoicenum'] : $r['id']),
            'total'   => (float) ($r['total'] ?? 0),
            'due'     => (string) ($r['duedate'] ?? ''),
            'overdue' => ((string) ($r['duedate'] ?? '')) !== ''
                && strtotime((string) $r['duedate']) < time(),
        ], $rows);
    }
}
