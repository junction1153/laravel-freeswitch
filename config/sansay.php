<?php
return [
    'servers' => [
        'server1' => [
            'base_url' => env('SANSAY_SERVER_1_URL'),
            'user' => env('SANSAY_SERVER_1_USER'),
            'api_key' => env('SANSAY_SERVER_1_API_KEY'),
        ],
        'server2' => [
            'base_url' => env('SANSAY_SERVER_2_URL'),
            'user' => env('SANSAY_SERVER_2_USER'),
            'api_key' => env('SANSAY_SERVER_2_API_KEY'),
        ],
    ],
];