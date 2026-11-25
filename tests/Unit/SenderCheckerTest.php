<?php

declare(strict_types=1);

use App\Evidence\SenderChecker;

// Catches a stranger passing as the organization, and a new colleague at its
// own domain being turned away.
it('takes a sender as the organization\'s by its address, or by a domain only the organization uses', function (string $sender, array $addresses, bool $belongs): void {
    expect((new SenderChecker)->belongsTo($sender, $addresses))->toBe($belongs);
})->with([
    'an address on record' => ['mei.lin@taiyuan.example', ['sales@taiyuan.example', 'mei.lin@taiyuan.example'], true],
    'an address on record in other case' => ['Sales@Taiyuan.Example', ['sales@taiyuan.example'], true],
    'a colleague at the same domain' => ['new.clerk@taiyuan.example', ['sales@taiyuan.example'], true],
    'another domain' => ['sales@taiyuan-forge.example', ['sales@taiyuan.example'], false],
    'a subdomain of the domain' => ['sales@mail.taiyuan.example', ['sales@taiyuan.example'], false],
    'a domain that ends like the domain' => ['sales@nottaiyuan.example', ['sales@taiyuan.example'], false],
    'the address on record at a shared domain' => ['taiyuan.forge@gmail.com', ['taiyuan.forge@gmail.com'], true],
    'another address at a shared domain' => ['someone.else@gmail.com', ['taiyuan.forge@gmail.com'], false],
    'another address at a subdomain of a shared domain' => ['liu@ms12.hinet.net', ['chen@ms12.hinet.net'], false],
    'no address on record' => ['sales@taiyuan.example', [], false],
]);
