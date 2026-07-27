<?php

declare(strict_types=1);

namespace Hostorio\Context;

use Hostorio\Core\Config;
use Hostorio\Core\Logger;
use Hostorio\Core\Request;

/**
 * Establishes *who is asking*, and refuses to guess.
 *
 * Two accepted proofs, in order:
 *
 *  1. A signed identity token (see IdentityToken) — works when the widget is
 *     embedded on a different host from this app.
 *  2. A server-side PHP session set by WHMCS or WordPress after it
 *     authenticated the visitor — for same-server installs.
 *
 * A `customer_id` in the request body is explicitly *not* a proof. It is read
 * only so an unverified claim can be logged and ignored, which is what turns a
 * silent data leak into a visible alert.
 */
final class CustomerIdentity
{
    private function __construct(
        public readonly ?int $customerId,
        public readonly string $method,
        public readonly bool $claimRejected,
    ) {
    }

    public static function anonymous(bool $claimRejected = false): self
    {
        return new self(null, 'anonymous', $claimRejected);
    }

    public static function verified(int $customerId, string $method): self
    {
        return new self($customerId, $method, false);
    }

    public function isVerified(): bool
    {
        return $this->customerId !== null;
    }

    /**
     * Resolve identity from the request.
     */
    public static function fromRequest(Request $request): self
    {
        $token = $request->header('x-chat-token');

        if ($token === null) {
            $bodyToken = $request->input('token');
            $token     = is_string($bodyToken) ? $bodyToken : null;
        }

        if ($token !== null && $token !== '') {
            $customerId = IdentityToken::verify($token);

            if ($customerId !== null) {
                return self::verified($customerId, 'token');
            }

            Logger::warning('Identity token rejected', ['ip' => $request->ip]);

            return self::anonymous(true);
        }

        $sessionId = self::fromSession();

        if ($sessionId !== null) {
            return self::verified($sessionId, 'session');
        }

        // An unproven claim. Answer the question, attach no account data, and
        // make the attempt visible — a spike here is someone enumerating ids.
        $claimed = $request->input('customer_id');

        if ($claimed !== null && $claimed !== '') {
            Logger::warning('Unverified customer_id claim ignored', [
                'claimed' => is_scalar($claimed) ? (string) $claimed : '(non-scalar)',
                'ip'      => $request->ip,
            ]);

            return self::anonymous(true);
        }

        return self::anonymous();
    }

    /**
     * Read an identity established server-side.
     *
     * Only trustworthy because the session store is on the server: the browser
     * holds an opaque session id, not the customer id.
     */
    private static function fromSession(): ?int
    {
        if (!Config::get('context.identity.allow_session', true)) {
            return null;
        }

        if (session_status() !== PHP_SESSION_ACTIVE) {
            return null;
        }

        $key   = (string) Config::get('context.identity.session_key', 'hoai_customer_id');
        $value = $_SESSION[$key] ?? null;

        if (!is_scalar($value)) {
            return null;
        }

        $id = filter_var((string) $value, FILTER_VALIDATE_INT);

        return ($id === false || $id < 1) ? null : $id;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'customer_id'    => $this->customerId,
            'method'         => $this->method,
            'claim_rejected' => $this->claimRejected,
        ];
    }
}
