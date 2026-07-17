<?php

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/cockpit.php';
require_once dirname(__DIR__) . '/financial.php';

require_login();
header('Content-Type: application/json; charset=utf-8');

try {
    $summary = cockpit_monthly_summary((string) ($_GET['month'] ?? date('Y-m')));
    echo json_encode([
        'ok' => true,
        'data' => [
            'month' => $summary['month'],
            'sales_fact' => $summary['sales_fact'],
            'cash_received' => $summary['cash_received'],
            'cash_from_selected_month_orders' => $summary['cash_from_selected_month_orders'],
            'cash_from_previous_orders' => $summary['cash_from_previous_orders'],
            'sales_unpaid_by_order' => $summary['sales_unpaid_by_order'],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Sales/cash data is unavailable.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
