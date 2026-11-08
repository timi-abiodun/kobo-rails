<?php

return [
    'driver' => env('IDENTITY_VERIFIER', 'mock'), // mock | dojah

    'hash' => [
        'pepper'  => env('IDENTITY_HASH_PEPPER'),
        'version' => 1, // not stored in DB (VARCHAR(64)); bump when you rotate
    ],

    'dojah' => [
        'base_url'        => env('DOJAH_BASE_URL', 'https://api.dojah.io'),
        'app_id'          => env('DOJAH_APP_ID'),
        'secret_key'      => env('DOJAH_SECRET_KEY'),
        'timeout'         => 8,
        'connect_timeout' => 3,
        'endpoints'       => [ // TODO: confirm against current Dojah docs
            'bvn' => '/api/v1/kyc/bvn/full',
            'nin' => '/api/v1/kyc/nin',
        ],
    ],
];