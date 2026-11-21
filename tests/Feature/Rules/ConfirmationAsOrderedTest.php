<?php

declare(strict_types=1);

use App\Processes\InboundPurchasing\Rules\ConfirmationAsOrdered;
use Tests\Support\RuleCase;

// Each row starts from a full confirmation for the planned date and changes one thing.
it('decides a confirmation as ordered', function (array $change, array $failures): void {
    expect(RuleCase::confirmation()->with(...$change)->failures(new ConfirmationAsOrdered))->toBe($failures);
})->with([
    // Bug: the rule never passes, so everything waits.
    'it passes on a full confirmation for the planned date' => [[], []],
    // Bug: the waiting check is applied to a rule it does not belong to.
    'it passes with manufacturing orders waiting' => [
        ['facts' => ['waiting' => [['name' => 'LAH/MO/00101', 'start' => '2026-12-02', 'need' => 60.0]]]],
        [],
    ],
    // Bug: a confirmation for another day is recorded as one for the planned date.
    'it fails when the confirmed date is not the planned date' => [
        ['facts' => ['plannedDate' => '2026-11-28']],
        ['the confirmed date 2026-11-30 is not the planned date 2026-11-28'],
    ],
    // Bug: a short confirmation is recorded as full.
    'a partial quantity fails it' => [
        ['facts' => ['orderedQty' => 150.0]],
        ['the confirmed quantity 120 is not the 150 ordered'],
    ],
]);

// Bug: the rule judges a write that moves the planned date.
it('applies only to a line write that leaves the planned date alone', function (): void {
    expect((new ConfirmationAsOrdered)->applies(RuleCase::confirmation()->write()))->toBeTrue()
        ->and((new ConfirmationAsOrdered)->applies(RuleCase::plannedDate()->write()))->toBeFalse();
});
