<?php

declare(strict_types=1);

namespace App\Gateway;

use App\Evidence\QuoteChecker;
use App\Models\Proposal;
use App\Systems\Mail\Mailbox;
use App\Systems\Mail\Message;
use App\Systems\Odoo\Exceptions\OdooException;
use App\Systems\Odoo\Exceptions\OdooUnexpected;
use App\Systems\Odoo\Exceptions\OdooUnreachable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Decides what happens to a proposal, and carries out a person's decision
 * (DESIGN.md, section 6). It knows proposals, tiers and the audit log. The
 * process knows the records, the fields and the rules.
 */
final class ProposalService
{
    public const RULE_TIER_OFF = 'the rule tier is switched off';

    public const ALREADY_DECIDED = 'Someone already decided this one.';

    public const REASON_REQUIRED = 'A rejection needs a reason, so the agent knows what to correct.';

    public const NOT_CONFIRMED = 'Odoo did not confirm the write';

    public function __construct(
        private readonly Processes $processes,
        private readonly Mailbox $mailbox,
        private readonly Audit $audit,
        private readonly QuoteChecker $checker,
    ) {}

    /**
     * Runs the nine steps of the decision on the agent's input. The first step
     * that ends the request wins.
     *
     * @param  array<string, mixed>  $input  system, model, record_id, values, message_id, quote, title, summary
     *
     * @throws InputError when the agent can correct the input. Nothing is stored.
     * @throws OdooException when Odoo cannot be read
     */
    public function propose(Actor $actor, array $input): ProposeResult
    {
        $process = $this->processes->current();

        // 1. Shape
        $input = $this->validated($input, $process);
        $write = new ProposedWrite($input['model'], (int) $input['record_id'], $input['values']);

        // 2. Message
        $message = $this->mailbox->find($input['message_id'])
            ?? throw new InputError("There is no message {$input['message_id']} in the mailbox. Use an id from mail_list_messages.");

        // 3. Duplicate
        $key = self::dedupKey($write, $message->id);
        $earlier = $this->earlier($key, $write, $message->id);

        if ($earlier !== null) {
            return $this->duplicate($actor, ...$earlier);
        }

        $proposal = new Proposal([
            'process' => $process->name(),
            'system' => $process->system(),
            'model' => $write->model,
            'record_id' => $write->recordId,
            'values' => $write->values,
            'message_id' => $message->id,
            'quote' => $input['quote'],
            'title' => $input['title'],
            'summary' => $input['summary'],
            'dedup_key' => $key,
            'replaces' => Proposal::query()
                ->where('dedup_key', $key)
                ->whereIn('status', [ProposalStatus::Failed, ProposalStatus::Stale])
                ->latest('id')
                ->value('id'),
        ]);

        // 4. Access
        $denial = $process->denial($write);

        if ($denial !== null) {
            $proposal->record_label = "{$write->model} {$write->recordId}";

            return $this->create($proposal, $denial, $actor, $process);
        }

        // 5. Record
        $facts = $process->facts($actor, $write);

        // 6. Pairing
        $write = $process->prepare($write, $facts);

        // 7. Quote
        $evidence = $this->evidence($message, $input['quote'], $write);

        // 8. Rules
        $decision = $this->decide($process, $write, $facts, $evidence);

        // 9. Apply or store
        $proposal->fill([
            'record_label' => $facts->label(),
            'values' => $write->values,
            'before' => $facts->values($write->fields()),
            'facts' => $facts->toArray(),
            'quote_source' => $evidence->source,
            'quote_location' => $evidence->location,
            'quote_status' => $evidence->status(),
        ]);

        $result = $this->create($proposal, $decision, $actor, $process);

        if (! $result->existing && $decision->rule !== null) {
            $this->apply($process, $result->proposal, Actor::rule($decision->rule));
        }

        return $result;
    }

    /**
     * Claims a pending proposal, checks that Odoo still holds what it held when
     * the proposal was made, and writes it.
     *
     * @return Proposal applied, stale or failed. It stays applying only when Odoo could not be read after an unconfirmed write.
     *
     * @throws DecisionRefused when the proposal is no longer pending
     */
    public function approve(Proposal $proposal, Actor $actor, ?string $reason = null): Proposal
    {
        $this->claim($proposal, $actor, ProposalStatus::Applying, AuditEvent::ProposalApproved, $reason);

        $process = $this->processes->named($proposal->process);
        $write = self::writeOf($proposal);

        try {
            $facts = $process->facts($actor, $write);
        } catch (InputError) {
            return $this->finish($proposal, ProposalStatus::Stale, $actor, detail: ['reason' => 'the record no longer exists']);
        } catch (OdooException $e) {
            return $this->finish($proposal, ProposalStatus::Failed, $actor, $e->getMessage());
        }

        if (! self::holds($facts->values($write->fields()), $proposal->before ?? [])) {
            return $this->finish($proposal, ProposalStatus::Stale, $actor, detail: ['reason' => 'a proposed field changed']);
        }

        if (! $facts->matches($proposal->facts ?? [])) {
            return $this->finish($proposal, ProposalStatus::Stale, $actor, detail: ['reason' => 'the facts changed']);
        }

        return $this->apply($process, $proposal, $actor);
    }

    /**
     * @throws DecisionRefused when the reason is empty or the proposal is no longer pending
     */
    public function reject(Proposal $proposal, Actor $actor, string $reason): Proposal
    {
        if (trim($reason) === '') {
            $this->audit->record(AuditEvent::DecisionRefused, $actor, AuditOutcome::Refused, proposalId: $proposal->id, detail: ['reason' => self::REASON_REQUIRED]);

            throw new DecisionRefused(self::REASON_REQUIRED);
        }

        $this->claim($proposal, $actor, ProposalStatus::Rejected, AuditEvent::ProposalRejected, trim($reason));

        return $proposal;
    }

    /**
     * The "Check Odoo again" button on a proposal stuck in applying. It reads
     * the record and sets applied when it holds the proposed values, and
     * failed when it does not.
     *
     * An applying proposal already holds its place in the unique index on
     * dedup_key, so no replacement can exist and moving it to applied cannot
     * collide with one.
     *
     * @throws DecisionRefused when the proposal is not applying
     * @throws OdooException when Odoo cannot be read. The proposal stays applying.
     */
    public function recheck(Proposal $proposal, Actor $actor): Proposal
    {
        if ($proposal->status !== ProposalStatus::Applying) {
            throw new DecisionRefused('Only a proposal that is being written to Odoo can be checked again.');
        }

        return $this->confirm($this->processes->named($proposal->process), $proposal, $actor, 'Odoo does not hold the proposed values');
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{model: string, record_id: int|string, values: array<string, mixed>, message_id: string, quote: string, title: string, summary: string}
     */
    private function validated(array $input, Process $process): array
    {
        $validator = Validator::make($input, [
            'system' => ['required', 'string', 'in:'.$process->system()],
            'model' => ['required', 'string'],
            'record_id' => ['required', 'integer', 'min:1'],
            'values' => ['required', 'array', 'min:1'],
            'message_id' => ['required', 'string'],
            'quote' => ['required', 'string'],
            'title' => ['required', 'string', 'max:255'],
            'summary' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            throw new InputError(implode(' ', $validator->errors()->all()));
        }

        if (array_is_list($input['values'])) {
            throw new InputError('The values must map each field to its value, such as {"x_confirmed_qty": 100, "x_confirmed_date": "2026-12-14"}.');
        }

        /** @var array{model: string, record_id: int|string, values: array<string, mixed>, message_id: string, quote: string, title: string, summary: string} */
        return $validator->validated();
    }

    /**
     * An earlier proposal that answers this one, and whether its values differ.
     *
     * @return array{0: Proposal, 1: bool}|null
     */
    private function earlier(string $key, ProposedWrite $write, string $messageId): ?array
    {
        $same = Proposal::query()
            ->where('dedup_key', $key)
            ->whereNotIn('status', [ProposalStatus::Failed, ProposalStatus::Stale])
            ->first();

        if ($same !== null) {
            return [$same, false];
        }

        $fields = $write->fields();
        sort($fields);

        $open = Proposal::query()
            ->where('model', $write->model)
            ->where('record_id', $write->recordId)
            ->where('message_id', $messageId)
            ->whereIn('status', [ProposalStatus::Pending, ProposalStatus::Applying])
            ->oldest('id')
            ->get()
            ->first(function (Proposal $proposal) use ($fields): bool {
                $proposed = array_keys($proposal->values);
                sort($proposed);

                return $proposed === $fields;
            });

        return $open === null ? null : [$open, true];
    }

    private function duplicate(Actor $actor, Proposal $proposal, bool $otherValues = false): ProposeResult
    {
        $this->audit->record(AuditEvent::ProposalDuplicate, $actor, proposalId: $proposal->id);

        return new ProposeResult($proposal, existing: true, otherValues: $otherValues);
    }

    /**
     * Stores the proposal with its decision and the audit rows that record it,
     * in one transaction. A rule-tier proposal is stored as applying, with the
     * row that names the rule, before anything is written to Odoo.
     *
     * Two identical proposals sent at once both pass the duplicate check. The
     * unique index on dedup_key lets one in, and the other returns it.
     */
    private function create(Proposal $proposal, Decision $decision, Actor $actor, Process $process): ProposeResult
    {
        $decider = match ($decision->tier) {
            Tier::Rule => Actor::rule((string) $decision->rule),
            Tier::Policy => Actor::policy($process->name()),
            Tier::Human => null,
        };

        $proposal->fill([
            'tier' => $decision->tier,
            'status' => match ($decision->tier) {
                Tier::Rule => ProposalStatus::Applying,
                Tier::Human => ProposalStatus::Pending,
                Tier::Policy => ProposalStatus::Denied,
            },
            'rule' => $decision->rule,
            'policy_section' => $decision->policySection,
            'reasons' => $decision->reasons,
        ]);

        if ($decider !== null) {
            $proposal->fill(['decided_by' => $decider->name, 'decided_via' => self::via($decider), 'decided_at' => now()]);
        }

        try {
            DB::transaction(function () use ($proposal, $decision, $actor, $decider): void {
                $proposal->save();

                $this->audit->record(AuditEvent::ProposalCreated, $actor, proposalId: $proposal->id, detail: [
                    'model' => $proposal->model,
                    'record_id' => $proposal->record_id,
                    'fields' => array_keys($proposal->values),
                    'tier' => $decision->tier->value,
                ]);

                if ($decider !== null) {
                    $this->audit->record(
                        $decision->tier === Tier::Rule ? AuditEvent::ProposalAppliedByRule : AuditEvent::ProposalDenied,
                        $decider,
                        proposalId: $proposal->id,
                        detail: ['reasons' => $decision->reasons],
                    );
                }
            });
        } catch (UniqueConstraintViolationException) {
            $winner = Proposal::query()
                ->where('dedup_key', $proposal->dedup_key)
                ->whereNotIn('status', [ProposalStatus::Failed, ProposalStatus::Stale])
                ->firstOrFail();

            return $this->duplicate($actor, $winner);
        }

        return new ProposeResult($proposal);
    }

    /**
     * Step 7. Finds the quote in the body and in each attachment's text.
     *
     * @throws InputError when the quote is in none of them and every document has text
     */
    private function evidence(Message $message, string $quote, ProposedWrite $write): Evidence
    {
        $context = [
            'laterMessages' => array_values(array_filter(
                $this->mailbox->messages(),
                fn (Message $other): bool => $other->date->greaterThan($message->date),
            )),
            'appliedByRule' => Proposal::query()
                ->where('model', $write->model)
                ->where('record_id', $write->recordId)
                ->where('message_id', $message->id)
                ->where('tier', Tier::Rule)
                ->where('status', ProposalStatus::Applied)
                ->value('id'),
        ];

        $lines = explode("\n", $message->body);
        $body = array_combine(array_map(fn (int $index): string => 'l'.($index + 1), array_keys($lines)), $lines);
        $location = $this->checker->locate($quote, $body);

        if ($location !== null) {
            return new Evidence($message, $quote, true, Evidence::BODY, (string) $location, ...$context);
        }

        foreach ($message->attachments as $attachment) {
            $location = $this->checker->locate($quote, $attachment->lines);

            if ($location !== null) {
                return new Evidence($message, $quote, true, $attachment->name, (string) $location, ...$context);
            }
        }

        foreach ($message->attachments as $attachment) {
            if (! $attachment->hasTextLayer) {
                return new Evidence($message, $quote, false, ...$context);
            }
        }

        throw new InputError("The quote is not in message {$message->id}. Copy the passage exactly from the body or from an attachment's lines.");
    }

    /**
     * Step 8. The first applying rule with no failures applies the write.
     * Otherwise the write waits, with the failures of the first rule that
     * applies, or the process's reason when no rule applies at all.
     */
    private function decide(Process $process, ProposedWrite $write, Facts $facts, Evidence $evidence): Decision
    {
        $rules = array_values(array_filter($process->rules(), fn (Rule $rule): bool => $rule->applies($write)));

        if ($rules === []) {
            return Decision::human([$process->humanReason($write)]);
        }

        if (! config('gateway.rule_tier')) {
            return Decision::human([self::RULE_TIER_OFF]);
        }

        $failures = null;

        foreach ($rules as $rule) {
            $failed = $rule->failures($write, $facts, $evidence);

            if ($failed === []) {
                return Decision::byRule($rule->name(), $rule->passReason($write, $facts));
            }

            $failures ??= $failed;
        }

        return Decision::human($failures);
    }

    /**
     * Writes an applying proposal to Odoo. A clear refusal sets failed with
     * Odoo's message. A timeout or a server error leaves the outcome unknown,
     * so the record is read once more.
     */
    private function apply(Process $process, Proposal $proposal, Actor $actor): Proposal
    {
        try {
            $process->write($actor, self::writeOf($proposal), $proposal->id);
        } catch (OdooUnreachable|OdooUnexpected) {
            try {
                return $this->confirm($process, $proposal, $actor, self::NOT_CONFIRMED);
            } catch (OdooException) {
                // Still unknown. The proposal stays applying until someone checks Odoo again.
                return $proposal;
            }
        } catch (OdooException $e) {
            return $this->finish($proposal, ProposalStatus::Failed, $actor, $e->getMessage());
        }

        return $this->finish($proposal, ProposalStatus::Applied, $actor);
    }

    /**
     * Reads the record and sets applied when it holds the proposed values,
     * and failed with the error when it does not.
     *
     * @throws OdooException when Odoo cannot be read
     */
    private function confirm(Process $process, Proposal $proposal, Actor $actor, string $error): Proposal
    {
        $write = self::writeOf($proposal);

        try {
            $holds = self::holds($process->facts($actor, $write)->values($write->fields()), $proposal->values);
        } catch (InputError) {
            $holds = false;
        }

        return $holds
            ? $this->finish($proposal, ProposalStatus::Applied, $actor)
            : $this->finish($proposal, ProposalStatus::Failed, $actor, $error);
    }

    /**
     * Moves a pending proposal to its decision in one statement, so of two
     * decisions sent at once only one is taken, and records it.
     *
     * @throws DecisionRefused when the proposal is no longer pending
     */
    private function claim(Proposal $proposal, Actor $actor, ProposalStatus $status, AuditEvent $event, ?string $reason): void
    {
        $claimed = DB::transaction(function () use ($proposal, $actor, $status, $event, $reason): bool {
            $claimed = Proposal::query()
                ->whereKey($proposal->id)
                ->where('status', ProposalStatus::Pending)
                ->update([
                    'status' => $status,
                    'decided_by' => $actor->name,
                    'decided_via' => self::via($actor),
                    'decided_at' => now(),
                    'decision_reason' => $reason,
                ]);

            if ($claimed === 1) {
                $this->audit->record($event, $actor, proposalId: $proposal->id, detail: $reason === null ? [] : ['reason' => $reason]);
            }

            return $claimed === 1;
        });

        if (! $claimed) {
            $this->audit->record(AuditEvent::DecisionRefused, $actor, AuditOutcome::Refused, proposalId: $proposal->id, detail: ['reason' => self::ALREADY_DECIDED]);

            throw new DecisionRefused(self::ALREADY_DECIDED);
        }

        $proposal->refresh();
    }

    /**
     * Moves an applying proposal to where it ended and records it. Another
     * request may have ended it first, and then it is left as that request set it.
     *
     * @param  array<string, mixed>  $detail
     */
    private function finish(Proposal $proposal, ProposalStatus $status, Actor $actor, ?string $error = null, array $detail = []): Proposal
    {
        $finished = Proposal::query()
            ->whereKey($proposal->id)
            ->where('status', ProposalStatus::Applying)
            ->update([
                'status' => $status,
                'applied_at' => $status === ProposalStatus::Applied ? now() : null,
                'error' => $error,
            ]);

        if ($finished === 1) {
            $this->audit->record(
                match ($status) {
                    ProposalStatus::Applied => AuditEvent::ProposalApplied,
                    ProposalStatus::Stale => AuditEvent::ProposalStale,
                    default => AuditEvent::ProposalFailed,
                },
                $actor,
                $status === ProposalStatus::Applied ? AuditOutcome::Ok : AuditOutcome::Refused,
                proposalId: $proposal->id,
                detail: $error === null ? $detail : [...$detail, 'error' => $error],
            );
        }

        return $proposal->refresh();
    }

    /**
     * sha256 of the model, the record id, the values with their fields in
     * order, and the message id.
     */
    private static function dedupKey(ProposedWrite $write, string $messageId): string
    {
        $values = $write->values;
        ksort($values);

        return hash('sha256', json_encode([$write->model, $write->recordId, $values, $messageId], JSON_THROW_ON_ERROR));
    }

    private static function writeOf(Proposal $proposal): ProposedWrite
    {
        return new ProposedWrite($proposal->model, $proposal->record_id, $proposal->values);
    }

    /**
     * Whether the record's values equal the expected ones. Numbers compare by
     * value, so 100 from the agent equals 100.0 from Odoo.
     *
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $expected
     */
    private static function holds(array $current, array $expected): bool
    {
        foreach ($expected as $field => $value) {
            $now = $current[$field] ?? null;
            $numbers = (is_int($value) || is_float($value)) && (is_int($now) || is_float($now));

            if ($numbers ? (float) $now !== (float) $value : $now !== $value) {
                return false;
            }
        }

        return true;
    }

    /** proposals.decided_via: rule, policy, or the channel a person used. */
    private static function via(Actor $actor): string
    {
        return match ($actor->type) {
            ActorType::Rule => 'rule',
            ActorType::Policy => 'policy',
            default => $actor->channel->value,
        };
    }
}
