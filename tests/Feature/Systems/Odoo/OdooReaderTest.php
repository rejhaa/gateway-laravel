<?php

declare(strict_types=1);

use App\Gateway\Actor;
use App\Gateway\InputError;
use App\Systems\Odoo\OdooReader;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

const READER_ODOO_URL = 'http://odoo.test';

/**
 * A small readable list in the shape a process gives: model to field to the
 * model a dotted path may continue into.
 */
const READER_READABLE = [
    'purchase.order.line' => [
        'order_id' => 'purchase.order',
        'product_id' => 'product.product',
        'currency_id' => null,
        'product_qty' => null,
    ],
    'purchase.order' => [
        'name' => null,
        'state' => null,
        'partner_id' => 'res.partner',
    ],
    'product.product' => [
        'default_code' => null,
    ],
];

beforeEach(function (): void {
    $this->keysFile = tempnam(sys_get_temp_dir(), 'odoo-keys-');
    file_put_contents($this->keysFile, json_encode(['riku' => ['api_key' => 'secret']]));

    config([
        'gateway.odoo.url' => READER_ODOO_URL,
        'gateway.odoo.keys_file' => $this->keysFile,
        'gateway.user.odoo_login' => 'riku',
    ]);

    Http::preventStrayRequests();
});

afterEach(function (): void {
    @unlink($this->keysFile);
});

// Bug: the reader blocks a read the list allows, so the agent cannot find the line a mail is about.
it('passes a read within the list to Odoo unchanged', function (): void {
    Http::fake([READER_ODOO_URL.'/json/2/purchase.order.line/search_read' => Http::response([['id' => 36]])]);
    $domain = ['|', ['order_id.name', '=', 'P00104'], '!', ['product_id.default_code', 'in', ['VG-HEAD-V1', 'VG-SHAFT']]];

    $records = app(OdooReader::class)->searchRead(
        Actor::agent('riku', 'Claude'),
        READER_READABLE,
        'purchase.order.line',
        $domain,
        ['id', 'display_name', 'order_id', 'product_qty'],
        limit: 200,
    );

    expect($records)->toBe([['id' => 36]]);
    Http::assertSent(fn (Request $request): bool => $request->data() === [
        'domain' => $domain,
        'fields' => ['id', 'display_name', 'order_id', 'product_qty'],
        'limit' => 200,
    ]);
});

// Bug: data outside the list leaks through the fields, a filter or a nested domain, or a refused read still reaches Odoo.
it('refuses a read outside the list and makes no Odoo call', function (string $model, array $domain, array $fields, int $limit, string $sentence): void {
    Http::fake();

    expect(fn () => app(OdooReader::class)->searchRead(Actor::agent('riku', 'Claude'), READER_READABLE, $model, $domain, $fields, $limit))
        ->toThrow(InputError::class, $sentence);

    Http::assertNothingSent();
})->with([
    'an unlisted model' => ['res.users', [], ['name'], 80, 'The model res.users is not readable.'],
    'an unlisted field' => ['purchase.order.line', [], ['product_qty', 'price_unit'], 80, 'The field price_unit on purchase.order.line is not readable.'],
    'no fields, which Odoo reads as every field' => ['purchase.order.line', [], [], 80, 'Name the fields to read as a list.'],
    'an unlisted field in the domain' => ['purchase.order.line', [['price_unit', '>', 0]], ['id'], 80, 'The field price_unit on purchase.order.line in price_unit is not readable.'],
    'the any operator' => ['purchase.order.line', [['order_id', 'any', [['amount_total', '>', 0]]]], ['id'], 80, 'The operator any is not allowed.'],
    'the child_of operator' => ['purchase.order', [['partner_id', 'child_of', 3]], ['id'], 80, 'The operator child_of is not allowed.'],
    'a dotted path to an unreadable field' => ['purchase.order.line', [['order_id.amount_total', '>', 0]], ['id'], 80, 'The field amount_total on purchase.order in order_id.amount_total is not readable.'],
    'a dotted path through a field that leads nowhere readable' => ['purchase.order.line', [['currency_id.name', '=', 'EUR']], ['id'], 80, 'The field currency_id on purchase.order.line in currency_id.name does not lead to a readable model.'],
    'a dotted path into a model not on the list' => ['purchase.order', [['partner_id.name', '=', 'Forge']], ['id'], 80, 'The field partner_id on purchase.order in partner_id.name does not lead to a readable model.'],
    'a dotted path through id' => ['purchase.order.line', [['id.name', '=', 'x']], ['id'], 80, 'The field id on purchase.order.line in id.name does not lead to a readable model.'],
    'a nested domain hidden in a value' => ['purchase.order.line', [['order_id', 'in', [['state', '=', 'draft']]]], ['id'], 80, 'The value for order_id must be'],
    'a malformed term' => ['purchase.order.line', [['order_id', '=']], ['id'], 80, 'Each domain term must be'],
    'a limit over 200' => ['purchase.order.line', [], ['id'], 201, 'The limit must be between 1 and 200.'],
]);
