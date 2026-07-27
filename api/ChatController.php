<?php

declare(strict_types=1);

namespace Hostorio\Api;

use Hostorio\Context\ContextBuilder;
use Hostorio\Context\CustomerIdentity;
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
        $conversation = Security::sanitizeIdentifier((string) $request->input('conversation_id', ''));

        /*
         * Establish who is asking.
         *
         * A `customer_id` in the request body is a claim from the browser, not
         * a fact — trusting it would let anyone read anyone else's services,
         * tickets and invoices by changing a number. Only a signed token or a
         * server-side session counts as proof; an unproven claim is logged and
         * the question is answered without account context.
         */
        $identity = CustomerIdentity::fromRequest($request);

        // Rate limit per verified customer where we have one, per IP otherwise.
        // Deliberately not per *claimed* customer: that would let a caller
        // sidestep the limit by inventing a new id for each request.
        $rateKey = $identity->isVerified()
            ? 'customer:' . $identity->customerId
            : 'ip:' . $request->ip;

        $limit = RateLimiter::attempt($rateKey);

        if (!$limit->allowed) {
            Logger::warning('Rate limit exceeded', ['identity' => $rateKey]);

            return Response::error(
                'Too many messages. Please wait a moment and try again.',
                429,
                'rate_limited'
            )->withHeaders($limit->headers());
        }

        Logger::info('Chat message received', [
            'identity'        => $identity->toArray(),
            'conversation_id' => $conversation !== '' ? $conversation : null,
            'message_length'  => mb_strlen($message, 'UTF-8'),
        ]);

        // Build the retrieval context. Phase 5 hands this to the router along
        // with the conversation history and returns the generated answer.
        $context = (new ContextBuilder())->build($message, $identity);

        // ── Phase 5 replaces the response below with the generated answer.

        return Response::error(
            'Answer generation is not wired up yet. Retrieval is: your message was received, '
            . 'your identity resolved, and a context block assembled.',
            501,
            'not_implemented',
            [
                'accepted' => [
                    'message_length'  => mb_strlen($message, 'UTF-8'),
                    'conversation_id' => $conversation !== '' ? $conversation : null,
                    'identity'        => [
                        // The resolved id is echoed only when it was actually
                        // proved, so this can never confirm a guessed id.
                        'customer_id' => $identity->customerId,
                        'method'      => $identity->method,
                    ],
                ],
                // Metadata only. The context text itself holds account data and
                // is never returned to the browser.
                'context' => $context->toArray(),
            ]
        )->withHeaders($limit->headers());
    }
}
