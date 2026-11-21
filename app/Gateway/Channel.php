<?php

declare(strict_types=1);

namespace App\Gateway;

/**
 * The way an actor reached the gateway.
 */
enum Channel: string
{
    case Mcp = 'mcp';
    case Web = 'web';
    case Card = 'card';
    case Gateway = 'gateway';
}
