<?php

declare(strict_types=1);

use App\Gateway\Actor;
use App\Gateway\AuditEvent;
use App\Gateway\AuditOutcome;
use App\Models\AuditEntry;
use App\Models\Proposal;
use App\Systems\Odoo\Exceptions\OdooAccessDenied;
use App\Systems\Odoo\Exceptions\OdooAuthenticationFailed;
use App\Systems\Odoo\Exceptions\OdooException;
use App\Systems\Odoo\Exceptions\OdooNotFound;
use App\Systems\Odoo\Exceptions\OdooRejected;
use App\Systems\Odoo\Exceptions\OdooUnexpected;
use App\Systems\Odoo\Exceptions\OdooUnreachable;
use App\Systems\Odoo\OdooClient;
use GuzzleHttp\Exception\NetworkTimeoutException;
use GuzzleHttp\Promise\Create;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

const ODOO_URL = 'http://odoo.test';

beforeEach(function (): void {
    $this->keysFile = tempnam(sys_get_temp_dir(), 'odoo-keys-');
    file_put_contents($this->keysFile, json_encode(['riku' => ['api_key' => 'key-one']]));

    config([
        'gateway.odoo.url' => ODOO_URL,
        'gateway.odoo.keys_file' => $this->keysFile,
        'gateway.user.odoo_login' => 'riku',
    ]);

    Http::preventStrayRequests();
});

afterEach(function (): void {
    @unlink($this->keysFile);
});

function odooClient(): OdooClient
{
    return app(OdooClient::class);
}

function odooAgent(): Actor
{
    return Actor::agent('riku', 'Claude');
}

// Bug: requests reach Odoo in a shape JSON-2 does not accept, or with a stale key after a reseed.
it('sends search_read to JSON-2 with the key the keys file holds at the time of the call', function (): void {
    Http::fake([ODOO_URL.'/json/2/purchase.order/search_read' => Http::response([['id' => 7, 'name' => 'P00104']])]);

    $records = odooClient()->searchRead(odooAgent(), 'purchase.order', [['name', '=', 'P00104']], ['name'], 5);
    file_put_contents($this->keysFile, json_encode(['riku' => ['api_key' => 'key-two']]));
    odooClient()->searchRead(odooAgent(), 'purchase.order', [], ['name'], 5);

    expect($records)->toBe([['id' => 7, 'name' => 'P00104']]);

    $sent = Http::recorded()->map(fn (array $pair): Request => $pair[0]);
    expect($sent)->toHaveCount(2)
        ->and($sent[0]->method())->toBe('POST')
        ->and($sent[0]->data())->toBe(['domain' => [['name', '=', 'P00104']], 'fields' => ['name'], 'limit' => 5])
        ->and($sent[0]->header('Authorization'))->toBe(['bearer key-one'])
        ->and($sent[1]->header('Authorization'))->toBe(['bearer key-two']);
});

// Bug: a write is sent in a shape Odoo ignores, so nothing changes and nothing fails.
it('sends write with the ids and vals Odoo expects', function (): void {
    Http::fake([ODOO_URL.'/json/2/purchase.order.line/write' => Http::response('true')]);

    odooClient()->write(odooAgent(), 'purchase.order.line', 36, ['x_confirmed_qty' => 100]);

    Http::assertSent(fn (Request $request): bool => $request->url() === ODOO_URL.'/json/2/purchase.order.line/write'
        && $request->data() === ['ids' => [36], 'vals' => ['x_confirmed_qty' => 100]]);
});

// Bug: an Odoo error is misread, or a body that is not JSON crashes the client.
it('maps each Odoo answer to its exception without retrying it', function (int $status, string $body, string $exception, string $message): void {
    Http::fake([ODOO_URL.'/json/2/purchase.order/search_read' => Http::response($body, $status)]);

    try {
        odooClient()->searchRead(odooAgent(), 'purchase.order', [], ['name'], 5);
        $this->fail('No exception was thrown.');
    } catch (OdooException $e) {
        expect($e)->toBeInstanceOf($exception)
            ->and($e->getMessage())->toBe($message)
            ->and($e->getMessage())->not->toContain('Traceback');
    }

    Http::assertSentCount(1);
})->with([
    '401' => [401, '{"name":"odoo.exceptions.AccessDenied","message":"Invalid apikey","debug":"Traceback 401"}', OdooAuthenticationFailed::class, 'Invalid apikey'],
    '403' => [403, '{"name":"odoo.exceptions.AccessError","message":"You are not allowed","debug":"Traceback 403"}', OdooAccessDenied::class, 'You are not allowed'],
    '404' => [404, '{"name":"werkzeug.exceptions.NotFound","message":"Unknown model","debug":"Traceback 404"}', OdooNotFound::class, 'Unknown model'],
    '422' => [422, '{"name":"odoo.exceptions.ValidationError","message":"Date is invalid","debug":"Traceback 422"}', OdooRejected::class, 'Date is invalid'],
    '500' => [500, '{"name":"builtins.ValueError","message":"Invalid field foo","debug":"Traceback 500"}', OdooUnexpected::class, 'Invalid field foo'],
    '404 with HTML' => [404, '<html><body>Database not found</body></html>', OdooUnexpected::class, 'Odoo answered 404 to search_read on purchase.order with a body that is not a JSON error.'],
    '200 with HTML' => [200, '<html><body>Login</body></html>', OdooUnexpected::class, 'Odoo answered search_read on purchase.order with a body that is not JSON.'],
    '200 with an object' => [200, '{"id":7}', OdooUnexpected::class, 'Odoo answered search_read on purchase.order with a result of an unexpected shape.'],
]);

// Bug: Odoo's traceback reaches the agent, or a huge one floods the log.
it('keeps Odoo\'s debug field out of the message and trims it in the log context', function (): void {
    $debug = 'Traceback '.str_repeat('x', 5000);
    Http::fake([ODOO_URL.'/json/2/purchase.order/search_read' => Http::response(['name' => 'builtins.ValueError', 'message' => 'Invalid domain', 'debug' => $debug], 500)]);

    $exception = null;

    try {
        odooClient()->searchRead(odooAgent(), 'purchase.order', [], ['name'], 5);
    } catch (OdooUnexpected $e) {
        $exception = $e;
    }

    expect($exception?->getMessage())->toBe('Invalid domain')
        ->and($exception?->context())->toMatchArray([
            'model' => 'purchase.order',
            'method' => 'search_read',
            'status' => 500,
            'odoo_error' => 'builtins.ValueError',
        ])
        ->and($exception?->context()['debug'])->toBe(mb_substr($debug, 0, 2000));
});

// Bug: a moment of Odoo restarting fails a read the agent needs.
it('retries a read after a 503 and returns the next answer', function (): void {
    Sleep::fake();
    Http::fake([ODOO_URL.'/json/2/purchase.order/search_read' => Http::sequence()
        ->push(['name' => 'Service Unavailable', 'message' => 'Restarting'], 503)
        ->push([['id' => 7]])]);

    $records = odooClient()->searchRead(odooAgent(), 'purchase.order', [], ['id'], 5);

    expect($records)->toBe([['id' => 7]]);
    Http::assertSentCount(2);
});

// Bug: reads hammer an Odoo that is down, or never give up.
it('gives up on a read after two retries with a growing wait', function (): void {
    Sleep::fake();
    Http::fake([ODOO_URL.'/json/2/purchase.order/search_read' => Http::response('Bad gateway', 502)]);

    expect(fn () => odooClient()->searchRead(odooAgent(), 'purchase.order', [], ['id'], 5))
        ->toThrow(OdooUnexpected::class);

    Http::assertSentCount(3);
    Sleep::assertSequence([Sleep::for(200)->milliseconds(), Sleep::for(600)->milliseconds()]);
});

// Bug: a write that may have reached Odoo is sent again, so it is applied twice.
it('does not retry a write after the request was sent', function (): void {
    Sleep::fake();
    Http::fake([ODOO_URL.'/json/2/purchase.order.line/write' => fn (Request $request) => Create::rejectionFor(
        new NetworkTimeoutException('cURL error 28: Operation timed out', $request->toPsrRequest()),
    )]);

    expect(fn () => odooClient()->write(odooAgent(), 'purchase.order.line', 36, ['x_confirmed_qty' => 100]))
        ->toThrow(OdooUnreachable::class);

    Http::assertSentCount(1);
});

// Bug: a 503 on a write is taken as safe to repeat, though Odoo may have committed it.
it('does not retry a write that got a 503', function (): void {
    Sleep::fake();
    Http::fake([ODOO_URL.'/json/2/purchase.order.line/write' => Http::response('Unavailable', 503)]);

    expect(fn () => odooClient()->write(odooAgent(), 'purchase.order.line', 36, ['x_confirmed_qty' => 100]))
        ->toThrow(OdooUnexpected::class);

    Http::assertSentCount(1);
});

// Bug: a write fails for good when Odoo was only briefly not accepting connections.
it('retries a write when the connection could not be opened', function (): void {
    Sleep::fake();
    Http::fake([ODOO_URL.'/json/2/purchase.order.line/write' => Http::sequence()
        ->pushFailedConnection()
        ->push('true')]);

    odooClient()->write(odooAgent(), 'purchase.order.line', 36, ['x_confirmed_qty' => 100]);

    Http::assertSentCount(2);
});

// Bug: an Odoo write leaves no trace, or the trace holds the API key.
it('audits a write with its model, method, fields and outcome and without the key', function (): void {
    $proposal = Proposal::factory()->create();
    Http::fake([ODOO_URL.'/json/2/purchase.order.line/write' => Http::response('true')]);

    odooClient()->write(Actor::rule('planned-date-under-cap'), 'purchase.order.line', 36, ['date_planned' => '2026-12-14 10:00:00'], proposalId: $proposal->id);

    $row = AuditEntry::sole();
    expect($row->event)->toBe(AuditEvent::OdooWrite)
        ->and($row->outcome)->toBe(AuditOutcome::Ok)
        ->and($row->actor)->toBe('planned-date-under-cap')
        ->and($row->proposal_id)->toBe($proposal->id)
        ->and($row->detail)->toBe([
            'model' => 'purchase.order.line',
            'method' => 'write',
            'ids' => [36],
            'fields' => ['date_planned'],
        ])
        ->and(json_encode($row->getAttributes()))->not->toContain('key-one');
});

// Bug: a refused or failed call is missing from the audit log, or recorded as done.
it('audits a refused read and a failed read with their outcome', function (): void {
    Sleep::fake();
    Http::fake([
        ODOO_URL.'/json/2/purchase.order/search_read' => Http::response(['name' => 'odoo.exceptions.AccessError', 'message' => 'No access', 'debug' => ''], 403),
        ODOO_URL.'/json/2/purchase.order/read' => Http::failedConnection(),
    ]);

    expect(fn () => odooClient()->searchRead(odooAgent(), 'purchase.order', [], ['name'], 5))->toThrow(OdooAccessDenied::class)
        ->and(fn () => odooClient()->read(odooAgent(), 'purchase.order', [7], ['name']))->toThrow(OdooUnreachable::class);

    $rows = AuditEntry::query()->orderBy('id')->get();
    expect($rows)->toHaveCount(2)
        ->and($rows[0]->event)->toBe(AuditEvent::OdooRead)
        ->and($rows[0]->outcome)->toBe(AuditOutcome::Refused)
        ->and($rows[0]->detail)->toMatchArray(['method' => 'search_read', 'status' => 403, 'odoo_error' => 'odoo.exceptions.AccessError'])
        ->and($rows[1]->outcome)->toBe(AuditOutcome::Error)
        ->and($rows[1]->detail)->toMatchArray(['method' => 'read', 'ids' => [7], 'fields' => ['name']]);
});

// Bug: a missing key sends an unauthenticated request instead of saying what is wrong.
it('refuses to call Odoo when the keys file has no key for the user', function (): void {
    config(['gateway.user.odoo_login' => 'someone-else']);
    Http::fake();

    expect(fn () => odooClient()->searchRead(odooAgent(), 'purchase.order', [], ['name'], 5))
        ->toThrow(OdooAuthenticationFailed::class, 'The keys file has no Odoo API key for the login someone-else.');

    Http::assertNothingSent();
});
