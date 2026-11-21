<?php

declare(strict_types=1);

use App\Gateway\AuditEvent;
use App\Gateway\ProposalStatus;
use App\Mcp\Servers\GatewayServer;
use App\Mcp\Tools\GetPolicy;
use App\Mcp\Tools\ListProposals;
use App\Mcp\Tools\MailListMessages;
use App\Mcp\Tools\MailReadMessage;
use App\Mcp\Tools\OdooSearchRead;
use App\Mcp\Tools\Whoami;
use App\Models\AuditEntry;
use App\Models\Proposal;
use App\Models\User;
use App\Systems\Mail\Attachment;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeOdoo;
use Tests\Support\Inbound;

// Bug: the agent acts under the wrong name or date.
it('whoami returns Riku and today in his time zone', function (?string $configured, string $today): void {
    config(['gateway.today' => $configured]);
    $this->travelTo('2026-11-05 23:30:00');

    GatewayServer::actingAs(User::primary())->tool(Whoami::class)
        ->assertOk()
        ->assertStructuredContent([
            'name' => 'Riku Lindgren',
            'role' => 'supply chain lead',
            'odoo_login' => 'riku',
            'today' => $today,
        ]);

    $row = AuditEntry::query()->sole();
    expect($row->event)->toBe(AuditEvent::ToolCalled)
        ->and($row->tool)->toBe('whoami')
        ->and($row->actor)->toBe('riku');
})->with([
    'the real date, already the 6th in Helsinki' => [null, '2026-11-06'],
    'the configured date of a case' => ['2026-11-03', '2026-11-03'],
]);

// Bug: the agent works without the rules.
it('get_policy returns all three documents', function (): void {
    GatewayServer::actingAs(User::primary())->tool(GetPolicy::class)
        ->assertOk()
        ->assertStructuredContent(['documents' => array_map(
            fn (string $name): array => ['name' => $name, 'markdown' => file_get_contents(base_path("rules/{$name}"))],
            ['purchasing-policy.md', 'approval-rules.md', 'agent-access.md'],
        )]);
});

// Bug: the agent redoes handled mail.
it('mail_list_messages shows a message\'s proposal and its status', function (): void {
    Inbound::mailbox(Inbound::message(attachments: [new Attachment('TYF-OC-26-0918.pdf', 'application/pdf', true)]));
    $proposal = Proposal::factory()->create(['message_id' => Inbound::message()->id]);

    GatewayServer::actingAs(User::primary())->tool(MailListMessages::class)
        ->assertOk()
        ->assertStructuredContent(['messages' => [[
            'id' => Inbound::message()->id,
            'from' => 'Tai-Yuan Forge & Shaft <sales@taiyuan.example>',
            'subject' => 'Order confirmation P00104',
            'date' => '2026-11-05T11:12:00+02:00',
            'attachments' => ['TYF-OC-26-0918.pdf'],
            'proposals' => [[
                'id' => $proposal->id,
                'status' => 'pending',
                'title' => $proposal->title,
                'record' => $proposal->record_label,
            ]],
        ]]]);
});

// Bug: the agent cannot quote, or the checker and the agent see different text.
it('mail_read_message returns numbered attachment lines', function (): void {
    Inbound::mailbox(Inbound::message(attachments: [
        new Attachment('TYF-OC-26-0918.pdf', 'application/pdf', true, ['p1.l1' => 'Order confirmation', 'p1.l17' => Inbound::QUOTE_PARTIAL]),
        new Attachment('scan.pdf', 'application/pdf', false),
    ]));

    GatewayServer::actingAs(User::primary())->tool(MailReadMessage::class, ['id' => Inbound::message()->id])
        ->assertOk()
        ->assertStructuredContentPath('body', Inbound::message()->body)
        ->assertStructuredContentPath('attachments', [
            [
                'index' => 1,
                'name' => 'TYF-OC-26-0918.pdf',
                'content_type' => 'application/pdf',
                'has_text_layer' => true,
                'lines' => ['p1.l1: Order confirmation', 'p1.l17: '.Inbound::QUOTE_PARTIAL],
            ],
            ['index' => 2, 'name' => 'scan.pdf', 'content_type' => 'application/pdf', 'has_text_layer' => false, 'lines' => []],
        ])
        ->assertStructuredContentPath('proposals', []);
});

// Bug: the agent loops on an id that does not exist instead of being told where ids come from.
it('mail_read_message tells the agent where message ids come from', function (): void {
    Inbound::mailbox();

    GatewayServer::actingAs(User::primary())->tool(MailReadMessage::class, ['id' => 'nope'])
        ->assertHasErrors(['There is no message nope in the mailbox. Use an id from mail_list_messages.']);

    expect(AuditEntry::query()->sole()->event)->toBe(AuditEvent::InputRejected);
});

// Bug: a read reaches Odoo before it is checked.
it('odoo_search_read refuses an unlisted field and makes no Odoo call', function (): void {
    FakeOdoo::start()->line();

    GatewayServer::actingAs(User::primary())->tool(OdooSearchRead::class, [
        'model' => 'purchase.order.line',
        'domain' => [['id', '=', FakeOdoo::LINE]],
        'fields' => ['product_qty', 'x_secret'],
    ])->assertHasErrors(['The field x_secret on purchase.order.line is not readable.']);

    Http::assertNothingSent();
});

// Bug: the agent proposes over a waiting decision.
it('odoo_search_read attaches pending and applied proposals to a line', function (): void {
    FakeOdoo::start()->line();
    $pending = Proposal::factory()->create(['record_id' => FakeOdoo::LINE]);
    $applied = Proposal::factory()->create(['record_id' => FakeOdoo::LINE, 'status' => ProposalStatus::Applied]);
    Proposal::factory()->create(['record_id' => FakeOdoo::LINE, 'status' => ProposalStatus::Rejected]);

    GatewayServer::actingAs(User::primary())->tool(OdooSearchRead::class, [
        'model' => 'purchase.order.line',
        'domain' => [['id', '=', FakeOdoo::LINE]],
        'fields' => ['product_qty'],
    ])->assertOk()->assertStructuredContent(['records' => [[
        'id' => FakeOdoo::LINE,
        'product_qty' => 120.0,
        'proposals' => array_map(fn (Proposal $proposal): array => [
            'id' => $proposal->id,
            'status' => $proposal->status->value,
            'title' => $proposal->title,
            'values' => $proposal->values,
            'message_id' => $proposal->message_id,
        ], [$pending, $applied]),
    ]]]);
});

// Bug: the agent cannot tell Riku's answer.
it('list_proposals returns who decided, when and why', function (): void {
    Inbound::mailbox();
    $rejected = Proposal::factory()->create([
        'message_id' => Inbound::message()->id,
        'status' => ProposalStatus::Rejected,
        'decided_by' => 'riku',
        'decided_via' => 'web',
        'decided_at' => '2026-11-06 12:02:00',
        'decision_reason' => 'The supplier confirmed 120 by phone. Ask for a written confirmation.',
    ]);
    Proposal::factory()->create(['message_id' => Inbound::message()->id]);

    GatewayServer::actingAs(User::primary())->tool(ListProposals::class, ['status' => 'rejected'])
        ->assertOk()
        ->assertStructuredContentPath('waiting', 1)
        ->assertStructuredContentPath('note', '1 proposal waits for Riku. He decides it on the card or on the approval page, and you cannot decide it.')
        ->assertStructuredContentPath('messages.0.message.subject', 'Order confirmation P00104')
        ->assertStructuredContentPath('messages.0.proposals', [[
            'id' => $rejected->id,
            'title' => $rejected->title,
            'record' => $rejected->record_label,
            'values' => $rejected->values,
            'status' => 'rejected',
            'tier' => 'human',
            'reasons' => $rejected->reasons,
            'decided_by' => 'riku',
            'decided_via' => 'web',
            'decided_at' => '2026-11-06T12:02:00+00:00',
            'decision_reason' => 'The supplier confirmed 120 by phone. Ask for a written confirmation.',
            'error' => null,
        ]]);
});
