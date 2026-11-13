<?php

return [
    'defaults' => [
        'guard' => env('AUTH_GUARD', 'web'),
        'passwords' => 'users',
    ],
    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],
    ],
    'providers' => [
        'users' => [
            'driver' => 'qntd_accounts',
        ],
    ],
    // Không dùng bảng reset mật khẩu vì schema 42 bảng không có bảng này.
    'passwords' => [],
    'password_timeout' => 10800,
];