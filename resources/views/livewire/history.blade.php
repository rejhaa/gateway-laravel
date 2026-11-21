<div class="grid gap-6">
    <div class="flex flex-wrap items-end justify-between gap-x-6 gap-y-3">
        <h1 class="gw-heading">History</h1>

        <div class="flex flex-wrap gap-3">
            <label class="grid gap-1 text-[13px] font-semibold text-muted">
                Status
                <select wire:model.live="status" class="gw-input w-40 font-normal text-ink">
                    <option value="">All</option>
                    @foreach ($statuses as $value)
                        <option value="{{ $value }}">{{ $value }}</option>
                    @endforeach
                </select>
            </label>

            <label class="grid gap-1 text-[13px] font-semibold text-muted">
                Tier
                <select wire:model.live="tier" class="gw-input w-32 font-normal text-ink">
                    <option value="">All</option>
                    @foreach ($tiers as $value)
                        <option value="{{ $value }}">{{ $value }}</option>
                    @endforeach
                </select>
            </label>
        </div>
    </div>

    @foreach ($groups as $group)
        <section class="gw-group" wire:key="group-{{ $group['message']['id'] }}">
            <x-mail-head :message="$group['message']" />

            @foreach ($group['proposals'] as $block)
                <x-proposal-block
                    :block="$block"
                    :collapsed="$open !== $block['id']"
                    :toggle="'toggle('.$block['id'].')'"
                    wire:key="proposal-{{ $block['id'] }}"
                >
                    <section class="grid gap-2">
                        <h4 class="pb-heading">Audit trail for this proposal</h4>
                        <x-audit-rows :entries="$trail" :with-proposal="false" />
                    </section>
                </x-proposal-block>
            @endforeach
        </section>
    @endforeach
</div>
