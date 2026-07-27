<?php

declare(strict_types=1);

/**
 * Preview the context block a question would produce.
 *
 * This is the Phase 4 deliverable made inspectable — exactly what the model
 * will be shown, before any of it costs a token.
 *
 *   php tools/context.php "why is my site down?"
 *   php tools/context.php --customer=42 "why is my site down?"
 *   php tools/context.php --token                 Mint an identity token
 *
 * --customer bypasses identity verification and is CLI-only. It exists so the
 * account section can be inspected during setup; nothing over HTTP can do this.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This tool must be run from the command line.\n");
}

require_once dirname(__DIR__) . '/bootstrap.php';

use Hostorio\Context\ContextBuilder;
use Hostorio\Context\CustomerIdentity;
use Hostorio\Context\IdentityToken;

$flags      = [];
$positional = [];

foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--([a-z\-]+)(?:=(.*))?$/i', $argument, $m) === 1) {
        $flags[strtolower($m[1])] = $m[2] ?? 'true';
    } else {
        $positional[] = $argument;
    }
}

// Token minting mode — what a hosting company calls from a WHMCS template.
if (isset($flags['token'])) {
    $customerId = (int) ($flags['customer'] ?? 0);

    if ($customerId < 1) {
        fwrite(STDERR, "\nUsage: php tools/context.php --token --customer=42\n\n");
        exit(1);
    }

    try {
        $token = IdentityToken::issue($customerId);
    } catch (Throwable $e) {
        fwrite(STDERR, "\nCould not issue a token: " . $e->getMessage() . "\n\n");
        exit(1);
    }

    echo "\nIdentity token for customer {$customerId}:\n\n{$token}\n\n";
    echo "Send it as the X-Chat-Token header (or a `token` field) on /api/chat.\n";
    echo "In a WHMCS client-area template:\n\n";
    echo "    \$token = Hostorio\\Context\\IdentityToken::issue((int) \$client->id);\n\n";
    exit(0);
}

$question = trim(implode(' ', $positional));

if ($question === '') {
    fwrite(STDERR, "\nUsage: php tools/context.php [--customer=42] \"your question\"\n\n");
    exit(1);
}

$customerId = isset($flags['customer']) ? (int) $flags['customer'] : 0;

$identity = $customerId > 0
    ? CustomerIdentity::verified($customerId, 'cli')
    : CustomerIdentity::anonymous();

try {
    $context = (new ContextBuilder())->build($question, $identity);
} catch (Throwable $e) {
    fwrite(STDERR, "\nFailed to build context: " . $e->getMessage() . "\n\n");
    exit(1);
}

echo "\nQuestion: {$question}\n";
echo 'Identity: ' . ($identity->isVerified() ? "customer {$identity->customerId} (forced via CLI)" : 'anonymous') . "\n";
echo str_repeat('=', 72) . "\n\n";

echo $context->isEmpty()
    ? "(no context — nothing matched and no account data available)\n"
    : $context->text . "\n";

echo "\n" . str_repeat('=', 72) . "\n";
printf("  %-22s %d\n", 'estimated tokens', $context->estimatedTokens);
printf("  %-22s %s\n", 'account context', $context->hasAccountContext ? 'included' : 'none');
printf("  %-22s %d\n", 'manual notes', $context->manualNotes);
printf("  %-22s %d\n", 'knowledge chunks', $context->knowledgeChunks);

foreach ($context->sections as $section) {
    printf("  section %-14s %d tokens\n", $section['name'], $section['tokens']);
}

if ($context->sourcesUsed !== []) {
    echo "\n  sources:\n";

    foreach ($context->sourcesUsed as $source) {
        echo '    ' . $source . "\n";
    }
}

if ($context->wasTrimmed()) {
    echo "\n  dropped for budget:\n";

    foreach ($context->dropped as $dropped) {
        echo '    ' . $dropped . "\n";
    }
}

echo "\n";
