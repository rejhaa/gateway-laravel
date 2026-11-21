<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Gateway\Actor;
use App\Gateway\CardToken;
use App\Gateway\DecisionRefused;
use App\Gateway\ProposalPresenter;
use App\Gateway\ProposalService;
use App\Gateway\ProposalStatus;
use App\Models\Proposal;
use App\Systems\Odoo\Exceptions\OdooException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * The card's two endpoints. They answer JSON to the card in the Claude chat,
 * and accept only a card token (DESIGN.md, sections 4 and 8).
 */
final class CardController extends Controller
{
    public function __construct(
        private readonly ProposalPresenter $presenter,
        private readonly ProposalService $proposals,
    ) {}

    /** Everything that waits, grouped by mail, and what rules applied. */
    public function index(): JsonResponse
    {
        return response()->json($this->presenter->card());
    }

    /**
     * Approves or rejects one proposal as the user on the card. A refusal and
     * an Odoo error come back as a sentence. Anything unexpected is reported.
     */
    public function decide(Request $request, Proposal $proposal): JsonResponse
    {
        $input = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'reject'])],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        /** @var CardToken $token */
        $token = $request->attributes->get(CardToken::class);
        $actor = Actor::card((string) config('gateway.user.odoo_login'), $token->id);
        $reason = $input['reason'] ?? null;

        try {
            $proposal = $input['decision'] === 'approve'
                ? $this->proposals->approve($proposal, $actor, $reason)
                : $this->proposals->reject($proposal, $actor, (string) $reason);
        } catch (DecisionRefused $e) {
            return $this->answer($proposal->refresh(), error: $e->getMessage(), status: 409);
        } catch (OdooException $e) {
            report($e);

            return $this->answer($proposal->refresh(), error: ProposalPresenter::odooTrouble($e), status: 502);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'error' => 'The gateway could not record this decision. Open the approval page and try again.',
                'sentence' => null,
                'proposal' => null,
                'heading' => null,
                'waiting' => null,
            ], 500);
        }

        return $this->answer($proposal, sentence: $this->presenter->outcome($proposal));
    }

    private function answer(Proposal $proposal, ?string $sentence = null, ?string $error = null, int $status = 200): JsonResponse
    {
        $waiting = Proposal::query()->where('status', ProposalStatus::Pending)->count();

        return response()->json([
            'error' => $error,
            'sentence' => $sentence,
            'proposal' => $this->presenter->block($proposal),
            'heading' => ProposalPresenter::heading($waiting),
            'waiting' => $waiting,
        ], $status);
    }
}
