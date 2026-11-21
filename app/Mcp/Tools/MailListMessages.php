<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Gateway\Actor;
use App\Models\Proposal;
use App\Systems\Mail\Attachment;
use App\Systems\Mail\Mailbox;
use App\Systems\Mail\Message;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('mail_list_messages')]
#[Description('The messages in the purchasing mailbox, oldest first, with the proposals already made from each. A message with proposals may still need more, because one document can cover several records.')]
#[IsReadOnly]
final class MailListMessages extends GatewayTool
{
    public function __construct(private readonly Mailbox $mailbox) {}

    protected function run(Request $request, Actor $actor): ResponseFactory
    {
        $messages = $this->mailbox->messages();
        $proposals = self::proposalsCiting(array_map(fn (Message $message): string => $message->id, $messages));

        return Response::structured([
            'messages' => array_map(fn (Message $message): array => [
                'id' => $message->id,
                'from' => $message->from,
                'subject' => $message->subject,
                'date' => self::date($message),
                'attachments' => array_map(fn (Attachment $attachment): string => $attachment->name, $message->attachments),
                'proposals' => $proposals[$message->id] ?? [],
            ], $messages),
        ]);
    }

    /**
     * The proposals that cite each message, oldest first.
     *
     * @param  list<string>  $messageIds
     * @return array<string, list<array{id: int, status: string, title: string, record: string}>>
     */
    public static function proposalsCiting(array $messageIds): array
    {
        return Proposal::query()
            ->whereIn('message_id', $messageIds)
            ->oldest('id')
            ->get()
            ->groupBy('message_id')
            ->map(fn ($proposals): array => $proposals->map(fn (Proposal $proposal): array => [
                'id' => $proposal->id,
                'status' => $proposal->status->value,
                'title' => $proposal->title,
                'record' => $proposal->record_label,
            ])->values()->all())
            ->all();
    }

    /** The message's date in the user's time zone, as ISO 8601. */
    public static function date(Message $message): string
    {
        return $message->date->setTimezone((string) config('gateway.timezone'))->toIso8601String();
    }
}
