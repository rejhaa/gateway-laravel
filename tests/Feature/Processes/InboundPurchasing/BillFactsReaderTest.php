<?php

declare(strict_types=1);

use App\Processes\InboundPurchasing\BillFactsReader;
use Tests\Support\FakeOdoo;
use Tests\Support\Inbound;

// Bug: a bill placed on a contact person has no supplier company or mail domains, so the screens cannot say that a
// dispute's mail is not from the supplier.
it('reads the company and the mail domains of the supplier a bill is from', function (): void {
    $odoo = FakeOdoo::start()->bill();
    $odoo->records['account.move'][FakeOdoo::BILL]['partner_id'] = [4, 'Tai-Yuan Forge & Shaft, Mei Lin'];
    $odoo->records['res.partner'][4] = ['name' => 'Mei Lin', 'commercial_partner_id' => [FakeOdoo::SUPPLIER, 'Tai-Yuan Forge & Shaft'], 'x_mail_domains' => false];

    $facts = app(BillFactsReader::class)->read(Inbound::agent(), FakeOdoo::BILL);

    expect($facts->supplier)->toBe('Tai-Yuan Forge & Shaft, Mei Lin')
        ->and($facts->supplierName)->toBe('Tai-Yuan Forge & Shaft')
        ->and($facts->supplierDomains)->toBe(['taiyuan.example']);
});

// Bug: a bill with no partner fails to load, or names a supplier it does not have.
it('reads no supplier for a bill with no partner', function (): void {
    $odoo = FakeOdoo::start()->bill();
    $odoo->records['account.move'][FakeOdoo::BILL]['partner_id'] = false;

    $facts = app(BillFactsReader::class)->read(Inbound::agent(), FakeOdoo::BILL);

    expect($facts->supplierName)->toBe('')
        ->and($facts->supplierDomains)->toBe([]);
});
