<?php

declare(strict_types=1);

use App\Gateway\AuditEvent;
use App\Gateway\CardToken;
use App\Mcp\Resources\ProposalsCard;
use App\Mcp\Servers\GatewayServer;
use App\Mcp\Tools\ListProposals;
use App\Models\AuditEntry;
use App\Models\User;
use Laravel\Mcp\Server\Testing\TestResponse;

// Bug: the card arrives with a token the decision endpoints refuse, or no record of whom it was issued to.
it('carries a card token the endpoints accept, and records that it was issued', function (): void {
    TestResponse::macro('html', fn (): string => implode("\n", $this->content()));

    $html = GatewayServer::actingAs(User::primary())->resource(ProposalsCard::class)->assertOk()->html();

    preg_match('#<script type="application/json" id="card-config">(.+?)</script>#s', $html, $match);
    $config = json_decode($match[1], true, flags: JSON_THROW_ON_ERROR);
    $token = CardToken::check($config['token']);
    $issued = AuditEntry::query()->sole();

    expect($token)->not->toBeNull()
        ->and($config['gateway'])->toBe(rtrim(config('app.url'), '/'))
        ->and($issued->event)->toBe(AuditEvent::CardIssued)
        ->and($issued->detail)->toBe(['token_id' => $token->id]);
});

// Bug: claude.ai shows the tool's text but never draws the card, or the card may not reach the gateway.
it('is the card list_proposals renders, and may fetch from the gateway', function (): void {
    $card = new ProposalsCard;

    expect(app(ListProposals::class)->toArray()['_meta']['ui']['resourceUri'])->toBe($card->uri())
        ->and($card->resolvedAppMeta()['csp']['connectDomains'])->toBe([rtrim(config('app.url'), '/')]);
});
