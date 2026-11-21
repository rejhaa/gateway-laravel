<?php

declare(strict_types=1);

use App\Gateway\Decision;
use App\Gateway\InputError;
use App\Gateway\ProposedWrite;
use App\Gateway\Tier;
use App\Processes\InboundPurchasing\InboundPurchasing;

/**
 * The tables in rules/agent-access.md, keyed by their header row. Each table
 * of models has one backticked model in its first column and comma-separated
 * backticked fields in its second. A third column applies to every field in
 * its row, and a model may span several rows.
 *
 * @return array{reads: array<string, list<string>>, writes: array<string, array<string, string|list<string>>>, never: array<string, array<string, string>>, sentences: array<string, string>}
 */
function agentAccessTables(): array
{
    $tables = ['reads' => [], 'writes' => [], 'never' => [], 'sentences' => []];
    $kind = null;

    foreach (file(base_path('rules/agent-access.md'), FILE_IGNORE_NEW_LINES) as $line) {
        if (! str_starts_with($line, '|')) {
            $kind = null;

            continue;
        }

        $cells = array_map(trim(...), explode('|', trim($line, '|')));

        if ($kind === null) {
            $kind = match ($cells) {
                ['Model', 'Fields'] => 'reads',
                ['Model', 'Fields', 'Value'] => 'writes',
                ['Model', 'Fields', 'Policy'] => 'never',
                ['Policy', 'Sentence'] => 'sentences',
                default => 'other',
            };

            continue;
        }

        if ($kind === 'other' || str_starts_with($cells[0], '---')) {
            continue;
        }

        if ($kind === 'sentences') {
            $tables['sentences'][$cells[0]] = $cells[1];

            continue;
        }

        preg_match('/^`([^`]+)`$/', $cells[0], $model);
        preg_match_all('/`([^`]+)`/', $cells[1], $fields);

        foreach ($fields[1] as $field) {
            match ($kind) {
                'reads' => $tables['reads'][$model[1]][] = $field,
                'never' => $tables['never'][$model[1]][$field] = $cells[2],
                'writes' => $tables['writes'][$model[1]][$field] = preg_match_all('/`([^`]+)`/', $cells[2], $values) > 0 ? $values[1] : $cells[2],
            };
        }
    }

    return $tables;
}

function purchasing(): InboundPurchasing
{
    return app(InboundPurchasing::class);
}

// Bug: the document the agent reads says something the code does not do.
it('keeps the tables in agent-access.md equal to the constants in code', function (): void {
    $tables = agentAccessTables();

    expect(array_map(array_keys(...), InboundPurchasing::READABLE))->toEqualCanonicalizing($tables['reads'])
        ->and(InboundPurchasing::WRITABLE)->toEqualCanonicalizing($tables['writes'])
        ->and(InboundPurchasing::NEVER)->toEqualCanonicalizing($tables['never'])
        ->and(InboundPurchasing::POLICY_SENTENCE)->toBe($tables['sentences']);

    expect($tables['reads'])->toHaveCount(15)
        ->and($tables['never'])->toHaveCount(5);
});

// Bug: a dotted path leads into a model that is not on the list.
it('maps a relational field only to a model on the readable list', function (): void {
    foreach (InboundPurchasing::READABLE as $model => $fields) {
        foreach ($fields as $field => $target) {
            if ($target !== null) {
                expect(InboundPurchasing::READABLE)->toHaveKey($target, message: "{$model}.{$field} leads to {$target}");
            }
        }
    }

    expect(InboundPurchasing::READABLE['purchase.order.line']['order_id'])->toBe('purchase.order')
        ->and(InboundPurchasing::READABLE['purchase.order']['currency_id'])->toBeNull();
});

// Bug: the agent changes a price or an ordered quantity.
it('denies each never-written field under its policy section', function (string $model, string $field, string $section): void {
    $decision = purchasing()->denial(new ProposedWrite($model, 1, [$field => 5]));

    expect($decision)->toEqual(Decision::denied(InboundPurchasing::POLICY_SENTENCE[$section], $section));
})->with(function (): Generator {
    foreach (InboundPurchasing::NEVER as $model => $fields) {
        foreach ($fields as $field => $section) {
            yield "{$model} {$field}" => [$model, $field, $section];
        }
    }
});

// Bug: a never-written field slips through beside a writable one.
it('denies a write that mixes a writable field with a never-written one', function (): void {
    $decision = purchasing()->denial(new ProposedWrite('purchase.order.line', 36, ['x_confirmed_qty' => 100, 'price_unit' => 9.5]));

    expect($decision?->policySection)->toBe('POL-2');
});

// Bug: a new Odoo field or model becomes writable by default.
it('denies a field or a model on neither list as outside what the agent may write', function (string $model, string $field, string $reason): void {
    $decision = purchasing()->denial(new ProposedWrite($model, 1, [$field => 'x']));

    expect($decision?->tier)->toBe(Tier::Policy)
        ->and($decision?->policySection)->toBeNull()
        ->and($decision?->reasons)->toBe([$reason]);
})->with([
    'a field' => ['purchase.order.line', 'name', 'the field name on purchase.order.line is outside what the agent may write'],
    'a model' => ['res.partner', 'email', 'the model res.partner is outside what the agent may write'],
]);

// Bug: the agent lifts a dispute.
it('denies x_dispute with any value but disputed under POL-4', function (): void {
    $decision = purchasing()->denial(new ProposedWrite('account.move', 51, ['x_dispute' => 'none', 'x_dispute_note' => 'Paid in full.']));

    expect($decision)->toEqual(Decision::denied(InboundPurchasing::POLICY_SENTENCE['POL-4'], 'POL-4'));
});

// Bug: a malformed value reaches Odoo, or a day-first date is guessed.
it('refuses a value of the wrong type as an input error', function (string $field, mixed $value, string $sentence): void {
    expect(fn () => purchasing()->denial(new ProposedWrite('purchase.order.line', 36, [$field => $value])))
        ->toThrow(InputError::class, $sentence);
})->with([
    'a day-first date' => ['date_planned', '14.12.2026', 'date_planned must be a date written YYYY-MM-DD, such as 2026-12-14.'],
    'a date that does not exist' => ['x_confirmed_date', '2026-02-30', 'x_confirmed_date must be a date written YYYY-MM-DD'],
    'a quantity as text' => ['x_confirmed_qty', '100', 'x_confirmed_qty must be a number, such as 100.'],
]);

// Bug: a correct write is refused.
it('allows the writable fields with values of their kind', function (): void {
    expect(purchasing()->denial(new ProposedWrite('purchase.order.line', 36, ['date_planned' => '2026-12-14', 'x_confirmed_qty' => 100, 'x_confirmed_date' => '2026-12-14'])))->toBeNull()
        ->and(purchasing()->denial(new ProposedWrite('account.move', 51, ['x_dispute' => 'disputed', 'x_dispute_note' => 'Short delivery.'])))->toBeNull();
});
