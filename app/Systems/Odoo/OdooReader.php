<?php

declare(strict_types=1);

namespace App\Systems\Odoo;

use App\Gateway\Actor;
use App\Gateway\InputError;

/**
 * The agent's search_read. It checks the model, the fields, the domain and the
 * limit against a readable list before anything reaches Odoo.
 *
 * The readable list belongs to the process and maps each model to its readable
 * fields. A field's value is the model it leads to when a dotted path may pass
 * through it, and null otherwise:
 *
 *     ['purchase.order.line' => ['order_id' => 'purchase.order', 'name' => null]]
 *
 * A dotted path passes through a field only when that field names a model that
 * is itself on the list.
 */
final class OdooReader
{
    public const DEFAULT_LIMIT = 80;

    public const MAX_LIMIT = 200;

    /** Readable on every model, and never part of a dotted path. */
    public const ALWAYS_READABLE = ['id', 'display_name'];

    public const OPERATORS = ['=', '!=', '>', '>=', '<', '<=', 'in', 'not in', 'like', 'ilike', '=like', '=ilike'];

    private const PREFIX_OPERATORS = ['&', '|', '!'];

    public function __construct(private readonly OdooClient $client) {}

    /**
     * @param  array<string, array<string, string|null>>  $readable
     * @param  array<mixed>  $domain
     * @param  array<mixed>  $fields
     * @return list<array<string, mixed>>
     *
     * @throws InputError when the request reaches outside the readable list
     */
    public function searchRead(
        Actor $actor,
        array $readable,
        string $model,
        array $domain,
        array $fields,
        int $limit = self::DEFAULT_LIMIT,
    ): array {
        $this->checkModel($readable, $model);
        $this->checkFields($readable, $model, $fields);
        $this->checkDomain($readable, $model, $domain);

        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new InputError('The limit must be between 1 and '.self::MAX_LIMIT.'.');
        }

        return $this->client->searchRead($actor, $model, $domain, $fields, $limit);
    }

    /**
     * @param  array<string, array<string, string|null>>  $readable
     */
    private function checkModel(array $readable, string $model): void
    {
        if (! isset($readable[$model])) {
            throw new InputError("The model {$model} is not readable. Read one of: ".implode(', ', array_keys($readable)).'.');
        }
    }

    /**
     * @param  array<string, array<string, string|null>>  $readable
     * @param  array<mixed>  $fields
     */
    private function checkFields(array $readable, string $model, array $fields): void
    {
        if ($fields === [] || ! array_is_list($fields)) {
            throw new InputError('Name the fields to read as a list. An empty list would read every field.');
        }

        foreach ($fields as $field) {
            if (! is_string($field) || ! $this->isReadable($readable, $model, $field)) {
                throw new InputError('The field '.self::describe($field)." on {$model} is not readable.");
            }
        }
    }

    /**
     * @param  array<string, array<string, string|null>>  $readable
     * @param  array<mixed>  $domain
     */
    private function checkDomain(array $readable, string $model, array $domain): void
    {
        if (! array_is_list($domain)) {
            throw new InputError('The domain must be a list of terms.');
        }

        foreach ($domain as $term) {
            if (is_string($term) && in_array($term, self::PREFIX_OPERATORS, true)) {
                continue;
            }

            if (! is_array($term) || ! array_is_list($term) || count($term) !== 3 || ! is_string($term[0]) || ! is_string($term[1])) {
                throw new InputError('Each domain term must be &, |, ! or a list of a field, an operator and a value, such as ["order_id.name", "=", "P00101"].');
            }

            [$path, $operator, $value] = $term;

            if (! in_array($operator, self::OPERATORS, true)) {
                throw new InputError("The operator {$operator} is not allowed. Use one of: ".implode(', ', self::OPERATORS).'.');
            }

            $this->checkPath($readable, $model, $path);

            if (! self::isPlainValue($value)) {
                throw new InputError("The value for {$path} must be a number, a text, true, false, null or a list of those.");
            }
        }
    }

    /**
     * Each part of the path must be readable, and each part before the last must
     * lead to a readable model.
     *
     * @param  array<string, array<string, string|null>>  $readable
     */
    private function checkPath(array $readable, string $model, string $path): void
    {
        $parts = explode('.', $path);
        $last = count($parts) - 1;

        foreach ($parts as $index => $field) {
            if (! $this->isReadable($readable, $model, $field)) {
                throw new InputError("The field {$field} on {$model} in {$path} is not readable.");
            }

            if ($index === $last) {
                return;
            }

            $next = $readable[$model][$field] ?? null;

            if ($next === null || ! isset($readable[$next])) {
                throw new InputError("The field {$field} on {$model} in {$path} does not lead to a readable model.");
            }

            $model = $next;
        }
    }

    /**
     * @param  array<string, array<string, string|null>>  $readable
     */
    private function isReadable(array $readable, string $model, string $field): bool
    {
        return in_array($field, self::ALWAYS_READABLE, true) || array_key_exists($field, $readable[$model]);
    }

    private static function isPlainValue(mixed $value): bool
    {
        if (is_array($value)) {
            return array_is_list($value) && array_all($value, fn (mixed $item): bool => is_scalar($item) || $item === null);
        }

        return is_scalar($value) || $value === null;
    }

    private static function describe(mixed $field): string
    {
        return is_string($field) ? $field : (string) json_encode($field);
    }
}
