<?php

declare(strict_types=1);

use App\Systems\Mail\FileMailbox;
use App\Systems\Mail\Mailbox;
use App\Systems\Mail\Message;
use App\Systems\Mail\PdfText;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\ExecutableFinder;
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
 */
function eml(string $messageId, string $date, string $subject, string $body, array $attachments = []): string
{
    $headers = "From: =?UTF-8?Q?Tai-Yuan_Forge_&_Shaft?= <sales@taiyuan.example>\r\n"
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
