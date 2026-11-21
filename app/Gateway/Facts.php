<?php

declare(strict_types=1);

namespace App\Gateway;

/**
 * What the gateway read about a record, as the process reads it. The core
 * stores these with a proposal and asks them whether an approval is still
 * truthful. It never looks inside them.
 */
interface Facts
{
    /** How a person names the record, such as "P00104, V1 forged iron head (VG-HEAD-V1), 120 ordered". */
    public function label(): string;

    /**
     * The record's current values of these fields, in the form a proposal
     * states them. An empty value is null.
     *
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    public function values(array $fields): array;

    /**
     * The facts as stored in proposals.facts.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array;

    /**
     * Whether facts stored with a proposal still describe this record, so an
     * approval given on them still holds. The written values are compared
     * separately.
     *
     * @param  array<string, mixed>  $stored  what toArray() returned when the proposal was made
     */
    public function matches(array $stored): bool;
}
