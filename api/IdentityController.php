<?php

declare(strict_types=1);

namespace Hostorio\Api;

use Hostorio\Context\IdentityToken;
use Hostorio\Core\Config;
use Hostorio\Core\Logger;
use Hostorio\Core\Request;
use Hostorio\Core\Response;
use Hostorio\Core\Security;
use Throwable;

/**
 * Server-to-server bridge for minting identity tokens.
 *
 * IdentityToken::issue() is meant to be called from code that already
 * authenticated the visitor — normally a WHMCS template running on the same
 * PHP process. That breaks when WHMCS runs on an older PHP version than this
 * application requires: `require_once bootstrap.php` from a PHP 7.x process
 * fails to even parse this codebase's PHP 8.1 syntax.
 *
 * This endpoint exists so the two applications never need to share a PHP
 * runtime. WHMCS calls it over HTTP instead of including our code directly.
 *
 * It is deliberately NOT reachable from a browser in practice: there is no
 * customer-facing reason to call it, and the shared secret check below fails
 * closed, so a missing configuration refuses every request rather than
 * silently minting tokens for anyone who asks.
 */
final class IdentityController
{
    public function token(Request $request): Response
    {
        $secret = (string) Config::get('context.identity.bridge_secret', '');

        if ($secret === '') {
            // Fail closed: without a configured secret there is nothing to
            // check a caller's claim against, so nobody gets a token.
            Logger::error('Identity bridge called but IDENTITY_BRIDGE_SECRET is not configured');

            return Response::error('Not found.', 404, 'not_found');
        }

        $given = (string) ($request->header('x-bridge-secret', '') ?? '');

        if ($given === '' || !Security::secureCompare($secret, $given)) {
            Logger::warning('Identity bridge rejected an invalid secret', [
                'ip' => $request->ip,
            ]);

            return Response::error('Not found.', 404, 'not_found');
        }

        $customerId = Security::sanitizeId($request->input('customer_id'));

        if ($customerId === null) {
            return Response::error('The `customer_id` field must be a positive integer.', 422, 'invalid_input');
        }

        try {
            $token = IdentityToken::issue($customerId);
        } catch (Throwable $e) {
            Logger::error('Identity bridge could not issue a token', [
                'error' => $e->getMessage(),
            ]);

            return Response::error('Could not issue a token.', 500, 'server_error');
        }

        Logger::info('Identity bridge issued a token', ['customer_id' => $customerId]);

        return Response::ok([
            'token'      => $token,
            'expires_in' => (int) Config::get('context.identity.token_ttl', 3600),
        ]);
    }
}
