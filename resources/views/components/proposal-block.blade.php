{{--
    One proposal, drawn from ProposalPresenter::block() (DESIGN.md, section 8).
    The card's script draws the same structure from the same data, and
    resources/css/proposals.css styles both.

    Every string comes out through Blade's escaped echo, because the title,
    the summary and the quote are the agent's words.

    collapsed  only the title and the one-line status, as after a decision
    decidable  the reason field and the buttons on a pending proposal, and
               "Check Odoo again" on one stuck in applying. They call the
               Livewire page's approve, reject and recheck actions.
    problem    a sentence about the last decision that did not go through
    toggle     a Livewire action the title calls to open or close the block
--}}
@props([
    'block',
    'collapsed' => false,
    'decidable' => false,
    'problem' => null,
    'toggle' => null,
])

@php
    $effect = $block['effect'];
    $quote = $block['quote'];
    $hasNow = collect($effect['rows'])->contains(fn (array $row): bool => $row['now'] !== null);
    $pending = $block['status'] === 'pending';
@endphp

<article {{ $attributes->class('pb') }} data-status="{{ $block['status'] }}">
    <div class="pb-head">
        <h3 class="pb-title">
            @if ($toggle !== null)
                <button type="button" class="pb-toggle" wire:click="{{ $toggle }}" aria-expanded="{{ $collapsed ? 'false' : 'true' }}">{{ $block['title'] }}</button>
            @else
                {{ $block['title'] }}
            @endif
        </h3>

        @if ($block['line'] !== null)
            <p class="pb-line">{{ $block['line'] }}</p>
        @endif
    </div>

    @if ($problem !== null && ! ($decidable && $pending))
        <p class="pb-error" role="alert">{{ $problem }}</p>
    @endif

    @if ($decidable && $block['status'] === 'applying')
        <div class="pb-buttons">
            <button type="button" class="gw-button" wire:click="recheck({{ $block['id'] }})" wire:loading.attr="disabled">Check Odoo again</button>
        </div>
    @endif

    @unless ($collapsed)
        @if ($block['because'] !== null)
            <p class="pb-because">{{ $block['because'] }}</p>
        @endif

        <details class="gw-fold" wire:ignore.self>
            <summary>The agent's summary</summary>
            <div class="gw-fold-body">
                <p class="pb-summary">{{ $block['summary'] }}</p>
            </div>
        </details>

        <section class="pb-effect">
            @if ($pending)
                <h4 class="pb-heading">If you say yes, Odoo changes this</h4>
            @endif

            <p class="pb-record">@if ($effect['kind'] !== null)<span class="pb-kind">{{ $effect['kind'] }}</span> @endif{{ $effect['record'] }}</p>

            <div @class(['pb-grid', 'pb-grid--values' => ! $hasNow])>
                @if ($hasNow)
                    <span class="pb-corner"></span><span class="pb-col">Now</span><span class="pb-col">After</span>
                @endif

                @foreach ($effect['rows'] as $row)
                    <span class="pb-label">{{ $row['label'] }}</span>
                    @if ($hasNow)
                        <span class="pb-now">{{ $row['now'] }}</span>
                    @endif
                    <span class="pb-after"><strong>{{ $row['after'] }}</strong>@if ($row['note'] !== null)<span class="pb-note">{{ $row['note'] }}</span>@endif</span>
                    @if ($row['warning'] !== null)
                        <p class="pb-warning">{{ $row['warning'] }}</p>
                    @endif
                @endforeach
            </div>

            @if ($effect['notice'] !== null)
                <p class="pb-notice">{{ $effect['notice'] }}</p>
            @endif

            <details class="gw-fold" wire:ignore.self>
                <summary>Why, and where it says so</summary>
                <div class="gw-fold-body">
                    <p class="pb-quote">"{{ $quote['text'] }}"</p>
                    @if ($quote['document'] !== null)
                        <p class="pb-source">{{ $quote['document'] }}, {{ $quote['location'] }}</p>
                    @endif
                    @if ($quote['check'] !== null)
                        <p>{{ $quote['check'] }}</p>
                    @endif
                    @if ($block['reasons'] !== [])
                        <ul class="pb-reasons">
                            @foreach ($block['reasons'] as $reason)
                                <li>{{ $reason }}</li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </details>
        </section>

        @if ($decidable && $pending)
            <form class="pb-decide" x-data="{ reason: '', sending: null }" x-on:submit.prevent>
                <input
                    type="text"
                    class="gw-input"
                    x-model="reason"
                    placeholder="Why, if no"
                    aria-label="Why, if no"
                    maxlength="2000"
                >
                @if ($problem !== null)
                    <p class="pb-error" role="alert">{{ $problem }}</p>
                @endif
                <div class="pb-buttons">
                    <button
                        type="button"
                        class="gw-button gw-button--primary"
                        x-bind:disabled="sending !== null"
                        x-on:click="sending = 'approve'; $wire.approve({{ $block['id'] }}, reason).finally(() => sending = null)"
                        x-text="sending === 'approve' ? 'Recording' : 'Yes, do it'"
                    >Yes, do it</button>
                    <button
                        type="button"
                        class="gw-button"
                        disabled
                        x-bind:disabled="sending !== null || reason.trim() === ''"
                        x-on:click="sending = 'reject'; $wire.reject({{ $block['id'] }}, reason).finally(() => sending = null)"
                        x-text="sending === 'reject' ? 'Recording' : 'No'"
                    >No</button>
                </div>
            </form>
        @endif

        {{ $slot }}
    @endunless
</article>
