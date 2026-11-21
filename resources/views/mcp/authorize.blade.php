{{-- Passport's authorization page. It names the client and asks one thing, whether to connect it. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Connect {{ $client->name }} · {{ config('app.name') }}</title>
    @fonts
    @vite(['resources/css/app.css'])
</head>
<body class="gw min-h-screen">
    <main class="flex min-h-screen items-center justify-center p-4">
        <div class="grid w-full max-w-md gap-4 rounded-md border border-line p-6">
            <h1 class="gw-heading">Connect {{ $client->name }}?</h1>

            <p>
                {{ $client->name }} will read the purchasing mailbox and Odoo as {{ config('gateway.user.name') }} and propose changes.
                The gateway decides each one, and {{ $client->name }} cannot approve anything.
            </p>

            <p class="border-t border-line pt-4 text-[13px] text-muted">Signed in as {{ $user->name }}</p>

            <div class="flex justify-end gap-2">
                <form method="POST" action="{{ route('passport.authorizations.deny') }}">
                    @csrf
                    @method('DELETE')
                    <input type="hidden" name="state" value="">
                    <input type="hidden" name="client_id" value="{{ $client->getKey() }}">
                    <input type="hidden" name="auth_token" value="{{ $authToken }}">
                    <button type="submit" class="gw-button">Cancel</button>
                </form>

                <form method="POST" action="{{ route('passport.authorizations.approve') }}">
                    @csrf
                    <input type="hidden" name="state" value="">
                    <input type="hidden" name="client_id" value="{{ $client->getKey() }}">
                    <input type="hidden" name="auth_token" value="{{ $authToken }}">
                    <button type="submit" class="gw-button gw-button--primary">Connect</button>
                </form>
            </div>
        </div>
    </main>
</body>
</html>
