<?php

declare(strict_types=1);

use App\Evidence\SenderChecker;

// Catches a stranger passing as the organization, and a colleague at one of
// its own domains being turned away.
it('takes a sender as the organization\'s only at one of its mail domains', function (string $sender, array $domains, bool $belongs): void {
    expect((new SenderChecker)->belongsTo($sender, $domains))->toBe($belongs);
})->with([
    'an address at the domain' => ['sales@taiyuan.example', ['taiyuan.example'], true],
    'an address in other case' => ['Sales@Taiyuan.Example', ['taiyuan.example'], true],
    'an address at the second domain' => ['mei.lin@tyforge.example', ['taiyuan.example', 'tyforge.example'], true],
    'another domain' => ['sales@taiyuan-forge.example', ['taiyuan.example'], false],
    'a subdomain of the domain' => ['sales@mail.taiyuan.example', ['taiyuan.example'], false],
    'a domain that ends like the domain' => ['sales@nottaiyuan.example', ['taiyuan.example'], false],
    'the domain before a second @' => ['sales@taiyuan.example@attacker.example', ['taiyuan.example'], false],
    'an address with no domain' => ['taiyuan.example', ['taiyuan.example'], false],
    'no domain on record' => ['sales@taiyuan.example', [], false],
]);

// Catches a domain not counting because a person wrote it with a capital, an
// @ or a line break, and an empty field counting as a domain.
it('reads the mail domains a person wrote into one field', function (string $text, array $domains): void {
    expect(SenderChecker::domains($text))->toBe($domains);
})->with([
    'one domain' => ['taiyuan.example', ['taiyuan.example']],
    'two domains with a comma and capitals' => ['Taiyuan.Example, tyforge.com.tw', ['taiyuan.example', 'tyforge.com.tw']],
    'domains with an @ on separate lines' => ["@taiyuan.example;\n@tyforge.com.tw", ['taiyuan.example', 'tyforge.com.tw']],
    'a domain written twice' => ['taiyuan.example taiyuan.example', ['taiyuan.example']],
    'white space alone' => [" \n", []],
    'an @ alone' => ['@', []],
]);

// Catches a whole address in the field opening its domain, or every domain, to all senders.
it('takes no sender for an entry that is a whole address', function (): void {
    $domains = SenderChecker::domains('sales@taiyuan.example');

    expect((new SenderChecker)->belongsTo('sales@taiyuan.example', $domains))->toBeFalse()
        ->and((new SenderChecker)->belongsTo('clerk@taiyuan.example', $domains))->toBeFalse();
});
