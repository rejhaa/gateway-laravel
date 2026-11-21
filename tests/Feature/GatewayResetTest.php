<?php

declare(strict_types=1);

use App\Gateway\Actor;
use App\Gateway\Audit;
use App\Gateway\AuditEvent;
use App\Models\AuditEntry;
use App\Models\Proposal;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;

beforeEach(function (): void {
    Process::fake();

    User::primary();
    $proposal = Proposal::factory()->create();
    app(Audit::class)->record(AuditEvent::ProposalCreated, Actor::agent('riku', 'Claude'), proposalId: $proposal->id);
});

// Bug: proposals of the last run point at records a reseed gave to other orders, the audit log stays writable after the reset, or Claude has to connect again.
it('reseeds Odoo with the case and empties both tables, keeping users, OAuth clients and the append-only triggers', function (): void {
    $this->app['env'] = 'local';
    app(ClientRepository::class)->createAuthorizationCodeGrantClient('Claude', ['https://claude.ai/api/mcp/auth_callback']);

    $this->artisan('gateway:reset', ['case' => 'cases/demo/demo-mailbox'])->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process): bool => $process->command === ['bin/odoo', 'seed', 'cases/demo/demo-mailbox']);

    expect(Proposal::query()->count())->toBe(0)
        ->and(AuditEntry::query()->count())->toBe(0)
        ->and(User::query()->count())->toBe(1)
        ->and(Passport::client()->newQuery()->sole()->name)->toBe('Claude');

    $row = app(Audit::class)->record(AuditEvent::ToolCalled, Actor::agent('riku', 'Claude'), tool: 'whoami');

    expect(fn () => DB::table('audit_log')->where('id', $row->id)->delete())
        ->toThrow(QueryException::class, 'audit_log is append-only');
});

// Bug: a reset outside development wipes the audit trail.
it('refuses to run unless APP_ENV is local', function (): void {
    $this->app['env'] = 'production';

    $this->artisan('gateway:reset')->assertFailed();

    Process::assertNothingRan();

    expect(Proposal::query()->count())->toBe(1)
        ->and(AuditEntry::query()->count())->toBe(1);
});

// Bug: a failed reseed still empties the gateway, so the last run's record is gone and Odoo is unchanged.
it('leaves both tables alone when the reseed fails', function (): void {
    $this->app['env'] = 'local';
    Process::fake(['*' => Process::result(errorOutput: 'Odoo is not running', exitCode: 1)]);

    $this->artisan('gateway:reset')->assertFailed();

    Process::assertRan(fn (PendingProcess $process): bool => $process->command === ['bin/odoo', 'seed']);

    expect(Proposal::query()->count())->toBe(1)
        ->and(AuditEntry::query()->count())->toBe(1);
});
