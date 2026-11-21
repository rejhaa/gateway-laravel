<?php

declare(strict_types=1);

use App\Systems\Mail\PdfText;
use Illuminate\Process\Exceptions\ProcessFailedException;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\ExecutableFinder;
use Tests\Support\MinimalPdf;

$withoutPdftotext = fn (): bool => (new ExecutableFinder)->find('pdftotext') === null;

// Bug: the agent quotes a location the checker cannot find, because pages or lines are counted differently.
it('numbers non-empty lines per page with runs of spaces collapsed', function (): void {
    $pdf = MinimalPdf::fromPages([
        ['Order confirmation P00104', 'VG-HEAD-V1      Forged head      100 pcs   14.12.2026'],
        [],
        ['Delivery terms: FCA Taichung (port)'],
    ]);

    $attachment = app(PdfText::class)->attachment('confirmation.pdf', $pdf);

    expect($attachment->hasTextLayer)->toBeTrue()
        ->and($attachment->contentType)->toBe('application/pdf')
        ->and($attachment->lines)->toBe([
            'p1.l1' => 'Order confirmation P00104',
            'p1.l2' => 'VG-HEAD-V1 Forged head 100 pcs 14.12.2026',
            'p3.l1' => 'Delivery terms: FCA Taichung (port)',
        ]);
})->skip($withoutPdftotext, 'pdftotext is not installed');

// Bug: a scan with a stray stamp of text is read as a document, so its values look quoted.
it('treats a document with fewer than 20 characters per page as having no text layer', function (): void {
    $pdf = MinimalPdf::fromPages([['Scanned 05.11.2026'], []]);

    $attachment = app(PdfText::class)->attachment('scan.pdf', $pdf);

    expect($attachment->hasTextLayer)->toBeFalse()
        ->and($attachment->lines)->toBe([]);
})->skip($withoutPdftotext, 'pdftotext is not installed');

// Bug: a missing or failing pdftotext crashes the mailbox, or loses the text with nothing in the log.
it('reports a failed pdftotext run and returns the attachment without a text layer', function (): void {
    Exceptions::fake();
    Process::fake(['pdftotext *' => Process::result(errorOutput: 'pdftotext: not found', exitCode: 127)]);

    $attachment = app(PdfText::class)->attachment('confirmation.pdf', '%PDF-1.4');

    expect($attachment->name)->toBe('confirmation.pdf')
        ->and($attachment->hasTextLayer)->toBeFalse()
        ->and($attachment->lines)->toBe([]);
    Exceptions::assertReported(ProcessFailedException::class);
});
