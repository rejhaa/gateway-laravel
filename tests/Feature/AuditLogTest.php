<?php

declare(strict_types=1);

use App\Gateway\Actor;
use App\Gateway\Audit;
use App\Gateway\AuditEvent;
use App\Models\AuditEntry;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

// Bug: the audit trail can be rewritten.

function auditRow(): AuditEntry
{
    return app(Audit::class)->record(AuditEvent::ToolCalled, Actor::agent('riku', 'Claude'), tool: 'whoami');
}

it('refuses an update of an audit row in the database', function (): void {
    $row = auditRow();

    expect(fn () => DB::table('audit_log')->where('id', $row->id)->update(['actor' => 'someone']))
        ->toThrow(QueryException::class, 'audit_log is append-only');

    expect(DB::table('audit_log')->value('actor'))->toBe('riku');
});

it('refuses a delete of an audit row in the database', function (): void {
    $row = auditRow();

    expect(fn () => DB::table('audit_log')->where('id', $row->id)->delete())
        ->toThrow(QueryException::class, 'audit_log is append-only');

    expect(DB::table('audit_log')->count())->toBe(1);
});

it('refuses an update of an audit row in the model', function (): void {
    $row = auditRow();

    expect(fn () => $row->update(['actor' => 'someone']))
        ->toThrow(LogicException::class, 'audit_log is append-only');
});

it('refuses a delete of an audit row in the model', function (): void {
    $row = auditRow();

    expect(fn () => $row->delete())->toThrow(LogicException::class, 'audit_log is append-only');

    expect(AuditEntry::count())->toBe(1);
});

// Bug: the rows of one request cannot be read together.
it('gives every row of one request the same request id', function (): void {
    $first = auditRow();
    $second = auditRow();

    expect($first->request_id)->not->toBeEmpty()
        ->and($second->request_id)->toBe($first->request_id);
});
