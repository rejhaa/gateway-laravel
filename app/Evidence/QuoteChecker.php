<?php

declare(strict_types=1);

namespace App\Evidence;

use Normalizer;

/**
 * Checks a quoted passage against the text it was copied from and against the
 * values it is said to state (DESIGN.md, section 6, "Evidence").
 *
 * The class is pure. It reads nothing and keeps no state, so the caller passes
 * in the text and the values.
 */
final class QuoteChecker
{
    private const MONTHS = 'jan(?:uary)?|feb(?:ruary)?|mar(?:ch)?|apr(?:il)?|may|june?|july?|aug(?:ust)?|sep(?:t(?:ember)?)?|oct(?:ober)?|nov(?:ember)?|dec(?:ember)?';

    /**
     * Date patterns, matched against folded text in this order. Each match is
     * removed before the next pattern runs, so one date is read only once.
     */
    private const DATE_PATTERNS = [
        // 2026-12-14
        '/(?<!\d)(?<year>\d{4})-(?<month>\d{1,2})-(?<day>\d{1,2})(?!\d)/u',
        // 29.11.2026, 03/04/2026, 6-11-2026
        '/(?<!\d)(?<first>\d{1,2})(?<separator>[.\/-])(?<second>\d{1,2})\k<separator>(?<year>\d{4})(?!\d)/u',
        // 14 December 2026, 14th of Dec, 2026, 14-Dec-2026, and 14 December without a year
        '/(?<![\p{L}\p{N}])(?<day>\d{1,2})(?!\d)(?:st|nd|rd|th)?\.?[\s-]*(?:of\s+)?(?<month>'.self::MONTHS.')\b\.?,?[\s-]*(?<year>\d{4})?(?!\d)/u',
        // December 14, 2026, and December 14 without a year
        '/\b(?<month>'.self::MONTHS.')\b\.?[\s-]*(?<day>\d{1,2})(?!\d)(?:st|nd|rd|th)?,?\s*(?<year>\d{4})?(?!\d)/u',
    ];

    /**
     * A number with optional comma or point marks. A number that is part of a
     * word or of a hyphenated code, such as P00104 or TYF-OC-26-0918, does not count.
     */
    private const NUMBER = '/(?<![\p{L}\p{N}-])\d++(?:[.,]\d++)*+(?!-[\p{L}\p{N}])/u';

    /**
     * Finds the quote in the lines of one text, such as a message body or one
     * attachment. A quote may cross a line break.
     *
     * @param  array<int|string, string>  $lines  the text's lines, keyed by their location, such as `p1.l17`, or a plain list
     * @return int|string|null the key of the line where the quote starts, or null when it is not there
     */
    public function locate(string $quote, array $lines): int|string|null
    {
        $needle = $this->normalize($quote);

        if ($needle === '') {
            return null;
        }

        $joined = '';
        $lineStarts = [];

        foreach ($lines as $location => $line) {
            $lineStarts[$location] = strlen($joined);
            $joined .= $this->normalize($line);
        }

        $offset = strpos($joined, $needle);

        if ($offset === false) {
            return null;
        }

        // The match starts in the last line that starts at or before it. Taking
        // the last one skips lines that normalize to nothing.
        $found = null;

        foreach ($lineStarts as $location => $start) {
            if ($start > $offset) {
                break;
            }

            $found = $location;
        }

        return $found;
    }

    /**
     * Whether the quote contains one of the product's names. A name shorter
     * than 3 characters is ignored.
     *
     * @param  list<string>  $productNames
     */
    public function namesProduct(string $quote, array $productNames): bool
    {
        $quote = $this->normalize($quote);

        foreach ($productNames as $name) {
            $name = $this->normalize($name);

            if (mb_strlen($name) >= 3 && str_contains($quote, $name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether one of the numbers in the quote equals the quantity. A comma or
     * point in a number is read both as a decimal mark and as a thousands mark.
     * Numbers inside dates are not read.
     */
    public function statesQuantity(string $quote, int|float $quantity): bool
    {
        $text = preg_replace(self::DATE_PATTERNS, ' ', $this->fold($quote));

        preg_match_all(self::NUMBER, $text, $matches);

        foreach ($matches[0] as $number) {
            if (in_array((float) $quantity, $this->readings($number), true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the quote states the date unambiguously.
     *
     * @param  string  $date  YYYY-MM-DD
     */
    public function statesDate(string $quote, string $date): bool
    {
        $dates = [];

        preg_replace_callback(self::DATE_PATTERNS, function (array $match) use (&$dates): string {
            $dates[] = $this->dateFrom($match);

            return ' ';
        }, $this->fold($quote));

        return in_array($date, $dates, true);
    }

    /**
     * Whether the text names the order by one of its references, the order's
     * name or the supplier's reference, as a whole token. A reference shorter
     * than 4 characters is ignored.
     *
     * @param  list<string>  $references
     */
    public function namesOrder(string $text, array $references): bool
    {
        $text = $this->fold($text);

        foreach ($references as $reference) {
            $reference = $this->fold(trim($reference));

            if (mb_strlen($reference) < 4) {
                continue;
            }

            $token = '/(?<![\p{L}\p{N}])'.preg_quote($reference, '/').'(?![\p{L}\p{N}])/u';

            if (preg_match($token, $text) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * NFKC, lowercase, every dash as `-` and curly quotes as straight ones.
     * Whitespace stays, so numbers and words keep their boundaries.
     */
    private function fold(string $text): string
    {
        $text = (string) Normalizer::normalize(mb_scrub($text, 'UTF-8'), Normalizer::FORM_KC);
        $text = mb_strtolower($text, 'UTF-8');
        $text = preg_replace('/[\p{Pd}\x{2212}]/u', '-', $text);

        return strtr($text, [
            "\u{2018}" => "'", "\u{2019}" => "'", "\u{201A}" => "'", "\u{201B}" => "'",
            "\u{201C}" => '"', "\u{201D}" => '"', "\u{201E}" => '"', "\u{201F}" => '"',
        ]);
    }

    /** The folded text with all whitespace removed, the form quotes are compared in. */
    private function normalize(string $text): string
    {
        return preg_replace('/\s+/u', '', $this->fold($text));
    }

    /**
     * Every value a number can be read as. A reading with thousands marks
     * needs groups of three digits after each of them.
     *
     * @return list<float>
     */
    private function readings(string $number): array
    {
        $groups = preg_split('/[.,]/', $number);
        $readings = [];

        if ($this->groupedByThousands($groups)) {
            $readings[] = (float) implode('', $groups);
        }

        if (count($groups) > 1) {
            $decimals = array_pop($groups);

            if ($this->groupedByThousands($groups)) {
                $readings[] = (float) (implode('', $groups).'.'.$decimals);
            }
        }

        return $readings;
    }

    /** @param  list<string>  $groups */
    private function groupedByThousands(array $groups): bool
    {
        foreach (array_slice($groups, 1) as $group) {
            if (strlen($group) !== 3) {
                return false;
            }
        }

        return true;
    }

    /**
     * The date a pattern match states, as YYYY-MM-DD, or null when it does not
     * state one unambiguously.
     *
     * @param  array<int|string, string>  $match
     */
    private function dateFrom(array $match): ?string
    {
        if (($match['year'] ?? '') === '') {
            return null;
        }

        $year = (int) $match['year'];

        if (isset($match['first'])) {
            $first = (int) $match['first'];
            $second = (int) $match['second'];

            // Both parts could be the month, so day-first and month-first readings differ.
            if ($first <= 12 && $second <= 12 && $first !== $second) {
                return null;
            }

            [$day, $month] = $first > 12 ? [$first, $second] : [$second, $first];
        } else {
            $day = (int) $match['day'];
            $month = ctype_digit($match['month'])
                ? (int) $match['month']
                : $this->monthNumber($match['month']);
        }

        return checkdate($month, $day, $year) ? sprintf('%04d-%02d-%02d', $year, $month, $day) : null;
    }

    private function monthNumber(string $name): int
    {
        $months = ['jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec'];

        return (int) array_search(substr($name, 0, 3), $months, true) + 1;
    }
}
