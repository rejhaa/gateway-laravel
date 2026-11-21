<?php

declare(strict_types=1);

return [

    /*
    | Only the card's endpoints answer cross-origin requests. The card runs in
    | an iframe on a subdomain of claudemcpcontent.com and sends its token in
    | the X-Card-Token header, never a cookie.
    */

    'paths' => ['card/*'],

    'allowed_methods' => ['GET', 'POST'],

    'allowed_origins' => [],

    'allowed_origins_patterns' => ['#^https://[a-z0-9-]+\.claudemcpcontent\.com$#'],

    'allowed_headers' => ['X-Card-Token', 'Content-Type'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
