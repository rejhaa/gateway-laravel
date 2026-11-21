<?php

declare(strict_types=1);

namespace App\Processes\InboundPurchasing;

use App\Gateway\Actor;
use App\Gateway\InputError;
use App\Systems\Odoo\Exceptions\OdooException;
use App\Systems\Odoo\OdooClient;

/**
 * Reads the facts about a vendor bill from Odoo as the actor.
 */
final class BillFactsReader
{
    private const FIELDS = ['name', 'ref', 'state', 'partner_id', 'amount_untaxed', 'currency_id', 'x_dispute', 'x_dispute_note'];

    public function __construct(private readonly OdooClient $odoo) {}

    /**
     * @throws InputError when the bill does not exist
     * @throws OdooException
     */
    public function read(Actor $actor, int $billId): BillFacts
    {
        $bill = $this->odoo->searchRead($actor, 'account.move', [['id', '=', $billId]], self::FIELDS, 1)[0]
            ?? throw new InputError("There is no account.move with id {$billId} in Odoo.");

        return new BillFacts(
            billId: $billId,
            name: (string) $bill['name'],
            ref: self::text($bill['ref']),
            state: (string) $bill['state'],
            supplier: is_array($bill['partner_id']) ? (string) $bill['partner_id'][1] : '',
            amountUntaxed: (float) $bill['amount_untaxed'],
            currency: is_array($bill['currency_id']) ? (string) $bill['currency_id'][1] : '',
            dispute: self::text($bill['x_dispute']),
            disputeNote: self::text($bill['x_dispute_note']),
        );
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
