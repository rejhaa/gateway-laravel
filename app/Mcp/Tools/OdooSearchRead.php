<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Gateway\Actor;
use App\Gateway\Processes;
use App\Gateway\ProposalStatus;
use App\Models\Proposal;
use App\Systems\Odoo\OdooReader;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('odoo_search_read')]
#[Description('Search and read Odoo records as the user. Only the models and fields in agent-access.md are readable, and a domain may use only the operators listed there. Records you may propose changes to come back with their pending and applied proposals.')]
#[IsReadOnly]
final class OdooSearchRead extends GatewayTool
{
    public function __construct(
        private readonly Processes $processes,
        private readonly OdooReader $reader,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'model' => $schema->string()->description('The Odoo model, such as purchase.order.line.')->required(),
            'domain' => $schema->array()->description('A list of &, |, ! and [field, operator, value] terms, such as [["order_id.name", "=", "P00104"]]. Empty reads every record up to the limit.'),
            'fields' => $schema->array()->items($schema->string())->description('The fields to read. id and display_name are always readable.')->required(),
            'limit' => $schema->integer()->min(1)->max(OdooReader::MAX_LIMIT)->description('At most '.OdooReader::MAX_LIMIT.' records. The default is '.OdooReader::DEFAULT_LIMIT.'.'),
        ];
    }

    protected function run(Request $request, Actor $actor): ResponseFactory
    {
        $input = $request->validate([
            'model' => ['required', 'string'],
            'domain' => ['array'],
            'fields' => ['required', 'array'],
            'limit' => ['integer', 'min:1', 'max:'.OdooReader::MAX_LIMIT],
        ]);

        $process = $this->processes->current();
        $model = $input['model'];

        $records = $this->reader->searchRead(
            $actor,
            $process->readable(),
            $model,
            $input['domain'] ?? [],
            $input['fields'],
            $input['limit'] ?? OdooReader::DEFAULT_LIMIT,
        );

        if (isset($process->writable()[$model])) {
            $records = $this->withProposals($model, $records);
        }

        return Response::structured(['records' => $records]);
    }

    /**
     * Each record with the pending and applied proposals on it, oldest first.
     *
     * @param  list<array<string, mixed>>  $records
     * @return list<array<string, mixed>>
     */
    private function withProposals(string $model, array $records): array
    {
        $proposals = Proposal::query()
            ->where('model', $model)
            ->whereIn('record_id', array_column($records, 'id'))
            ->whereIn('status', [ProposalStatus::Pending, ProposalStatus::Applying, ProposalStatus::Applied])
            ->oldest('id')
            ->get()
            ->groupBy('record_id');

        return array_map(fn (array $record): array => [
            ...$record,
            'proposals' => ($proposals[$record['id']] ?? collect())->map(fn (Proposal $proposal): array => [
                'id' => $proposal->id,
                'status' => $proposal->status->value,
                'title' => $proposal->title,
                'values' => $proposal->values,
                'message_id' => $proposal->message_id,
            ])->values()->all(),
        ], $records);
    }
}
