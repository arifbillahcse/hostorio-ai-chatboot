<?php

declare(strict_types=1);

namespace Hostorio\Api;

use Hostorio\Context\IdentityToken;
use Hostorio\Context\WhmcsApiClient;
use Hostorio\Core\Config;
use Hostorio\Core\Logger;
use Hostorio\Core\RateLimiter;
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

    /**
     * Sign a visitor into their WHMCS account from inside the chat widget,
     * without leaving the page.
     *
     * The widget's pre-chat form asks a visitor who picks the Services
     * department to prove they are a real customer before it hands account
     * data (or account-changing tools) to the chat engine. This endpoint is
     * the browser-facing half of that: it forwards the credentials to
     * WHMCS's own ValidateLogin API (never a raw database query — WHMCS's
     * password hash format has changed across versions, and re-deriving it
     * here would silently drift out of sync) and, on success, mints the same
     * signed token the WHMCS template hook would have.
     *
     * Rate limited far tighter than the chat endpoint, because this one
     * checks a real password.
     */
    public function login(Request $request): Response
    {
        $email    = Security::sanitizeEmail((string) $request->input('email', ''));
        $password = (string) $request->input('password', '');

        if ($email === null || $password === '') {
            return Response::error('Please enter your email and password.', 422, 'invalid_input');
        }

        $limit = RateLimiter::attempt(
            'login:' . $request->ip,
            (int) Config::get('rate_limit.login_max_requests', 5),
            (int) Config::get('rate_limit.login_window', 900)
        );

        if (!$limit->allowed) {
            Logger::warning('Widget login rate limited', ['ip' => $request->ip]);

            return Response::error(
                'Too many sign-in attempts. Please wait a few minutes and try again.',
                429,
                'rate_limited'
            )->withHeaders($limit->headers());
        }

        $result = WhmcsApiClient::validateLogin($email, $password);

        if ($result['status'] === WhmcsApiClient::REQUIRES_2FA) {
            return Response::error(
                'Your account has two-factor authentication enabled. Please sign in at '
                . 'my.hostorio.com to continue, then come back and chat.',
                401,
                'requires_2fa'
            );
        }

        if ($result['status'] === WhmcsApiClient::INVALID) {
            Logger::warning('Widget login rejected: invalid credentials', ['ip' => $request->ip]);

            return Response::error('Incorrect email or password.', 401, 'invalid_credentials');
        }

        if ($result['status'] !== WhmcsApiClient::SUCCESS || $result['customer_id'] === null) {
            return Response::error(
                'Sign-in is temporarily unavailable. Please try again shortly.',
                503,
                'unavailable'
            );
        }

        try {
            $token = IdentityToken::issue($result['customer_id']);
        } catch (Throwable $e) {
            Logger::error('Widget login could not issue a token', ['error' => $e->getMessage()]);

            return Response::error('Sign-in is temporarily unavailable. Please try again shortly.', 503, 'unavailable');
        }

        Logger::info('Widget login succeeded', ['customer_id' => $result['customer_id']]);

        return Response::ok([
            'token'      => $token,
            'expires_in' => (int) Config::get('context.identity.token_ttl', 3600),
        ]);
    }
}
