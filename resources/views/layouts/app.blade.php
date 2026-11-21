{{-- The layout of the three web pages: a top bar with the pages and the person they act for. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Approvals' }} · {{ config('app.name') }}</title>
    @fonts
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="gw min-h-screen">
    <header class="border-b border-line">
        <nav class="mx-auto flex max-w-4xl flex-wrap items-center gap-x-5 gap-y-1 px-4 py-3" aria-label="Pages">
            @foreach (['approvals' => 'Approvals', 'history' => 'History', 'audit' => 'Audit log'] as $route => $label)
                <a
                    href="{{ route($route) }}"
                    wire:navigate
                    @class([
                        'rounded-sm py-1 font-semibold underline-offset-[6px] decoration-2 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent',
                        'text-ink underline decoration-accent' => request()->routeIs($route),
                        'text-muted hover:text-ink' => ! request()->routeIs($route),
                    ])
                    @if (request()->routeIs($route)) aria-current="page" @endif
                >{{ $label }}</a>
            @endforeach

            <span class="ms-auto text-[13px] text-muted">{{ config('gateway.user.name') }}, {{ config('gateway.user.role') }}</span>
        </nav>
    </header>

    <main class="mx-auto max-w-4xl px-4 pt-6 pb-16">
        {{ $slot }}
    </main>
</body>
</html>
