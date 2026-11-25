<?php

declare(strict_types=1);

use App\Systems\Mail\AuthenticationResults;

// Catches a forged From address counting as verified, and a real one failing.
// The receiving server is mx.vuoksi.example and the From domain is taiyuan.example.
it('verifies the From domain only from a pass for that domain that the receiving server wrote', function (string $header, bool $verified): void {
    expect(AuthenticationResults::verifies([$header], 'mx.vuoksi.example', 'taiyuan.example'))->toBe($verified);
})->with([
    'DMARC passes for the domain' => ['mx.vuoksi.example; dkim=pass header.d=mailer.example; dmarc=pass (p=reject) header.from=taiyuan.example', true],
    'DMARC fails for the domain' => ['mx.vuoksi.example; spf=pass smtp.mailfrom=attacker.example; dmarc=fail header.from=taiyuan.example', false],
    'DMARC passes for another domain' => ['mx.vuoksi.example; dmarc=pass header.from=attacker.example', false],
    'DMARC passes for a subdomain' => ['mx.vuoksi.example; dmarc=pass header.from=mail.taiyuan.example', false],
    'DKIM passes for the domain without a DMARC record' => ['mx.vuoksi.example; dkim=pass header.d=taiyuan.example header.s=k1; dmarc=none', true],
    'DKIM passes for the signing identity at the domain' => ['mx.vuoksi.example; dkim=pass header.i=@taiyuan.example', true],
    'DKIM passes for the sender\'s own domain' => ['mx.vuoksi.example; dkim=pass header.d=attacker.example; dmarc=none', false],
    'DKIM fails for the domain' => ['mx.vuoksi.example; dkim=fail header.d=taiyuan.example', false],
    'SPF passes for the domain' => ['mx.vuoksi.example; spf=pass (sender IP is 203.0.113.9) smtp.mailfrom=bounce@taiyuan.example', true],
    'SPF passes for another envelope domain' => ['mx.vuoksi.example; spf=pass smtp.mailfrom=bounce@attacker.example', false],
    'another server wrote the header' => ['mx.attacker.example; dmarc=pass header.from=taiyuan.example', false],
    'the server id is the start of a longer id' => ['mx.vuoksi.example.attacker.example; dmarc=pass header.from=taiyuan.example', false],
    'a pass written inside a comment' => ['mx.vuoksi.example; dmarc=fail (was; dmarc=pass header.from=taiyuan.example) header.from=taiyuan.example', false],
    'a folded header in capitals with a version' => ["MX.Vuoksi.Example 1;\r\n\tDMARC = Pass header.from=\"Taiyuan.Example\"", true],
    'no result' => ['mx.vuoksi.example; none', false],
    'a DMARC fail beside a DKIM pass for the domain' => ['mx.vuoksi.example; dkim=pass header.d=taiyuan.example; dmarc=fail header.from=taiyuan.example', false],
    'a property named twice' => ['mx.vuoksi.example; dkim=pass header.d=attacker.example header.d=taiyuan.example', false],
    'the server id followed by a word that is no version' => ['mx.vuoksi.example junk; dmarc=pass header.from=taiyuan.example', false],
    'the server id followed by a vertical tab' => ["mx.vuoksi.example\x0bjunk; dmarc=pass header.from=taiyuan.example", false],
    'a comment that never ends' => ['mx.vuoksi.example; dmarc=pass header.from=taiyuan.example (checked', false],
    'a quoted string that never ends' => ['mx.vuoksi.example; dmarc=pass header.from="taiyuan.example', false],
]);

// Catches a sender forging a pass with text of their own that the receiving
// server copied into its header. The server quotes such text, so nothing in a
// quoted string or a comment may start a result.
it('reads no result from the sender\'s text inside a quoted string or a comment', function (string $header, bool $verified): void {
    expect(AuthenticationResults::verifies([$header], 'mx.vuoksi.example', 'taiyuan.example'))->toBe($verified);
})->with([
    'a result inside a quoted envelope address' => [
        'mx.vuoksi.example; spf=pass (domain of "a;dmarc=pass header.from=taiyuan.example b"@attacker.example designates 203.0.113.9)'
            .' smtp.mailfrom="a;dmarc=pass header.from=taiyuan.example b"@attacker.example; dkim=none; dmarc=none header.from=taiyuan.example',
        false,
    ],
    'a result after an escaped quote inside a quoted string' => [
        'mx.vuoksi.example; spf=pass smtp.mailfrom="a\";dmarc=pass header.from=taiyuan.example;\""@attacker.example; dmarc=none header.from=taiyuan.example',
        false,
    ],
    'a result inside a comment within a comment' => [
        'mx.vuoksi.example; dmarc=none (policy (none; dmarc=pass header.from=taiyuan.example) applied) header.from=taiyuan.example',
        false,
    ],
    'a result after an escaped parenthesis inside a comment' => [
        'mx.vuoksi.example; dmarc=none (helo a\); dmarc=pass header.from=taiyuan.example) header.from=taiyuan.example',
        false,
    ],
    'a quoted local part at the domain' => [
        'mx.vuoksi.example; spf=pass smtp.mailfrom="order desk"@taiyuan.example',
        true,
    ],
]);

// Catches a header the sender added being read in place of the receiving server's.
it('reads the receiving server\'s header among the headers of other servers', function (): void {
    $headers = [
        'mx.attacker.example; dmarc=pass header.from=taiyuan.example',
        'mx.vuoksi.example; dmarc=fail header.from=taiyuan.example',
    ];

    expect(AuthenticationResults::verifies($headers, 'mx.vuoksi.example', 'taiyuan.example'))->toBeFalse()
        ->and(AuthenticationResults::verifies($headers, 'mx.attacker.example', 'taiyuan.example'))->toBeTrue();
});
