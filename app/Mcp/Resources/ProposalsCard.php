<?php

declare(strict_types=1);

namespace App\Mcp\Resources;

use App\Gateway\Actor;
use App\Gateway\Audit;
use App\Gateway\AuditEvent;
use App\Gateway\CardToken;
use App\Mcp\Tools\GatewayTool;
use App\Models\User;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\AppResource;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Ui\AppMeta;
use Laravel\Mcp\Server\Ui\Csp;

/**
 * The card claude.ai draws under list_proposals (DESIGN.md, section 8). The
 * HTML carries a new card token and the gateway's URL. The card's script
 * reads the proposals from the gateway itself and posts each decision there,
 * so nothing it shows comes from the tool result.
 */
#[Title('Proposals')]
#[Description('The proposals that wait for the user, grouped by mail, with a button to approve and one to reject each. The user decides here, and the agent cannot.')]
final class ProposalsCard extends AppResource
{
    public function handle(Request $request, Audit $audit): Response
    {
        $token = CardToken::issue(User::primary()->id);

        $audit->record(
            AuditEvent::CardIssued,
            Actor::agent((string) config('gateway.user.odoo_login'), GatewayTool::client($request)),
            detail: ['token_id' => $token->id],
        );

        return Response::view('mcp.proposals-card', [
            'title' => $this->title(),
            'config' => [
                'gateway' => self::gateway(),
                'token' => $token->value,
                'page' => self::gateway().'/',
            ],
        ]);
    }

    /** The card may fetch from the gateway only. */
    public function appMeta(): AppMeta
    {
        return AppMeta::make()->csp(Csp::make()->connectDomains([self::gateway()]));
    }

    private static function gateway(): string
    {
        return rtrim((string) config('app.url'), '/');
    }
}
