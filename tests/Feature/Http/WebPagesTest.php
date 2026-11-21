<?php

declare(strict_types=1);

use App\Gateway\Actor;
use App\Gateway\Audit;
use App\Gateway\AuditEvent;
use App\Gateway\ProposalPresenter;
use App\Gateway\ProposalService;
use App\Gateway\ProposalStatus;
use App\Gateway\Tier;
use App\Livewire\Approvals;
use App\Livewire\AuditLog;
use App\Livewire\History;
use App\Models\AuditEntry;
use App\Models\Proposal;
use App\Models\User;
use Livewire\Livewire;
use Tests\Support\FakeOdoo;
use Tests\Support\Inbound;

beforeEach(function (): void {
    $this->odoo = FakeOdoo::start()->line();
    Inbound::mailbox();
});

// Bug: the approval pages open to anyone who reaches the URL without the sign-in.
it('sends a visitor who is not signed in to the sign-in', function (string $page): void {
    $this->get($page)->assertRedirect(route('login'));
})->with(['/', '/history', '/audit']);

// Bug: Claude's OAuth token opens the approval page.
it('refuses a page request that carries a bearer token', function (): void {
    $this->actingAs(User::primary())
        ->withHeader('Authorization', 'Bearer anything')
        ->get('/')
        ->assertUnauthorized();
});

// Bug: Claude's OAuth token runs the approve action on Livewire's update endpoint.
it('refuses a Livewire action request that carries a bearer token', function (): void {
    $proposal = Inbound::service()->propose(Inbound::agent(), Inbound::partial())->proposal;

    $this->actingAs(User::primary())
        ->withHeaders(['Authorization' => 'Bearer anything', 'X-Livewire' => '1'])
        ->postJson(route('livewire.update'), ['components' => [['calls' => [['method' => 'approve', 'params' => [$proposal->id]]]]]])
        ->assertUnauthorized();

    expect($proposal->refresh()->status)->toBe(ProposalStatus::Pending)
        ->and($this->odoo->writes)->toBe([]);
});

// Bug: the agent injects script into the approval screen.
it('draws an agent-written title containing HTML as text', function (): void {
    $title = '<img src=x onerror="alert(1)"> P00104 <b>heads</b>';
    $proposal = Proposal::factory()->create(['title' => $title]);

    $this->blade('<x-proposal-block :block="$block" />', ['block' => app(ProposalPresenter::class)->block($proposal)])
        ->assertSee($title)
        ->assertDontSee('<img src=x', false)
        ->assertDontSee('<b>heads</b>', false);
});

// Bug: a click on "Yes, do it" writes twice, or the block stays open as if nothing happened.
it('approves from the page once and collapses the block to its line', function (): void {
    $proposal = Inbound::service()->propose(Inbound::agent(), Inbound::partial())->proposal;

    Livewire::actingAs(User::primary())
        ->test(Approvals::class)
        ->assertSee('Why, if no')
        ->call('approve', $proposal->id)
        ->call('approve', $proposal->id)
        ->assertSee('Approved by Riku at '.now('Europe/Helsinki')->format('H:i').'. Written to Odoo.')
        ->assertSee(ProposalService::ALREADY_DECIDED)
        ->assertDontSee('Why, if no');

    expect($this->odoo->writes)->toHaveCount(1)
        ->and($proposal->refresh()->status)->toBe(ProposalStatus::Applied)
        ->and($proposal->decided_via)->toBe('web');
});

// Bug: "No" with an empty reason rejects, so the agent gets a no with nothing to act on.
it('does not reject without a reason, and says why', function (): void {
    $proposal = Inbound::service()->propose(Inbound::agent(), Inbound::partial())->proposal;

    Livewire::actingAs(User::primary())
        ->test(Approvals::class)
        ->call('reject', $proposal->id, '   ')
        ->assertSee(ProposalService::REASON_REQUIRED)
        ->assertSee('Why, if no');

    expect($proposal->refresh()->status)->toBe(ProposalStatus::Pending);
});

// Bug: a rejection from the page loses the reason the agent needs.
it('rejects from the page with the reason', function (): void {
    $proposal = Inbound::service()->propose(Inbound::agent(), Inbound::partial())->proposal;

    Livewire::actingAs(User::primary())
        ->test(Approvals::class)
        ->call('reject', $proposal->id, 'Ask for all 120.')
        ->assertSee('Rejected by Riku at');

    expect($proposal->refresh()->status)->toBe(ProposalStatus::Rejected)
        ->and($proposal->decision_reason)->toBe('Ask for all 120.')
        ->and($this->odoo->writes)->toBe([]);
});

// Bug: "Yes to all" decides only the first proposal, or records one decision for the whole mail.
it('records one decision per proposal on "Yes to all"', function (): void {
    $message = Inbound::message();
    $first = Proposal::factory()->create(['record_id' => FakeOdoo::LINE, 'message_id' => $message->id]);
    $second = Proposal::factory()->create([
        'record_id' => FakeOdoo::LINE,
        'message_id' => $message->id,
        'values' => ['x_confirmed_qty' => 120, 'x_confirmed_date' => '2026-11-30'],
        'dedup_key' => str_repeat('b', 64),
    ]);

    Livewire::actingAs(User::primary())
        ->test(Approvals::class)
        ->assertSee('Yes to all 2')
        ->call('approveAll', $message->id)
        ->assertDontSee('Yes to all');

    expect(AuditEntry::query()->where('event', AuditEvent::ProposalApproved)->orderBy('id')->pluck('proposal_id')->all())
        ->toBe([$first->id, $second->id])
        ->and($first->refresh()->status)->toBe(ProposalStatus::Applied)
        // The first write changed the line, so the second finds it changed.
        ->and($second->refresh()->status)->toBe(ProposalStatus::Stale);
});

// Bug: a proposal stuck in applying can never be settled from the page.
it('checks Odoo again for a proposal stuck in applying', function (): void {
    $proposal = Proposal::factory()->create([
        'record_id' => FakeOdoo::LINE,
        'status' => ProposalStatus::Applying,
        'values' => ['x_confirmed_qty' => 100, 'x_confirmed_date' => '2026-12-14'],
    ]);
    $this->odoo->records['purchase.order.line'][FakeOdoo::LINE]['x_confirmed_qty'] = 100.0;
    $this->odoo->records['purchase.order.line'][FakeOdoo::LINE]['x_confirmed_date'] = '2026-12-14';

    Livewire::actingAs(User::primary())
        ->test(Approvals::class)
        ->assertSee('Being written to Odoo.')
        ->assertSee('Check Odoo again')
        ->call('recheck', $proposal->id)
        ->assertDontSee('Check Odoo again');

    expect($proposal->refresh()->status)->toBe(ProposalStatus::Applied);
});

// Bug: the history filter shows proposals of another status.
it('narrows the history to one status', function (): void {
    Proposal::factory()->create(['title' => 'P00104 heads: waiting']);
    Proposal::factory()->create(['title' => 'P00101 grips: said no', 'status' => ProposalStatus::Rejected, 'decision_reason' => 'Wrong order.']);

    Livewire::actingAs(User::primary())
        ->test(History::class)
        ->assertSee(['P00104 heads: waiting', 'P00101 grips: said no'])
        ->set('status', 'rejected')
        ->assertSee('P00101 grips: said no')
        ->assertDontSee('P00104 heads: waiting')
        ->set('status', '')
        ->set('tier', 'rule')
        ->assertDontSee(['P00104 heads: waiting', 'P00101 grips: said no']);
});

// Bug: an opened history row does not show who did what to the proposal.
it('opens a history row to the full block and its audit trail', function (): void {
    $proposal = Inbound::service()->propose(Inbound::agent(), Inbound::partial())->proposal;

    Livewire::actingAs(User::primary())
        ->test(History::class)
        ->assertDontSee('Audit trail for this proposal')
        ->call('toggle', $proposal->id)
        ->assertSee(['Audit trail for this proposal', 'proposal.created', 'If you say yes, Odoo changes this']);
});

// Bug: the audit log page hides rows, or its filter does not narrow.
it('lists audit rows newest first and filters them by event', function (): void {
    $audit = app(Audit::class);
    $audit->record(AuditEvent::ToolCalled, Actor::agent('riku', 'Claude'), tool: 'whoami');
    $audit->record(AuditEvent::CardIssued, Actor::agent('riku', 'Claude'), detail: ['token_id' => 'abc123']);

    Livewire::actingAs(User::primary())
        ->test(AuditLog::class)
        ->assertSeeInOrder(['card.issued', 'tool.called'])
        ->assertSee('abc123')
        ->set('event', 'tool.called')
        ->assertSee('whoami')
        ->assertDontSee('abc123');
});

// Bug: the approval page hides what rules applied, so Riku cannot see the gateway's own changes.
it('shows what rules applied on the approval page', function (): void {
    Proposal::factory()->create(['title' => 'P00104 heads: all 120 confirmed', 'tier' => Tier::Rule, 'status' => ProposalStatus::Applied, 'rule' => 'confirmation-as-ordered']);

    Livewire::actingAs(User::primary())
        ->test(Approvals::class)
        ->assertSee(['Nothing waits for your answer.', 'Applied by rule (1)', 'P00104 heads: all 120 confirmed', 'confirmation-as-ordered']);
});
