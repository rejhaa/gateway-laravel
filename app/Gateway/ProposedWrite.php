<?php

declare(strict_types=1);

namespace App\Gateway;

/**
 * One write of one or more fields on one record, as the agent proposed it.
 * Values are in the form the agent sends them, such as a date as YYYY-MM-DD.
 */
final readonly class ProposedWrite
{
    /**
     * @param  array<string, mixed>  $values  field to proposed value
     */
    public function __construct(
        public string $model,
        public int $recordId,
        public array $values,
    ) {}

    /** @return list<string> */
    public function fields(): array
    {
        return array_keys($this->values);
    }

    public function has(string $field): bool
    {
        return array_key_exists($field, $this->values);
    }

    public function without(string $field): self
    {
        $values = $this->values;
        unset($values[$field]);

        return new self($this->model, $this->recordId, $values);
    }
}
