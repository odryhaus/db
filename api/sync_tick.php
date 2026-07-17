<?php

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/sync_core.php';
require_role('ceo');

header('Content-Type: application/json; charset=utf-8');

try {
    if (function_exists('set_time_limit')) {
        set_time_limit(70);
    }
    $result = sync_worker_run_once();
    echo json_encode([
        'ok' => true,
        'ran_job' => $result !== null,
        'result' => $result,
        'checked_at' => date('Y-m-d H:i:s'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Sync worker tick failed.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
