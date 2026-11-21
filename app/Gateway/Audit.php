<?php

declare(strict_types=1);

namespace App\Gateway;

use App\Models\AuditEntry;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;

/**
 * Writes rows to the append-only audit log.
 *
 * Every row carries the request id from Laravel's Context, so the rows of one
 * request read together and every log line of that request carries the same id.
 * The first row of a request creates the id when nothing set it before.
 */
final class Audit
{
    public const REQUEST_ID = 'request_id';

    /**
     * @param  array<string, mixed>  $detail  arguments, model and method, field names, the reason. Never keys or tokens.
     */
    public function record(
        AuditEvent $event,
        Actor $actor,
        AuditOutcome $outcome = AuditOutcome::Ok,
        ?string $tool = null,
        ?int $proposalId = null,
        array $detail = [],
    ): AuditEntry {
        return AuditEntry::create([
            'request_id' => self::requestId(),
            'actor_type' => $actor->type,
            'actor' => $actor->name,
            'channel' => $actor->channel,
            'event' => $event,
            'client' => $actor->client,
            'tool' => $tool,
            'proposal_id' => $proposalId,
            'outcome' => $outcome,
            'detail' => $detail === [] ? null : $detail,
        ]);
    }

    public static function requestId(): string
    {
        Context::addIf(self::REQUEST_ID, (string) Str::uuid7());

        return Context::get(self::REQUEST_ID);
    }
}
