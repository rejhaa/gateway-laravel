<?php

declare(strict_types=1);

namespace App\Gateway;

enum AuditOutcome: string
{
    case Ok = 'ok';
    case Refused = 'refused';
    case Error = 'error';
}
