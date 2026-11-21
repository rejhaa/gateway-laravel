<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Gateway\Actor;
use App\Gateway\DecisionRefused;
use App\Gateway\ProposalPresenter;
use App\Gateway\ProposalService;
use App\Gateway\ProposalStatus;
use App\Models\Proposal;
use App\Systems\Odoo\Exceptions\OdooException;
use Closure;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The approval page (DESIGN.md, section 8): what waits for the user, grouped
 * by mail, with the same blocks as the card.
 *
 * A proposal decided here stays on the page in its collapsed form until the
 * page is opened again, so the user sees what the decision did.
 */
#[Title('Approvals')]
final class Approvals extends Component
{
    /** @var list<int> proposals decided on this page since it was opened */
    public array $decided = [];

    /** @var array<int, string> per proposal id, a sentence about a decision that did not go through */
    public array $problems = [];

    private ProposalService $proposals;

    public function boot(ProposalService $proposals): void
    {
        $this->proposals = $proposals;
    }

    public function approve(int $id, ?string $reason = null): void
    {
        $reason = trim((string) $reason);

        $this->decide($id, fn (Proposal $proposal): Proposal => $this->proposals->approve($proposal, self::actor(), $reason === '' ? null : $reason));
    }

    public function reject(int $id, string $reason = ''): void
    {
        $this->decide($id, fn (Proposal $proposal): Proposal => $this->proposals->reject($proposal, self::actor(), $reason));
    }

    /** "Yes to all": one decision per pending proposal that cites the message, each with its own audit rows. */
    public function approveAll(string $messageId): void
    {
        $ids = Proposal::query()
            ->where('message_id', $messageId)
            ->where('status', ProposalStatus::Pending)
            ->oldest('id')
            ->pluck('id');

        foreach ($ids as $id) {
            $this->approve($id);
        }
    }

    /** "Check Odoo again" on a proposal stuck in applying. */
    public function recheck(int $id): void
    {
        $this->decide($id, fn (Proposal $proposal): Proposal => $this->proposals->recheck($proposal, self::actor()));
    }

    public function render(ProposalPresenter $presenter): View
    {
        $proposals = Proposal::query()
            ->whereIn('status', [ProposalStatus::Pending, ProposalStatus::Applying])
            ->orWhereIn('id', $this->decided)
            ->oldest('id')
            ->get();

        $waiting = $proposals->where('status', ProposalStatus::Pending)->count();

        return view('livewire.approvals', [
            'heading' => ProposalPresenter::heading($waiting),
            'groups' => $presenter->groups($proposals),
            'appliedByRule' => $presenter->appliedByRule(),
        ]);
    }

    /**
     * Runs one decision and keeps the proposal on the page. A refusal and an
     * Odoo error become a sentence under the proposal. Anything else is
     * unexpected and goes to Laravel's exception handler, which reports it.
     *
     * @param  Closure(Proposal): Proposal  $decision
     */
    private function decide(int $id, Closure $decision): void
    {
        $proposal = Proposal::query()->findOrFail($id);
        unset($this->problems[$id]);

        try {
            $decision($proposal);
        } catch (DecisionRefused $e) {
            $this->problems[$id] = $e->getMessage();
        } catch (OdooException $e) {
            report($e);
            $this->problems[$id] = ProposalPresenter::odooTrouble($e);
        }

        if ($proposal->refresh()->status !== ProposalStatus::Pending && ! in_array($id, $this->decided, true)) {
            $this->decided[] = $id;
        }
    }

    private static function actor(): Actor
    {
        return Actor::web((string) config('gateway.user.odoo_login'));
    }
}
