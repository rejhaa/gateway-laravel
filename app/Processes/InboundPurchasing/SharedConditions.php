<?php

declare(strict_types=1);

namespace App\Processes\InboundPurchasing;

use App\Evidence\QuoteChecker;
use App\Evidence\SenderChecker;
use App\Gateway\Evidence;
use App\Gateway\ProposedWrite;
use App\Systems\Mail\Message;

/**
 * The conditions both rules apply to a write on one purchase order line
 * (rules/approval-rules.md, "Conditions both rules share"). Each failed
 * condition yields its phrase.
 *
 * The rule tier switch is not here. The gateway checks it before it tries
 * any rule, so its phrase is shown alone.
 */
final class SharedConditions
{
    /** The only fields a rule writes on a line. */
    public const FIELDS = ['date_planned', 'x_confirmed_qty', 'x_confirmed_date'];

    /**
     * @return list<string>
     */
    public static function failures(ProposedWrite $write, LineFacts $facts, Evidence $evidence): array
    {
        $checker = new QuoteChecker;
        $failures = [];

        if ($facts->orderState !== 'purchase') {
            $failures[] = 'the order is not a confirmed purchase order';
        }

        if (array_diff($write->fields(), self::FIELDS) !== []) {
            $failures[] = 'the change is more than the planned date and the confirmed quantity and date';
        }

        if ($evidence->found) {
            $namesProduct = $checker->namesProduct($evidence->quote, $facts->productNames);

            foreach (array_intersect_key($write->values, array_flip(self::FIELDS)) as $field => $value) {
                $stated = $field === 'x_confirmed_qty'
                    ? $checker->statesQuantity($evidence->quote, $value)
                    : $checker->statesDate($evidence->quote, $value);

                if (! $namesProduct || ! $stated) {
                    $failures[] = "no quoted passage that names {$facts->product()} states the "
                        .lcfirst(Effect::LABELS[$field]).' '.self::value($value).' unambiguously';
                }
            }
        } else {
            $failures[] = 'the document has no text layer, so the quote was not checked';
        }

        $sender = self::senderFailure($facts, $evidence->message);

        if ($sender !== null) {
            $failures[] = $sender;
        }

        $references = array_values(array_filter([$facts->orderName, $facts->partnerRef]));

        if (! $checker->namesOrder(self::text($evidence->message), $references)) {
            $failures[] = "the message does not name the order {$facts->orderName}"
                .($facts->partnerRef === null ? '' : " or the supplier's reference {$facts->partnerRef}");
        }

        $later = array_filter($evidence->laterMessages, fn (Message $message): bool => $checker->namesOrder(self::text($message), $references));

        if ($later !== []) {
            $failures[] = 'a later message ('.implode(', ', array_map(fn (Message $message): string => $message->subject, $later)).') also names the order';
        }

        if ($evidence->appliedByRule !== null) {
            $failures[] = "the same message was already applied to the line by rule in proposal {$evidence->appliedByRule}";
        }

        if ($write->has('x_confirmed_qty') && (float) $write->values['x_confirmed_qty'] !== $facts->orderedQty) {
            $failures[] = 'the confirmed quantity '.self::value($write->values['x_confirmed_qty'])
                .' is not the '.LineFacts::quantity($facts->orderedQty).' ordered';
        }

        return $failures;
    }

    /**
     * The cited message has to come from the order's supplier. Its one sender
     * address has to belong to the supplier's company in Odoo, and the
     * receiving mail server has to have verified it, because the sender
     * writes the From header. A forwarder or a colleague who passes a
     * document on is not the supplier, so such a message waits for a person.
     */
    private static function senderFailure(LineFacts $facts, Message $message): ?string
    {
        if ($message->fromAddress === null) {
            return 'the message has no single sender address';
        }

        if ($facts->supplierAddresses === []) {
            return "{$facts->supplierName} has no email address in Odoo to check the sender against";
        }

        if (! (new SenderChecker)->belongsTo($message->fromAddress, $facts->supplierAddresses)) {
            return "the sender {$message->fromAddress} is not an address or a domain of {$facts->supplierName} in Odoo";
        }

        if (! $message->fromVerified) {
            return "the mail server did not verify that the message comes from {$message->fromAddress}";
        }

        return null;
    }

    /** The subject, the body and every attachment line, where a message may name the order. */
    private static function text(Message $message): string
    {
        $lines = [$message->subject, $message->body];

        foreach ($message->attachments as $attachment) {
            array_push($lines, ...array_values($attachment->lines));
        }

        return implode("\n", $lines);
    }

    private static function value(mixed $value): string
    {
        return is_int($value) || is_float($value) ? LineFacts::quantity($value) : (string) $value;
    }
}
