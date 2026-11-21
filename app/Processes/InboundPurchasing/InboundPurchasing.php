<?php

declare(strict_types=1);

namespace App\Processes\InboundPurchasing;

use App\Gateway\Actor;
use App\Gateway\Decision;
use App\Gateway\Facts;
use App\Gateway\InputError;
use App\Gateway\Process;
use App\Gateway\ProposedWrite;
use App\Models\Proposal;
use App\Processes\InboundPurchasing\Rules\ConfirmationAsOrdered;
use App\Processes\InboundPurchasing\Rules\PlannedDateUnderCap;
use App\Systems\Odoo\OdooClient;
use LogicException;

/**
 * The inbound purchasing process. The agent works through the purchasing
 * mailbox and proposes changes to purchase order lines and vendor bills.
 *
 * Everything the process allows, denies and applies by rule is in this file.
 * rules/agent-access.md and rules/approval-rules.md say the same in words, and
 * tests/Feature/AccessTest.php keeps the access tables equal to the constants.
 *
 * To add a rule, write a class under Rules/ and add it to rules().
 */
final class InboundPurchasing implements Process
{
    /**
     * What the agent may read. Each model maps its fields to the model a
     * dotted path may continue into, or to null where a path stops.
     */
    public const READABLE = [
        'purchase.order' => [
            'name' => null, 'partner_id' => 'res.partner', 'partner_ref' => null, 'state' => null,
            'date_order' => null, 'date_planned' => null, 'picking_type_id' => 'stock.picking.type',
            'order_line' => 'purchase.order.line', 'user_id' => 'res.users', 'origin' => null,
            'currency_id' => null, 'amount_untaxed' => null, 'invoice_ids' => 'account.move',
            'picking_ids' => 'stock.picking', 'receipt_status' => null,
        ],
        'purchase.order.line' => [
            'order_id' => 'purchase.order', 'partner_id' => 'res.partner', 'sequence' => null, 'name' => null,
            'product_id' => 'product.product', 'product_qty' => null, 'uom_id' => null, 'price_unit' => null,
            'currency_id' => null, 'price_subtotal' => null, 'state' => null, 'date_planned' => null,
            'date_promised' => null, 'x_confirmed_qty' => null, 'x_confirmed_date' => null,
            'qty_received' => null, 'qty_invoiced' => null, 'move_ids' => 'stock.move',
            'move_dest_ids' => 'stock.move', 'invoice_lines' => 'account.move.line', 'display_type' => null,
        ],
        'stock.picking' => [
            'name' => null, 'state' => null, 'picking_type_id' => 'stock.picking.type', 'picking_type_code' => null,
            'partner_id' => 'res.partner', 'origin' => null, 'purchase_id' => 'purchase.order',
            'scheduled_date' => null, 'date_done' => null, 'backorder_id' => 'stock.picking',
            'backorder_ids' => 'stock.picking', 'move_ids' => 'stock.move',
        ],
        'stock.move' => [
            'reference' => null, 'product_id' => 'product.product', 'product_uom_qty' => null, 'quantity' => null,
            'picked' => null, 'state' => null, 'picking_id' => 'stock.picking',
            'purchase_line_id' => 'purchase.order.line', 'created_purchase_line_ids' => 'purchase.order.line',
            'raw_material_production_id' => 'mrp.production', 'move_orig_ids' => 'stock.move',
            'move_dest_ids' => 'stock.move', 'procure_method' => null, 'date' => null, 'date_deadline' => null,
            'location_id' => null, 'location_dest_id' => null,
        ],
        'mrp.production' => [
            'name' => null, 'state' => null, 'product_id' => 'product.product', 'product_qty' => null,
            'date_start' => null, 'date_finished' => null, 'move_raw_ids' => 'stock.move',
            'components_availability' => null, 'components_availability_state' => null, 'origin' => null,
            'user_id' => 'res.users', 'picking_type_id' => 'stock.picking.type',
        ],
        'account.move' => [
            'name' => null, 'ref' => null, 'state' => null, 'move_type' => null, 'partner_id' => 'res.partner',
            'invoice_date' => null, 'invoice_date_due' => null, 'invoice_origin' => null, 'amount_untaxed' => null,
            'amount_total' => null, 'amount_residual' => null, 'currency_id' => null, 'payment_state' => null,
            'invoice_line_ids' => 'account.move.line', 'x_dispute' => null, 'x_dispute_note' => null,
        ],
        'account.move.line' => [
            'move_id' => 'account.move', 'product_id' => 'product.product', 'name' => null, 'quantity' => null,
            'price_unit' => null, 'price_subtotal' => null, 'purchase_line_id' => 'purchase.order.line',
            'purchase_order_id' => 'purchase.order', 'display_type' => null,
        ],
        'product.product' => [
            'name' => null, 'default_code' => null, 'seller_ids' => 'product.supplierinfo',
            'standard_price' => null, 'is_storable' => null, 'bom_ids' => null,
        ],
        'product.supplierinfo' => [
            'partner_id' => 'res.partner', 'product_id' => 'product.product', 'product_tmpl_id' => null,
            'product_code' => null, 'product_name' => null, 'delay' => null, 'min_qty' => null, 'price' => null,
        ],
        'res.partner' => [
            'name' => null, 'is_company' => null, 'parent_id' => 'res.partner', 'child_ids' => 'res.partner',
            'email' => null, 'phone' => null, 'city' => null, 'country_id' => null, 'ref' => null,
        ],
        'res.users' => ['name' => null, 'login' => null, 'partner_id' => 'res.partner'],
        'stock.picking.type' => ['name' => null, 'code' => null, 'warehouse_id' => 'stock.warehouse'],
        'stock.warehouse' => ['name' => null, 'code' => null, 'partner_id' => 'res.partner'],
        'mail.activity' => [
            'res_model' => null, 'res_id' => null, 'res_name' => null, 'user_id' => 'res.users', 'summary' => null,
            'note' => null, 'date_deadline' => null, 'date_done' => null, 'active' => null, 'state' => null,
        ],
        'mail.message' => [
            'model' => null, 'res_id' => null, 'body' => null, 'date' => null, 'author_id' => 'res.partner',
            'message_type' => null, 'subtype_id' => null,
        ],
    ];

    /**
     * What a proposal may write. Each model maps its fields to the kind of
     * value they take, which is a date (YYYY-MM-DD), a quantity (a number),
     * text, or the list of the only values the field takes.
     */
    public const WRITABLE = [
        'purchase.order.line' => ['date_planned' => 'date', 'x_confirmed_qty' => 'quantity', 'x_confirmed_date' => 'date'],
        'account.move' => ['x_dispute' => ['disputed'], 'x_dispute_note' => 'text'],
    ];

    /** Fields an agent never writes, each mapped to the policy section that denies it. */
    public const NEVER = [
        'purchase.order.line' => [
            'product_qty' => 'POL-2', 'price_unit' => 'POL-2', 'product_id' => 'POL-2',
            'date_promised' => 'POL-1', 'x_requested_date' => 'POL-1',
            'state' => 'POL-6',
        ],
        'purchase.order' => ['state' => 'POL-6', 'order_line' => 'POL-2'],
        'stock.move' => ['quantity' => 'POL-3', 'picked' => 'POL-3', 'product_uom_qty' => 'POL-3'],
        'stock.move.line' => ['quantity' => 'POL-3', 'picked' => 'POL-3'],
        'mrp.production' => ['date_start' => 'POL-5', 'date_finished' => 'POL-5'],
    ];

    /** The sentence a denial quotes, per section of rules/purchasing-policy.md. */
    public const POLICY_SENTENCE = [
        'POL-1' => 'The requested date on a purchase order line is the date we asked for and never changes after the order is confirmed.',
        'POL-2' => 'The ordered quantity and the price on a confirmed purchase order change only by a buyer, through an amendment the supplier has signed.',
        'POL-3' => 'A receipt is validated for the quantity the warehouse counted and recorded, never for a quantity from a supplier document.',
        'POL-4' => 'Only finance lifts a dispute. Nothing is sent to a supplier or a customer by an agent.',
        'POL-5' => 'The production planner owns the start dates of manufacturing orders.',
        'POL-6' => 'Cancelling a purchase order or a line, changing an ordered quantity or a price on a confirmed order, validating a receipt for a quantity nobody counted, lifting a dispute, and sending anything to a supplier or a customer are never done by an agent, whoever asks.',
    ];

    /** Fields a proposal writes together or not at all. */
    public const TOGETHER = [
        ['x_confirmed_qty', 'x_confirmed_date'],
        ['x_dispute', 'x_dispute_note'],
    ];

    /** A planned date this many days or more after the requested date waits for a person. */
    public const SLIP_CAP_DAYS = 14;

    /** The reason a vendor bill dispute waits, since no rule applies to it. */
    public const DISPUTE_REASON = 'a dispute on a vendor bill is decided by a person (POL-4)';

    /** Odoo stores a planned date as a datetime in UTC. This time of day is the same calendar day in Helsinki. */
    private const PLANNED_TIME_UTC = '10:00:00';

    public function __construct(
        private readonly LineFactsReader $lines,
        private readonly BillFactsReader $bills,
        private readonly OdooClient $odoo,
    ) {}

    public function rules(): array
    {
        return [
            new ConfirmationAsOrdered,
            new PlannedDateUnderCap,
        ];
    }

    public function humanReason(ProposedWrite $write): string
    {
        return $write->model === 'account.move' ? self::DISPUTE_REASON : 'no rule covers this change';
    }

    public function denial(ProposedWrite $write): ?Decision
    {
        foreach ($write->fields() as $field) {
            $section = self::NEVER[$write->model][$field] ?? null;

            if ($section !== null) {
                return Decision::denied(self::POLICY_SENTENCE[$section], $section);
            }
        }

        if (! isset(self::WRITABLE[$write->model])) {
            return Decision::denied("the model {$write->model} is outside what the agent may write");
        }

        foreach ($write->fields() as $field) {
            if (! isset(self::WRITABLE[$write->model][$field])) {
                return Decision::denied("the field {$field} on {$write->model} is outside what the agent may write");
            }
        }

        foreach ($write->values as $field => $value) {
            $kind = self::WRITABLE[$write->model][$field];

            // The only field with a list of values is x_dispute, and only finance lifts a dispute.
            if (is_array($kind) && ! in_array($value, $kind, true)) {
                return Decision::denied(self::POLICY_SENTENCE['POL-4'], 'POL-4');
            }

            if (is_string($kind)) {
                self::checkKind($field, $kind, $value);
            }
        }

        return null;
    }

    public function prepare(ProposedWrite $write, Facts $facts): ProposedWrite
    {
        foreach (self::TOGETHER as $fields) {
            $missing = array_values(array_filter($fields, fn (string $field): bool => ! $write->has($field)));

            if ($missing !== [] && count($missing) < count($fields)) {
                throw new InputError(implode(' and ', $fields).' go in the same proposal. Add '.implode(' and ', $missing).'.');
            }
        }

        if ($facts instanceof LineFacts && $write->has('date_planned') && $write->values['date_planned'] === $facts->plannedDate) {
            $write = $write->without('date_planned');

            if ($write->values === []) {
                throw new InputError("The planned date is already {$facts->plannedDate} in Odoo, so this proposal changes nothing.");
            }
        }

        return $write;
    }

    public function facts(Actor $actor, ProposedWrite $write): Facts
    {
        return match ($write->model) {
            'purchase.order.line' => $this->lines->read($actor, $write->recordId),
            'account.move' => $this->bills->read($actor, $write->recordId),
            default => throw new LogicException("The process reads no facts for {$write->model}."),
        };
    }

    public function write(Actor $actor, ProposedWrite $write, int $proposalId): void
    {
        $values = $write->values;

        if (isset($values['date_planned'])) {
            $values['date_planned'] .= ' '.self::PLANNED_TIME_UTC;
        }

        $this->odoo->write($actor, $write->model, $write->recordId, $values, $proposalId);
    }

    public function name(): string
    {
        return 'inbound-purchasing';
    }

    public function system(): string
    {
        return 'odoo';
    }

    public function readable(): array
    {
        return self::READABLE;
    }

    public function writable(): array
    {
        return self::WRITABLE;
    }

    public function effect(Proposal $proposal): array
    {
        return (new Effect)->of($proposal);
    }

    public function policyDocuments(): array
    {
        $documents = [];

        foreach (['purchasing-policy.md', 'approval-rules.md', 'agent-access.md'] as $file) {
            $documents[$file] = (string) file_get_contents(base_path("rules/{$file}"));
        }

        return $documents;
    }

    private static function checkKind(string $field, string $kind, mixed $value): void
    {
        $valid = match ($kind) {
            'date' => is_string($value) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) === 1
                && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]),
            'quantity' => is_int($value) || is_float($value),
            'text' => is_string($value) && trim($value) !== '',
        };

        if (! $valid) {
            throw new InputError(match ($kind) {
                'date' => "{$field} must be a date written YYYY-MM-DD, such as 2026-12-14.",
                'quantity' => "{$field} must be a number, such as 100.",
                'text' => "{$field} must be text that is not empty.",
            });
        }
    }
}
