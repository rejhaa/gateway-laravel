<?php

declare(strict_types=1);

namespace App\Processes\InboundPurchasing;

use App\Gateway\Actor;
use App\Gateway\InputError;
use App\Systems\Odoo\Exceptions\OdooException;
use App\Systems\Odoo\OdooClient;
use Carbon\CarbonImmutable;

/**
 * Reads the facts about a purchase order line from Odoo as the actor. The
 * agent never supplies them.
 */
final class LineFactsReader
{
    /** The company's time zone. A datetime from Odoo is in UTC and is converted before its date is taken. */
    private const TIMEZONE = 'Europe/Helsinki';

    private const LINE_FIELDS = ['order_id', 'product_id', 'product_qty', 'date_planned', 'date_promised', 'x_confirmed_qty', 'x_confirmed_date', 'move_dest_ids'];

    public function __construct(
        private readonly OdooClient $odoo,
        private readonly SupplierReader $suppliers,
    ) {}

    /**
     * @throws InputError when the line does not exist
     * @throws OdooException
     */
    public function read(Actor $actor, int $lineId): LineFacts
    {
        $line = $this->odoo->searchRead($actor, 'purchase.order.line', [['id', '=', $lineId]], self::LINE_FIELDS, 1)[0]
            ?? throw new InputError("There is no purchase.order.line with id {$lineId} in Odoo.");

        $productId = self::id($line['product_id'])
            ?? throw new InputError("The purchase.order.line {$lineId} has no product, so it is a section or a note and not a line to change.");
        $order = $this->odoo->read($actor, 'purchase.order', [self::id($line['order_id'])], ['name', 'partner_ref', 'partner_id', 'state'])[0];
        $product = $this->odoo->read($actor, 'product.product', [$productId], ['default_code', 'name', 'seller_ids'])[0];
        $supplierId = self::id($order['partner_id']);
        $supplier = $supplierId === null ? ['name' => '', 'domains' => []] : $this->suppliers->read($actor, $supplierId);

        return new LineFacts(
            lineId: $lineId,
            orderName: (string) $order['name'],
            partnerRef: self::text($order['partner_ref']),
            orderState: (string) $order['state'],
            productId: $productId,
            productName: (string) $product['name'],
            productCode: self::text($product['default_code']),
            productNames: $this->productNames($actor, $product, $productId, $supplierId),
            orderedQty: (float) $line['product_qty'],
            plannedDate: self::localDate($line['date_planned']),
            requestedDate: self::localDate($line['date_promised']),
            confirmedQty: $line['x_confirmed_qty'] === false ? null : (float) $line['x_confirmed_qty'],
            confirmedDate: self::localDate($line['x_confirmed_date']),
            waiting: $this->waiting($actor, $line['move_dest_ids']),
            supplierName: $supplier['name'],
            supplierDomains: $supplier['domains'],
        );
    }

    /**
     * The product's own names and the names the order's supplier uses for it.
     *
     * @param  array<string, mixed>  $product
     * @return list<string>
     */
    private function productNames(Actor $actor, array $product, int $productId, ?int $supplierId): array
    {
        $names = [self::text($product['default_code']), self::text($product['name'])];

        if ($product['seller_ids'] !== [] && $product['seller_ids'] !== false) {
            $rows = $this->odoo->read($actor, 'product.supplierinfo', $product['seller_ids'], ['partner_id', 'product_id', 'product_code', 'product_name']);

            foreach ($rows as $row) {
                $rowProduct = self::id($row['product_id']);

                if (self::id($row['partner_id']) === $supplierId && ($rowProduct === null || $rowProduct === $productId)) {
                    $names[] = self::text($row['product_code']);
                    $names[] = self::text($row['product_name']);
                }
            }
        }

        return array_values(array_unique(array_filter($names, fn (?string $name): bool => $name !== null)));
    }

    /**
     * The manufacturing orders that consume the line's goods, through the
     * stock moves in move_dest_ids, each with what it needs from them.
     *
     * @param  list<int>|false  $moveIds
     * @return list<array{name: string, start: string|null, need: float}>
     */
    private function waiting(Actor $actor, array|false $moveIds): array
    {
        if ($moveIds === [] || $moveIds === false) {
            return [];
        }

        $need = [];

        foreach ($this->odoo->read($actor, 'stock.move', $moveIds, ['raw_material_production_id', 'product_uom_qty']) as $move) {
            $production = self::id($move['raw_material_production_id']);

            if ($production !== null) {
                $need[$production] = ($need[$production] ?? 0.0) + (float) $move['product_uom_qty'];
            }
        }

        if ($need === []) {
            return [];
        }

        $waiting = array_map(fn (array $production): array => [
            'name' => (string) $production['name'],
            'start' => self::localDate($production['date_start']),
            'need' => $need[$production['id']],
        ], $this->odoo->read($actor, 'mrp.production', array_keys($need), ['name', 'date_start']));

        usort($waiting, fn (array $a, array $b): int => $a['name'] <=> $b['name']);

        return $waiting;
    }

    /** The id of a many2one, which Odoo returns as [id, name] or false. */
    private static function id(mixed $value): ?int
    {
        return is_array($value) ? (int) $value[0] : null;
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /** The Helsinki calendar day of an Odoo datetime in UTC, or a date field as it is. */
    private static function localDate(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        if (strlen($value) === 10) {
            return $value;
        }

        return CarbonImmutable::createFromFormat('Y-m-d H:i:s', $value, 'UTC')->setTimezone(self::TIMEZONE)->toDateString();
    }
}
