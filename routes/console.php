<?php

use Illuminate\Foundation\DevCommands;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// composer dev sets PHP_CLI_SERVER_WORKERS=4, so a slow Odoo call does not hold up the pages and the card.
// The built-in server starts more than one worker only without reloading.
DevCommands::artisan('serve --no-reload', 'server');
