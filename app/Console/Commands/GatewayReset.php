<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Process;

/**
 * The one way to start a run (DESIGN.md, section 9). A reseed gives every
 * Odoo record a new id, so the proposals of the last run would point at other
 * records. This reseeds Odoo, then drops and recreates the gateway's two
 * tables from their migrations, triggers included. users and Passport's
 * tables stay, so the connector stays authorized.
 */
#[Signature('gateway:reset {case? : A case directory to load on top of the base seed, such as cases/demo/demo-mailbox}')]
#[Description('Reseed Odoo and empty the proposals and the audit log')]
final class GatewayReset extends Command
{
    /** The migrations of the gateway's tables, in the order they are created. */
    public const MIGRATIONS = [
        '2026_11_21_154805_create_proposals_table.php',
        '2026_11_21_154806_create_audit_log_table.php',
    ];

    public function handle(): int
    {
        if (! $this->laravel->environment('local')) {
            $this->components->error('gateway:reset runs only when APP_ENV is local, because it empties the audit log.');

            return self::FAILURE;
        }

        $case = $this->argument('case');
        $seed = Process::path(base_path())
            ->timeout(300)
            ->run(['bin/odoo', 'seed', ...($case === null ? [] : [$case])], function (string $type, string $output): void {
                $this->output->write($output);
            });

        if ($seed->failed()) {
            $this->components->error('bin/odoo seed failed, so the gateway\'s tables were left as they were.');

            return self::FAILURE;
        }

        /** @var list<Migration> $migrations each migration file returns its migration */
        $migrations = array_map(fn (string $file): Migration => require database_path('migrations/'.$file), self::MIGRATIONS);

        // audit_log goes first and comes back last, because it refers to proposals.
        foreach (array_reverse($migrations) as $migration) {
            $migration->down();
        }

        foreach ($migrations as $migration) {
            $migration->up();
        }

        $this->components->info('Odoo is reseeded, and the proposals and the audit log are empty.');

        return self::SUCCESS;
    }
}
