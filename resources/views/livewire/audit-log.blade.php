<div class="grid gap-6">
    <div class="flex flex-wrap items-end justify-between gap-x-6 gap-y-3">
        <h1 class="gw-heading">Audit log</h1>

        <div class="flex flex-wrap gap-3">
            <label class="grid gap-1 text-[13px] font-semibold text-muted">
                Actor
                <select wire:model.live="actor" class="gw-input w-40 font-normal text-ink">
                    <option value="">All</option>
                    @foreach ($actors as $value)
                        <option value="{{ $value }}">{{ $value }}</option>
                    @endforeach
                </select>
            </label>

            <label class="grid gap-1 text-[13px] font-semibold text-muted">
                Event
                <select wire:model.live="event" class="gw-input w-52 font-normal text-ink">
                    <option value="">All</option>
                    @foreach ($events as $value)
                        <option value="{{ $value }}">{{ $value }}</option>
                    @endforeach
                </select>
            </label>

            <label class="grid gap-1 text-[13px] font-semibold text-muted">
                Proposal
                <input type="text" inputmode="numeric" wire:model.live.debounce.400ms="proposal" class="gw-input w-24 font-normal text-ink">
            </label>
        </div>
    </div>

    <x-audit-rows :entries="$entries" />

    @if ($entries->hasPages())
        <nav class="flex items-center justify-between gap-3" aria-label="Pages of the audit log">
            <button type="button" class="gw-button" wire:click="previousPage" @disabled($entries->onFirstPage())>Newer</button>
            <span class="text-[13px] text-muted tabular-nums">Page {{ $entries->currentPage() }}</span>
            <button type="button" class="gw-button" wire:click="nextPage" @disabled(! $entries->hasMorePages())>Older</button>
        </nav>
    @endif
</div>
