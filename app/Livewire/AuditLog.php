<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Gateway\AuditEvent;
use App\Models\AuditEntry;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The audit log, newest first, paginated, with filters for the actor, the
 * event and the proposal (DESIGN.md, section 8).
 */
#[Title('Audit log')]
final class AuditLog extends Component
{
    use WithPagination;

    public const PER_PAGE = 50;

    #[Url]
    public string $actor = '';

    #[Url]
    public string $event = '';

    #[Url]
    public string $proposal = '';

    /** A changed filter starts again from the newest rows. */
    public function updated(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $proposalId = filter_var($this->proposal, FILTER_VALIDATE_INT);

        $entries = AuditEntry::query()
            ->when($this->actor !== '', fn ($query) => $query->where('actor', $this->actor))
            ->when(AuditEvent::tryFrom($this->event), fn ($query, AuditEvent $event) => $query->where('event', $event))
            ->when($proposalId !== false, fn ($query) => $query->where('proposal_id', $proposalId))
            ->latest('id')
            ->simplePaginate(self::PER_PAGE);

        return view('livewire.audit-log', [
            'entries' => $entries,
            'actors' => AuditEntry::query()->distinct()->orderBy('actor')->pluck('actor'),
            'events' => array_column(AuditEvent::cases(), 'value'),
        ]);
    }
}
