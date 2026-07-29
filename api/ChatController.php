<?php

declare(strict_types=1);

namespace Hostorio\Api;

use Hostorio\Chat\ChatEngine;
use Hostorio\Chat\ConversationStore;
use Hostorio\Context\CustomerIdentity;
use Hostorio\Core\Config;
use Hostorio\Core\Logger;
use Hostorio\Core\RateLimiter;
use Hostorio\Core\Request;
use Hostorio\Core\Response;
use Hostorio\Core\Security;
use Hostorio\Llm\LlmException;
use Throwable;

/**
 * The chat endpoint.
 *
 * Validation, identity and rate limiting happen here; everything else is the
 * ChatEngine's job. Keeping this thin means the security-relevant checks are
 * all visible in one screen.
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

        $conversationId = Security::sanitizeIdentifier((string) $request->input('conversation_id', ''));

        /*
         * A `customer_id` in the request body is a claim from the browser, not
         * a fact — trusting it would let anyone read anyone else's services,
         * tickets and invoices. Only a signed token or a server-side session
         * counts as proof.
         */
        $identity = CustomerIdentity::fromRequest($request);

        // Keyed on the verified id, or the IP. Keying on a claimed id would let
        // a caller reset their own limit by inventing a new number.
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
            'conversation_id' => $conversationId !== '' ? $conversationId : null,
            'message_length'  => mb_strlen($message, 'UTF-8'),
        ]);

        try {
            $engine = new ChatEngine(conversations: $this->conversationStore());

            $reply = $engine->ask(
                $message,
                $identity,
                $conversationId !== '' ? $conversationId : null,
                $request->ip
            );
        } catch (LlmException $e) {
            // The message can name providers and internal error types, so it
            // goes to the log rather than to the customer.
            Logger::error('Chat generation failed', [
                'error_type' => $e->errorType,
                'message'    => $e->getMessage(),
            ]);

            return Response::error(
                'Sorry — I could not generate an answer just now. Please try again in a moment, '
                . 'or open a support ticket if it keeps happening.',
                503,
                'generation_failed'
            )->withHeaders($limit->headers());
        } catch (Throwable $e) {
            Logger::error('Unexpected chat failure', [
                'exception' => $e::class,
                'message'   => $e->getMessage(),
            ]);

            return Response::error(
                'Sorry — something went wrong handling that message.',
                500,
                'server_error'
            )->withHeaders($limit->headers());
        }

        return Response::ok($reply->toPublicArray())->withHeaders($limit->headers());
    }

    /**
     * Redisplay a conversation's messages after the widget reloads on a new
     * page. The conversation id already proves ownership by itself (128 bits
     * of randomness, checked against the same identity/client-key rule as
     * resuming one to chat), so this is deliberately not rate limited the way
     * sending a message is — it is a cheap read, not a paid generation.
     */
    public function history(Request $request): Response
    {
        $conversationId = Security::sanitizeIdentifier((string) $request->input('conversation_id', ''));

        if ($conversationId === '') {
            return Response::error('The `conversation_id` field is required.', 422, 'invalid_input');
        }

        $store = $this->conversationStore();

        if ($store === null) {
            return Response::error('Conversation history is unavailable.', 503, 'unavailable');
        }

        $identity = CustomerIdentity::fromRequest($request);
        $messages = $store->displayHistory($conversationId, $identity, ConversationStore::clientKey($request->ip));

        if ($messages === null) {
            return Response::error('Conversation not found.', 404, 'not_found');
        }

        return Response::ok(['messages' => $messages]);
    }

    /**
     * Conversation persistence is optional: without a working database the
     * chatbot still answers, it just forgets. Better than refusing to talk.
     */
    private function conversationStore(): ?ConversationStore
    {
        try {
            return new ConversationStore();
        } catch (Throwable $e) {
            Logger::warning('Conversation storage unavailable; continuing without history', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
