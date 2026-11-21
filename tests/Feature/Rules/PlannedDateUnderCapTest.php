<?php

declare(strict_types=1);

use App\Processes\InboundPurchasing\Rules\PlannedDateUnderCap;
use Tests\Support\RuleCase;

// Each row starts from a 13-day slip with nothing waiting and changes one thing.
it('decides a planned date move', function (array $change, array $failures): void {
    expect(RuleCase::plannedDate()->with(...$change)->failures(new PlannedDateUnderCap))->toBe($failures);
})->with([
    // Bug: the rule never passes.
    'it passes at a 13-day slip with nothing waiting' => [[], []],
    // Bug: a date moved earlier is read as a slip over the cap.
    'it passes when the date moves earlier' => [
        ['facts' => ['requestedDate' => '2026-12-20']],
        [],
    ],
    // Bug: an off-by-one lets the cap value through.
    'it fails at exactly 14 days' => [
        ['facts' => ['requestedDate' => '2026-11-29']],
        ['the slip of 14 days from the requested date is at or over the 14-day cap'],
    ],
    // Bug: a null date is read as a zero slip.
    'it fails with no requested date' => [
        ['facts' => ['requestedDate' => null]],
        ['the line has no requested date to count the slip from'],
    ],
    // Bug: a date move reaches production with no person.
    'it fails with one manufacturing order waiting' => [
        ['facts' => ['waiting' => [['name' => 'LAH/MO/00101', 'start' => '2026-12-02', 'need' => 60.0]]]],
        ['1 manufacturing order waits on the line'],
    ],
    'it fails with two manufacturing orders waiting' => [
        ['facts' => ['waiting' => [
            ['name' => 'LAH/MO/00101', 'start' => '2026-12-02', 'need' => 60.0],
            ['name' => 'LAH/MO/00102', 'start' => '2026-12-07', 'need' => 60.0],
        ]]],
        ['2 manufacturing orders wait on the line'],
    ],
    // Bug: the two dates drift apart by rule.
    'it fails when the confirmed date differs from the new planned date' => [
        ['values' => ['x_confirmed_date' => '2026-12-12']],
        ['the confirmed date is not the new planned date'],
    ],
    // Bug: a short confirmation is recorded as full.
    'a partial quantity fails it' => [
        ['facts' => ['orderedQty' => 150.0]],
        ['the confirmed quantity 120 is not the 150 ordered'],
    ],
]);

// Bug: the rule judges a write that leaves the planned date alone.
it('applies only to a line write that moves the planned date', function (): void {
    expect((new PlannedDateUnderCap)->applies(RuleCase::plannedDate()->write()))->toBeTrue()
        ->and((new PlannedDateUnderCap)->applies(RuleCase::confirmation()->write()))->toBeFalse();
});
