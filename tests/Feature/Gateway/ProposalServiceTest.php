<?php

declare(strict_types=1);

use App\Gateway\AuditEvent;
use App\Gateway\DecisionRefused;
use App\Gateway\InputError;
use App\Gateway\ProposalService;
use App\Gateway\ProposalStatus;
use App\Gateway\Tier;
use App\Models\AuditEntry;
use App\Models\Proposal;
use App\Processes\InboundPurchasing\InboundPurchasing;
use App\Systems\Mail\Attachment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeOdoo;
use Tests\Support\Inbound;

describe('propose', function (): void {
    // Bug: a rule pass is stored but never written.
    it('applies a passing write by rule and writes it to Odoo once', function (): void {
        $odoo = FakeOdoo::start()->line();
        Inbound::mailbox();

        $result = Inbound::service()->propose(Inbound::agent(), Inbound::input());

        expect($result->toArray())->toMatchArray([
            'status' => 'applied',
            'tier' => 'rule',
            'basis' => 'confirmation-as-ordered',
            'reasons' => ['the supplier confirms the 120 ordered for the planned date 2026-11-30'],
            'next' => 'The gateway wrote this to Odoo. Nothing more is needed.',
            'existing' => false,
        ])->and($odoo->writes)->toHaveCount(1)
            ->and($odoo->writes[0]['values'])->toBe(['x_confirmed_qty' => 120, 'x_confirmed_date' => '2026-11-30'])
            ->and($result->proposal->record_label)->toBe('P00104, V1 forged iron head (VG-HEAD-V1), 120 ordered')
            ->and($result->proposal->decided_via)->toBe('rule');
    });

    // Bug: the planned date lands on another day than the one proposed.
    it('writes a planned date as the proposed day at 10:00 UTC', function (): void {
        $odoo = FakeOdoo::start()->line();
        Inbound::mailbox();

        Inbound::service()->propose(Inbound::agent(), Inbound::input([
            'values' => ['date_planned' => '2026-12-13', 'x_confirmed_qty' => 120, 'x_confirmed_date' => '2026-12-13'],
            'quote' => Inbound::QUOTE_MOVED,
        ]));

        expect($odoo->writes[0]['values']['date_planned'])->toBe('2026-12-13 10:00:00');
    });

    // Bug: a waiting change reaches Odoo.
    it('stores a write that fails a condition as pending and writes nothing', function (): void {
        $odoo = FakeOdoo::start()->line();
        Inbound::mailbox();

        $result = Inbound::service()->propose(Inbound::agent(), Inbound::partial());

        expect($result->toArray())->toMatchArray([
            'status' => 'pending',
            'tier' => 'human',
            'decision' => 'This waits for Riku, because the confirmed quantity 100 is not the 120 ordered; the confirmed date 2026-12-14 is not the planned date 2026-11-30.',
            'next' => 'This waits for Riku. He decides on the approval page or on the card that list_proposals shows. Do not propose it again.',
        ])->and($odoo->writes)->toBe([])
            ->and($result->proposal->before)->toBe(['x_confirmed_qty' => null, 'x_confirmed_date' => null])
            ->and($result->proposal->quote_source)->toBe('body')
            ->and($result->proposal->quote_location)->toBe('l5');
    });

    // Bug: the switch does nothing.
    it('sends a passing write to Riku when the rule tier is off, with that reason alone', function (): void {
        config(['gateway.rule_tier' => false]);
        $odoo = FakeOdoo::start()->line();
        Inbound::mailbox();

        $result = Inbound::service()->propose(Inbound::agent(), Inbound::input());

        expect($result->proposal->status)->toBe(ProposalStatus::Pending)
            ->and($result->proposal->reasons)->toBe([ProposalService::RULE_TIER_OFF])
            ->and($odoo->writes)->toBe([]);
    });

    // Bug: a denied change reaches Odoo, or the record is read before the denial.
    it('stores a denial without reading the record and writes nothing', function (): void {
        FakeOdoo::start()->line();
        Inbound::mailbox();

        $result = Inbound::service()->propose(Inbound::agent(), Inbound::input(['values' => ['price_unit' => 9.5]]));

        expect($result->toArray())->toMatchArray([
            'status' => 'denied',
            'tier' => 'policy',
            'decision' => 'Denied under POL-2: '.InboundPurchasing::POLICY_SENTENCE['POL-2'],
            'basis' => 'POL-2: '.InboundPurchasing::POLICY_SENTENCE['POL-2'],
            'next' => 'This is not done by an agent. Report it to the person who owns it under the policy.',
        ])->and($result->proposal->record_label)->toBe('purchase.order.line 36')
            ->and($result->proposal->before)->toBeNull();
        Http::assertNothingSent();
    });

    // Bug: a vendor bill dispute is applied without a person.
    it('sends a dispute on a vendor bill to Riku with the POL-4 reason', function (): void {
        $odoo = FakeOdoo::start()->bill();
        Inbound::mailbox(Inbound::message(subject: 'Invoice TYF-INV-2211', body: 'We invoice 120 heads, TYF-INV-2211.'));

        $result = Inbound::service()->propose(Inbound::agent(), Inbound::input([
            'model' => 'account.move',
            'record_id' => FakeOdoo::BILL,
            'values' => ['x_dispute' => 'disputed', 'x_dispute_note' => 'Only 100 of the 120 heads arrived.'],
            'quote' => 'We invoice 120 heads',
        ]));

        expect($result->proposal->status)->toBe(ProposalStatus::Pending)
            ->and($result->proposal->reasons)->toBe([InboundPurchasing::DISPUTE_REASON])
            ->and($result->proposal->record_label)->toBe('Posted vendor bill TYF-INV-2211 from Tai-Yuan Forge & Shaft, 4,800.00 EUR untaxed')
            ->and($odoo->writes)->toBe([]);
    });

    // Bug: a scan's values are applied by rule though nobody could check them.
    it('sends a write whose quote could not be checked to Riku', function (): void {
        FakeOdoo::start()->line();
        Inbound::mailbox(Inbound::message(
            body: 'Please find our confirmation of P00104 attached.',
            attachments: [new Attachment('scan.pdf', 'application/pdf', false)],
        ));

        $result = Inbound::service()->propose(Inbound::agent(), Inbound::input());

        expect($result->proposal->status)->toBe(ProposalStatus::Pending)
            ->and($result->proposal->quote_status)->toBe('unchecked')
            ->and($result->proposal->reasons)->toBe(['the document has no text layer, so the quote was not checked']);
    });

    // Bug: a memoryless agent floods Riku with duplicates.
    it('returns the first proposal when the same one is sent twice', function (): void {
        FakeOdoo::start()->line();
        Inbound::mailbox();

        $first = Inbound::service()->propose(Inbound::agent(), Inbound::partial());
        $second = Inbound::service()->propose(Inbound::agent(), Inbound::partial());

        expect($second->proposal->id)->toBe($first->proposal->id)
            ->and($second->existing)->toBeTrue()
            ->and(Proposal::count())->toBe(1)
            ->and(AuditEntry::where('event', 'proposal.duplicate')->count())->toBe(1);
    });

    // Bug: two conflicting decisions wait on one line.
    it('returns the pending proposal when other values come for the same fields from the same message', function (): void {
        FakeOdoo::start()->line();
        Inbound::mailbox();

        $first = Inbound::service()->propose(Inbound::agent(), Inbound::partial());
        $second = Inbound::service()->propose(Inbound::agent(), Inbound::input([
            'values' => ['x_confirmed_qty' => 100, 'x_confirmed_date' => '2026-11-30'],
        ]));

        expect($second->proposal->id)->toBe($first->proposal->id)
            ->and($second->toArray()['decision'])->toBe("Proposal {$first->proposal->id} from the same message already proposes other values for these fields on this record, and Riku decides it first.")
            ->and(Proposal::count())->toBe(1);
    });

    // Bug: one Odoo outage blocks a change for good.
    it('creates a new proposal that replaces a failed one', function (): void {
        $odoo = FakeOdoo::start()->line()->refuseWrites(403, 'You are not allowed to modify this line.');
        Inbound::mailbox();

        $failed = Inbound::service()->propose(Inbound::agent(), Inbound::input());
        $odoo->acceptWrites();
        $again = Inbound::service()->propose(Inbound::agent(), Inbound::input());

        expect($failed->proposal->status)->toBe(ProposalStatus::Failed)
            ->and($again->proposal->id)->not->toBe($failed->proposal->id)
            ->and($again->proposal->replaces)->toBe($failed->proposal->id)
            ->and($again->proposal->status)->toBe(ProposalStatus::Applied)
            ->and($odoo->writes)->toHaveCount(1);
    });

    // Bug: the second denial crashes on the unique key.
    it('returns the first denial when a denied proposal is sent twice', function (): void {
        FakeOdoo::start()->line();
        Inbound::mailbox();
        $input = Inbound::input(['values' => ['product_qty' => 100]]);

        $first = Inbound::service()->propose(Inbound::agent(), $input);
        $second = Inbound::service()->propose(Inbound::agent(), $input);

        expect($second->proposal->id)->toBe($first->proposal->id)
            ->and($second->existing)->toBeTrue()
            ->and(Proposal::count())->toBe(1);
    });

    // Bug: two identical calls at once store two proposals, or the loser crashes.
    it('returns the winner when an identical proposal is stored between the check and the insert', function (): void {
        FakeOdoo::start()->line();
        Inbound::mailbox();

        // The winner is failed when the duplicate check runs, and pending again
        // by the time the record has been read, as if another call stored it.
        $winner = Inbound::service()->propose(Inbound::agent(), Inbound::partial())->proposal;
        DB::table('proposals')->update(['status' => 'failed']);
        AuditEntry::created(function (AuditEntry $entry): void {
            if ($entry->event === AuditEvent::OdooRead) {
                DB::table('proposals')->update(['status' => 'pending']);
            }
        });

        $result = Inbound::service()->propose(Inbound::agent(), Inbound::partial());

        expect($result->proposal->id)->toBe($winner->id)
            ->and($result->existing)->toBeTrue()
            ->and(Proposal::count())->toBe(1);
    });

    // Bug: false evidence reaches Riku.
    it('stores nothing when the quote is not in the mail', function (): void {
        FakeOdoo::start()->line();
        Inbound::mailbox();

        expect(fn () => Inbound::service()->propose(Inbound::agent(), Inbound::input(['quote' => 'VG-HEAD-V1 120 pcs delivery 1 December 2026'])))
            ->toThrow(InputError::class, 'The quote is not in message');

        expect(Proposal::count())->toBe(0);
    });

    // Bug: half a confirmation is recorded.
    it('refuses a confirmed quantity without a confirmed date as an input error', function (): void {
        FakeOdoo::start()->line();
        Inbound::mailbox();

        expect(fn () => Inbound::service()->propose(Inbound::agent(), Inbound::input(['values' => ['x_confirmed_qty' => 120]])))
            ->toThrow(InputError::class, 'x_confirmed_qty and x_confirmed_date go in the same proposal. Add x_confirmed_date.');

        expect(Proposal::count())->toBe(0);
    });

    // Bug: a proposal cites a message the gateway cannot check.
    it('refuses a message that is not in the mailbox and reads nothing from Odoo', function (): void {
        FakeOdoo::start()->line();
        Inbound::mailbox();

        expect(fn () => Inbound::service()->propose(Inbound::agent(), Inbound::input(['message_id' => 'abcdef123456'])))
            ->toThrow(InputError::class, 'There is no message abcdef123456 in the mailbox.');

        Http::assertNothingSent();
    });

    // Bug: an unchanged planned date makes a plain confirmation look like a date move.
    it('drops a planned date equal to the current one and judges the rest', function (): void {
        $odoo = FakeOdoo::start()->line();
        Inbound::mailbox();

        $result = Inbound::service()->propose(Inbound::agent(), Inbound::input([
            'values' => ['date_planned' => '2026-11-30', 'x_confirmed_qty' => 120, 'x_confirmed_date' => '2026-11-30'],
        ]));

        expect($result->proposal->rule)->toBe('confirmation-as-ordered')
            ->and($result->proposal->values)->toBe(['x_confirmed_qty' => 120, 'x_confirmed_date' => '2026-11-30'])
            ->and($odoo->writes[0]['values'])->not->toHaveKey('date_planned');
    });

    // Bug: a write happens with no record of the rule that applied it.
    it('writes the rule-tier audit rows before the Odoo call', function (): void {
        $odoo = FakeOdoo::start()->line();
        Inbound::mailbox();

        Inbound::service()->propose(Inbound::agent(), Inbound::input());

        expect($odoo->writes[0]['audit'])->toContain('proposal.created', 'proposal.applied_by_rule')
            ->and($odoo->writes[0]['audit'])->not->toContain('odoo.write', 'proposal.applied');
    });
});

describe('approve and reject', function (): void {
    // Bug: a double click writes twice.
    it('writes to Odoo once when approved twice', function (): void {
        $odoo = FakeOdoo::start()->line();
        Inbound::mailbox();
        $proposal = Inbound::service()->propose(Inbound::agent(), Inbound::partial())->proposal;

        $approved = Inbound::service()->approve($proposal, Inbound::riku());

        expect(fn () => Inbound::service()->approve($proposal, Inbound::riku()))
            ->toThrow(DecisionRefused::class, ProposalService::ALREADY_DECIDED);

        expect($approved->status)->toBe(ProposalStatus::Applied)
            ->and($approved->decided_by)->toBe('riku')
            ->and($approved->decided_via)->toBe('web')
            ->and($odoo->writes)->toHaveCount(1)
            ->and($odoo->writes[0]['values'])->toBe(['x_confirmed_qty' => 100, 'x_confirmed_date' => '2026-12-14'])
            ->and(AuditEntry::where('event', 'decision.refused')->count())->toBe(1);
    });

    // Bug: a write happens with no record of who approved.
    it('writes the approval audit row before the Odoo call', function (): void {
        $odoo = FakeOdoo::start()->line();
        Inbound::mailbox();
        $proposal = Inbound::service()->propose(Inbound::agent(), Inbound::partial())->proposal;

        Inbound::service()->approve($proposal, Inbound::riku());

        expect($odoo->writes[0]['audit'])->toContain('proposal.approved')
            ->and(AuditEntry::where('event', 'proposal.applied')->sole()->actor)->toBe('riku');
    });

    // Bug: an approval overwrites a newer value, or writes to another order's line after a reseed.
    it('sets stale and writes nothing when the record changed', function (Closure $change): void {
        $odoo = FakeOdoo::start()->line();
        Inbound::mailbox();
        $proposal = Inbound::service()->propose(Inbound::agent(), Inbound::partial())->proposal;

        $change($odoo);
        $approved = Inbound::service()->approve($proposal, Inbound::riku());

        expect($approved->status)->toBe(ProposalStatus::Stale)
            ->and($odoo->writes)->toBe([])
            ->and(AuditEntry::where('event', 'proposal.stale')->count())->toBe(1);
    })->with([
        'a proposed field' => fn (FakeOdoo $odoo) => $odoo->records['purchase.order.line'][FakeOdoo::LINE]['x_confirmed_qty'] = 50.0,
        'the order name' => fn (FakeOdoo $odoo) => $odoo->records['purchase.order'][FakeOdoo::ORDER]['name'] = 'P00999',
        'the line is gone' => fn (FakeOdoo $odoo) => $odoo->records['purchase.order.line'] = [],
    ]);

    // Bug: Riku approves on a gap the screen misstated.
    it('sets stale when a waiting manufacturing order\'s start moved', function (): void {
        $odoo = FakeOdoo::start()->line()->waitingOrder(81, 91, 'LAH/MO/00101', '2026-12-02 06:00:00', 60.0);
        Inbound::mailbox();
        $proposal = Inbound::service()->propose(Inbound::agent(), Inbound::input([
            'values' => ['date_planned' => '2026-12-13', 'x_confirmed_qty' => 120, 'x_confirmed_date' => '2026-12-13'],
            'quote' => Inbound::QUOTE_MOVED,
        ]))->proposal;

        $odoo->records['mrp.production'][91]['date_start'] = '2026-12-09 06:00:00';
        $approved = Inbound::service()->approve($proposal, Inbound::riku());

        expect($proposal->reasons)->toBe(['1 manufacturing order waits on the line'])
            ->and($approved->status)->toBe(ProposalStatus::Stale)
            ->and($odoo->writes)->toBe([]);
    });

    // Bug: a refused write is shown as done.
    it('sets failed with Odoo\'s message when Odoo refuses the write', function (): void {
        $odoo = FakeOdoo::start()->line();
        Inbound::mailbox();
        $proposal = Inbound::service()->propose(Inbound::agent(), Inbound::partial())->proposal;

        $odoo->refuseWrites(403, 'You are not allowed to modify this line.');
        $approved = Inbound::service()->approve($proposal, Inbound::riku());

        expect($approved->status)->toBe(ProposalStatus::Failed)
            ->and($approved->error)->toBe('You are not allowed to modify this line.');
    });

    // Bug: an unknown outcome is recorded as failed and proposed again, or as applied when it was not.
    it('reads the record again after a timeout and decides from what Odoo holds', function (bool $committed, ProposalStatus $status, ?string $error): void {
        $odoo = FakeOdoo::start()->line();
        Inbound::mailbox();
        $proposal = Inbound::service()->propose(Inbound::agent(), Inbound::partial())->proposal;

        $odoo->timeOutWrites($committed);
        $approved = Inbound::service()->approve($proposal, Inbound::riku());

        expect($approved->status)->toBe($status)
            ->and($approved->error)->toBe($error);
    })->with([
        'Odoo took it' => [true, ProposalStatus::Applied, null],
        'Odoo did not take it' => [false, ProposalStatus::Failed, ProposalService::NOT_CONFIRMED],
    ]);

    // Bug: the agent gets a no with nothing to act on.
    it('refuses a rejection without a reason', function (): void {
        FakeOdoo::start()->line();
        Inbound::mailbox();
        $proposal = Inbound::service()->propose(Inbound::agent(), Inbound::partial())->proposal;

        expect(fn () => Inbound::service()->reject($proposal, Inbound::riku(), '  '))
            ->toThrow(DecisionRefused::class, ProposalService::REASON_REQUIRED);

        expect($proposal->refresh()->status)->toBe(ProposalStatus::Pending);
    });

    // Bug: a rejection reaches Odoo, or its reason is lost.
    it('rejects with the reason and writes nothing', function (): void {
        $odoo = FakeOdoo::start()->line();
        Inbound::mailbox();
        $proposal = Inbound::service()->propose(Inbound::agent(), Inbound::partial())->proposal;

        $rejected = Inbound::service()->reject($proposal, Inbound::riku(), 'Ask them for all 120 by 30 November.');

        expect($rejected->status)->toBe(ProposalStatus::Rejected)
            ->and($rejected->decision_reason)->toBe('Ask them for all 120 by 30 November.')
            ->and($odoo->writes)->toBe([]);
    });
});

describe('recheck', function (): void {
    // Bug: a proposal stuck in applying can never be resolved.
    it('sets a stuck proposal from what Odoo holds', function (array $line, ProposalStatus $status): void {
        FakeOdoo::start()->line($line);
        $proposal = Proposal::factory()->create([
            'record_id' => FakeOdoo::LINE,
            'status' => ProposalStatus::Applying,
            'tier' => Tier::Human,
            'values' => ['x_confirmed_qty' => 100, 'x_confirmed_date' => '2026-12-14'],
        ]);

        expect(Inbound::service()->recheck($proposal, Inbound::riku())->status)->toBe($status);
    })->with([
        'Odoo holds the values' => [['x_confirmed_qty' => 100.0, 'x_confirmed_date' => '2026-12-14'], ProposalStatus::Applied],
        'Odoo does not' => [[], ProposalStatus::Failed],
    ]);
});
