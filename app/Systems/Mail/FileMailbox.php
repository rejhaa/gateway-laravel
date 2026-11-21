<?php

declare(strict_types=1);

namespace App\Systems\Mail;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as Cache;
use ZBateson\MailMimeParser\Header\DateHeader;
use ZBateson\MailMimeParser\Header\HeaderConsts;
use ZBateson\MailMimeParser\IMessage;
use ZBateson\MailMimeParser\MailMimeParser;
use ZBateson\MailMimeParser\Message\IMessagePart;

/**
 * The .eml files in one directory.
 *
 * Each parsed message, with its attachment text, is cached under the file's
 * path and modification time, because every proposal reads the whole mailbox
 * and pdftotext starts a process for each PDF.
 */
final class FileMailbox implements Mailbox
{
    public function __construct(
        private readonly string $directory,
        private readonly PdfText $pdfText,
        private readonly Cache $cache,
    ) {}

    public function messages(): array
    {
        $messages = array_map($this->load(...), glob(rtrim($this->directory, '/').'/*.eml') ?: []);

        usort($messages, fn (Message $a, Message $b): int => [$a->date, $a->id] <=> [$b->date, $b->id]);

        return $messages;
    }

    public function find(string $id): ?Message
    {
        foreach ($this->messages() as $message) {
            if ($message->id === $id) {
                return $message;
            }
        }

        return null;
    }

    private function load(string $path): Message
    {
        $key = 'mailbox:'.hash('sha256', $path).':'.filemtime($path);

        return $this->cache->rememberForever($key, fn (): Message => $this->parse($path));
    }

    private function parse(string $path): Message
    {
        $raw = (string) file_get_contents($path);
        $mail = (new MailMimeParser)->parse($raw, false);

        return new Message(
            id: Message::idFor($mail->getMessageId() ?? hash('sha256', $raw)),
            from: $mail->getHeader(HeaderConsts::FROM)?->getDecodedValue() ?? '',
            to: $mail->getHeader(HeaderConsts::TO)?->getDecodedValue() ?? '',
            subject: $mail->getSubject() ?? '',
            date: $this->date($mail, $path),
            body: $this->body($mail),
            attachments: array_values(array_map($this->attachment(...), $mail->getAllAttachmentParts())),
        );
    }

    private function date(IMessage $mail, string $path): CarbonImmutable
    {
        $header = $mail->getHeader(HeaderConsts::DATE);
        $date = $header instanceof DateHeader ? $header->getDateTimeImmutable() : null;

        return $date !== null
            ? CarbonImmutable::instance($date)
            : CarbonImmutable::createFromTimestamp((int) filemtime($path));
    }

    private function body(IMessage $mail): string
    {
        $text = $mail->getTextContent() ?? strip_tags($mail->getHtmlContent() ?? '');

        return trim(str_replace("\r\n", "\n", $text));
    }

    private function attachment(IMessagePart $part): Attachment
    {
        $name = $part->getFilename() ?? 'attachment';
        $type = strtolower($part->getContentType('application/octet-stream'));

        if ($type === 'application/pdf' || str_ends_with(strtolower($name), '.pdf')) {
            return $this->pdfText->attachment($name, (string) $part->getBinaryContentStream()?->getContents());
        }

        if (str_starts_with($type, 'text/')) {
            return new Attachment($name, $type, true, PdfText::numberLines([$part->getContent() ?? '']));
        }

        return new Attachment($name, $type, false);
    }
}
