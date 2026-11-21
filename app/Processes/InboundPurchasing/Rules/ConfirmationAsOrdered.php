<?php

declare(strict_types=1);

namespace App\Processes\InboundPurchasing\Rules;

use App\Gateway\Evidence;
use App\Gateway\Facts;
use App\Gateway\ProposedWrite;
use App\Gateway\Rule;
use App\Processes\InboundPurchasing\LineFacts;
use App\Processes\InboundPurchasing\SharedConditions;

/**
 * The supplier confirms a line as ordered, with the full quantity for the
 * planned date. Recording it moves no date, so waiting manufacturing orders
 * do not stop it.
 */
final class ConfirmationAsOrdered implements Rule
{
    public function name(): string
    {
        return 'confirmation-as-ordered';
    }

    public function applies(ProposedWrite $write): bool
    {
        return $write->model === 'purchase.order.line' && ! $write->has('date_planned');
    }

    /**
     * @param  LineFacts  $facts
     */
    public function failures(ProposedWrite $write, Facts $facts, Evidence $evidence): array
    {
        $failures = SharedConditions::failures($write, $facts, $evidence);

        if (! $write->has('x_confirmed_qty') || ! $write->has('x_confirmed_date')) {
            $failures[] = 'a confirmation as ordered records both the confirmed quantity and the confirmed date';
        } elseif ($write->values['x_confirmed_date'] !== $facts->plannedDate) {
            $failures[] = "the confirmed date {$write->values['x_confirmed_date']} is not the planned date ".($facts->plannedDate ?? 'none');
        }

        return $failures;
    }

    /**
     * @param  LineFacts  $facts
     */
    public function passReason(ProposedWrite $write, Facts $facts): string
    {
        return 'the supplier confirms the '.LineFacts::quantity($facts->orderedQty)." ordered for the planned date {$facts->plannedDate}";
    }
}
