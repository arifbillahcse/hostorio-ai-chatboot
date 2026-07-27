<?php

declare(strict_types=1);

use Hostorio\Core\Env;

/**
 * Chat widget appearance. Merged into Config under the `widget.` prefix.
 *
 * Served to the browser by GET /api/widget/config so branding can be changed in
 * one place rather than edited into every page that embeds the widget. Anything
 * here can still be overridden per-page with a data- attribute on the script
 * tag, which is what a customer with several brands on one install needs.
 *
 * Everything in this file is public by definition — it is sent to every
 * visitor. Do not put anything sensitive here.
 */
return [
    'title'        => Env::get('WIDGET_TITLE', 'Support'),
    'subtitle'     => Env::get('WIDGET_SUBTITLE', 'Ask us anything'),
    'welcome'      => Env::get('WIDGET_WELCOME', 'Hi! Ask me anything about your hosting and I\'ll do my best to help.'),
    'placeholder'  => Env::get('WIDGET_PLACEHOLDER', 'Type your message…'),
    'launcherLabel' => Env::get('WIDGET_LAUNCHER_LABEL', 'Chat with support'),

    // Accent colour. Used for the launcher, the send button and outgoing
    // messages; everything else is derived from it so one value is enough.
    'accent'       => Env::get('WIDGET_ACCENT', '#2563eb'),
    'accentText'   => Env::get('WIDGET_ACCENT_TEXT', '#ffffff'),

    // bottom-right | bottom-left
    'position'     => Env::get('WIDGET_POSITION', 'bottom-right'),

    /*
     * Suggested opening questions, shown as buttons on an empty conversation.
     *
     * Worth having: a blank chat box gets far fewer first messages than one
     * that shows people what it can answer.
     */
    'suggestions'  => array_values(array_filter(array_map(
        'trim',
        explode('|', (string) Env::get(
            'WIDGET_SUGGESTIONS',
            'How do I reset my password?|Why is my site down?|How do I set up email?'
        ))
    ))),

    /*
     * Reveal the answer progressively instead of all at once.
     *
     * This is a rendering effect, not token streaming — see the note in
     * public/widget/widget.js. Disable for the plainest possible behaviour.
     */
    'typewriter'   => Env::bool('WIDGET_TYPEWRITER', true),

    /* Show "powered by" under the composer. */
    'branding'     => Env::bool('WIDGET_BRANDING', false),
];
