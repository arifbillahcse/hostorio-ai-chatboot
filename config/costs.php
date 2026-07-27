<?php

declare(strict_types=1);

use Hostorio\Core\Env;

/**
 * Spend monitoring. Merged into Config under the `costs.` prefix.
 *
 * The failure this exists to catch is not a slow drift upward — the dashboard
 * shows that. It is the sudden spike: a scraper hammering the widget, a
 * runaway loop, a routing change that quietly sends everything to the expensive
 * model. Those can turn a $20 month into a $2,000 one before anybody opens the
 * admin panel.
 */
return [
    /*
     * Budgets in USD. Zero disables that check.
     *
     * The hourly figure is the one that catches abuse: daily spend looks normal
     * right up until the moment it does not, whereas an hour's worth of a
     * runaway is visible within the hour.
     */
    'daily_budget'  => (float) Env::get('COST_DAILY_BUDGET', '0'),
    'hourly_budget' => (float) Env::get('COST_HOURLY_BUDGET', '0'),

    /*
     * Warn at this fraction of a budget, before it is actually breached.
     */
    'warn_at' => 0.8,

    /*
     * Refuse new requests once a budget is exceeded.
     *
     * Off by default, and that default is deliberate. A hard stop turns a
     * billing problem into an outage: every customer gets "sorry, try later"
     * until someone notices. For most hosting companies an alert plus a
     * dashboard banner is the better trade — you find out quickly and decide
     * yourself. Turn it on if an unbounded bill is genuinely worse than being
     * offline.
     */
    'hard_stop' => Env::bool('COST_HARD_STOP', false),

    /*
     * How long a triggered alert stays suppressed, so one spike does not write
     * a log line per request.
     */
    'alert_cooldown' => 3600,
];
