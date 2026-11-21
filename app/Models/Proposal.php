<?php

declare(strict_types=1);

namespace App\Models;

use App\Gateway\ProposalStatus;
use App\Gateway\Tier;
use Database\Factories\ProposalFactory;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One write of one or more fields on one record, citing one message and one quote.
 *
 * @property int $id
 * @property string $process
 * @property string $system
 * @property string $model
 * @property int $record_id
 * @property string $record_label
 * @property array<string, mixed> $values
 * @property array<string, mixed>|null $before
 * @property array<string, mixed>|null $facts
 * @property string $message_id
 * @property string $quote
 * @property string|null $quote_source
 * @property string|null $quote_location
 * @property string|null $quote_status
 * @property string $title
 * @property string $summary
 * @property Tier $tier
 * @property ProposalStatus $status
 * @property string|null $rule
 * @property string|null $policy_section
 * @property list<string> $reasons
 * @property string|null $decided_by
 * @property string|null $decided_via
 * @property Carbon|null $decided_at
 * @property string|null $decision_reason
 * @property Carbon|null $applied_at
 * @property string|null $error
 * @property string $dedup_key
 * @property int|null $replaces
 */
#[Unguarded]
class Proposal extends Model
{
    /** @use HasFactory<ProposalFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'record_id' => 'integer',
            'values' => 'array',
            'before' => 'array',
            'facts' => 'array',
            'reasons' => 'array',
            'tier' => Tier::class,
            'status' => ProposalStatus::class,
            'decided_at' => 'datetime',
            'applied_at' => 'datetime',
            'replaces' => 'integer',
        ];
    }

    /**
     * The failed or stale proposal this one was proposed after.
     *
     * @return BelongsTo<Proposal, $this>
     */
    public function replaced(): BelongsTo
    {
        return $this->belongsTo(Proposal::class, 'replaces');
    }

    /**
     * @return HasMany<AuditEntry, $this>
     */
    public function auditEntries(): HasMany
    {
        return $this->hasMany(AuditEntry::class);
    }
}
