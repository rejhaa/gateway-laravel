<?php

declare(strict_types=1);

namespace App\Systems\Mail;

use Carbon\CarbonImmutable;

/**
 * One mail message. The id is derived from the Message-ID header, so nothing in
 * it shows where the mail is stored.
 *
 * The sender writes the From header, so its text proves nothing. The mailbox
 * sets fromVerified when the receiving mail server found that the message
 * comes from the domain of fromAddress.
 */
final readonly class Message
{
    /**
     * @param  string  $from  as written in the header, such as "Name <address>"
     * @param  string  $to  every recipient, comma-separated
     * @param  list<Attachment>  $attachments
     * @param  string|null  $fromAddress  the one address in the From header in lowercase, null when it holds none or several
     */
    public function __construct(
        public string $id,
        public string $from,
        public string $to,
        public string $subject,
        public CarbonImmutable $date,
        public string $body,
        public array $attachments = [],
        public ?string $fromAddress = null,
        public bool $fromVerified = false,
    ) {}

    /**
     * The first 12 hex characters of the sha256 of the Message-ID header,
     * without its angle brackets.
     */
    public static function idFor(string $messageIdHeader): string
    {
        return substr(hash('sha256', trim($messageIdHeader, " \t<>")), 0, 12);
    }
}
