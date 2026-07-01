<?php

return [
    'db' => [
        'host' => 'localhost',
        'port' => 3306,
        'database' => 'qkbbstge_dashboard',
        'username' => 'DB_USERNAME',
        'password' => 'DB_PASSWORD',
        'charset' => 'utf8mb4',
    ],
    'app' => [
        'base_path' => '/db',
        'session_name' => 'brand_db_session',
        'timezone' => 'Europe/Kiev',
        'setup_key' => 'CHANGE_ME_LONG_RANDOM_SECRET',
        'debug' => false,
    ],
];
