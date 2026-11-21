<?php

declare(strict_types=1);

namespace App\Mcp\Servers;

use App\Mcp\Resources\ProposalsCard;
use App\Mcp\Tools\GetPolicy;
use App\Mcp\Tools\ListProposals;
use App\Mcp\Tools\MailListMessages;
use App\Mcp\Tools\MailReadMessage;
use App\Mcp\Tools\OdooSearchRead;
use App\Mcp\Tools\Propose;
use App\Mcp\Tools\Whoami;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

/**
 * The agent's way into the gateway (DESIGN.md, section 7). No tool approves,
 * rejects or writes. A test keeps the list at exactly these seven tools. The
 * one resource is the card that list_proposals renders.
 */
#[Name('Gateway')]
#[Version('1.0.0')]
#[Instructions('Read the policy with get_policy before you do anything else. Propose one change at a time with propose, citing the message and the exact quote it rests on. The gateway decides whether a rule applies the change, the user approves it, or the policy denies it, and you never approve anything yourself.')]
class GatewayServer extends Server
{
    protected array $tools = [
        Whoami::class,
        GetPolicy::class,
        MailListMessages::class,
        MailReadMessage::class,
        OdooSearchRead::class,
        Propose::class,
        ListProposals::class,
    ];

    protected array $resources = [
        ProposalsCard::class,
    ];
}
