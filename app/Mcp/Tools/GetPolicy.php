<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Gateway\Actor;
use App\Gateway\Processes;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_policy')]
#[Description('The purchasing policy, the approval rules and what you may read and write, each as a file name and its markdown. Read them before you propose anything.')]
#[IsReadOnly]
final class GetPolicy extends GatewayTool
{
    public function __construct(private readonly Processes $processes) {}

    protected function run(Request $request, Actor $actor): ResponseFactory
    {
        $documents = $this->processes->current()->policyDocuments();

        return Response::structured([
            'documents' => array_map(
                fn (string $name, string $markdown): array => ['name' => $name, 'markdown' => $markdown],
                array_keys($documents),
                array_values($documents),
            ),
        ]);
    }
}
