<?php

declare(strict_types=1);

use Hostorio\Core\Env;

/**
 * Update source. Merged into Config under the `update.` prefix.
 */
return [
    /*
     * URL of the JSON manifest describing the latest release:
     *
     *   {"version":"1.1.0","url":"https://…/chatbot-1.1.0.zip",
     *    "sha256":"…","min_php":"8.1","notes":"What changed"}
     *
     * Must be https. The updater refuses plain HTTP, because an unverified
     * update channel is a remote code execution feature — whoever controls the
     * network path would control the customer's site.
     */
    'manifest_url' => Env::get('UPDATE_MANIFEST_URL', ''),
];
