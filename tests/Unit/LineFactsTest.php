<?php

declare(strict_types=1);

use App\Processes\InboundPurchasing\LineFacts;
use Tests\Support\RuleCase;

// Bug: a proposal stored before a fact was removed cannot be loaded, so the history page and its approval fail.
it('loads stored facts that hold a fact this version no longer has', function (): void {
    $stored = [...RuleCase::confirmation()->lineFacts()->toArray(), 'supplierAddresses' => ['sales@taiyuan.example']];

    $facts = LineFacts::fromArray($stored);

    expect($facts->orderName)->toBe('P00104')
        ->and($facts->supplierDomains)->toBe(['taiyuan.example']);
});
