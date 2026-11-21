<?php

declare(strict_types=1);

use App\Processes\InboundPurchasing\InboundPurchasing;

return [

    /*
    | The business process the gateway runs, one of the names in processes.
    */
    'process' => env('GATEWAY_PROCESS', 'inbound-purchasing'),

    /*
    | Every process the gateway knows, by name. A proposal names its process,
    | so a proposal made under one process is decided under the same one.
    */
    'processes' => [
        'inbound-purchasing' => InboundPurchasing::class,
    ],

    /*
    | When false, no rule applies a write, so every proposal that a rule
    | would apply waits for the user instead.
    */
    'rule_tier' => (bool) env('GATEWAY_RULE_TIER', true),

    /*
    | The date whoami reports as today, as YYYY-MM-DD. Null means the real
    | date. Set it for a case whose story happens on another day.
    */
    'today' => env('GATEWAY_TODAY') ?: null,

    /*
    | The time zone the user reads dates and times in, on the screens and in
    | whoami's today.
    */
    'timezone' => env('GATEWAY_TIMEZONE', 'Europe/Helsinki'),

    /*
    | The one user everything runs as. The seeder creates the row from this.
    */
    'user' => [
        'name' => env('GATEWAY_USER_NAME', 'Riku Lindgren'),
        'role' => env('GATEWAY_USER_ROLE', 'supply chain lead'),
        'odoo_login' => env('GATEWAY_USER_ODOO_LOGIN', 'riku'),
    ],

    'odoo' => [
        'url' => env('ODOO_URL', 'http://localhost:8070'),

        /*
        | JSON file holding each user's Odoo API key, relative to the project
        | root. Read on every call, because a reseed replaces the keys.
        */
        'keys_file' => base_path(env('ODOO_KEYS_FILE', 'odoo/data/inbound-users.json')),
    ],

    'mailbox' => [
        /*
        | Directory of .eml files, relative to the project root.
        */
        'path' => base_path(env('GATEWAY_MAILBOX_PATH', 'cases/demo/demo-mailbox/messages')),
    ],

    'card' => [
        'token_hours' => (int) env('GATEWAY_CARD_TOKEN_HOURS', 12),
    ],

];
