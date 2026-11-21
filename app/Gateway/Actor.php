<?php

declare(strict_types=1);

namespace App\Gateway;

/**
 * Who acts and through which channel, as the audit log and a decision record it.
 *
 * The client is the OAuth client's name for an agent, and the card token's id
 * for a user deciding on a card.
 */
final readonly class Actor
{
    public function __construct(
        public ActorType $type,
        public string $name,
        public Channel $channel,
        public ?string $client = null,
    ) {}

    /** The agent, acting for a user through an OAuth client over MCP. */
    public static function agent(string $user, ?string $client): self
    {
        return new self(ActorType::Agent, $user, Channel::Mcp, $client);
    }

    /** A user on the web page. */
    public static function web(string $user): self
    {
        return new self(ActorType::User, $user, Channel::Web);
    }

    /** A user on a card, identified by the card token's id. */
    public static function card(string $user, string $tokenId): self
    {
        return new self(ActorType::User, $user, Channel::Card, $tokenId);
    }

    public static function rule(string $rule): self
    {
        return new self(ActorType::Rule, $rule, Channel::Gateway);
    }

    /** The policy, named by the process whose policy denied. */
    public static function policy(string $process): self
    {
        return new self(ActorType::Policy, $process, Channel::Gateway);
    }

    public static function system(): self
    {
        return new self(ActorType::System, 'gateway', Channel::Gateway);
    }
}
