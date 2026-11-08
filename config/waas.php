<?php

return [
    // 'mock' | 'real'. Anything else throws at resolve time.
    // NOTE: WaasClient is a singleton. Tests that change waas.* config must call
    // app()->forgetInstance(\App\Waas\WaasClient::class) before resolving it again.
    'driver' => env('WAAS_DRIVER', 'mock'),

    // Mock in production is refused unless this is explicitly true (e.g. a hackathon demo deploy).
    'allow_mock_in_production' => (bool) env('WAAS_ALLOW_MOCK_IN_PRODUCTION', false),

    // TODO(unverified): base URL, auth scheme (bearer? api key header? OAuth client credentials?) and paths.
    'base_url' => env('WAAS_BASE_URL'),
    'api_key' => env('WAAS_API_KEY'),

    // TODO(unverified): can a debit legitimately take longer than 'debit' seconds? Too low = reconcile noise, too high = stuck workers.
    'timeouts' => [
        'connect' => (int) env('WAAS_CONNECT_TIMEOUT', 3),
        'read' => (int) env('WAAS_TIMEOUT', 10),
        'debit' => (int) env('WAAS_DEBIT_TIMEOUT', 15),
    ],

        // Real client throws WaasCapabilityUnsupported unless these are true. Flip only after verifying with the docs.
    'capabilities' => [
        'balance' => (bool) env('WAAS_SUPPORTS_BALANCE', false),   // TODO(unverified)
        'reversal' => (bool) env('WAAS_SUPPORTS_REVERSAL', false), // TODO(unverified)
    ],

    'mock' => [
        'starting_balance_kobo' => (int) env('WAAS_MOCK_STARTING_BALANCE_KOBO', 5_000_000),     // ₦50,000
        'low_balance_starting_kobo' => (int) env('WAAS_MOCK_LOW_BALANCE_KOBO', 120_000),        // ₦1,200
        'reversal_window_hours' => 24,

        // Debit triggers match the SOURCE NUBAN.
        'triggers' => [
            'debit' => [
                'insufficient_funds' => '0000000001',
                'invalid_account' => '0000000002',
                'timeout_applied' => '0000000003',      // response lost, money DID move
                'timeout_not_applied' => '0000000004',  // response lost, money did NOT move
                'low_balance' => '0000000005',          // starts at low_balance_starting_kobo
            ],

            // Matched on PHONE, never BVN (the identity mock owns the magic BVNs).
            'create' => [
                'outage_phone' => '+2348000000099',
                'name_mismatch_phone' => '+2348000000098',
            ],
        ],

        // Returned for the name_mismatch_phone trigger. Deliberately unlike any real input name.
        'mismatch_account_name' => 'Zainab Bello Adeyemi (Kobo Kobo)',
    ],
];