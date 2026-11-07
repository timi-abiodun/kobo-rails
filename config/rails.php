<?php

return [
    'mode' => env('RAIL_MODE', 'mock'),   // mock | hydrogen
    'hydrogen' => [
        'base_url' => env('HYDROGEN_BASE_URL'),
        'api_key' => env('HYDROGEN_API_KEY'),
    ],
];
