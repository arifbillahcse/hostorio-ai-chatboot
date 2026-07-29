<?php
/**
 * Hostorio AI Chatbot — WHMCS identity bridge.
 *
 * Installation
 * ------------
 * Copy this file to includes/hooks/hostorio_chatbot.php inside your WHMCS
 * install. WHMCS auto-loads every file in includes/hooks/ — no activation
 * step needed.
 *
 * Then set the three constants below and add the widget script tag to your
 * template's footer.tpl (see the README this file ships alongside).
 *
 * Why HTTP instead of including the chatbot's code directly
 * -----------------------------------------------------------
 * The chatbot requires PHP 8.1. Many WHMCS installs run an older PHP version
 * for compatibility with other addons, and a `require_once` of PHP 8.1 code
 * from an older runtime fails to parse — it is not a bug to fix, the two
 * codebases simply cannot share a process. This hook calls the chatbot's own
 * /api/identity/token endpoint over HTTP instead, so WHMCS's PHP version is
 * irrelevant.
 *
 * Written to PHP 7.4 for that same reason — this file must run inside
 * whatever PHP version your WHMCS install uses, even if that is old.
 */

// ── Configuration — edit these three values ────────────────────────────────

// Where the chatbot is installed, no trailing slash.
const HOSTORIO_CHATBOT_URL = 'https://chat.hostorio.com';

// Must match IDENTITY_BRIDGE_SECRET in the chatbot's .env file exactly.
const HOSTORIO_BRIDGE_SECRET = '';

// Seconds to wait for the chatbot to respond before giving up. Short on
// purpose: a slow or unreachable chatbot must never make the WHMCS client
// area itself feel slow.
const HOSTORIO_BRIDGE_TIMEOUT = 3;

// ── Hook ─────────────────────────────────────────────────────────────────

add_hook('ClientAreaPage', 1, function ($vars) {
    if (HOSTORIO_BRIDGE_SECRET === '') {
        return array();
    }

    if (empty($vars['clientsdetails']['userid'])) {
        return array();
    }

    $clientId = (int) $vars['clientsdetails']['userid'];

    if ($clientId < 1) {
        return array();
    }

    $token = hostorio_fetch_identity_token($clientId);

    return $token === null ? array() : array('hostorio_chat_token' => $token);
});

/**
 * Ask the chatbot to mint a signed identity token for this customer.
 *
 * Returns null on any failure — an unreachable chatbot must degrade to an
 * anonymous widget, never break the WHMCS page that embeds it.
 *
 * @param int $clientId
 * @return string|null
 */
function hostorio_fetch_identity_token($clientId)
{
    if (!function_exists('curl_init')) {
        return null;
    }

    $ch = curl_init(rtrim(HOSTORIO_CHATBOT_URL, '/') . '/api/identity/token');

    curl_setopt_array($ch, array(
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(array('customer_id' => $clientId)),
        CURLOPT_HTTPHEADER     => array(
            'Content-Type: application/json',
            'X-Bridge-Secret: ' . HOSTORIO_BRIDGE_SECRET,
        ),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => HOSTORIO_BRIDGE_TIMEOUT,
        CURLOPT_TIMEOUT        => HOSTORIO_BRIDGE_TIMEOUT,
        CURLOPT_SSL_VERIFYPEER => true,
    ));

    $body   = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $failed = curl_errno($ch) !== 0;
    curl_close($ch);

    if ($failed || $status !== 200 || !is_string($body)) {
        return null;
    }

    $decoded = json_decode($body, true);

    if (!is_array($decoded) || empty($decoded['ok']) || empty($decoded['token'])) {
        return null;
    }

    return (string) $decoded['token'];
}
