<?php

declare(strict_types=1);

use App\Gateway\AuditEvent;
use App\Gateway\CardToken;
use App\Gateway\ProposalStatus;
use App\Models\AuditEntry;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\Support\FakeOdoo;
use Tests\Support\Inbound;

const CALLBACK = 'https://claude.ai/api/mcp/auth_callback';

/**
 * Connects a client the way claude.ai does: dynamic client registration, the
 * sign-in, the authorization page and its Connect button, and the code
 * exchange with PKCE.
 *
 * @return array{client_id: string, access_token: string, refresh_token: string}
 */
function connectClaude(): array
{
    $client = test()->postJson('/oauth/register', ['client_name' => 'Claude', 'redirect_uris' => [CALLBACK]])
        ->assertCreated()
        ->json('client_id');

    $verifier = Str::random(64);
    // In the order Laravel sorts a query, so the redirect back from the sign-in compares equal.
    $authorize = '/oauth/authorize?'.http_build_query([
        'client_id' => $client,
        'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
        'code_challenge_method' => 'S256',
        'redirect_uri' => CALLBACK,
        'response_type' => 'code',
        'scope' => 'mcp:use',
        'state' => 'state-1',
    ]);

    test()->get($authorize)->assertRedirect(route('login'));
    test()->get('/login')->assertRedirect(url($authorize));
    test()->get($authorize)->assertOk()->assertSee('Connect Claude?')->assertSee('Cancel');

    $location = test()->post(route('passport.authorizations.approve'), [
        'state' => '',
        'client_id' => $client,
        'auth_token' => session('authToken'),
    ])->assertRedirect()->headers->get('Location');

    parse_str((string) parse_url($location, PHP_URL_QUERY), $answer);

    expect($location)->toStartWith(CALLBACK)
        ->and($answer['state'])->toBe('state-1');

    $tokens = test()->postJson('/oauth/token', [
        'grant_type' => 'authorization_code',
        'client_id' => $client,
        'redirect_uri' => CALLBACK,
        'code_verifier' => $verifier,
        'code' => $answer['code'],
    ])->assertOk()->json();

    // A real client holds no web session, so later requests carry the bearer token alone.
    auth()->forgetGuards();

    return ['client_id' => $client, ...$tokens];
}

/**
 * The headers and body of a 2026-07-28 tools/call.
 *
 * @param  array<string, mixed>  $arguments
 * @return array{0: array<string, mixed>, 1: array<string, string>}
 */
function toolsCall(string $tool, array $arguments = []): array
{
    return [
        [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => $tool,
                'arguments' => (object) $arguments,
                '_meta' => [
                    'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                    'io.modelcontextprotocol/clientCapabilities' => (object) [],
                ],
            ],
        ],
        ['MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => 'tools/call', 'Mcp-Name' => $tool, 'Accept' => 'application/json, text/event-stream'],
    ];
}

// Bug: the server is open, or a client that accepts no JSON is sent to the sign-in page instead of told to authorize.
it('answers POST /mcp without a token with 401 and where to authorize', function (string $accept): void {
    [$body, $headers] = toolsCall('whoami');

    $this->call('POST', '/mcp', server: $this->transformHeadersToServerVars([...$headers, 'Accept' => $accept, 'Content-Type' => 'application/json']), content: json_encode($body))
        ->assertUnauthorized()
        ->assertHeader('WWW-Authenticate', 'Bearer realm="mcp", resource_metadata="'.url('/.well-known/oauth-protected-resource/mcp').'"');

    expect(AuditEntry::count())->toBe(0);
})->with(['an MCP client' => 'application/json, text/event-stream', 'no preference' => '*/*']);

// Bug: the newest protocol is broken by middleware or auth, or the audit log cannot tell which client acted.
it('answers a 2026-07-28 tools/call with a Passport token through the real route', function (): void {
    $tokens = connectClaude();
    [$body, $headers] = toolsCall('whoami');

    $this->postJson('/mcp', $body, [...$headers, 'Authorization' => "Bearer {$tokens['access_token']}"])
        ->assertOk()
        ->assertJsonPath('result.isError', false)
        ->assertJsonPath('result.structuredContent.name', 'Riku Lindgren');

    $called = AuditEntry::query()->where('event', AuditEvent::ToolCalled)->sole();

    expect($called->tool)->toBe('whoami')
        ->and($called->client)->toBe('Claude');
});

// Bug: every token refresh looks like a new connection, or a connection leaves no trace.
it('records client.connected once per client', function (): void {
    $tokens = connectClaude();

    $this->postJson('/oauth/token', [
        'grant_type' => 'refresh_token',
        'client_id' => $tokens['client_id'],
        'refresh_token' => $tokens['refresh_token'],
    ])->assertOk();

    $connected = AuditEntry::query()->where('event', AuditEvent::ClientConnected)->sole();

    expect($connected->client)->toBe('Claude')
        ->and($connected->detail)->toBe(['client_id' => $tokens['client_id']]);
});

// Bug: Claude's token approves on the card endpoint or on Livewire's update endpoint.
it('refuses a real Passport token on both decision endpoints', function (): void {
    $odoo = FakeOdoo::start()->line();
    Inbound::mailbox();
    $proposal = Inbound::service()->propose(Inbound::agent(), Inbound::partial())->proposal;
    $bearer = 'Bearer '.connectClaude()['access_token'];

    $this->postJson("/card/proposals/{$proposal->id}/decision", ['decision' => 'approve'], [
        CardToken::HEADER => CardToken::issue(User::primary()->id)->value,
        'Authorization' => $bearer,
    ])->assertUnauthorized();

    $this->actingAs(User::primary())
        ->withHeaders(['Authorization' => $bearer, 'X-Livewire' => '1'])
        ->postJson(route('livewire.update'), ['components' => [['calls' => [['method' => 'approve', 'params' => [$proposal->id]]]]]])
        ->assertUnauthorized();

    expect($proposal->refresh()->status)->toBe(ProposalStatus::Pending)
        ->and($odoo->writes)->toBe([]);
});
