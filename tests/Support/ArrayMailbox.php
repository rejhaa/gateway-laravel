<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Systems\Mail\Mailbox;
use App\Systems\Mail\Message;

/**
 * A mailbox holding messages built in the test. Bind it with
 * app()->instance(Mailbox::class, new ArrayMailbox([...])).
 */
final class ArrayMailbox implements Mailbox
{
    /** @var list<Message> */
    private array $messages;

    /**
     * @param  list<Message>  $messages  in any order
     */
    public function __construct(array $messages = [])
    {
        usort($messages, fn (Message $a, Message $b): int => [$a->date, $a->id] <=> [$b->date, $b->id]);

        $this->messages = $messages;
    }

    public function messages(): array
    {
        return $this->messages;
    }

    public function find(string $id): ?Message
    {
        foreach ($this->messages as $message) {
            if ($message->id === $id) {
                return $message;
            }
        }

        return null;
    }
}
