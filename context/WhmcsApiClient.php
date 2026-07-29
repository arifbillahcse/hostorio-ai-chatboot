<?php

declare(strict_types=1);

namespace Hostorio\Context;

use Hostorio\Core\Config;
use Hostorio\Core\Logger;

/**
 * Validates a WHMCS client's email/password via WHMCS's own Local API.
 *
 * Deliberately does not touch the read-only WHMCS database connection
 * (Hostorio\Database\WhmcsDatabase) or reimplement WHMCS's password hashing.
 * WHMCS has changed its hashing scheme across versions, and getting that
 * wrong either locks customers out or, worse, accepts a wrong password.
 * ValidateLogin is the action WHMCS ships for exactly this case, so it is
 * the only thing this class calls.
 */
final class WhmcsApiClient
{
    public const SUCCESS      = 'success';
    public const REQUIRES_2FA = 'requires_2fa';
    public const INVALID      = 'invalid';
    public const UNAVAILABLE  = 'unavailable';

    /**
     * @return array{status: string, customer_id: ?int}
     */
    public static function validateLogin(string $email, string $password): array
    {
        $url        = (string) Config::get('whmcs_api.url', '');
        $identifier = (string) Config::get('whmcs_api.identifier', '');
        $secret     = (string) Config::get('whmcs_api.secret', '');

        if ($url === '' || $identifier === '' || $secret === '') {
            Logger::error('WHMCS API is not configured; cannot validate a widget login');

            return ['status' => self::UNAVAILABLE, 'customer_id' => null];
        }

        if (!function_exists('curl_init')) {
            Logger::error('curl extension is unavailable; cannot validate a widget login');

            return ['status' => self::UNAVAILABLE, 'customer_id' => null];
        }

        $handle = curl_init($url);

        curl_setopt_array($handle, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'action'       => 'ValidateLogin',
                'identifier'   => $identifier,
                'secret'       => $secret,
                'email'        => $email,
                'password2'    => $password,
                'responsetype' => 'json',
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 8,
            // Some hosts front their WHMCS install with a WAF/ModSecurity
            // rule that blocks any request without a User-Agent header —
            // curl sends none by default, which reads as a bot and gets a
            // bare 403 before WHMCS's api.php ever runs.
            CURLOPT_USERAGENT      => 'Hostorio-Chatbot/1.0 (+https://chat.hostorio.com)',
            // Certificate verification is non-negotiable: this request carries
            // a customer's real password.
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $body      = curl_exec($handle);
        $status    = curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $failed    = curl_errno($handle) !== 0;
        $curlError = $failed ? curl_error($handle) : null;
        curl_close($handle);

        if ($failed || $status !== 200 || !is_string($body)) {
            Logger::error('WHMCS API unreachable while validating a widget login', [
                'status'       => $status,
                'curl_error'   => $curlError,
                'body_snippet' => is_string($body) ? substr($body, 0, 300) : null,
            ]);

            return ['status' => self::UNAVAILABLE, 'customer_id' => null];
        }

        $decoded = json_decode($body, true);

        if (!is_array($decoded)) {
            Logger::error('WHMCS API returned a non-JSON response while validating a widget login');

            return ['status' => self::UNAVAILABLE, 'customer_id' => null];
        }

        if (($decoded['result'] ?? '') !== 'success') {
            return ['status' => self::INVALID, 'customer_id' => null];
        }

        $customerId = (int) ($decoded['userid'] ?? 0);

        if ($customerId < 1) {
            return ['status' => self::INVALID, 'customer_id' => null];
        }

        // No passwordhash is issued until the second factor is verified, and
        // there is no in-widget flow for entering one — the widget falls back
        // to sending these customers to sign in on WHMCS directly.
        if (!empty($decoded['twoFactorEnabled'])) {
            return ['status' => self::REQUIRES_2FA, 'customer_id' => $customerId];
        }

        return ['status' => self::SUCCESS, 'customer_id' => $customerId];
    }
}
