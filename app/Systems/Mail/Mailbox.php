<?php

declare(strict_types=1);

namespace App\Systems\Mail;

/**
 * The mail the agent works through.
 */
interface Mailbox
{
    /** @return list<Message> oldest first */
    public function messages(): array;

    public function find(string $id): ?Message;
}
