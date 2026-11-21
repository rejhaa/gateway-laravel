<div wire:poll.5s class="grid gap-6">
    @if ($groups === [])
        <p class="gw-empty">Nothing waits for your answer.</p>
    @else
        <h1 class="gw-heading">{{ $heading }}</h1>

        @foreach ($groups as $group)
            <section class="gw-group" wire:key="group-{{ $group['message']['id'] }}">
                <x-mail-head :message="$group['message']" />

                @foreach ($group['proposals'] as $block)
                    <x-proposal-block
                        :block="$block"
                        :collapsed="$block['line'] !== null"
                        :decidable="true"
                        :problem="$problems[$block['id']] ?? null"
                        wire:key="proposal-{{ $block['id'] }}"
                    />
                @endforeach

                @if ($group['pending'] > 1)
                    <div class="gw-group-actions" x-data="{ sending: false }">
                        <button
                            type="button"
                            class="gw-button gw-button--primary"
                            x-bind:disabled="sending"
                            x-on:click="sending = true; $wire.approveAll(@js($group['message']['id'])).finally(() => sending = false)"
                            x-text="sending ? 'Recording' : @js('Yes to all '.$group['pending'])"
                        >Yes to all {{ $group['pending'] }}</button>
                    </div>
                @endif
            </section>
        @endforeach
    @endif

    @if ($appliedByRule !== [])
        <div class="gw-foot">
            <details class="gw-fold" wire:ignore.self>
                <summary>Applied by rule ({{ count($appliedByRule) }})</summary>
                <ul class="gw-applied">
                    @foreach ($appliedByRule as $applied)
                        <li wire:key="applied-{{ $applied['id'] }}">{{ $applied['title'] }} <span class="gw-rule">{{ $applied['rule'] }}</span></li>
                    @endforeach
                </ul>
            </details>
        </div>
    @endif
</div>
