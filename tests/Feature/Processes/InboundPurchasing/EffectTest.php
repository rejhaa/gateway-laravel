<?php

declare(strict_types=1);

use App\Gateway\ProposalStatus;
use App\Models\Proposal;
use App\Processes\InboundPurchasing\BillFacts;
use App\Processes\InboundPurchasing\Effect;
use Tests\Support\RuleCase;

/**
 * The effect of a pending proposal on the standard P00104 line: 120 ordered,
 * planned and requested for 2026-11-30, quoted from p1.l17 of the confirmation.
 *
 * @param  array<string, mixed>  $values
 * @param  array<string, mixed>  $facts  LineFacts arguments that differ
 * @param  array<string, mixed>  $attributes  proposal columns that differ
 * @return array<string, mixed>
 */
function lineEffect(array $values, array $facts = [], array $attributes = []): array
{
    return (new Effect)->of(Proposal::factory()->make([
        'values' => $values,
        'before' => array_fill_keys(array_keys($values), null),
        'facts' => RuleCase::confirmation()->with(facts: $facts)->lineFacts()->toArray(),
        'quote' => 'VG-HEAD-V1 V1 forged iron head 100 pcs ETD 14 December 2026',
        'quote_source' => 'TYF-OC-26-0918.pdf',
        'quote_location' => 'p1.l17',
        ...$attributes,
    ]));
}

// Bug: Riku reads the slip in the wrong direction or with the wrong count.
it('notes the planned date against the requested one', function (string $planned, string $note): void {
    $row = lineEffect(['date_planned' => $planned], attributes: ['quote_status' => 'unchecked'])['rows'][0];

    expect($row['note'])->toBe($note);
})->with([
    '14 days after' => ['2026-12-14', ', 14 days after the requested 2026-11-30'],
    '1 day after' => ['2026-12-01', ', 1 day after the requested 2026-11-30'],
    '2 days before' => ['2026-11-28', ', 2 days before the requested 2026-11-30'],
    'no slip' => ['2026-11-30', ', the requested date'],
]);

// Bug: a short confirmation reads as a full one.
it('notes the confirmed quantity against the ordered one', function (int $quantity, ?string $note): void {
    $rows = lineEffect(['x_confirmed_qty' => $quantity, 'x_confirmed_date' => '2026-12-14'], attributes: ['quote_status' => 'unchecked'])['rows'];

    expect($rows[1]['label'])->toBe('Confirmed quantity')
        ->and($rows[1]['note'])->toBe($note);
})->with([
    'short' => [100, ', 20 of 120 stay unconfirmed'],
    'over' => [125, ', 5 above the 120 ordered'],
    'as ordered' => [120, null],
]);

// Bug: the block shows the change without where it comes from, or repeats the source on every row.
it('draws the rows with the source once, on the first row', function (): void {
    $effect = lineEffect(['date_planned' => '2026-12-14', 'x_confirmed_qty' => 100, 'x_confirmed_date' => '2026-12-14'], attributes: ['quote_status' => 'found']);

    expect($effect['kind'])->toBe('Purchase order line')
        ->and($effect['record'])->toBe('P00104, V1 forged iron head (VG-HEAD-V1), 120 ordered')
        ->and($effect['rows'])->toBe([
            ['label' => 'Planned date', 'now' => 'none', 'after' => '2026-12-14', 'note' => ', 14 days after the requested 2026-11-30 in TYF-OC-26-0918.pdf, p1.l17', 'warning' => null],
            ['label' => 'Confirmed date', 'now' => 'none', 'after' => '2026-12-14', 'note' => null, 'warning' => null],
            ['label' => 'Confirmed quantity', 'now' => 'none', 'after' => '100', 'note' => ', 20 of 120 stay unconfirmed', 'warning' => null],
        ]);
});

// Bug: Riku approves a value nobody could check without being told.
it('warns on a value the quote does not state, and on every value from a scan', function (): void {
    $found = lineEffect(['x_confirmed_qty' => 120, 'x_confirmed_date' => '2026-12-14'], attributes: ['quote_status' => 'found'])['rows'];
    $scan = lineEffect(['x_confirmed_qty' => 100, 'x_confirmed_date' => '2026-12-14'], attributes: ['quote_status' => 'unchecked'])['rows'];

    expect(array_column($found, 'warning'))->toBe([null, Effect::NOT_IN_QUOTE])
        ->and(array_column($scan, 'warning'))->toBe([Effect::NOT_CHECKED, Effect::NOT_CHECKED]);
});

// Bug: Riku approves a gap with production that the screen misstates.
it('draws each waiting manufacturing order against the new planned date', function (array $values, array $after): void {
    $rows = lineEffect($values, ['waiting' => [
        ['name' => 'LAH/MO/00101', 'start' => '2026-12-02', 'need' => 60.0],
        ['name' => 'LAH/MO/00102', 'start' => '2026-12-14', 'need' => 60.0],
        ['name' => 'LAH/MO/00103', 'start' => '2026-12-15', 'need' => 1.0],
    ]], ['quote_status' => 'unchecked'])['rows'];

    $productions = array_slice($rows, count($values));

    expect(array_column($productions, 'label'))->toBe(['LAH/MO/00101 needs 60', 'LAH/MO/00102 needs 60', 'LAH/MO/00103 needs 1'])
        ->and(array_column($productions, 'now'))->toBe(['starts 2026-12-02', 'starts 2026-12-14', 'starts 2026-12-15'])
        ->and(array_column($productions, 'after'))->toBe($after);
})->with([
    'the date moves' => [
        ['date_planned' => '2026-12-14'],
        ['line arrives 12 days after its start', 'line arrives on its start', 'line arrives 1 day before its start'],
    ],
    'the date stays' => [
        ['x_confirmed_qty' => 120, 'x_confirmed_date' => '2026-11-30'],
        ['planned date unchanged', 'planned date unchanged', 'planned date unchanged'],
    ],
]);

// Bug: a dispute is approved without the sentence that says what it does.
it('draws a dispute with the bill as its record line and the dispute sentence', function (): void {
    $bill = new BillFacts(51, 'BILL/2026/11/0007', 'TYF-INV-2211', 'posted', 'Tai-Yuan Forge & Shaft', 4800.0, 'EUR', null, null);

    $effect = (new Effect)->of(Proposal::factory()->make([
        'model' => 'account.move',
        'record_label' => $bill->label(),
        'values' => ['x_dispute_note' => 'Only 100 of 120 heads arrived.', 'x_dispute' => 'disputed'],
        'before' => ['x_dispute' => null, 'x_dispute_note' => null],
        'facts' => $bill->toArray(),
        'quote_source' => 'body',
        'quote_location' => 'l3',
    ]));

    expect($effect)->toBe([
        'kind' => null,
        'record' => 'Posted vendor bill TYF-INV-2211 from Tai-Yuan Forge & Shaft, 4,800.00 EUR untaxed',
        'rows' => [
            ['label' => 'Dispute', 'now' => 'none', 'after' => 'disputed', 'note' => ' in the mail body, l3', 'warning' => null],
            ['label' => 'Dispute note', 'now' => 'none', 'after' => 'Only 100 of 120 heads arrived.', 'note' => null, 'warning' => null],
        ],
        'notice' => Effect::DISPUTE_NOTICE,
    ]);
});

// Bug: a denied proposal shows a Now column for a record the gateway never read.
it('draws a denied proposal as its proposed values with nothing now', function (): void {
    $effect = (new Effect)->of(Proposal::factory()->make([
        'status' => ProposalStatus::Denied,
        'record_label' => 'purchase.order.line 36',
        'values' => ['price_unit' => 9.5],
        'before' => null,
        'facts' => null,
    ]));

    expect($effect['rows'])->toBe([['label' => 'price_unit', 'now' => null, 'after' => '9.5', 'note' => null, 'warning' => null]]);
});
