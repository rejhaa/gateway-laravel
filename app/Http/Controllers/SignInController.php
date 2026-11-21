<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Gateway\Actor;
use App\Gateway\Audit;
use App\Gateway\AuditEvent;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Signs in the one user on a GET and returns to where the visit was going.
 * It shows nothing and asks nothing, which DESIGN.md, section 1, accepts for
 * this version.
 */
final class SignInController extends Controller
{
    public function __invoke(Request $request, Audit $audit): RedirectResponse
    {
        Auth::login(User::primary());
        $request->session()->regenerate();

        $audit->record(AuditEvent::UserSignedIn, Actor::web((string) config('gateway.user.odoo_login')));

        return redirect()->intended('/');
    }
}
