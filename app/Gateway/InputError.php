<?php

declare(strict_types=1);

namespace App\Gateway;

use RuntimeException;

/**
 * Input the agent can correct. The message is one sentence that says what to
 * correct, and the agent reads it as it is. It is audited as input.rejected
 * and never reported.
 */
final class InputError extends RuntimeException
{
    public function __construct(string $sentence)
    {
        parent::__construct($sentence);
    }
}
