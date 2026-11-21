<?php

declare(strict_types=1);

namespace App\Gateway;

/**
 * Who decides a proposal: a rule applies it, a human decides it, or the policy denies it.
 */
enum Tier: string
{
    case Rule = 'rule';
    case Human = 'human';
    case Policy = 'policy';
}
