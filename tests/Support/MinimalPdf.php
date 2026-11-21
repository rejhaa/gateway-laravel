<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Builds a small PDF with a text layer, so a test needs no fixture file.
 * Each page is a list of lines set in Helvetica. A page with no lines has no
 * text at all, as a scan would.
 */
final class MinimalPdf
{
    /**
     * @param  list<list<string>>  $pages
     */
    public static function fromPages(array $pages): string
    {
        $fontId = 3 + 2 * count($pages);
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids ['.implode(' ', array_map(
                fn (int $index): string => (3 + 2 * $index).' 0 R',
                array_keys($pages),
            )).'] /Count '.count($pages).' >>',
        ];

        foreach ($pages as $index => $lines) {
            $pageId = 3 + 2 * $index;
            $stream = self::contentStream($lines);
            $objects[$pageId] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 {$fontId} 0 R >> >> /Contents ".($pageId + 1).' 0 R >>';
            $objects[$pageId + 1] = '<< /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream";
        }

        $objects[$fontId] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        ksort($objects);

        $pdf = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= "{$id} 0 obj\n{$body}\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= 'xref'."\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";

        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf.'trailer << /Size '.(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }

    /**
     * @param  list<string>  $lines
     */
    private static function contentStream(array $lines): string
    {
        if ($lines === []) {
            return '';
        }

        $text = array_map(
            fn (string $line): string => '('.strtr(mb_convert_encoding($line, 'Windows-1252', 'UTF-8'), ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']).') Tj T*',
            $lines,
        );

        return "BT /F1 11 Tf 14 TL 50 800 Td\n".implode("\n", $text)."\nET";
    }
}
