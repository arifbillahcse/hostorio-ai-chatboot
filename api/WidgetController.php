<?php

declare(strict_types=1);

namespace Hostorio\Api;

use Hostorio\Core\Config;
use Hostorio\Core\Request;
use Hostorio\Core\Response;

/**
 * Serves the widget's appearance settings.
 *
 * Public and unauthenticated by design — it is branding, and the widget needs
 * it before anyone has identified themselves. The endpoint returns only the
 * keys listed here, so a future setting added to config/widget.php cannot leak
 * to the browser by accident.
 */
final class WidgetController
{
    /** The only keys ever sent to a browser. */
    private const PUBLIC_KEYS = [
        'title', 'subtitle', 'welcome', 'placeholder', 'launcherLabel',
        'accent', 'accentText', 'position', 'suggestions', 'typewriter', 'branding',
    ];

    public function config(Request $request): Response
    {
        $config = [];

        foreach (self::PUBLIC_KEYS as $key) {
            $config[$key] = Config::get('widget.' . $key);
        }

        // Branding changes rarely; letting the browser cache it for a few
        // minutes keeps a busy site from re-fetching it on every page view.
        return Response::ok(['config' => $config])
            ->withHeaders(['Cache-Control' => 'public, max-age=300']);
    }
}
