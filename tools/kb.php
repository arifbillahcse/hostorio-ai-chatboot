<?php

declare(strict_types=1);

/**
 * Knowledge base management from the command line.
 *
 * Until the admin panel lands in Phase 7, this is the interface.
 *
 *   php tools/kb.php index                          Sync WordPress and index what changed
 *   php tools/kb.php status                          What is indexed, and what is pending
 *   php tools/kb.php search "ssl not working"        Show what the chatbot would retrieve
 *
 *   php tools/kb.php note:add "Title" "Body text" [--priority=20] [--expires="+7 days"]
 *   php tools/kb.php note:list [--all]
 *   php tools/kb.php note:edit <id> [--title=…] [--body=…] [--priority=…] [--expires=…]
 *   php tools/kb.php note:expire <id>
 *   php tools/kb.php note:delete <id>
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This tool must be run from the command line.\n");
}

require_once dirname(__DIR__) . '/bootstrap.php';

use Hostorio\Knowledge\Indexer;
use Hostorio\Knowledge\KnowledgeStore;
use Hostorio\Knowledge\ManualNotes;

$arguments = array_slice($argv, 1);
$command   = array_shift($arguments) ?? '';

/** Pull --key=value flags out, leaving positional arguments. */
$flags     = [];
$positional = [];

foreach ($arguments as $argument) {
    if (preg_match('/^--([a-z\-]+)(?:=(.*))?$/i', $argument, $matches) === 1) {
        $flags[strtolower($matches[1])] = $matches[2] ?? 'true';
    } else {
        $positional[] = $argument;
    }
}

function fail(string $message): never
{
    fwrite(STDERR, "\n" . $message . "\n\n");
    exit(1);
}

try {
    switch ($command) {
        case 'index':
            $indexer = new Indexer();

            echo "\nIndexing…  (embedder: " . $indexer->embedder()->name() . ")\n";

            $summary = $indexer->run();

            echo str_repeat('-', 60) . "\n";
            printf("  WordPress available   %s\n", ($summary['wordpress']['available'] ?? false) ? 'yes' : 'no');
            printf("  documents fetched     %d\n", $summary['wordpress']['fetched'] ?? 0);
            printf("  changed / unchanged   %d / %d\n",
                $summary['wordpress']['changed'] ?? 0, $summary['wordpress']['unchanged'] ?? 0);
            printf("  deactivated upstream  %d\n", $summary['wordpress']['deactivated'] ?? 0);
            printf("  documents indexed     %d\n", $summary['documents_indexed']);
            printf("  chunks written        %d\n", $summary['chunks_written']);
            printf("  chunks embedded       %d\n", $summary['chunks_embedded']);
            printf("  embedding cost        $%.6f\n", $summary['embedding_cost_usd']);
            printf("  expired chunks pruned %d\n", $summary['expired_chunks_pruned']);
            printf("  duration              %d ms\n\n", $summary['duration_ms']);
            break;

        case 'status':
            $stats = (new KnowledgeStore())->stats();

            echo "\nKnowledge base\n" . str_repeat('-', 60) . "\n";

            if (($stats['by_source'] ?? []) === []) {
                echo "  (nothing indexed yet — run: php tools/kb.php index)\n";
            }

            foreach ($stats['by_source'] as $row) {
                printf("  %-12s %d documents (%d active, %d pending)\n",
                    $row['source'], $row['documents'], $row['active'], $row['pending']);
            }

            printf("\n  chunks total     %d\n", $stats['chunks_total']);
            printf("  chunks embedded  %d%s\n", $stats['chunks_embedded'],
                $stats['chunks_embedded'] === 0 ? '   (lexical search only)' : '');
            printf("  embedder         %s\n\n", Indexer::resolveEmbedder()->name());
            break;

        case 'search':
            $query = trim(implode(' ', $positional));

            if ($query === '') {
                fail('Usage: php tools/kb.php search "your question"');
            }

            $results = (new Indexer())->search($query);

            echo "\nResults for: {$query}\n" . str_repeat('-', 60) . "\n";

            if ($results === []) {
                echo "  (nothing matched)\n\n";
                break;
            }

            foreach ($results as $rank => $result) {
                printf("\n  #%d  score %.3f  (lexical %.3f, vector %.3f)  [%s, priority %d]\n",
                    $rank + 1, $result->score, $result->lexicalScore, $result->vectorScore,
                    $result->source, $result->priority);
                printf("      %s\n", $result->title);

                if ($result->url !== null) {
                    printf("      %s\n", $result->url);
                }

                printf("      %s\n", mb_strimwidth(str_replace("\n", ' ', $result->content), 0, 160, '…'));
            }

            echo "\n";
            break;

        case 'note:add':
            $title = $positional[0] ?? '';
            $body  = $positional[1] ?? '';

            if (trim($body) === '') {
                fail('Usage: php tools/kb.php note:add "Title" "Body text" [--priority=20] [--expires="+7 days"]');
            }

            $id = (new ManualNotes())->add(
                $title,
                $body,
                (int) ($flags['priority'] ?? 10),
                $flags['expires'] ?? null
            );

            echo "\nNote #{$id} added. Run `php tools/kb.php index` to make it searchable.\n\n";
            break;

        case 'note:list':
            $notes = (new ManualNotes())->all(isset($flags['all']));

            echo "\nManual notes\n" . str_repeat('-', 78) . "\n";

            if ($notes === []) {
                echo "  (none yet — add one with: php tools/kb.php note:add \"Title\" \"Body\")\n\n";
                break;
            }

            foreach ($notes as $note) {
                printf("  #%-4d p%-3d %-8s %-10s %s\n",
                    $note['id'],
                    $note['priority'],
                    ((int) $note['is_active']) === 1 ? 'active' : 'expired',
                    $note['indexed_at'] === null ? 'PENDING' : 'indexed',
                    mb_strimwidth((string) $note['title'], 0, 44, '…')
                );

                if ($note['expires_at'] !== null) {
                    printf("        expires %s\n", $note['expires_at']);
                }
            }

            echo "\n";
            break;

        case 'note:edit':
            $id = (int) ($positional[0] ?? 0);

            if ($id <= 0) {
                fail('Usage: php tools/kb.php note:edit <id> [--title=…] [--body=…] [--priority=…] [--expires=…]');
            }

            $changes = [];

            foreach (['title', 'body'] as $field) {
                if (isset($flags[$field])) {
                    $changes[$field] = $flags[$field];
                }
            }

            if (isset($flags['priority'])) {
                $changes['priority'] = (int) $flags['priority'];
            }

            if (isset($flags['expires'])) {
                $changes['expires_at'] = $flags['expires'] === 'never' ? null : $flags['expires'];
            }

            if ($changes === []) {
                fail('Nothing to change. Pass at least one of --title, --body, --priority, --expires.');
            }

            echo (new ManualNotes())->update($id, $changes)
                ? "\nNote #{$id} updated. Run `php tools/kb.php index` to re-index it.\n\n"
                : "\nNo note #{$id} found.\n\n";
            break;

        case 'note:expire':
            $id = (int) ($positional[0] ?? 0);

            echo (new ManualNotes())->expire($id)
                ? "\nNote #{$id} expired and removed from search immediately.\n\n"
                : "\nNo note #{$id} found.\n\n";
            break;

        case 'note:delete':
            $id = (int) ($positional[0] ?? 0);

            echo (new ManualNotes())->delete($id)
                ? "\nNote #{$id} deleted permanently.\n\n"
                : "\nNo note #{$id} found.\n\n";
            break;

        default:
            echo "\nKnowledge base management\n" . str_repeat('-', 60) . "\n";
            echo "  index                    Sync WordPress and index changed documents\n";
            echo "  status                   Show what is indexed and what is pending\n";
            echo "  search \"question\"        Show what the chatbot would retrieve\n";
            echo "  note:add \"T\" \"Body\"      Add a manual note   [--priority=N] [--expires=…]\n";
            echo "  note:list [--all]        List manual notes\n";
            echo "  note:edit <id> --body=…  Edit a note\n";
            echo "  note:expire <id>         Retire a note (reversible)\n";
            echo "  note:delete <id>         Delete a note permanently\n\n";
            exit($command === '' ? 0 : 1);
    }
} catch (Throwable $e) {
    fail('Failed: ' . $e->getMessage());
}
