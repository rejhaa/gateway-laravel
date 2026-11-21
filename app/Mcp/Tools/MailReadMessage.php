<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Gateway\Actor;
use App\Gateway\InputError;
use App\Systems\Mail\Attachment;
use App\Systems\Mail\Mailbox;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('mail_read_message')]
#[Description('One message with its body and the text of each attachment. Attachment lines are numbered "p1.l17: text", page and line. Quote a whole row or sentence exactly as written here.')]
#[IsReadOnly]
final class MailReadMessage extends GatewayTool
{
    public function __construct(private readonly Mailbox $mailbox) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description('The message id from mail_list_messages.')->required(),
        ];
    }

    protected function run(Request $request, Actor $actor): ResponseFactory
    {
        $id = $request->validate(['id' => ['required', 'string']])['id'];

        $message = $this->mailbox->find($id)
            ?? throw new InputError("There is no message {$id} in the mailbox. Use an id from mail_list_messages.");

        return Response::structured([
            'id' => $message->id,
            'from' => $message->from,
            'to' => $message->to,
            'subject' => $message->subject,
            'date' => MailListMessages::date($message),
            'body' => $message->body,
            'attachments' => array_map(fn (Attachment $attachment, int $index): array => [
                'index' => $index + 1,
                'name' => $attachment->name,
                'content_type' => $attachment->contentType,
                'has_text_layer' => $attachment->hasTextLayer,
                'lines' => array_map(
                    fn (string $location, string $text): string => "{$location}: {$text}",
                    array_keys($attachment->lines),
                    array_values($attachment->lines),
                ),
            ], $message->attachments, array_keys($message->attachments)),
            'proposals' => MailListMessages::proposalsCiting([$message->id])[$message->id] ?? [],
        ]);
    }
}
