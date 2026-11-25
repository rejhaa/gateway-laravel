<?php

declare(strict_types=1);

use App\Systems\Mail\FileMailbox;
use App\Systems\Mail\Mailbox;
use App\Systems\Mail\Message;
use App\Systems\Mail\PdfText;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\ExecutableFinder;
use Tests\Support\FakeOdoo;
use Tests\Support\Inbound;
use Tests\Support\MinimalPdf;

beforeEach(function (): void {
    $this->directory = sys_get_temp_dir().'/mailbox-'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($this->directory);
    config(['gateway.mailbox.path' => $this->directory]);
});

afterEach(function (): void {
    File::deleteDirectory($this->directory);
});

/**
 * @param  array<string, string>  $attachments  file name to PDF content
 * @param  string  $from  the value of the From header
 * @param  string|null  $authenticationResults  the value of an Authentication-Results header, or none
 */
function eml(
    string $messageId,
    string $date,
    string $subject,
    string $body,
    array $attachments = [],
    string $from = '=?UTF-8?Q?Tai-Yuan_Forge_&_Shaft?= <sales@taiyuan.example>',
    ?string $authenticationResults = null,
): string {
    $headers = ($authenticationResults === null ? '' : "Authentication-Results: {$authenticationResults}\r\n")
        ."From: {$from}\r\n"
        ."To: Riku Lindgren <riku@vuoksi.example>\r\n"
        ."Subject: {$subject}\r\n"
        ."Date: {$date}\r\n"
        ."Message-ID: <{$messageId}>\r\n"
        ."MIME-Version: 1.0\r\n";

    $parts = ["Content-Type: text/plain; charset=utf-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n{$body}\r\n"];

    foreach ($attachments as $name => $pdf) {
        $parts[] = "Content-Type: application/pdf; name=\"{$name}\"\r\n"
            ."Content-Disposition: attachment; filename=\"{$name}\"\r\n"
            ."Content-Transfer-Encoding: base64\r\n\r\n"
            .chunk_split(base64_encode($pdf));
    }

    return $headers."Content-Type: multipart/mixed; boundary=\"b1\"\r\n\r\n"
        .implode('', array_map(fn (string $part): string => "--b1\r\n{$part}", $parts))
        ."--b1--\r\n";
}

// Bug: the agent cannot quote an attachment, or it reads headers and body other than what the mail says.
it('reads the headers, the body and the numbered lines of a PDF attachment', function (): void {
    $pdf = MinimalPdf::fromPages([['Order confirmation P00104', 'VG-HEAD-V1   100 pcs   14.12.2026']]);
    File::put($this->directory.'/confirmation.eml', eml(
        'conf-104@taiyuan.example',
        'Wed, 05 Nov 2026 09:12:00 +0800',
        'Order confirmation P00104, partial',
        "Dear Riku,\r\nplease find our confirmation attached.",
        ['TYF-OC-26-0918.pdf' => $pdf],
    ));

    $message = app(Mailbox::class)->messages()[0];

    expect($message->id)->toBe(substr(hash('sha256', 'conf-104@taiyuan.example'), 0, 12))
        ->and($message->from)->toBe('Tai-Yuan Forge & Shaft <sales@taiyuan.example>')
        ->and($message->to)->toBe('Riku Lindgren <riku@vuoksi.example>')
        ->and($message->subject)->toBe('Order confirmation P00104, partial')
        ->and($message->date->toIso8601String())->toBe('2026-11-05T09:12:00+08:00')
        ->and($message->body)->toBe("Dear Riku,\nplease find our confirmation attached.")
        ->and($message->attachments)->toHaveCount(1)
        ->and($message->attachments[0]->name)->toBe('TYF-OC-26-0918.pdf')
        ->and($message->attachments[0]->hasTextLayer)->toBeTrue()
        ->and($message->attachments[0]->lines)->toBe([
            'p1.l1' => 'Order confirmation P00104',
            'p1.l2' => 'VG-HEAD-V1 100 pcs 14.12.2026',
        ]);
})->skip(fn (): bool => (new ExecutableFinder)->find('pdftotext') === null, 'pdftotext is not installed');

// Bug: a proposal's cited message stops resolving when the file is renamed or read again.
it('derives a stable id from the Message-ID header alone', function (): void {
    File::put($this->directory.'/a.eml', eml('stable@example', 'Mon, 03 Nov 2026 08:00:00 +0200', 'Packing list', 'Body'));
    $first = app(Mailbox::class)->messages()[0]->id;

    File::move($this->directory.'/a.eml', $this->directory.'/renamed.eml');
    $mailbox = app(Mailbox::class);

    expect($first)->toBe(Message::idFor('<stable@example>'))
        ->and($mailbox->messages()[0]->id)->toBe($first)
        ->and($mailbox->find($first)?->subject)->toBe('Packing list')
        ->and($mailbox->find('000000000000'))->toBeNull();
});

// Bug: the agent works through mail out of order, and an older confirmation looks like the newest.
it('lists messages oldest first whatever the file names', function (): void {
    File::put($this->directory.'/1-newer.eml', eml('newer@example', 'Wed, 05 Nov 2026 09:00:00 +0200', 'Newer', 'Body'));
    File::put($this->directory.'/2-older.eml', eml('older@example', 'Mon, 03 Nov 2026 09:00:00 +0200', 'Older', 'Body'));

    $subjects = array_map(fn (Message $message): string => $message->subject, app(Mailbox::class)->messages());

    expect($subjects)->toBe(['Older', 'Newer']);
});

// Bug: an edited mail file is served from the cache, or a cached message cannot be read back from the file cache.
it('serves a message from the cache until the file changes', function (): void {
    config(['cache.stores.file.path' => $this->directory.'/cache']);
    $mailbox = new FileMailbox($this->directory, app(PdfText::class), Cache::store('file'));
    $path = $this->directory.'/a.eml';

    File::put($path, eml('a@example', 'Mon, 03 Nov 2026 09:00:00 +0200', 'First', 'Body'));
    touch($path, 1_700_000_000);
    $first = $mailbox->messages()[0]->subject;

    File::put($path, eml('a@example', 'Mon, 03 Nov 2026 09:00:00 +0200', 'Second', 'Body'));
    touch($path, 1_700_000_000);
    clearstatcache();
    $cached = $mailbox->messages()[0];

    touch($path, 1_700_000_100);
    clearstatcache();
    $changed = $mailbox->messages()[0]->subject;

    expect($first)->toBe('First')
        ->and($cached)->toBeInstanceOf(Message::class)
        ->and($cached->subject)->toBe('First')
        ->and($changed)->toBe('Second');
});

// Bug: a forged From header counts as the supplier, or the supplier's address in a display name counts as the sender.
it('takes the sender from the one From address and its verification from the receiving server\'s header', function (string $from, ?string $results, ?string $address, bool $verified): void {
    config(['gateway.mailbox.authserv_id' => 'mx.vuoksi.example']);
    File::put($this->directory.'/a.eml', eml('a@example', 'Mon, 03 Nov 2026 09:00:00 +0200', 'Order confirmation', 'Body', from: $from, authenticationResults: $results));

    $message = app(Mailbox::class)->messages()[0];

    expect($message->fromAddress)->toBe($address)
        ->and($message->fromVerified)->toBe($verified);
})->with([
    'DMARC passed for the From domain' => ['Tai-Yuan Forge <Sales@Taiyuan.example>', 'mx.vuoksi.example; dmarc=pass header.from=taiyuan.example', 'sales@taiyuan.example', true],
    'DMARC failed for the From domain' => ['Tai-Yuan Forge <sales@taiyuan.example>', 'mx.vuoksi.example; dmarc=fail header.from=taiyuan.example', 'sales@taiyuan.example', false],
    'the receiving server added no header' => ['Tai-Yuan Forge <sales@taiyuan.example>', null, 'sales@taiyuan.example', false],
    'another server\'s header says it passed' => ['Tai-Yuan Forge <sales@taiyuan.example>', 'mx.attacker.example; dmarc=pass header.from=taiyuan.example', 'sales@taiyuan.example', false],
    'the supplier\'s address is the display name' => ['"sales@taiyuan.example" <x@attacker.example>', 'mx.vuoksi.example; dmarc=pass header.from=attacker.example', 'x@attacker.example', true],
    'two addresses in the From header' => ['sales@taiyuan.example, x@attacker.example', 'mx.vuoksi.example; dmarc=pass header.from=taiyuan.example', null, false],
    'two From headers' => ["sales@taiyuan.example\r\nFrom: x@attacker.example", 'mx.vuoksi.example; dmarc=pass header.from=taiyuan.example', null, false],
    // Bug: a header the sender named Authentication_Results, which no receiving server removes, is read as the server's.
    'a header whose name only looks like Authentication-Results' => ["Tai-Yuan Forge <sales@taiyuan.example>\r\nAuthentication_Results: mx.vuoksi.example; dmarc=pass header.from=taiyuan.example", null, 'sales@taiyuan.example', false],
    // Bug: a header named F-rom counts as a second From header and breaks the mailbox, or stands in for the sender.
    'a header whose name only looks like From beside the From header' => ["x@attacker.example\r\nF-rom: sales@taiyuan.example", 'mx.vuoksi.example; dmarc=pass header.from=taiyuan.example', 'x@attacker.example', false],
]);

// Bug: a message with no From header takes its sender from a header named F-rom, which the receiving server never checked.
it('gives a message with no From header no sender, whatever a header of a similar name says', function (): void {
    config(['gateway.mailbox.authserv_id' => 'mx.vuoksi.example']);
    $mail = eml('a@example', 'Mon, 03 Nov 2026 09:00:00 +0200', 'Order confirmation', 'Body', from: 'sales@taiyuan.example', authenticationResults: 'mx.vuoksi.example; dmarc=pass header.from=taiyuan.example');
    File::put($this->directory.'/a.eml', str_replace("\r\nFrom: ", "\r\nF-rom: ", $mail));

    $message = app(Mailbox::class)->messages()[0];

    expect($message->fromAddress)->toBeNull()
        ->and($message->fromVerified)->toBeFalse()
        ->and($message->from)->toBe('');
});

// Bug: a mail file passes from the mailbox into a rule write on a header the reader mistakes for a pass. The tests of
// the reader and of the conditions each pass alone, so only a file that goes the whole way shows it.
it('lets a rule write from a mail file only when the receiving server passed the supplier\'s domain', function (string $results, string $status, int $writes): void {
    config(['gateway.mailbox.authserv_id' => 'mx.vuoksi.example']);
    $odoo = FakeOdoo::start()->line();
    $body = "Dear Riku,\nwe confirm your order P00104:\n".Inbound::QUOTE_AS_ORDERED."\nBest regards";
    File::put($this->directory.'/a.eml', eml('conf-104@taiyuan.example', 'Thu, 05 Nov 2026 11:12:00 +0200', 'Order confirmation P00104', $body, authenticationResults: $results));

    $message = app(Mailbox::class)->messages()[0];
    $result = Inbound::service()->propose(Inbound::agent(), Inbound::input(['message_id' => $message->id]));

    expect($result->toArray()['status'])->toBe($status)
        ->and($odoo->writes)->toHaveCount($writes);
})->with([
    'DMARC passed for the supplier\'s domain' => ['mx.vuoksi.example; dmarc=pass header.from=taiyuan.example', 'applied', 1],
    'SPF passed for an envelope address that starts with the supplier\'s domain' => ['mx.vuoksi.example; spf=pass smtp.mailfrom=taiyuan.example=bounce@attacker.example; dkim=none; dmarc=none header.from=taiyuan.example', 'pending', 0],
    'DKIM passed for the sender\'s own domain' => ['mx.vuoksi.example; dkim=pass header.d=attacker.example; dmarc=none header.from=taiyuan.example', 'pending', 0],
]);

// Bug: with no receiving server configured, a header from any server verifies a sender.
it('verifies no sender when no receiving server id is configured', function (?string $configured): void {
    config(['gateway.mailbox.authserv_id' => $configured]);
    File::put($this->directory.'/a.eml', eml('a@example', 'Mon, 03 Nov 2026 09:00:00 +0200', 'Order confirmation', 'Body', authenticationResults: 'mx.vuoksi.example; dmarc=pass header.from=taiyuan.example'));

    $message = app(Mailbox::class)->messages()[0];

    expect($message->fromAddress)->toBe('sales@taiyuan.example')
        ->and($message->fromVerified)->toBeFalse();
})->with(['no id' => [null], 'an empty id' => ['']]);

// Bug: a sender verified under one receiving server id stays verified from the cache after the id changes.
it('reads a cached message again when the receiving server id changes', function (): void {
    File::put($this->directory.'/a.eml', eml('a@example', 'Mon, 03 Nov 2026 09:00:00 +0200', 'Order confirmation', 'Body', authenticationResults: 'mx.old.example; dmarc=pass header.from=taiyuan.example'));
    $underOldId = new FileMailbox($this->directory, app(PdfText::class), Cache::store(), 'mx.old.example');
    $underNewId = new FileMailbox($this->directory, app(PdfText::class), Cache::store(), 'mx.vuoksi.example');

    $verifiedBefore = $underOldId->messages()[0]->fromVerified;
    $verifiedAfter = $underNewId->messages()[0]->fromVerified;

    expect($verifiedBefore)->toBeTrue()
        ->and($verifiedAfter)->toBeFalse();
});
