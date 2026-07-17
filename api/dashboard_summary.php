<?php

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/cockpit.php';

require_login();
header('Content-Type: application/json; charset=utf-8');

try {
    echo json_encode([
        'ok' => true,
        'data' => cockpit_monthly_summary((string) ($_GET['month'] ?? date('Y-m'))),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'CEO Cockpit summary is unavailable.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
