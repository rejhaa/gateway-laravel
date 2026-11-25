<?php

declare(strict_types=1);

namespace App\Systems\Mail;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as Cache;
use ZBateson\MailMimeParser\Header\AddressHeader;
use ZBateson\MailMimeParser\Header\DateHeader;
use ZBateson\MailMimeParser\Header\HeaderConsts;
use ZBateson\MailMimeParser\Header\IHeader;
use ZBateson\MailMimeParser\IMessage;
use ZBateson\MailMimeParser\MailMimeParser;
use ZBateson\MailMimeParser\Message\IMessagePart;

/**
 * The .eml files in one directory.
 *
 * Each parsed message, with its attachment text, is cached under the file's
 * path and modification time and the receiving server's id, because every
 * proposal reads the whole mailbox and pdftotext starts a process for each PDF.
 *
 * A file is a message as the receiving mail server stored it, so it holds the
 * Authentication-Results header that server added.
 */
final class FileMailbox implements Mailbox
{
    /**
     * Part of every cache key. Raise it with any change to how a file is read
     * into a Message, so no message read the earlier way is served again.
     */
    private const READING = 3;

    public function __construct(
        private readonly string $directory,
        private readonly PdfText $pdfText,
        private readonly Cache $cache,
        private readonly ?string $authservId = null,
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
        $key = 'mailbox:'.self::READING.':'.hash('sha256', $path."\n".$this->authservId).':'.filemtime($path);

        return $this->cache->rememberForever($key, fn (): Message => $this->parse($path));
    }

    private function parse(string $path): Message
    {
        $raw = (string) file_get_contents($path);
        $mail = (new MailMimeParser)->parse($raw, false);
        $fromAddress = $this->fromAddress($mail);

        return new Message(
            id: Message::idFor($mail->getMessageId() ?? hash('sha256', $raw)),
            from: ($this->headers($mail, HeaderConsts::FROM)[0] ?? null)?->getDecodedValue() ?? '',
            to: $mail->getHeader(HeaderConsts::TO)?->getDecodedValue() ?? '',
            subject: $mail->getSubject() ?? '',
            date: $this->date($mail, $path),
            body: $this->body($mail),
            attachments: array_values(array_map($this->attachment(...), $mail->getAllAttachmentParts())),
            fromAddress: $fromAddress,
            fromVerified: $fromAddress !== null && $this->verified($mail, $fromAddress),
        );
    }

    /**
     * The address of a message with one From header that holds one address.
     * With several of either, nobody can say which one the receiving server
     * checked, so the message has no sender address.
     */
    private function fromAddress(IMessage $mail): ?string
    {
        $headers = $this->headers($mail, HeaderConsts::FROM);
        $addresses = count($headers) === 1 && $headers[0] instanceof AddressHeader ? $headers[0]->getAddresses() : [];

        if (count($addresses) !== 1) {
            return null;
        }

        $address = strtolower(trim($addresses[0]->getEmail()));

        return preg_match('/^[^@\s]+@[^@\s]+$/', $address) === 1 ? $address : null;
    }

    private function verified(IMessage $mail, string $fromAddress): bool
    {
        if ($this->authservId === null || $this->authservId === '') {
            return false;
        }

        $headers = array_map(
            fn (IHeader $header): string => $header->getRawValue(),
            $this->headers($mail, 'Authentication-Results'),
        );

        return AuthenticationResults::verifies($headers, $this->authservId, substr((string) strrchr($fromAddress, '@'), 1));
    }

    /**
     * The headers with exactly this name, in any case. The parser's own
     * lookup also returns a header whose name differs in punctuation, such
     * as Authentication_Results or F-rom. A receiving server neither writes
     * nor removes a header of such a name, so a sender could add one.
     *
     * @return list<IHeader>
     */
    private function headers(IMessage $mail, string $name): array
    {
        return array_values(array_filter(
            $mail->getAllHeadersByName($name),
            fn (IHeader $header): bool => strcasecmp($header->getName(), $name) === 0,
        ));
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
