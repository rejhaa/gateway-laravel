<?php

declare(strict_types=1);

use App\Mcp\Servers\GatewayServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::oauthRoutes();

Mcp::web('/mcp', GatewayServer::class)->middleware('auth:api');
