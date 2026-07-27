<?php

declare(strict_types=1);

/**
 * Talk to the chatbot from the command line.
 *
 *   php tools/chat.php "why is my ssl certificate not working?"
 *   php tools/chat.php --customer=42 "is my site suspended?"
 *   php tools/chat.php --interactive --customer=42
 *
 * --customer forces a verified identity and is CLI-only; nothing over HTTP can
 * do this. It exists so account context and tools can be exercised during setup.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This tool must be run from the command line.\n");
}

require_once dirname(__DIR__) . '/bootstrap.php';

use Hostorio\Chat\ChatEngine;
use Hostorio\Chat\ChatReply;
use Hostorio\Chat\ConversationStore;
use Hostorio\Context\CustomerIdentity;
use Hostorio\Llm\LlmException;

$flags      = [];
$positional = [];

foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--([a-z\-]+)(?:=(.*))?$/i', $argument, $m) === 1) {
        $flags[strtolower($m[1])] = $m[2] ?? 'true';
    } else {
        $positional[] = $argument;
    }
}

$customerId = isset($flags['customer']) ? (int) $flags['customer'] : 0;

$identity = $customerId > 0
    ? CustomerIdentity::verified($customerId, 'cli')
    : CustomerIdentity::anonymous();

// Conversation storage is optional — without a database the chat still works,
// it just does not remember.
$store = null;

try {
    $store = new ConversationStore();
} catch (Throwable $e) {
    fwrite(STDERR, "Note: conversation history unavailable (" . $e->getMessage() . ")\n");
}

$engine = new ChatEngine(conversations: $store);

function render(ChatReply $reply): void
{
    echo "\n" . $reply->text . "\n\n";
    echo str_repeat('-', 68) . "\n";
    printf("  %-16s %s\n", 'answered by', $reply->provider . ' (' . $reply->model . ')');
    printf("  %-16s %s\n", 'classified as', $reply->queryType);
    printf("  %-16s %d in / %d out  (context %d)\n",
        'tokens', $reply->inputTokens, $reply->outputTokens, $reply->contextTokens);
    printf("  %-16s $%.6f\n", 'est. cost', $reply->costUsd);

    if ($reply->conversationId !== null) {
        printf("  %-16s %s\n", 'conversation', $reply->conversationId);
    }

    foreach ($reply->toolCalls as $call) {
        printf("  %-16s %s -> %s\n", 'tool', $call['name'], $call['outcome']);
    }

    foreach ($reply->sources as $source) {
        printf("  %-16s %s\n", 'source', $source);
    }

    if ($reply->truncated) {
        printf("  %-16s answer hit the output limit and is incomplete\n", 'WARNING');
    }

    echo "\n";
}

// ── Interactive mode ─────────────────────────────────────────────────────────
if (isset($flags['interactive'])) {
    echo "\nHostorio AI Chatbot — interactive. Type 'exit' to quit.\n";
    echo $identity->isVerified()
        ? "Signed in as customer {$customerId}.\n"
        : "Not signed in — no account data or tools.\n";

    $conversation = null;

    while (true) {
        echo "\n> ";
        $line = fgets(STDIN);

        if ($line === false) {
            break;
        }

        $line = trim($line);

        if ($line === '' ) {
            continue;
        }

        if (in_array(strtolower($line), ['exit', 'quit'], true)) {
            break;
        }

        try {
            $reply        = $engine->ask($line, $identity, $conversation, '127.0.0.1');
            $conversation = $reply->conversationId;
            render($reply);
        } catch (LlmException $e) {
            fwrite(STDERR, "\nFailed: " . $e->getMessage() . "\n");
        }
    }

    echo "\nBye.\n";
    exit(0);
}

// ── Single question ──────────────────────────────────────────────────────────
$question = trim(implode(' ', $positional));

if ($question === '') {
    fwrite(STDERR, "\nUsage: php tools/chat.php [--customer=42] \"your question\"\n");
    fwrite(STDERR, "       php tools/chat.php --interactive [--customer=42]\n\n");
    exit(1);
}

try {
    render($engine->ask($question, $identity, $flags['conversation'] ?? null, '127.0.0.1'));
} catch (LlmException $e) {
    fwrite(STDERR, "\nFailed: " . $e->getMessage() . "\n\n");
    exit(1);
}
