<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Gateway\Actor;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('whoami')]
#[Description('Who you act for in Odoo and what day it is. Call it first.')]
#[IsReadOnly]
final class Whoami extends GatewayTool
{
    protected function run(Request $request, Actor $actor): ResponseFactory
    {
        return Response::structured([
            'name' => (string) config('gateway.user.name'),
            'role' => (string) config('gateway.user.role'),
            'odoo_login' => (string) config('gateway.user.odoo_login'),
            'today' => config('gateway.today') ?? now((string) config('gateway.timezone'))->toDateString(),
        ]);
    }
}
