<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Gateway\ProposalPresenter;
use App\Gateway\ProposalStatus;
use App\Gateway\Tier;
use App\Models\AuditEntry;
use App\Models\Proposal;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Every proposal, newest first, grouped by mail, in the collapsed form
 * (DESIGN.md, section 8). A row opens to the full block with its audit rows.
 */
#[Title('History')]
final class History extends Component
{
    #[Url]
    public string $status = '';

    #[Url]
    public string $tier = '';

    /** The proposal shown open, if any. */
    #[Url]
    public ?int $open = null;

    public function toggle(int $id): void
    {
        $this->open = $this->open === $id ? null : $id;
    }

    public function render(ProposalPresenter $presenter): View
    {
        $proposals = Proposal::query()
            ->when(ProposalStatus::tryFrom($this->status), fn ($query, ProposalStatus $status) => $query->where('status', $status))
            ->when(Tier::tryFrom($this->tier), fn ($query, Tier $tier) => $query->where('tier', $tier))
            ->latest('id')
            ->get();

        return view('livewire.history', [
            'groups' => $presenter->groups($proposals, newestFirst: true),
            'trail' => $this->trail(),
            'statuses' => array_column(ProposalStatus::cases(), 'value'),
            'tiers' => array_column(Tier::cases(), 'value'),
        ]);
    }

    /** @return Collection<int, AuditEntry> the audit rows of the open proposal, oldest first */
    private function trail(): Collection
    {
        if ($this->open === null) {
            return collect();
        }

        return AuditEntry::query()->where('proposal_id', $this->open)->oldest('id')->get();
    }
}
