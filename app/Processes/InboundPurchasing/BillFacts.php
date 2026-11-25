<?php

declare(strict_types=1);

namespace App\Processes\InboundPurchasing;

use App\Gateway\Facts;
use LogicException;

/**
 * What the gateway reads about a vendor bill before it is disputed.
 */
final readonly class BillFacts implements Facts
{
    /**
     * @param  string  $supplier  the bill's partner as Odoo names it
     * @param  string  $supplierName  the company that partner belongs to
     * @param  list<string>  $supplierDomains  the mail domains Odoo holds for that company, in lowercase
     */
    public function __construct(
        public int $billId,
        public string $name,
        public ?string $ref,
        public string $state,
        public string $supplier,
        public float $amountUntaxed,
        public string $currency,
        public ?string $dispute,
        public ?string $disputeNote,
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

    /** "Posted vendor bill TYF-INV-2211 from Tai-Yuan Forge & Shaft, 4,800.00 EUR untaxed" */
    public function label(): string
    {
        $state = match ($this->state) {
            'draft' => 'Draft',
            'posted' => 'Posted',
            'cancel' => 'Cancelled',
            default => ucfirst($this->state),
        };

        return "{$state} vendor bill ".($this->ref ?? $this->name)." from {$this->supplier}, "
            .number_format($this->amountUntaxed, 2)." {$this->currency} untaxed";
    }

    public function values(array $fields): array
    {
        $values = [];

        foreach ($fields as $field) {
            $values[$field] = match ($field) {
                'x_dispute' => $this->dispute,
                'x_dispute_note' => $this->disputeNote,
                default => throw new LogicException("A vendor bill's facts hold no value for {$field}."),
            };
        }

        return $values;
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /** The bill's name and supplier must be the same, so the write lands on the bill that was proposed. */
    public function matches(array $stored): bool
    {
        $then = self::fromArray($stored);

        return $then->name === $this->name && $then->supplier === $this->supplier;
    }
}
