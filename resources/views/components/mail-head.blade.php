{{--
    The head of a group: who sent the mail, its subject and when, with the
    fold "The mail". The sender chose the name, so the address follows it
    unless the name is the address, and the marks follow that, such as
    "sender not verified". The card's script draws the same structure. A
    message no longer in the mailbox shows only its id.
--}}
@props(['message'])

@php
    $address = $message['address'] ?? null;
    $showAddress = $address !== null && strcasecmp($address, (string) $message['sender']) !== 0;
    $marks = implode(', ', $message['marks'] ?? []);
@endphp

<div class="gw-mail">
    @if ($message['subject'] === null)
        <p class="gw-mail-head">{{ $message['id'] }}</p>
    @else
        <p class="gw-mail-head"><span class="gw-sender">{{ $message['sender'] }}</span>@if ($showAddress) <span class="gw-address">{{ $address }}</span>@endif @if ($marks !== '')<span class="gw-sender-mark">{{ $marks }}</span>@endif · {{ $message['subject'] }} · <time datetime="{{ $message['date'] }}">{{ $message['time'] }}</time></p>

        <details class="gw-fold" wire:ignore.self>
            <summary>The mail</summary>
            <div class="gw-mail-body">
                <pre>{{ $message['body'] }}</pre>
                @if ($message['attachments'] !== [])
                    <p class="gw-attachments">{{ implode(', ', $message['attachments']) }}</p>
                @endif
            </div>
        </details>
    @endif
</div>
