<?php

declare(strict_types=1);

use App\Gateway\CardToken;
use App\Gateway\ProposalStatus;
use App\Gateway\Tier;
use App\Http\Middleware\CardTokenOnly;
use App\Models\AuditEntry;
use App\Models\Proposal;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeOdoo;
use Tests\Support\Inbound;

/**
 * @param  array<string, mixed>  $body
 * @param  array<string, string>  $headers
 */
function decide(Proposal $proposal, array $body, array $headers): TestResponse
{
    return test()->postJson("/card/proposals/{$proposal->id}/decision", $body, $headers);
}

/** @return array<string, string> */
function cardHeaders(?CardToken $token = null): array
{
    return [CardToken::HEADER => ($token ?? CardToken::issue(User::primary()->id))->value];
}

/** The standard pending proposal on FakeOdoo's line, from the standard message. */
function pendingOnLine(): Proposal
{
    return Inbound::service()->propose(Inbound::agent(), Inbound::partial())->proposal;
}

beforeEach(function (): void {
    $this->odoo = FakeOdoo::start()->line();
    Inbound::mailbox();
});

// Bug: an old card approves forever.
it('rejects an expired card token', function (): void {
    $proposal = pendingOnLine();
    $headers = cardHeaders();
    $this->travel(config('gateway.card.token_hours'))->hours();

    decide($proposal, ['decision' => 'approve'], $headers)
        ->assertUnauthorized()
        ->assertExactJson(['error' => CardTokenOnly::EXPIRED]);

    expect($proposal->refresh()->status)->toBe(ProposalStatus::Pending);
});

// Bug: a forged card token approves.
it('rejects a tampered card token', function (Closure $tamper): void {
    $proposal = pendingOnLine();

    decide($proposal, ['decision' => 'approve'], [CardToken::HEADER => $tamper(CardToken::issue(User::primary()->id)->value)])
        ->assertUnauthorized();

    expect($proposal->refresh()->status)->toBe(ProposalStatus::Pending);
})->with([
    'a later expiry' => function (string $value): string {
        [$payload, $signature] = explode('.', $value);
        $claims = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);
        $claims['e'] += 86400;

        return rtrim(strtr(base64_encode(json_encode($claims)), '+/', '-_'), '=').'.'.$signature;
    },
    'another signature' => fn (string $value): string => explode('.', $value)[0].'.'.str_repeat('A', 43),
    'no signature' => fn (string $value): string => explode('.', $value)[0],
]);

// Bug: Claude's token approves on the card endpoint.
it('rejects a bearer token even with a valid card token', function (): void {
    $proposal = pendingOnLine();

    decide($proposal, ['decision' => 'approve'], [...cardHeaders(), 'Authorization' => 'Bearer anything'])
        ->assertUnauthorized();

    expect($proposal->refresh()->status)->toBe(ProposalStatus::Pending)
        ->and($this->odoo->writes)->toBe([]);
});

// Bug: the signed-in web session decides from another site through the card endpoint.
it('does not decide with a session cookie alone', function (): void {
    $proposal = pendingOnLine();

    $this->actingAs(User::primary());
    decide($proposal, ['decision' => 'approve'], [])->assertUnauthorized();

    expect($proposal->refresh()->status)->toBe(ProposalStatus::Pending);
});

// Bug: approving on the card does not write, or records the wrong channel.
it('approves through the card endpoint as Riku on the card', function (): void {
    $proposal = pendingOnLine();
    $token = CardToken::issue(User::primary()->id);

    decide($proposal, ['decision' => 'approve'], cardHeaders($token))
        ->assertOk()
        ->assertJsonPath('error', null)
        ->assertJsonPath('sentence', "Riku approved proposal {$proposal->id} and it was written to Odoo.")
        ->assertJsonPath('proposal.status', 'applied')
        ->assertJsonPath('proposal.line', 'Approved by Riku at '.now('Europe/Helsinki')->format('H:i').'. Written to Odoo.')
        ->assertJsonPath('heading', 'Nothing waits for you')
        ->assertJsonPath('waiting', 0);

    $approved = AuditEntry::query()->where('event', 'proposal.approved')->sole();

    expect($this->odoo->writes)->toHaveCount(1)
        ->and($proposal->refresh()->decided_via)->toBe('card')
        ->and($approved->client)->toBe($token->id);
});

// Bug: rejecting on the card loses the reason the agent needs.
it('rejects through the card endpoint with the reason', function (): void {
    $proposal = pendingOnLine();

    decide($proposal, ['decision' => 'reject', 'reason' => 'Ask for all 120.'], cardHeaders())
        ->assertOk()
        ->assertJsonPath('sentence', "Riku rejected proposal {$proposal->id}: \"Ask for all 120.\"")
        ->assertJsonPath('proposal.status', 'rejected');

    expect($proposal->refresh()->decision_reason)->toBe('Ask for all 120.')
        ->and($this->odoo->writes)->toBe([]);
});

// Bug: a second click on another card writes again, or fails without a word.
it('answers a decision on a decided proposal with a sentence', function (): void {
    $proposal = pendingOnLine();
    decide($proposal, ['decision' => 'approve'], cardHeaders());

    decide($proposal, ['decision' => 'approve'], cardHeaders())
        ->assertConflict()
        ->assertJsonPath('error', 'Someone already decided this one.')
        ->assertJsonPath('proposal.status', 'applied');

    expect($this->odoo->writes)->toHaveCount(1);
});

// Bug: the agent injects script into the approval screen.
it('returns an agent-written title containing HTML as plain data', function (): void {
    $title = '<img src=x onerror="alert(1)"> P00104 <b>heads</b>';
    Inbound::service()->propose(Inbound::agent(), [...Inbound::partial(), 'title' => $title]);

    $this->getJson('/card/proposals', cardHeaders())
        ->assertOk()
        ->assertHeader('Content-Type', 'application/json')
        ->assertJsonPath('groups.0.proposals.0.title', $title);
});

// Bug: the card misses what waits, or groups it under the wrong mail.
it('lists what waits grouped by mail, and what rules applied', function (): void {
    $pending = pendingOnLine();
    $applied = Proposal::factory()->create([
        'message_id' => Inbound::message()->id,
        'tier' => Tier::Rule,
        'status' => ProposalStatus::Applied,
        'rule' => 'confirmation-as-ordered',
    ]);

    $this->getJson('/card/proposals', cardHeaders())
        ->assertOk()
        ->assertJsonPath('heading', '1 decision waits for you')
        ->assertJsonPath('waiting', 1)
        ->assertJsonPath('groups.0.message.sender', 'Tai-Yuan Forge & Shaft')
        ->assertJsonPath('groups.0.message.day', '5 Nov')
        ->assertJsonPath('groups.0.pending', 1)
        ->assertJsonPath('groups.0.proposals.0.id', $pending->id)
        ->assertJsonPath('groups.0.proposals.0.because', 'You are asked because the confirmed quantity 100 is not the 120 ordered; the confirmed date 2026-12-14 is not the planned date 2026-11-30.')
        ->assertJsonPath('groups.0.proposals.0.effect.record', 'P00104, V1 forged iron head (VG-HEAD-V1), 120 ordered')
        ->assertJsonPath('groups.0.proposals.0.quote.document', 'the mail body')
        ->assertJsonPath('applied_by_rule', [['id' => $applied->id, 'title' => $applied->title, 'rule' => 'confirmation-as-ordered']]);
});
