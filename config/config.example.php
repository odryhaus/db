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
        'debug' => false,
    ],
    'keycrm' => [
        'base_url' => 'https://openapi.keycrm.app/v1',
        'api_key' => 'CHANGE_ME_IN_REAL_CONFIG',
        'sync_delta_pages' => 10,
        'unpaid_refresh_limit' => 500,
        'client_sync_delta_pages' => 20,
        'client_sync_initial_pages' => 200,
    ],
];
