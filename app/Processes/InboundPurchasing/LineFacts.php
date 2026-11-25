<?php

declare(strict_types=1);

namespace App\Processes\InboundPurchasing;

use App\Gateway\Facts;
use Carbon\CarbonImmutable;
use LogicException;

/**
 * What the gateway reads about a purchase order line, its order, its product
 * and the manufacturing orders that wait on it. Dates are calendar days in
 * Helsinki, written YYYY-MM-DD, and an empty value is null.
 */
final readonly class LineFacts implements Facts
{
    /**
     * @param  list<string>  $productNames  default_code and name, and the supplier's product_code and product_name
     * @param  list<array{name: string, start: string|null, need: float}>  $waiting  manufacturing orders that wait on the line, by name
     * @param  string  $supplierName  the company the order's supplier belongs to
     * @param  list<string>  $supplierDomains  the mail domains Odoo holds for that company, in lowercase
     */
    public function __construct(
        public int $lineId,
        public string $orderName,
        public ?string $partnerRef,
        public string $orderState,
        public int $productId,
        public string $productName,
        public ?string $productCode,
        public array $productNames,
        public float $orderedQty,
        public ?string $plannedDate,
        public ?string $requestedDate,
        public ?float $confirmedQty,
        public ?string $confirmedDate,
        public array $waiting = [],
        public string $supplierName = '',
        public array $supplierDomains = [],
    ) {}

    /**
     * A stored fact this version does not hold is left out, so a proposal
     * that an earlier version stored still loads.
     *
     * @param  array<string, mixed>  $stored  what toArray() returned
     */
    public static function fromArray(array $stored): self
    {
        return new self(...array_filter($stored, fn (string $fact): bool => property_exists(self::class, $fact), ARRAY_FILTER_USE_KEY));
    }

    /** "V1 forged iron head (VG-HEAD-V1)", or the name alone when the product has no code. */
    public function product(): string
    {
        return $this->productCode === null ? $this->productName : "{$this->productName} ({$this->productCode})";
    }

    public function label(): string
    {
        return "{$this->orderName}, {$this->product()}, ".self::quantity($this->orderedQty).' ordered';
    }

    public function values(array $fields): array
    {
        $values = [];

        foreach ($fields as $field) {
            $values[$field] = match ($field) {
                'date_planned' => $this->plannedDate,
                'x_confirmed_qty' => $this->confirmedQty,
                'x_confirmed_date' => $this->confirmedDate,
                default => throw new LogicException("A purchase order line's facts hold no value for {$field}."),
            };
        }

        return $values;
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /**
     * The order's name and state and the line's product must be the same, so
     * the write lands on the line that was proposed. The waiting manufacturing
     * orders and their starts must be the same, so the screen told the truth.
     */
    public function matches(array $stored): bool
    {
        $then = self::fromArray($stored);

        return $then->orderName === $this->orderName
            && $then->orderState === $this->orderState
            && $then->productId === $this->productId
            && self::starts($then->waiting) === self::starts($this->waiting);
    }

    /** Days from one date to another, negative when the second is earlier. */
    public static function days(string $from, string $to): int
    {
        return (int) CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to), false);
    }

    /** A quantity as a person writes it: 120 rather than 120.0. */
    public static function quantity(int|float $quantity): string
    {
        return floor((float) $quantity) === (float) $quantity ? (string) (int) $quantity : (string) $quantity;
    }

    /**
     * @param  list<array{name: string, start: string|null, need: float}>  $waiting
     * @return array<string, string|null>
     */
    private static function starts(array $waiting): array
    {
        $starts = array_column($waiting, 'start', 'name');
        ksort($starts);

        return $starts;
    }
}
