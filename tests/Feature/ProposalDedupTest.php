<?php

declare(strict_types=1);

use App\Gateway\ProposalStatus;
use App\Models\Proposal;
use Illuminate\Database\UniqueConstraintViolationException;

// Bug: two identical propose calls at once store two proposals.
it('refuses a second proposal with the same key while the first is not failed or stale', function (ProposalStatus $status): void {
    $first = Proposal::factory()->create(['status' => $status]);

    expect(fn () => Proposal::factory()->create(['dedup_key' => $first->dedup_key]))
        ->toThrow(UniqueConstraintViolationException::class);
})->with([
    ProposalStatus::Pending,
    ProposalStatus::Applying,
    ProposalStatus::Applied,
    ProposalStatus::Rejected,
    ProposalStatus::Denied,
]);

// Bug: one Odoo outage or one changed record blocks a change for good.
it('allows a new proposal with the same key after a failed or stale one', function (ProposalStatus $status): void {
    $first = Proposal::factory()->create(['status' => $status]);

    $second = Proposal::factory()->create(['dedup_key' => $first->dedup_key, 'replaces' => $first->id]);

    expect($second->replaced->is($first))->toBeTrue();
})->with([
    ProposalStatus::Failed,
    ProposalStatus::Stale,
]);
