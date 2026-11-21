<?php

declare(strict_types=1);

namespace App\Systems\Mail;

use Carbon\CarbonImmutable;

/**
 * One mail message. The id is derived from the Message-ID header, so nothing in
 * it shows where the mail is stored.
 */
final readonly class Message
{
    /**
     * @param  string  $from  as written in the header, such as "Name <address>"
     * @param  string  $to  every recipient, comma-separated
     * @param  list<Attachment>  $attachments
     */
    public function __construct(
        public string $id,
        public string $from,
        public string $to,
        public string $subject,
        public CarbonImmutable $date,
        public string $body,
        public array $attachments = [],
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
