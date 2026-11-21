<?php

declare(strict_types=1);

namespace App\Models;

use App\Gateway\ActorType;
use App\Gateway\AuditEvent;
use App\Gateway\AuditOutcome;
use App\Gateway\Channel;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One row of the append-only audit log. Write rows through App\Gateway\Audit.
 *
 * The database refuses an update or a delete with a trigger. The model refuses
 * them too, so the mistake fails before it reaches the database.
 *
 * @property int $id
 * @property Carbon $occurred_at
 * @property string $request_id
 * @property ActorType $actor_type
 * @property string $actor
 * @property Channel $channel
 * @property AuditEvent $event
 * @property string|null $client
 * @property string|null $tool
 * @property int|null $proposal_id
 * @property AuditOutcome $outcome
 * @property array<string, mixed>|null $detail
 */
#[Table('audit_log')]
#[Unguarded]
class AuditEntry extends Model
{
    public const CREATED_AT = 'occurred_at';

    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'actor_type' => ActorType::class,
            'channel' => Channel::class,
            'event' => AuditEvent::class,
            'outcome' => AuditOutcome::class,
            'proposal_id' => 'integer',
            'detail' => 'array',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('audit_log is append-only');
        }

        return parent::save($options);
    }

    public function delete(): never
    {
        throw new LogicException('audit_log is append-only');
    }

    /**
     * @return BelongsTo<Proposal, $this>
     */
    public function proposal(): BelongsTo
    {
        return $this->belongsTo(Proposal::class);
    }
}
