<?php

declare(strict_types=1);

namespace App\Processes\InboundPurchasing\Rules;

use App\Gateway\Evidence;
use App\Gateway\Facts;
use App\Gateway\ProposedWrite;
use App\Gateway\Rule;
use App\Processes\InboundPurchasing\InboundPurchasing;
use App\Processes\InboundPurchasing\LineFacts;
use App\Processes\InboundPurchasing\SharedConditions;

/**
 * The supplier moves a line's planned date by less than the cap from the
 * requested date, and no production waits on the line.
 */
final class PlannedDateUnderCap implements Rule
{
    public function name(): string
    {
        return 'planned-date-under-cap';
    }

    public function applies(ProposedWrite $write): bool
    {
        return $write->model === 'purchase.order.line' && $write->has('date_planned');
    }

    /**
     * @param  LineFacts  $facts
     */
    public function failures(ProposedWrite $write, Facts $facts, Evidence $evidence): array
    {
        $failures = SharedConditions::failures($write, $facts, $evidence);
        $planned = (string) $write->values['date_planned'];
        $cap = InboundPurchasing::SLIP_CAP_DAYS;

        if ($facts->requestedDate === null) {
            $failures[] = 'the line has no requested date to count the slip from';
        } elseif (($slip = LineFacts::days($facts->requestedDate, $planned)) >= $cap) {
            $failures[] = "the slip of {$slip} days from the requested date is at or over the {$cap}-day cap";
        }

        $waiting = count($facts->waiting);

        if ($waiting > 0) {
            $failures[] = $waiting === 1 ? '1 manufacturing order waits on the line' : "{$waiting} manufacturing orders wait on the line";
        }

        if ($write->has('x_confirmed_date') && $write->values['x_confirmed_date'] !== $planned) {
            $failures[] = 'the confirmed date is not the new planned date';
        }

        return $failures;
    }

    /**
     * @param  LineFacts  $facts
     */
    public function passReason(ProposedWrite $write, Facts $facts): string
    {
        return "the new planned date {$write->values['date_planned']} is under the ".InboundPurchasing::SLIP_CAP_DAYS
            ."-day cap from the requested date {$facts->requestedDate}, and no manufacturing order waits on the line";
    }
}
