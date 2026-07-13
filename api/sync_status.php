<?php

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/sync_core.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

try {
    $summary = sync_active_summary();
    echo json_encode([
        'ok' => true,
        'active' => (bool) ($summary['active'] ?? false),
        'active_jobs' => $summary['active_jobs'] ?? [],
        'last_global_job' => $summary['last_global_job'] ?? [],
        'states' => $summary['states'] ?? [],
        'checked_at' => date('Y-m-d H:i:s'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Sync status is unavailable.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
