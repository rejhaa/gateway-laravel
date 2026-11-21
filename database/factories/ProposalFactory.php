<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Gateway\ProposalStatus;
use App\Gateway\Tier;
use App\Models\Proposal;
use App\Processes\InboundPurchasing\LineFacts;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A pending proposal that confirms a purchase order line's quantity and date.
 *
 * @extends Factory<Proposal>
 */
class ProposalFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $recordId = fake()->numberBetween(1, 500);
        $messageId = bin2hex(random_bytes(6));
        $values = ['x_confirmed_qty' => 100, 'x_confirmed_date' => '2026-12-14'];

        return [
            'process' => 'inbound-purchasing',
            'system' => 'odoo',
            'model' => 'purchase.order.line',
            'record_id' => $recordId,
            'record_label' => 'P00104, V1 forged iron head (VG-HEAD-V1), 120 ordered',
            'values' => $values,
            'before' => ['x_confirmed_qty' => null, 'x_confirmed_date' => null],
            'facts' => (new LineFacts(
                lineId: $recordId,
                orderName: 'P00104',
                partnerRef: 'TYF-OC-26-0918',
                orderState: 'purchase',
                productId: 11,
                productName: 'V1 forged iron head',
                productCode: 'VG-HEAD-V1',
                productNames: ['VG-HEAD-V1', 'V1 forged iron head'],
                orderedQty: 120.0,
                plannedDate: '2026-11-30',
                requestedDate: '2026-11-30',
                confirmedQty: null,
                confirmedDate: null,
            ))->toArray(),
            'message_id' => $messageId,
            'quote' => 'VG-HEAD-V1 V1 forged iron head 100 pcs 2026-12-14',
            'quote_source' => 'body',
            'quote_location' => 'l1',
            'quote_status' => 'found',
            'title' => 'P00104 heads: 100 of 120 confirmed for 14 December',
            'summary' => 'The supplier confirms 100 of the 120 heads ordered.',
            'tier' => Tier::Human,
            'status' => ProposalStatus::Pending,
            'reasons' => ['the confirmed quantity 100 is not the 120 ordered', 'the confirmed date 2026-12-14 is not the planned date 2026-11-30'],
            'dedup_key' => hash('sha256', json_encode(['purchase.order.line', $recordId, $values, $messageId], JSON_THROW_ON_ERROR)),
        ];
    }
}
