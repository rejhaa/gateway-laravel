<?php

declare(strict_types=1);

use App\Evidence\QuoteChecker;

// Catches a correct quote being rejected as missing.
it('finds a quote across a line break and different spacing, dashes and case', function (): void {
    $attachment = [
        'p1.l16' => 'Item          Description            Qty',
        'p1.l17' => "VG\u{2013}HEAD\u{2013}V1    V1 Forged Iron Head    100 pcs",
        'p1.l18' => 'ETD 14 December 2026',
    ];

    $quote = "VG-HEAD-V1 v1 forged\u{00A0}iron head 100\npcs ETD 14 December 2026";

    expect((new QuoteChecker)->locate($quote, $attachment))->toBe('p1.l17');
    expect((new QuoteChecker)->locate('we confirm 100 pcs', ['Hello,', '', 'We confirm', '100 pcs.']))->toBe(2);
});

// Catches invented evidence passing, and an empty quote being found in every text.
it('does not find a quote that is not in the text, or an empty one', function (): void {
    $body = ['We confirm 100 pcs for 14 December 2026.'];

    expect((new QuoteChecker)->locate('We confirm 120 pcs for 14 December 2026.', $body))->toBeNull();
    expect((new QuoteChecker)->locate(" \n ", $body))->toBeNull();
});

// Catches a shafts row confirming the heads line. The two-character name V1
// is ignored, so it does not match inside the shaft's code.
it('does not take a quote naming another product as naming this one', function (): void {
    $heads = ['VG-HEAD-V1', 'V1 forged iron head', 'V1'];

    expect((new QuoteChecker)->namesProduct('VG-SHAFT-V1 V1 steel shaft 120 pcs', $heads))->toBeFalse();
    expect((new QuoteChecker)->namesProduct('VG-HEAD-V1 V1 Forged Iron Head 100 pcs', $heads))->toBeTrue();
});

it('reads a date only when it states one unambiguously', function (string $quote, string $date, bool $stated): void {
    expect((new QuoteChecker)->statesDate($quote, $date))->toBe($stated);
})->with([
    // Catches a month-first date being written as day-first.
    '03/04/2026 is not 3 April' => ['ETD 03/04/2026', '2026-04-03', false],
    '03/04/2026 is not 4 March' => ['ETD 03/04/2026', '2026-03-04', false],
    '29.11.2026 is 29 November' => ['ETD 29.11.2026', '2026-11-29', true],
    '2026-12-14 is 14 December' => ['ETD 2026-12-14', '2026-12-14', true],
    // Catches the month-first reading swapping day and month.
    '12/13/2026 is 13 December' => ['ETD 12/13/2026', '2026-12-13', true],
    // Catches a confirmation that names the month being rejected.
    '14 December 2026 is 14 December' => ['ETD 14 December 2026', '2026-12-14', true],
    'Dec 14, 2026 is 14 December' => ['ETD Dec 14, 2026', '2026-12-14', true],
    // Catches a year being guessed for a date that has none.
    '14 December has no year' => ['ETD 14 December', '2026-12-14', false],
]);

// Catches a quantity with a thousands mark failing the rule.
it('reads a comma or point as a decimal or thousands mark', function (string $quote): void {
    expect((new QuoteChecker)->statesQuantity($quote, 1200))->toBeTrue();
})->with([
    '1,200' => ['1,200 pcs'],
    '1.200,00' => ['1.200,00 pcs'],
]);

// Catches a quantity being confirmed by digits that belong to another number,
// an order name or a date.
it('does not read a quantity from part of a larger number, a code or a date', function (string $quote, int $quantity): void {
    expect((new QuoteChecker)->statesQuantity($quote, $quantity))->toBeFalse();
})->with([
    '100 inside 1100' => ['1100 pcs', 100],
    '104 inside the order name P00104' => ['P00104 confirmed', 104],
    '14 inside 2026-12-14' => ['120 pcs, ETD 2026-12-14', 14],
    '12 inside 14.12.2026' => ['120 pcs, ETD 14.12.2026', 12],
    '14 inside 14 December 2026' => ['120 pcs, ETD 14 December 2026', 14],
]);

// Catches a message about another order blocking or supporting this one.
it('names an order only as a whole token', function (): void {
    $checker = new QuoteChecker;

    expect($checker->namesOrder('Shipped: your order P001012', ['P00101']))->toBeFalse();
    expect($checker->namesOrder('Packing list, your order P00101.', ['P00101']))->toBeTrue();
    expect($checker->namesOrder('Ref TYF-OC-26-0918', ['P00104', 'TYF-OC-26-0918']))->toBeTrue();
});

// Catches a short supplier reference such as 101 naming every message that contains it.
it('ignores an order reference shorter than four characters', function (): void {
    expect((new QuoteChecker)->namesOrder('Invoice 101 attached', ['101']))->toBeFalse();
});
