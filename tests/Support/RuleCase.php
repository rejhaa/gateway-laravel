<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Gateway\Evidence;
use App\Gateway\ProposedWrite;
use App\Gateway\Rule;
use App\Processes\InboundPurchasing\LineFacts;
use App\Systems\Mail\Message;
use Carbon\CarbonImmutable;

/**
 * A write, the line's facts and the evidence, as plain arrays a test row
 * changes one thing in. Each named case passes its rule.
 */
final readonly class RuleCase
{
    /**
     * @param  array<string, mixed>  $values  the written fields
     * @param  array<string, mixed>  $facts  LineFacts constructor arguments
     * @param  array<string, mixed>  $evidence  quote, found, subject, fromAddress, fromVerified, laterSubjects and appliedByRule
     */
    private function __construct(
        public array $values,
        public array $facts,
        public array $evidence,
    ) {}

    /** P00104's heads confirmed in full for the planned date, 2026-11-30. */
    public static function confirmation(): self
    {
        return new self(
            ['x_confirmed_qty' => 120, 'x_confirmed_date' => '2026-11-30'],
            self::line(),
            self::quoted('VG-HEAD-V1 V1 forged iron head 120 pcs delivery 30 November 2026'),
        );
    }

    /** P00104's heads moved 13 days past the requested 2026-11-30, with nothing waiting. */
    public static function plannedDate(): self
    {
        return new self(
            ['date_planned' => '2026-12-13', 'x_confirmed_qty' => 120, 'x_confirmed_date' => '2026-12-13'],
            self::line(),
            self::quoted('VG-HEAD-V1 V1 forged iron head 120 pcs, ready 12 December 2026, delivery 13 December 2026'),
        );
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $facts
     * @param  array<string, mixed>  $evidence
     */
    public function with(array $values = [], array $facts = [], array $evidence = []): self
    {
        return new self([...$this->values, ...$values], [...$this->facts, ...$facts], [...$this->evidence, ...$evidence]);
    }

    /** @return list<string> */
    public function failures(Rule $rule): array
    {
        return $rule->failures($this->write(), $this->lineFacts(), $this->evidence());
    }

    public function write(): ProposedWrite
    {
        return new ProposedWrite('purchase.order.line', 36, $this->values);
    }

    public function lineFacts(): LineFacts
    {
        return new LineFacts(...$this->facts);
    }

    public function evidence(): Evidence
    {
        $message = fn (string $subject, string $body, string $date): Message => new Message(
            Message::idFor($subject.$date), 'sales@taiyuan.example', 'riku@vuoksi.example', $subject, CarbonImmutable::parse($date), $body,
            fromAddress: $this->evidence['fromAddress'],
            fromVerified: $this->evidence['fromVerified'],
        );

        return new Evidence(
            message: $message($this->evidence['subject'], "Dear Riku,\n{$this->evidence['quote']}", '2026-11-05'),
            quote: $this->evidence['quote'],
            found: $this->evidence['found'],
            source: $this->evidence['found'] ? Evidence::BODY : null,
            location: $this->evidence['found'] ? 'l2' : null,
            laterMessages: array_map(fn (string $subject): Message => $message($subject, 'See the update.', '2026-11-07'), $this->evidence['laterSubjects']),
            appliedByRule: $this->evidence['appliedByRule'],
        );
    }

    /** @return array<string, mixed> */
    private static function line(): array
    {
        return [
            'lineId' => 36,
            'orderName' => 'P00104',
            'partnerRef' => 'TYF-OC-26-0918',
            'orderState' => 'purchase',
            'productId' => 11,
            'productName' => 'V1 forged iron head',
            'productCode' => 'VG-HEAD-V1',
            'productNames' => ['VG-HEAD-V1', 'V1 forged iron head'],
            'orderedQty' => 120.0,
            'plannedDate' => '2026-11-30',
            'requestedDate' => '2026-11-30',
            'confirmedQty' => null,
            'confirmedDate' => null,
            'waiting' => [],
            'supplierName' => 'Tai-Yuan Forge & Shaft',
            'supplierAddresses' => ['sales@taiyuan.example', 'mei.lin@taiyuan.example'],
        ];
    }

    /** @return array<string, mixed> */
    private static function quoted(string $quote): array
    {
        return [
            'quote' => $quote,
            'found' => true,
            'subject' => 'Order confirmation P00104',
            'fromAddress' => 'sales@taiyuan.example',
            'fromVerified' => true,
            'laterSubjects' => [],
            'appliedByRule' => null,
        ];
    }
}
