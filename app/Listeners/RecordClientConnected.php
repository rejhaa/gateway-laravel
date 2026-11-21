<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Gateway\Actor;
use App\Gateway\Audit;
use App\Gateway\AuditEvent;
use Laravel\Passport\Events\AccessTokenCreated;
use Laravel\Passport\Passport;

/**
 * Writes client.connected when an OAuth client gets its first access token
 * for the user, which is the moment it connects. A refreshed token is a
 * second row for the same client and user, so it writes nothing.
 */
final class RecordClientConnected
{
    public function __construct(private readonly Audit $audit) {}

    public function handle(AccessTokenCreated $event): void
    {
        $tokens = Passport::token()->newQuery()
            ->where('client_id', $event->clientId)
            ->where('user_id', $event->userId)
            ->count();

        if ($tokens !== 1) {
            return;
        }

        $client = Passport::client()->newQuery()->find($event->clientId);

        $this->audit->record(
            AuditEvent::ClientConnected,
            Actor::agent((string) config('gateway.user.odoo_login'), $client?->name),
            detail: ['client_id' => $event->clientId],
        );
    }
}
