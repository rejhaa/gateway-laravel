<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Gateway\Actor;
use App\Gateway\ProposalPresenter;
use App\Gateway\ProposalStatus;
use App\Mcp\Resources\ProposalsCard;
use App\Models\Proposal;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\RendersApp;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_proposals')]
#[Description('The proposals grouped by the message they cite, with who decided each, when and why. This is the authority on what was decided. Call it once when the mailbox is done, so the user sees what waits for them.')]
#[IsReadOnly]
#[RendersApp(resource: ProposalsCard::class)]
final class ListProposals extends GatewayTool
{
    public function __construct(private readonly ProposalPresenter $presenter) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()->enum(self::statuses())->default('all')->description('Only proposals with this status. The default is all.'),
        ];
    }

    protected function run(Request $request, Actor $actor): ResponseFactory
    {
        $status = $request->validate(['status' => ['nullable', Rule::in(self::statuses())]])['status'] ?? 'all';

        $proposals = Proposal::query()
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->oldest('id')
            ->get();

        $waiting = Proposal::query()->where('status', ProposalStatus::Pending)->count();

        return Response::structured([
            'waiting' => $waiting,
            'note' => $this->presenter->waitingNote($waiting),
            'messages' => array_map(fn (array $group): array => [
                'message' => [
                    'id' => $group['message']['id'],
                    'from' => $group['message']['from'],
                    'subject' => $group['message']['subject'],
                    'date' => $group['message']['date'],
                ],
                'proposals' => $group['proposals']->map(fn (Proposal $proposal): array => [
                    'id' => $proposal->id,
                    'title' => $proposal->title,
                    'record' => $proposal->record_label,
                    'values' => $proposal->values,
                    'status' => $proposal->status->value,
                    'tier' => $proposal->tier->value,
                    'reasons' => $proposal->reasons,
                    'decided_by' => $proposal->decided_by,
                    'decided_via' => $proposal->decided_via,
                    'decided_at' => $proposal->decided_at?->toIso8601String(),
                    'decision_reason' => $proposal->decision_reason,
                    'error' => $proposal->error,
                ])->all(),
            ], $this->presenter->byMessage($proposals)),
        ]);
    }

    /** @return list<string> */
    private static function statuses(): array
    {
        return ['all', ...array_column(ProposalStatus::cases(), 'value')];
    }
}
