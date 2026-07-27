<?php

declare(strict_types=1);

/**
 * Ask the routing engine a question from the command line.
 *
 * Usage:
 *   php tools/ask.php "why is my ssl certificate not working?"
 *   php tools/ask.php --plan "reset my password"      # no API call, just the routing decision
 *   php tools/ask.php --type=simple "how much is a VPS?"
 *
 * `--plan` is the useful one before you have API keys: it shows which provider
 * and model a question would go to, and why, without spending anything.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This tool must be run from the command line.\n");
}

require_once dirname(__DIR__) . '/bootstrap.php';

use Hostorio\Llm\LlmException;
use Hostorio\Llm\LlmRouter;

$planOnly = false;
$type     = null;
$parts    = [];

foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--plan') {
        $planOnly = true;
    } elseif (str_starts_with($argument, '--type=')) {
        $type = substr($argument, 7);
    } else {
        $parts[] = $argument;
    }
}

$question = trim(implode(' ', $parts));

if ($question === '') {
    fwrite(STDERR, "Usage: php tools/ask.php [--plan] [--type=action|complex|simple] \"your question\"\n");
    exit(1);
}

$router = new LlmRouter();

if ($planOnly) {
    $plan = $router->plan($question);

    echo "\nRouting plan\n" . str_repeat('-', 60) . "\n";
    printf("  %-20s %s\n", 'question',         $question);
    printf("  %-20s %s\n", 'classified as',    $plan['query_type']);
    printf("  %-20s %s\n", 'because',          $plan['reason']);
    printf("  %-20s %s\n", 'configured order', implode(' -> ', $plan['configured_order']));
    printf("  %-20s %s\n", 'available now',    $plan['available_order'] === []
        ? '(none — no API keys configured)'
        : implode(' -> ', $plan['available_order']));
    printf("  %-20s %s\n", 'would use',        $plan['selected'] ?? '(nothing available)');
    printf("  %-20s %s\n", 'model',            $plan['model'] ?? '-');
    printf("  %-20s %d\n", 'max tokens',       $plan['max_tokens']);
    printf("  %-20s %s\n", 'thinking',         $plan['thinking'] ? 'on' : 'off');
    echo "\n";

    exit(0);
}

$system = 'You are a support assistant for a web hosting company. '
        . 'Answer accurately and concisely. If you are not certain, say so rather than guessing.';

try {
    $response = $router->ask($question, $system, $type !== null ? ['type' => $type] : []);
} catch (LlmException $e) {
    fwrite(STDERR, "\nRequest failed: " . $e->getMessage() . "\n\n");
    exit(1);
}

echo "\n" . $response->text . "\n\n";
echo str_repeat('-', 60) . "\n";
printf("  %-16s %s\n", 'classified as', $response->queryType);
printf("  %-16s %s\n", 'answered by',   $response->provider . ' (' . $response->model . ')');
printf("  %-16s %s\n", 'attempts',      implode(', ', $response->attempts));
printf("  %-16s %d in / %d out\n", 'tokens', $response->inputTokens, $response->outputTokens);
printf("  %-16s $%.6f\n", 'est. cost',  $response->costUsd);
printf("  %-16s %d ms\n", 'latency',    $response->durationMs);

if ($response->wasTruncated()) {
    printf("  %-16s answer hit the output limit and is incomplete\n", 'WARNING');
}

echo "\n";
