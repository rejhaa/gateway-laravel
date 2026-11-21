<?php

declare(strict_types=1);

namespace App\Gateway;

use RuntimeException;

/**
 * A decision the gateway did not take, because someone already decided the
 * proposal or a rejection came without a reason. The message is one sentence
 * to show on the screen.
 */
final class DecisionRefused extends RuntimeException
{
    public function __construct(string $sentence)
    {
        parent::__construct($sentence);
    }
}
