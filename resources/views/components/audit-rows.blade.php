{{--
    Audit rows as a table, on the audit log page and under an open proposal in
    the history. Times are in gateway.timezone. The client, an OAuth client's
    name or a card token's id, is shown under the actor.
--}}
@props(['entries', 'withProposal' => true])

@php
    // One line per detail key. A list of plain values reads as a comma-separated list.
    $show = fn (mixed $value): string => match (true) {
        is_string($value), is_int($value), is_float($value) => (string) $value,
        is_array($value) && array_is_list($value) && array_filter($value, fn (mixed $item): bool => ! is_string($item) && ! is_int($item)) === [] => implode(', ', $value),
        default => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    };
@endphp

<div class="overflow-x-auto">
    <table class="w-full min-w-[56rem] border-collapse text-left text-[13px]">
        <thead>
            <tr class="border-b border-line text-muted">
                <th scope="col" class="py-1.5 pe-3 font-semibold">Time</th>
                <th scope="col" class="py-1.5 pe-3 font-semibold">Actor</th>
                <th scope="col" class="py-1.5 pe-3 font-semibold">Channel</th>
                <th scope="col" class="py-1.5 pe-3 font-semibold">Event</th>
                <th scope="col" class="py-1.5 pe-3 font-semibold">Tool</th>
                @if ($withProposal)
                    <th scope="col" class="py-1.5 pe-3 font-semibold">Proposal</th>
                @endif
                <th scope="col" class="py-1.5 pe-3 font-semibold">Outcome</th>
                <th scope="col" class="py-1.5 font-semibold">Detail</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($entries as $entry)
                <tr class="border-b border-line align-top" wire:key="audit-{{ $entry->id }}">
                    <td class="py-1.5 pe-3 whitespace-nowrap tabular-nums">{{ $entry->occurred_at->setTimezone(config('gateway.timezone'))->format('Y-m-d H:i:s') }}</td>
                    <td class="py-1.5 pe-3">
                        <span class="whitespace-nowrap">{{ $entry->actor }} <span class="text-muted">{{ $entry->actor_type->value }}</span></span>
                        @if ($entry->client !== null)
                            <span class="block text-muted [overflow-wrap:anywhere]">{{ $entry->client }}</span>
                        @endif
                    </td>
                    <td class="py-1.5 pe-3">{{ $entry->channel->value }}</td>
                    <td class="py-1.5 pe-3 whitespace-nowrap">{{ $entry->event->value }}</td>
                    <td class="py-1.5 pe-3 whitespace-nowrap">{{ $entry->tool }}</td>
                    @if ($withProposal)
                        <td class="py-1.5 pe-3 tabular-nums">
                            @if ($entry->proposal_id !== null)
                                <a href="{{ route('history', ['open' => $entry->proposal_id]) }}" wire:navigate class="gw-link">{{ $entry->proposal_id }}</a>
                            @endif
                        </td>
                    @endif
                    <td @class([
                        'py-1.5 pe-3 font-semibold',
                        'text-muted' => $entry->outcome->value === 'ok',
                        'text-warn' => $entry->outcome->value === 'refused',
                        'text-bad' => $entry->outcome->value === 'error',
                    ])>{{ $entry->outcome->value }}</td>
                    <td class="py-1.5 text-muted [overflow-wrap:anywhere]">
                        @foreach ($entry->detail ?? [] as $key => $value)
                            @continue($value === null || $value === [])
                            <span class="block"><span class="font-semibold">{{ $key }}</span> {{ $show($value) }}</span>
                        @endforeach
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
