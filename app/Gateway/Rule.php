<?php

declare(strict_types=1);

namespace App\Gateway;

/**
 * One rule that may apply a write without a person. A rule is pure. It reads
 * nothing and returns phrases, so each condition can be tested with plain objects.
 */
interface Rule
{
    /** The name the audit log and the screens show, such as confirmation-as-ordered. */
    public function name(): string;

    /** Whether the write is this rule's kind of write. */
    public function applies(ProposedWrite $write): bool;

    /**
     * Every failed condition as a phrase, or an empty list when the rule passes.
     *
     * @return list<string>
     */
    public function failures(ProposedWrite $write, Facts $facts, Evidence $evidence): array;

    /** The reason shown when the rule applies the write. */
    public function passReason(ProposedWrite $write, Facts $facts): string;
}
