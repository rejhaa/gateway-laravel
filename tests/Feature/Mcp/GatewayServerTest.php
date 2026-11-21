<?php

declare(strict_types=1);

use App\Gateway\AuditEvent;
use App\Gateway\AuditOutcome;
use App\Mcp\Servers\GatewayServer;
use App\Mcp\Tools\MailListMessages;
use App\Models\AuditEntry;
use App\Models\User;
use App\Systems\Mail\Mailbox;
use Illuminate\Support\Facades\Exceptions;

// Bug: someone adds a tool that approves, or a reading tool loses its read-only mark.
it('registers exactly the seven tools, all read-only but propose', function (): void {
    $tools = (fn (): array => $this->items)->call(GatewayServer::tools());

    $readOnly = array_map(fn (array $annotations): bool => $annotations['readOnlyHint'] ?? false, array_column($tools, 'annotations', 'name'));

    expect($readOnly)->toBe([
        'whoami' => true,
        'get_policy' => true,
        'mail_list_messages' => true,
        'mail_read_message' => true,
        'odoo_search_read' => true,
        'propose' => false,
        'list_proposals' => true,
    ]);
});

// Bug: a failure disappears in development, or its details reach the agent.
it('reports and audits an exception in a tool with APP_DEBUG on, and tells the agent a plain sentence', function (): void {
    config(['app.debug' => true]);
    Exceptions::fake();
    $mailbox = Mockery::mock(Mailbox::class);
    $mailbox->shouldReceive('messages')->andThrow(new RuntimeException('SQLSTATE secret detail'));
    app()->instance(Mailbox::class, $mailbox);

    GatewayServer::actingAs(User::primary())->tool(MailListMessages::class)
        ->assertHasErrors(['The gateway could not do this: something unexpected went wrong, and it is logged for the developers.'])
        ->assertDontSee('secret');

    Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'SQLSTATE secret detail');

    $row = AuditEntry::query()->sole();
    expect($row->event)->toBe(AuditEvent::ToolFailed)
        ->and($row->outcome)->toBe(AuditOutcome::Error)
        ->and($row->tool)->toBe('mail_list_messages');
});
