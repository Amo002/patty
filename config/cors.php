<?php

/*
| S6: CORS is off. The UI is served from the same origin as the API, so it never needs CORS, and
| server-side clients (the POS, Postman) do not use it. Laravel's default answered preflights from
| any origin with `*`, which would let any page in the same browser send JSON writes to the local
| API. With no path listed, a cross-origin preflight gets no Access-Control-Allow-Origin header,
| the browser refuses, and the forged request is never sent. RequireJsonWrites makes every write
| need that preflight. To open the API to another origin later, list it here, never `*`.
*/

return [

    'paths' => [],

    'allowed_methods' => ['*'],

    'allowed_origins' => [],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
