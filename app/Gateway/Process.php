<?php

declare(strict_types=1);

namespace App\Gateway;

use App\Models\Proposal;
use App\Systems\Odoo\Exceptions\OdooException;

/**
 * A business process the gateway runs. It supplies the access lists, the
 * rules and the policy, and it reads and writes its records. The core calls
 * these methods in the order of DESIGN.md, section 6.
 */
interface Process
{
    /** The name proposals and config/gateway.php know the process by, such as inbound-purchasing. */
    public function name(): string;

    /** The system the process reads and writes, such as odoo. */
    public function system(): string;

    /**
     * Models and fields the agent may read. Each model maps its fields to the
     * model a dotted path may continue into, or to null where a path stops.
     *
     * @return array<string, array<string, string|null>>
     */
    public function readable(): array;

    /**
     * Models and fields a proposal may write, each field mapped to the kind of
     * value it takes.
     *
     * @return array<string, array<string, string|list<string>>>
     */
    public function writable(): array;

    /**
     * The documents get_policy returns.
     *
     * @return array<string, string> file name to markdown
     */
    public function policyDocuments(): array;

    /**
     * Step 4. The denial, or null when the write may be proposed.
     *
     * @throws InputError when a value has the wrong type
     */
    public function denial(ProposedWrite $write): ?Decision;

    /**
     * Step 5, and again at approval. Reads the record and the facts the rules
     * and the screens need, as the actor.
     *
     * @throws InputError when the record does not exist
     * @throws OdooException
     */
    public function facts(Actor $actor, ProposedWrite $write): Facts;

    /**
     * Step 6. The write the rules judge, after the fields that belong together
     * are checked and a value equal to the record's current one is dropped.
     *
     * @throws InputError
     */
    public function prepare(ProposedWrite $write, Facts $facts): ProposedWrite;

    /**
     * Rules in the order they are tried.
     *
     * @return list<Rule>
     */
    public function rules(): array;

    /** The reason a write waits for a person when no rule applies to its kind at all. */
    public function humanReason(ProposedWrite $write): string;

    /**
     * Writes the values to the record as the actor.
     *
     * @throws OdooException
     */
    public function write(Actor $actor, ProposedWrite $write, int $proposalId): void;

    /**
     * What the proposal changes, as the rows of its block on the screens.
     *
     * @return array{
     *     kind: string|null,
     *     record: string,
     *     rows: list<array{label: string, now: string|null, after: string, note: string|null, warning: string|null}>,
     *     notice: string|null,
     * }
     */
    public function effect(Proposal $proposal): array;
}
