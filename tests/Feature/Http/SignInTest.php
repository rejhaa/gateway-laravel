<?php

declare(strict_types=1);

use App\Gateway\AuditEvent;
use App\Models\AuditEntry;
use App\Models\User;

// Bug: connecting fails on a fresh database, or a sign-in leaves no trace.
it('signs in Riku, creating him when missing, and returns to the intended page', function (): void {
    $this->withSession(['url.intended' => url('/oauth/authorize?client_id=1')])
        ->get('/login')
        ->assertRedirect(url('/oauth/authorize?client_id=1'));

    $this->assertAuthenticatedAs(User::query()->sole());

    expect(User::query()->sole()->name)->toBe('Riku Lindgren')
        ->and(AuditEntry::query()->sole()->event)->toBe(AuditEvent::UserSignedIn);
});
