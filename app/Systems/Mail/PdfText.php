<?php

declare(strict_types=1);

namespace App\Systems\Mail;

use Illuminate\Process\Exceptions\ProcessFailedException;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;

/**
 * Reads the text layer of a PDF with pdftotext and numbers its lines.
 *
 * Pages are split on the form feed pdftotext writes after each page. Runs of
 * spaces collapse to one, empty lines are dropped, and the rest is numbered
 * p<page>.l<line> from 1. A document with fewer than 20 characters of text per
 * page has no text layer, so it gets no lines.
 */
final class PdfText
{
    public const MIN_CHARACTERS_PER_PAGE = 20;

    private const TIMEOUT_SECONDS = 30;

    public function attachment(string $name, string $pdf): Attachment
    {
        try {
            $text = Process::timeout(self::TIMEOUT_SECONDS)
                ->input($pdf)
                ->run(['pdftotext', '-layout', '-enc', 'UTF-8', '-', '-'])
                ->throw()
                ->output();
        } catch (ProcessFailedException|ProcessTimedOutException $e) {
            report($e);

            return new Attachment($name, 'application/pdf', false);
        }

        $pages = explode("\f", $text);

        if (count($pages) > 1 && trim(end($pages)) === '') {
            array_pop($pages);
        }

        $characters = mb_strlen((string) preg_replace('/\s+/u', '', $text));

        if ($characters < self::MIN_CHARACTERS_PER_PAGE * count($pages)) {
            return new Attachment($name, 'application/pdf', false);
        }

        return new Attachment($name, 'application/pdf', true, self::numberLines($pages));
    }

    /**
     * Numbers the non-empty lines of each page as p<page>.l<line>, after
     * collapsing runs of spaces and tabs to one space.
     *
     * @param  list<string>  $pages
     * @return array<string, string>
     */
    public static function numberLines(array $pages): array
    {
        $lines = [];

        foreach ($pages as $pageIndex => $page) {
            $lineNumber = 0;

            foreach (preg_split('/\R/u', $page) ?: [] as $line) {
                $line = trim((string) preg_replace('/[ \t]+/u', ' ', $line));

                if ($line !== '') {
                    $lineNumber++;
                    $lines['p'.($pageIndex + 1).'.l'.$lineNumber] = $line;
                }
            }
        }

        return $lines;
    }
}
