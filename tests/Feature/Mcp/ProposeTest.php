<?php

declare(strict_types=1);

use App\Gateway\AuditEvent;
use App\Mcp\Servers\GatewayServer;
use App\Mcp\Tools\Propose;
use App\Models\AuditEntry;
use App\Models\Proposal;
use App\Models\User;
use App\Processes\InboundPurchasing\InboundPurchasing;
use Laravel\Mcp\Server\Testing\TestResponse;
use Tests\Support\FakeOdoo;
use Tests\Support\Inbound;

/**
 * @param  array<string, mixed>  $input
 */
function propose(array $input): TestResponse
{
    return GatewayServer::actingAs(User::primary())->tool(Propose::class, $input);
}

beforeEach(function (): void {
    $this->odoo = FakeOdoo::start()->line();
    Inbound::mailbox();
});

// Bug: a rule pass is stored but never written.
it('applies by rule and writes to Odoo once', function (): void {
    propose(Inbound::input())->assertOk()->assertStructuredContent([
        'proposal_id' => Proposal::query()->sole()->id,
        'status' => 'applied',
        'tier' => 'rule',
        'decision' => 'Rule confirmation-as-ordered applied this, because the supplier confirms the 120 ordered for the planned date 2026-11-30.',
        'basis' => 'confirmation-as-ordered',
        'reasons' => ['the supplier confirms the 120 ordered for the planned date 2026-11-30'],
        'next' => 'The gateway wrote this to Odoo. Nothing more is needed.',
        'existing' => false,
    ]);

    expect($this->odoo->writes)->toHaveCount(1);
});

// Bug: a waiting change reaches Odoo.
it('stores a pending proposal and writes nothing', function (): void {
    propose(Inbound::partial())->assertOk()
        ->assertStructuredContentPath('status', 'pending')
        ->assertStructuredContentPath('tier', 'human')
        ->assertStructuredContentPath('next', 'This waits for Riku. He decides on the approval page or on the card that list_proposals shows. Do not propose it again.');

    expect($this->odoo->writes)->toBe([]);
});

// Bug: a denied change reaches Odoo.
it('stores a denial and writes nothing', function (): void {
    propose(Inbound::input(['values' => ['price_unit' => 9.5]]))->assertOk()
        ->assertStructuredContentPath('status', 'denied')
        ->assertStructuredContentPath('basis', 'POL-2: '.InboundPurchasing::POLICY_SENTENCE['POL-2'])
        ->assertStructuredContentPath('next', 'This is not done by an agent. Report it to the person who owns it under the policy.');

    expect($this->odoo->writes)->toBe([]);
});

// Bug: a memoryless agent floods Riku with duplicates.
it('returns the first proposal when the same one is proposed twice', function (): void {
    propose(Inbound::partial());

    propose(Inbound::partial())->assertOk()
        ->assertStructuredContentPath('proposal_id', Proposal::query()->sole()->id)
        ->assertStructuredContentPath('existing', true);
});

// Bug: two conflicting decisions wait on one line.
it('returns the pending proposal when other values come for it from the same message', function (): void {
    propose(Inbound::partial());
    $pending = Proposal::query()->sole();

    propose(Inbound::input(['values' => ['x_confirmed_qty' => 100, 'x_confirmed_date' => '2026-11-30']]))->assertOk()
        ->assertStructuredContentPath('proposal_id', $pending->id)
        ->assertStructuredContentPath('decision', "Proposal {$pending->id} from the same message already proposes other values for these fields on this record, and Riku decides it first.")
        ->assertStructuredContentPath('existing', true);
});

// Bug: one Odoo outage blocks a change for good.
it('creates a new proposal after a failed one', function (): void {
    $this->odoo->refuseWrites(403, 'You are not allowed to modify this line.');
    propose(Inbound::input())->assertStructuredContentPath('status', 'failed');
    $this->odoo->acceptWrites();

    propose(Inbound::input())->assertOk()
        ->assertStructuredContentPath('status', 'applied')
        ->assertStructuredContentPath('existing', false);

    expect(Proposal::count())->toBe(2);
});

// Bug: the second denial crashes on the unique key.
it('returns the first denial when a denied proposal is proposed twice', function (): void {
    $input = Inbound::input(['values' => ['product_qty' => 100]]);
    propose($input);

    propose($input)->assertOk()
        ->assertStructuredContentPath('status', 'denied')
        ->assertStructuredContentPath('existing', true);
});

// Bug: false evidence reaches Riku.
it('stores nothing for a quote that is not in the mail and tells the agent why', function (): void {
    propose(Inbound::input(['quote' => 'VG-HEAD-V1 120 pcs delivery 1 December 2026']))
        ->assertHasErrors(['The quote is not in message '.Inbound::message()->id.'. Copy the passage exactly from the body or from an attachment\'s lines.']);

    $row = AuditEntry::query()->where('event', AuditEvent::InputRejected)->sole();

    expect(Proposal::count())->toBe(0)
        ->and($row->tool)->toBe('propose')
        ->and($row->detail['reason'])->toStartWith('The quote is not in message');
});

// Bug: half a confirmation is recorded.
it('answers a quantity without a date as an input error', function (): void {
    propose(Inbound::input(['values' => ['x_confirmed_qty' => 120]]))
        ->assertHasErrors(['x_confirmed_qty and x_confirmed_date go in the same proposal. Add x_confirmed_date.']);

    expect(Proposal::count())->toBe(0);
});
