<?php

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/cockpit.php';

require_login();
header('Content-Type: application/json; charset=utf-8');

try {
    $summary = cockpit_monthly_summary((string) ($_GET['month'] ?? date('Y-m')));
    echo json_encode([
        'ok' => true,
        'data' => [
            'month' => $summary['month'],
            'cash_received' => $summary['cash_received'],
            'receivables_total' => $summary['receivables_total'],
            'operational_due_this_month' => $summary['operational_due_this_month'],
            'operational_due_this_week' => $summary['operational_due_this_week'],
            'overdue_obligations_total' => $summary['overdue_obligations_total'],
            'cash_forecast' => $summary['cash_forecast'],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Cash forecast data is unavailable.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
