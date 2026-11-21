<?php

declare(strict_types=1);

namespace App\Gateway;

use App\Models\Proposal;
use Illuminate\Support\Str;

/**
 * What propose answers the agent (DESIGN.md, section 7, "propose"). It is
 * built from the stored proposal, so a new proposal and one that already
 * existed read the same way.
 */
final readonly class ProposeResult
{
    /**
     * @param  bool  $existing  true when the proposal was there before this call
     * @param  bool  $otherValues  true when the existing proposal is a pending one from the same message with other values
     */
    public function __construct(
        public Proposal $proposal,
        public bool $existing = false,
        public bool $otherValues = false,
    ) {}

    /**
     * @return array{proposal_id: int, status: string, tier: string, decision: string, basis: string|null, reasons: list<string>, next: string, existing: bool}
     */
    public function toArray(): array
    {
        $proposal = $this->proposal;

        return [
            'proposal_id' => $proposal->id,
            'status' => $proposal->status->value,
            'tier' => $proposal->tier->value,
            'decision' => $this->otherValues
                ? "Proposal {$proposal->id} from the same message already proposes other values for these fields on this record, and ".self::person().' decides it first.'
                : $this->decision(),
            'basis' => $this->basis(),
            'reasons' => $proposal->reasons,
            'next' => $this->next(),
            'existing' => $this->existing,
        ];
    }

    private function decision(): string
    {
        $proposal = $this->proposal;
        $person = self::person();

        return match ($proposal->status) {
            ProposalStatus::Applied => $proposal->tier === Tier::Rule
                ? "Rule {$proposal->rule} applied this, because {$proposal->reasons[0]}."
                : "{$person} approved this, and the gateway wrote it to Odoo.",
            ProposalStatus::Applying => 'The gateway is writing this to Odoo.',
            ProposalStatus::Pending => "This waits for {$person}, because ".implode('; ', $proposal->reasons).'.',
            ProposalStatus::Denied => $proposal->policy_section !== null
                ? "Denied under {$proposal->policy_section}: {$proposal->reasons[0]}"
                : "Denied, because {$proposal->reasons[0]}.",
            ProposalStatus::Rejected => "{$person} rejected this: \"{$proposal->decision_reason}\"",
            ProposalStatus::Stale => "{$person} approved this, but the record had changed in Odoo, so nothing was written.",
            ProposalStatus::Failed => "Odoo did not take the write: {$proposal->error}",
        };
    }

    /** The rule's name, or the policy section with its sentence. */
    private function basis(): ?string
    {
        $proposal = $this->proposal;

        return match ($proposal->tier) {
            Tier::Rule => $proposal->rule,
            Tier::Policy => $proposal->policy_section !== null
                ? "{$proposal->policy_section}: {$proposal->reasons[0]}"
                : $proposal->reasons[0],
            Tier::Human => null,
        };
    }

    private function next(): string
    {
        $person = self::person();

        return match ($this->proposal->status) {
            ProposalStatus::Applied => 'The gateway wrote this to Odoo. Nothing more is needed.',
            ProposalStatus::Applying => 'The gateway is writing this to Odoo. Do not propose it again.',
            ProposalStatus::Pending => "This waits for {$person}. He decides on the approval page or on the card that list_proposals shows. Do not propose it again.",
            ProposalStatus::Denied => 'This is not done by an agent. Report it to the person who owns it under the policy.',
            ProposalStatus::Rejected => "{$person} said no. Propose a corrected change only when his reason asks for one.",
            ProposalStatus::Stale, ProposalStatus::Failed => 'Nothing was written. You can propose it again.',
        };
    }

    /** The first name of the user the gateway acts for, such as Riku. */
    private static function person(): string
    {
        return Str::before((string) config('gateway.user.name'), ' ');
    }
}
