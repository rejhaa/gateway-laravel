<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Gateway\Actor;
use App\Gateway\ProposalService;
use App\Systems\Mail\Attachment;
use App\Systems\Mail\Mailbox;
use App\Systems\Mail\Message;
use Carbon\CarbonImmutable;

/**
 * Mail and propose input for the standard line in FakeOdoo::line(). The
 * standard message confirms P00104 three ways, as ordered, as 100 of the 120
 * a fortnight late, and as all 120 on 13 December.
 */
final class Inbound
{
    public const QUOTE_AS_ORDERED = 'VG-HEAD-V1 V1 forged iron head 120 pcs delivery 30 November 2026';

    public const QUOTE_PARTIAL = 'VG-HEAD-V1 V1 forged iron head 100 pcs delivery 14 December 2026';

    public const QUOTE_MOVED = 'VG-HEAD-V1 V1 forged iron head 120 pcs delivery 13 December 2026';

    /**
     * @param  list<Attachment>  $attachments
     */
    public static function message(
        string $messageIdHeader = 'conf-104@taiyuan.example',
        string $subject = 'Order confirmation P00104',
        ?string $body = null,
        string $date = '2026-11-05 09:12:00',
        array $attachments = [],
    ): Message {
        return new Message(
            id: Message::idFor($messageIdHeader),
            from: 'Tai-Yuan Forge & Shaft <sales@taiyuan.example>',
            to: 'Riku Lindgren <riku@vuoksi.example>',
            subject: $subject,
            date: CarbonImmutable::parse($date, 'UTC'),
            body: $body ?? "Dear Riku,\nwe confirm your order P00104:\n".self::QUOTE_AS_ORDERED."\nor, if you prefer:\n"
                .self::QUOTE_PARTIAL."\nor else:\n".self::QUOTE_MOVED."\nBest regards",
            attachments: $attachments,
        );
    }

    /** Binds a mailbox holding these messages, or the standard one. */
    public static function mailbox(Message ...$messages): void
    {
        app()->instance(Mailbox::class, new ArrayMailbox($messages === [] ? [self::message()] : array_values($messages)));
    }

    /**
     * Propose input that confirms the standard line as ordered, which the rule applies.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function input(array $overrides = []): array
    {
        return [
            'system' => 'odoo',
            'model' => 'purchase.order.line',
            'record_id' => FakeOdoo::LINE,
            'values' => ['x_confirmed_qty' => 120, 'x_confirmed_date' => '2026-11-30'],
            'message_id' => self::message()->id,
            'quote' => self::QUOTE_AS_ORDERED,
            'title' => 'P00104 heads: all 120 confirmed for 30 November',
            'summary' => 'The supplier confirms the heads as ordered.',
            ...$overrides,
        ];
    }

    /**
     * Input that confirms 100 of the 120 for 14 December, which waits for Riku.
     *
     * @return array<string, mixed>
     */
    public static function partial(): array
    {
        return self::input([
            'values' => ['x_confirmed_qty' => 100, 'x_confirmed_date' => '2026-12-14'],
            'quote' => self::QUOTE_PARTIAL,
            'title' => 'P00104 heads: 100 of 120 confirmed for 14 December',
        ]);
    }

    public static function service(): ProposalService
    {
        return app(ProposalService::class);
    }

    public static function agent(): Actor
    {
        return Actor::agent('riku', 'Claude');
    }

    public static function riku(): Actor
    {
        return Actor::web('riku');
    }
}
