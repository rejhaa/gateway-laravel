<?php

declare(strict_types=1);

use App\Gateway\InputError;
use App\Processes\InboundPurchasing\LineFactsReader;
use Tests\Support\FakeOdoo;
use Tests\Support\Inbound;

// Bug: a date late in the UTC evening is taken as the day before in Helsinki.
it('takes the Helsinki day of a datetime Odoo returns in UTC', function (): void {
    FakeOdoo::start()->line(['date_planned' => '2026-11-29 23:30:00', 'x_confirmed_date' => '2026-12-01']);

    $facts = app(LineFactsReader::class)->read(Inbound::agent(), FakeOdoo::LINE);

    expect($facts->plannedDate)->toBe('2026-11-30')
        ->and($facts->confirmedDate)->toBe('2026-12-01')
        ->and($facts->requestedDate)->toBe('2026-11-30');
});

// Bug: a quote in the supplier's words does not name the product, or another supplier's name for it counts.
it('reads the order supplier\'s names for the product and no one else\'s', function (): void {
    $odoo = FakeOdoo::start()->line();
    $odoo->records['product.product'][FakeOdoo::PRODUCT]['seller_ids'] = [61, 62, 63];
    $odoo->records['product.supplierinfo'] = [
        61 => ['partner_id' => [FakeOdoo::SUPPLIER, 'Tai-Yuan'], 'product_id' => false, 'product_code' => 'TY-H1', 'product_name' => 'Iron head V1'],
        62 => ['partner_id' => [99, 'Other forge'], 'product_id' => false, 'product_code' => 'OF-77', 'product_name' => 'Head'],
        63 => ['partner_id' => [FakeOdoo::SUPPLIER, 'Tai-Yuan'], 'product_id' => [12, 'Other variant'], 'product_code' => 'TY-H2', 'product_name' => false],
    ];

    $facts = app(LineFactsReader::class)->read(Inbound::agent(), FakeOdoo::LINE);

    expect($facts->productNames)->toBe(['VG-HEAD-V1', 'V1 forged iron head', 'TY-H1', 'Iron head V1']);
});

// Bug: a manufacturing order that needs the line through two moves counts twice, or its need is wrong.
it('reads each waiting manufacturing order once with its whole need', function (): void {
    $odoo = FakeOdoo::start()->line()
        ->waitingOrder(81, 91, 'LAH/MO/00102', '2026-12-07 06:00:00', 60.0)
        ->waitingOrder(82, 92, 'LAH/MO/00101', '2026-12-02 06:00:00', 40.0)
        ->waitingOrder(83, 92, 'LAH/MO/00101', '2026-12-02 06:00:00', 20.0);
    $odoo->records['stock.move'][84] = ['raw_material_production_id' => false, 'product_uom_qty' => 5.0];
    $odoo->records['purchase.order.line'][FakeOdoo::LINE]['move_dest_ids'][] = 84;

    $facts = app(LineFactsReader::class)->read(Inbound::agent(), FakeOdoo::LINE);

    expect($facts->waiting)->toBe([
        ['name' => 'LAH/MO/00101', 'start' => '2026-12-02', 'need' => 60.0],
        ['name' => 'LAH/MO/00102', 'start' => '2026-12-07', 'need' => 60.0],
    ]);
});

// Bug: a proposal on a line that does not exist reaches the rules.
it('refuses a line that does not exist as an input error', function (): void {
    FakeOdoo::start()->line();

    expect(fn () => app(LineFactsReader::class)->read(Inbound::agent(), 999))
        ->toThrow(InputError::class, 'There is no purchase.order.line with id 999 in Odoo.');
});
