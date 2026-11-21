<?php

declare(strict_types=1);

namespace App\Processes\InboundPurchasing;

use App\Evidence\QuoteChecker;
use App\Gateway\Evidence;
use App\Gateway\ProposalStatus;
use App\Models\Proposal;

/**
 * What a proposal changes in Odoo, as the rows of its block (DESIGN.md,
 * section 8, "A proposal block"). It returns plain data, so the Blade
 * component and the card's JSON draw the same words.
 *
 * The kind is drawn muted before the record. It is null when the record line
 * names the kind itself, as a bill's and a denied proposal's do. Every row has
 * a label, the value now, the value after, a muted note after that value, and
 * a warning drawn on a full-width line of its own. A denied proposal was never
 * read from Odoo, so its rows have no value now and its block shows the
 * proposed values without the Now and After table.
 */
final class Effect
{
    /** Row labels, in the order the rows are drawn. */
    public const LABELS = [
        'date_planned' => 'Planned date',
        'x_confirmed_date' => 'Confirmed date',
        'x_confirmed_qty' => 'Confirmed quantity',
        'x_dispute' => 'Dispute',
        'x_dispute_note' => 'Dispute note',
    ];

    /** The kind drawn muted before the record. A bill's record line names its kind itself. */
    public const KINDS = [
        'purchase.order.line' => 'Purchase order line',
    ];

    public const DISPUTE_NOTICE = 'A disputed bill is not paid until a credit note or a confirmed delivery closes the dispute. Only finance lifts it (POL-4).';

    public const NOT_IN_QUOTE = 'Not in the quoted passage. Check the document.';

    public const NOT_CHECKED = 'From a scan without text. Not checked.';

    /**
     * @return array{
     *     kind: string|null,
     *     record: string,
     *     rows: list<array{label: string, now: string|null, after: string, note: string|null, warning: string|null}>,
     *     notice: string|null,
     * }
     */
    public function of(Proposal $proposal): array
    {
        $values = self::ordered($proposal->values);
        $rows = [];

        if ($proposal->status === ProposalStatus::Denied || $proposal->facts === null) {
            foreach ($values as $field => $value) {
                $rows[] = self::row(self::LABELS[$field] ?? $field, null, self::show($value));
            }

            return ['kind' => null, 'record' => $proposal->record_label, 'rows' => $rows, 'notice' => null];
        }

        $before = $proposal->before ?? [];

        if ($proposal->model === 'purchase.order.line') {
            $rows = $this->lineRows($proposal, $values, $before, LineFacts::fromArray($proposal->facts));
        } else {
            foreach ($values as $field => $value) {
                $rows[] = self::row(self::LABELS[$field] ?? $field, self::show($before[$field] ?? null), self::show($value));
            }
        }

        $rows = self::withSource($rows, $proposal);

        return [
            'kind' => self::KINDS[$proposal->model] ?? null,
            'record' => $proposal->record_label,
            'rows' => $rows,
            'notice' => $proposal->model === 'account.move' ? self::DISPUTE_NOTICE : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $before
     * @return list<array{label: string, now: string|null, after: string, note: string|null, warning: string|null}>
     */
    private function lineRows(Proposal $proposal, array $values, array $before, LineFacts $facts): array
    {
        $checker = new QuoteChecker;
        $namesProduct = $checker->namesProduct($proposal->quote, $facts->productNames);
        $rows = [];

        foreach ($values as $field => $value) {
            $note = match ($field) {
                'date_planned' => self::slipNote($facts->requestedDate, (string) $value),
                'x_confirmed_qty' => self::quantityNote((float) $value, $facts->orderedQty),
                default => null,
            };

            $stated = match ($field) {
                'x_confirmed_qty' => $checker->statesQuantity($proposal->quote, $value),
                default => $checker->statesDate($proposal->quote, (string) $value),
            };

            $warning = match (true) {
                $proposal->quote_status !== 'found' => self::NOT_CHECKED,
                ! $namesProduct || ! $stated => self::NOT_IN_QUOTE,
                default => null,
            };

            $rows[] = self::row(self::LABELS[$field], self::show($before[$field] ?? null), self::show($value), $note, $warning);
        }

        $planned = $values['date_planned'] ?? null;

        foreach ($facts->waiting as $production) {
            $rows[] = self::row(
                "{$production['name']} needs ".LineFacts::quantity($production['need']),
                $production['start'] === null ? 'no start date' : "starts {$production['start']}",
                self::arrival($production['start'], $planned),
            );
        }

        return $rows;
    }

    /** ", 14 days after the requested 2026-11-30", or ", the requested date" when there is no slip. */
    private static function slipNote(?string $requested, string $planned): ?string
    {
        if ($requested === null) {
            return null;
        }

        $slip = LineFacts::days($requested, $planned);

        return match (true) {
            $slip === 0 => ', the requested date',
            $slip > 0 => ', '.self::days($slip)." after the requested {$requested}",
            default => ', '.self::days($slip)." before the requested {$requested}",
        };
    }

    /** ", 20 of 120 stay unconfirmed", or ", 5 above the 120 ordered". */
    private static function quantityNote(float $confirmed, float $ordered): ?string
    {
        return match (true) {
            $confirmed < $ordered => ', '.LineFacts::quantity($ordered - $confirmed).' of '.LineFacts::quantity($ordered).' stay unconfirmed',
            $confirmed > $ordered => ', '.LineFacts::quantity($confirmed - $ordered).' above the '.LineFacts::quantity($ordered).' ordered',
            default => null,
        };
    }

    /** When the line arrives against a manufacturing order's start. */
    private static function arrival(?string $start, mixed $planned): string
    {
        if (! is_string($planned)) {
            return 'planned date unchanged';
        }

        if ($start === null) {
            return 'its start is not set';
        }

        $gap = LineFacts::days($start, $planned);

        return match (true) {
            $gap === 0 => 'line arrives on its start',
            $gap > 0 => 'line arrives '.self::days($gap).' after its start',
            default => 'line arrives '.self::days($gap).' before its start',
        };
    }

    /**
     * The source note " in {document}, {location}" goes once, on the first row.
     *
     * @param  list<array{label: string, now: string|null, after: string, note: string|null, warning: string|null}>  $rows
     * @return list<array{label: string, now: string|null, after: string, note: string|null, warning: string|null}>
     */
    private static function withSource(array $rows, Proposal $proposal): array
    {
        if ($rows === [] || $proposal->quote_status !== 'found') {
            return $rows;
        }

        $document = $proposal->quote_source === Evidence::BODY ? 'the mail body' : $proposal->quote_source;
        $rows[0]['note'] = ($rows[0]['note'] ?? '')." in {$document}, {$proposal->quote_location}";

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private static function ordered(array $values): array
    {
        $order = array_flip(array_keys(self::LABELS));
        uksort($values, fn (string $a, string $b): int => ($order[$a] ?? PHP_INT_MAX) <=> ($order[$b] ?? PHP_INT_MAX));

        return $values;
    }

    /**
     * @return array{label: string, now: string|null, after: string, note: string|null, warning: string|null}
     */
    private static function row(string $label, ?string $now, string $after, ?string $note = null, ?string $warning = null): array
    {
        return ['label' => $label, 'now' => $now, 'after' => $after, 'note' => $note, 'warning' => $warning];
    }

    private static function show(mixed $value): string
    {
        return match (true) {
            $value === null || $value === false => 'none',
            is_int($value) || is_float($value) => LineFacts::quantity($value),
            default => (string) $value,
        };
    }

    private static function days(int $days): string
    {
        $days = abs($days);

        return $days === 1 ? '1 day' : "{$days} days";
    }
}
