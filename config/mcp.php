<?php

declare(strict_types=1);

return [

    /*
    | Origins besides APP_URL and localhost that may call /mcp, as a
    | comma-separated MCP_ALLOWED_ORIGINS. laravel/mcp's ValidateOrigin
    | answers 403 to any other Origin header. Requests without an Origin
    | header always pass. The package's other mcp.* settings keep their
    | defaults.
    */

    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('MCP_ALLOWED_ORIGINS', ''))))),

];
