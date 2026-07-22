<?php

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/cockpit.php';
require_once dirname(__DIR__) . '/financial.php';

require_login();
header('Content-Type: application/json; charset=utf-8');
if (user_role() === 'manager') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Access denied.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    echo json_encode([
        'ok' => true,
        'month' => cockpit_valid_month((string) ($_GET['month'] ?? date('Y-m'))),
        'data' => cockpit_manager_summary((string) ($_GET['month'] ?? date('Y-m'))),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Manager summary is unavailable.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
