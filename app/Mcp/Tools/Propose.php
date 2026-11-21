<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Gateway\Actor;
use App\Gateway\ProposalService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('propose')]
#[Description('Propose one change to one Odoo record, citing the message and the exact passage it rests on. The gateway decides: a rule applies it to Odoo now, it waits for the user, or the policy denies it. The answer says which and what to do next. You cannot approve anything.')]
final class Propose extends GatewayTool
{
    public function __construct(private readonly ProposalService $proposals) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'system' => $schema->string()->enum(['odoo'])->description('The system the record is in.')->required(),
            'model' => $schema->string()->description('The record\'s model, such as purchase.order.line.')->required(),
            'record_id' => $schema->integer()->min(1)->description('The record\'s id.')->required(),
            'values' => $schema->object()->description('Field to value, such as {"x_confirmed_qty": 100, "x_confirmed_date": "2026-12-14"}. Dates are YYYY-MM-DD.')->required(),
            'message_id' => $schema->string()->description('The id of the message the values come from.')->required(),
            'quote' => $schema->string()->description('The exact passage, a whole row or sentence, copied from the body or from an attachment\'s lines without the "p1.l17: " prefix.')->required(),
            'title' => $schema->string()->max(255)->description('One line a buyer understands without the mail, such as "P00104 heads: 100 of 120 confirmed for 14 December, 14 days late".')->required(),
            'summary' => $schema->string()->description('Two or three sentences on what the decision means.')->required(),
        ];
    }

    protected function run(Request $request, Actor $actor): ResponseFactory
    {
        return Response::structured($this->proposals->propose($actor, $request->all())->toArray());
    }
}
