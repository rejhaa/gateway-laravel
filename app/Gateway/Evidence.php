<?php

declare(strict_types=1);

namespace App\Gateway;

use App\Systems\Mail\Message;

/**
 * What the cited message says and what it already did, as the gateway found it.
 *
 * The quote is found when it was located in the body or in an attachment's
 * text. It is not found when it was in neither but an attachment has no text
 * layer, so it could not be checked.
 */
final readonly class Evidence
{
    public const BODY = 'body';

    /**
     * @param  string|null  $source  `body` or the attachment's name, null when the quote was not found
     * @param  string|null  $location  the line the quote starts on, such as `l3` in the body or `p1.l17` in an attachment
     * @param  list<Message>  $laterMessages  every message in the mailbox newer than the cited one
     * @param  int|null  $appliedByRule  the id of an applied rule-tier proposal that cites this message on the same record
     */
    public function __construct(
        public Message $message,
        public string $quote,
        public bool $found,
        public ?string $source = null,
        public ?string $location = null,
        public array $laterMessages = [],
        public ?int $appliedByRule = null,
    ) {}

    /** The value of proposals.quote_status. */
    public function status(): string
    {
        return $this->found ? 'found' : 'unchecked';
    }
}
