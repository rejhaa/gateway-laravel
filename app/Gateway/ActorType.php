<?php

declare(strict_types=1);

namespace App\Gateway;

enum ActorType: string
{
    case Agent = 'agent';
    case User = 'user';
    case Rule = 'rule';
    case Policy = 'policy';
    case System = 'system';
}
