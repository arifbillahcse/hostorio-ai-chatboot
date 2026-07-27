<?php

declare(strict_types=1);

namespace Hostorio\Api;

use Hostorio\Core\Config;
use Hostorio\Core\Logger;
use Hostorio\Core\RateLimiter;
use Hostorio\Core\Request;
use Hostorio\Core\Response;
use Hostorio\Core\Security;

/**
 * Chat endpoint.
 *
 * Phase 1 wires the full request path — validation, rate limiting, logging —
 * and stops short of generating an answer. The pipeline is therefore testable
 * end to end now, and Phase 5 only has to replace the placeholder response
 * with a real one.
 */
final class ChatController
{
    public function send(Request $request): Response
    {
        $rawMessage = $request->input('message', '');

        if (!is_string($rawMessage)) {
            return Response::error('The `message` field must be a string.', 422, 'invalid_input');
        }

        $maxLength = (int) Config::get('security.max_message_length', Security::MAX_MESSAGE_LENGTH);
        $message   = Security::sanitizeMessage($rawMessage, $maxLength);

        if ($message === '') {
            return Response::error('Please enter a message.', 422, 'invalid_input');
        }

        // Customer id, when the widget is embedded in a logged-in WHMCS area.
        // Trusted only as a hint in Phase 1; Phase 4 verifies it against a
        // signed session before any customer data is read.
        $customerId    = Security::sanitizeId($request->input('customer_id'));
        $conversation  = Security::sanitizeIdentifier((string) $request->input('conversation_id', ''));

        // Rate limit per customer where known, per IP otherwise.
        $identity = $customerId !== null ? 'customer:' . $customerId : 'ip:' . $request->ip;
        $limit    = RateLimiter::attempt($identity);

        if (!$limit->allowed) {
            Logger::warning('Rate limit exceeded', ['identity' => $identity]);

            return Response::error(
                'Too many messages. Please wait a moment and try again.',
                429,
                'rate_limited'
            )->withHeaders($limit->headers());
        }

        Logger::info('Chat message received', [
            'customer_id'     => $customerId,
            'conversation_id' => $conversation !== '' ? $conversation : null,
            'message_length'  => mb_strlen($message, 'UTF-8'),
        ]);

        // ── Phase 2 inserts provider routing here.
        // ── Phase 4 inserts RAG context building here.
        // ── Phase 5 replaces the response below with the generated answer.

        return Response::error(
            'Chat processing is not built yet. The foundation is working: your message was '
            . 'received, validated and rate-limited successfully.',
            501,
            'not_implemented',
            [
                'accepted' => [
                    'message_length'  => mb_strlen($message, 'UTF-8'),
                    'customer_id'     => $customerId,
                    'conversation_id' => $conversation !== '' ? $conversation : null,
                ],
            ]
        )->withHeaders($limit->headers());
    }
}
