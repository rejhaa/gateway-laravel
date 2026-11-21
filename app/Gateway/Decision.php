<?php

declare(strict_types=1);

namespace App\Gateway;

/**
 * Who decides a proposal and why. It names the rule that applies it, the
 * reasons it waits for a person, or the policy section that denies it.
 */
final readonly class Decision
{
    /**
     * @param  list<string>  $reasons
     */
    private function __construct(
        public Tier $tier,
        public array $reasons,
        public ?string $rule = null,
        public ?string $policySection = null,
    ) {}

    public static function byRule(string $rule, string $reason): self
    {
        return new self(Tier::Rule, [$reason], rule: $rule);
    }

    /**
     * @param  list<string>  $reasons
     */
    public static function human(array $reasons): self
    {
        return new self(Tier::Human, $reasons);
    }

    /** Denied under a policy section with its sentence, or with no section as outside what the agent may write. */
    public static function denied(string $reason, ?string $policySection = null): self
    {
        return new self(Tier::Policy, [$reason], policySection: $policySection);
    }
}
