<?php

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $db = app_config('db');
    if (!is_array($db)) {
        throw new RuntimeException('Database configuration is missing.');
    }

    $host = (string) ($db['host'] ?? 'localhost');
    $port = (int) ($db['port'] ?? 3306);
    $database = (string) ($db['database'] ?? '');
    $charset = (string) ($db['charset'] ?? 'utf8mb4');
    $username = (string) ($db['username'] ?? '');
    $password = (string) ($db['password'] ?? '');

    $dsn = "mysql:host={$host};port={$port};dbname={$database};charset={$charset}";

    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}
