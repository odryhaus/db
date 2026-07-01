<?php

declare(strict_types=1);

$configPath = __DIR__ . '/config/config.php';
if (!is_file($configPath)) {
    $GLOBALS['app_config'] = require __DIR__ . '/config/config.example.php';
} else {
    $GLOBALS['app_config'] = require $configPath;
}

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

date_default_timezone_set((string) app_config('app.timezone', 'Europe/Kiev'));

$secureSession = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
$sessionName = (string) app_config('app.session_name', 'brand_db_session');
session_name($sessionName);
session_set_cookie_params([
    'lifetime' => 0,
    'path' => base_path('/'),
    'secure' => $secureSession,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();
