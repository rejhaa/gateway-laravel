<?php

declare(strict_types=1);

namespace App\Gateway;

use App\Models\Proposal;
use App\Systems\Mail\Attachment;
use App\Systems\Mail\Mailbox;
use App\Systems\Mail\Message;
use App\Systems\Odoo\Exceptions\OdooException;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Proposals as the screens show them (DESIGN.md, section 8). The card's JSON,
 * the Blade component and the web pages take their words from here, so a
 * proposal reads the same wherever Riku decides it.
 *
 * Everything returned is plain data. Strings the agent wrote, such as the
 * title, the summary and the quote, are returned as they are and must be
 * drawn as text, never as HTML.
 */
final class ProposalPresenter
{
    public function __construct(
        private readonly Processes $processes,
        private readonly Mailbox $mailbox,
    ) {}

    /**
     * What the card draws: everything that waits, grouped by mail, and what
     * rules applied.
     *
     * @return array{
     *     heading: string,
     *     waiting: int,
     *     groups: list<array{message: array<string, mixed>, pending: int, proposals: list<array<string, mixed>>}>,
     *     applied_by_rule: list<array{id: int, title: string, rule: string|null}>,
     *     page_url: string,
     * }
     */
    public function card(): array
    {
        $pending = Proposal::query()->where('status', ProposalStatus::Pending)->oldest('id')->get();

        return [
            'heading' => self::heading($pending->count()),
            'waiting' => $pending->count(),
            'groups' => $this->groups($pending),
            'applied_by_rule' => $this->appliedByRule(),
            'page_url' => route('approvals'),
        ];
    }

    /**
     * One line per change a rule applied, oldest first.
     *
     * @return list<array{id: int, title: string, rule: string|null}>
     */
    public function appliedByRule(): array
    {
        return Proposal::query()
            ->where('tier', Tier::Rule)
            ->where('status', ProposalStatus::Applied)
            ->oldest('id')
            ->get()
            ->map(fn (Proposal $proposal): array => ['id' => $proposal->id, 'title' => $proposal->title, 'rule' => $proposal->rule])
            ->all();
    }

    /**
     * Proposal blocks grouped by the message they cite.
     *
     * @param  Collection<int, Proposal>  $proposals
     * @return list<array{message: array<string, mixed>, pending: int, proposals: list<array<string, mixed>>}>
     */
    public function groups(Collection $proposals, bool $newestFirst = false): array
    {
        return array_map(fn (array $group): array => [
            'message' => $group['message'],
            'pending' => $group['proposals']->where('status', ProposalStatus::Pending)->count(),
            'proposals' => $group['proposals']->map($this->block(...))->all(),
        ], $this->byMessage($proposals, $newestFirst));
    }

    /**
     * Proposals grouped by the message they cite, oldest message first, or
     * newest first when asked. A message that is no longer in the mailbox
     * comes last either way, with only its id.
     *
     * @param  Collection<int, Proposal>  $proposals
     * @return list<array{
     *     message: array{id: string, from: string|null, sender: string|null, subject: string|null, date: string|null, day: string|null, time: string|null, body: string|null, attachments: list<string>},
     *     proposals: Collection<int, Proposal>,
     * }>
     */
    public function byMessage(Collection $proposals, bool $newestFirst = false): array
    {
        $messages = collect($this->mailbox->messages())->keyBy('id');
        $direction = $newestFirst ? -1 : 1;

        return $proposals
            ->groupBy('message_id')
            ->sortBy(fn (Collection $group, int|string $id): array => [
                $messages->has($id) ? 0 : 1,
                $direction * ($messages->get($id)?->date->getTimestamp() ?? 0),
                $direction * $group->min('id'),
            ])
            ->map(fn (Collection $group, int|string $id): array => [
                // An id of digits alone comes back from groupBy as an integer key.
                'message' => self::message((string) $id, $messages->get($id)),
                'proposals' => $group->values(),
            ])
            ->values()
            ->all();
    }

    /**
     * One proposal block. The effect is the middle of the block, the quote
     * goes in the fold "Why, and where it says so", and the line replaces the
     * block once it is decided.
     *
     * @return array{
     *     id: int,
     *     title: string,
     *     summary: string,
     *     status: string,
     *     tier: string,
     *     because: string|null,
     *     reasons: list<string>,
     *     effect: array<string, mixed>,
     *     quote: array{text: string, source: string|null, document: string|null, location: string|null, status: string|null, check: string|null},
     *     line: string|null,
     *     rule: string|null,
     *     policy_section: string|null,
     *     decided_by: string|null,
     *     decided_via: string|null,
     *     decided_at: string|null,
     *     decision_reason: string|null,
     *     error: string|null,
     * }
     */
    public function block(Proposal $proposal): array
    {
        return [
            'id' => $proposal->id,
            'title' => $proposal->title,
            'summary' => $proposal->summary,
            'status' => $proposal->status->value,
            'tier' => $proposal->tier->value,
            'because' => $proposal->tier === Tier::Human ? 'You are asked because '.implode('; ', $proposal->reasons).'.' : null,
            'reasons' => $proposal->reasons,
            'effect' => $this->processes->named($proposal->process)->effect($proposal),
            'quote' => [
                'text' => $proposal->quote,
                'source' => $proposal->quote_source,
                'document' => match ($proposal->quote_source) {
                    null => null,
                    Evidence::BODY => 'the mail body',
                    default => $proposal->quote_source,
                },
                'location' => $proposal->quote_location,
                'status' => $proposal->quote_status,
                'check' => match ($proposal->quote_status) {
                    'found' => 'found in the document',
                    'unchecked' => 'not checked, the document has no text layer',
                    default => null,
                },
            ],
            'line' => $this->line($proposal),
            'rule' => $proposal->rule,
            'policy_section' => $proposal->policy_section,
            'decided_by' => $proposal->decided_by,
            'decided_via' => $proposal->decided_via,
            'decided_at' => $proposal->decided_at?->toIso8601String(),
            'decision_reason' => $proposal->decision_reason,
            'error' => $proposal->error,
        ];
    }

    /**
     * The one line a decided block collapses to, or null while it waits.
     * A stale or failed proposal's line says the agent can propose it again.
     */
    public function line(Proposal $proposal): ?string
    {
        $at = $proposal->decided_at?->setTimezone((string) config('gateway.timezone'))->format('H:i');
        $person = self::person();
        $again = 'The agent can propose it again.';

        return match ($proposal->status) {
            ProposalStatus::Pending => null,
            ProposalStatus::Applying => 'Being written to Odoo.',
            ProposalStatus::Applied => $proposal->tier === Tier::Rule
                ? "Applied by rule {$proposal->rule} at {$at}."
                : "Approved by {$person} at {$at}. Written to Odoo.",
            ProposalStatus::Rejected => "Rejected by {$person} at {$at}: \"{$proposal->decision_reason}\"",
            ProposalStatus::Denied => $proposal->policy_section !== null
                ? "Denied under {$proposal->policy_section}: {$proposal->reasons[0]}"
                : "Denied, because {$proposal->reasons[0]}.",
            ProposalStatus::Stale => "Approved at {$at}, but the record had changed in Odoo. Nothing was written. {$again}",
            ProposalStatus::Failed => ($proposal->tier === Tier::Rule ? "Applied by rule {$proposal->rule} at {$at}." : "Approved at {$at}.")
                .' Odoo refused: '.rtrim((string) $proposal->error, '.').". {$again}",
        };
    }

    /**
     * The sentence the card tells the agent after Riku decided on it.
     */
    public function outcome(Proposal $proposal): string
    {
        $person = self::person();
        $id = $proposal->id;

        return match ($proposal->status) {
            ProposalStatus::Applied => "{$person} approved proposal {$id} and it was written to Odoo.",
            ProposalStatus::Applying => "{$person} approved proposal {$id}, and Odoo has not yet confirmed the write.",
            ProposalStatus::Stale => "{$person} approved proposal {$id}, but the record had changed in Odoo, so nothing was written.",
            ProposalStatus::Failed => "{$person} approved proposal {$id}, but Odoo refused: {$proposal->error}",
            ProposalStatus::Rejected => "{$person} rejected proposal {$id}: \"{$proposal->decision_reason}\"",
            default => "Proposal {$id} is {$proposal->status->value}.",
        };
    }

    /** "3 decisions wait for you", "1 decision waits for you" or "Nothing waits for you". */
    public static function heading(int $waiting): string
    {
        return match ($waiting) {
            0 => 'Nothing waits for you',
            1 => '1 decision waits for you',
            default => "{$waiting} decisions wait for you",
        };
    }

    /**
     * The sentence a screen shows when Odoo failed in a way the service did
     * not settle, such as "Odoo did not answer at http://localhost:8070. Try
     * again in a moment." The message of an OdooException is written for a
     * person.
     */
    public static function odooTrouble(OdooException $e): string
    {
        return rtrim($e->getMessage(), '.').'. Try again in a moment.';
    }

    /** What list_proposals tells the agent about the proposals that wait. */
    public function waitingNote(int $waiting): string
    {
        $person = self::person();

        return match ($waiting) {
            0 => "Nothing waits for {$person}.",
            1 => "1 proposal waits for {$person}. He decides it on the card or on the approval page, and you cannot decide it.",
            default => "{$waiting} proposals wait for {$person}. He decides them on the card or on the approval page, and you cannot decide them.",
        };
    }

    /**
     * The day is short, for the card ("5 Nov"). The time is absolute, for the
     * web page ("5 Nov 2026, 11:12").
     *
     * @return array{id: string, from: string|null, sender: string|null, subject: string|null, date: string|null, day: string|null, time: string|null, body: string|null, attachments: list<string>}
     */
    private static function message(string $id, ?Message $message): array
    {
        $date = $message?->date->setTimezone((string) config('gateway.timezone'));

        return [
            'id' => $id,
            'from' => $message?->from,
            'sender' => $message === null ? null : self::sender($message->from),
            'subject' => $message?->subject,
            'date' => $date?->toIso8601String(),
            'day' => $date?->format('j M'),
            'time' => $date?->format('j M Y, H:i'),
            'body' => $message?->body,
            'attachments' => $message === null ? [] : array_map(fn (Attachment $attachment): string => $attachment->name, $message->attachments),
        ];
    }

    /** The display name of a From header, or its address when it has no name. */
    private static function sender(string $from): string
    {
        $name = trim(Str::before($from, '<'), " \t\"");

        return $name !== '' ? $name : trim($from, " \t<>");
    }

    /** The first name of the user the gateway acts for, such as Riku. */
    private static function person(): string
    {
        return Str::before((string) config('gateway.user.name'), ' ');
    }
}
