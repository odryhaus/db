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
    $summary = cockpit_monthly_summary((string) ($_GET['month'] ?? date('Y-m')));
    echo json_encode([
        'ok' => true,
        'month' => $summary['month'],
        'order_count' => $summary['order_count'],
        'sales_fact' => $summary['sales_fact'],
        'paid' => $summary['sales_paid_by_order'],
        'paid_source' => 'db_orders.paid_amount_uah',
        'cash_received' => $summary['cash_received'],
        'cash_source' => 'db_order_payments.payment_date',
        'unpaid_month' => $summary['sales_unpaid_by_order'],
        'receivables_total' => $summary['receivables_total'],
        'current_balance' => $summary['current_balance'],
        'planned_obligations_unpaid' => $summary['operational_due_this_month'],
        'expected_net_cash' => $summary['cash_forecast'],
        'checked_at' => date('Y-m-d H:i:s'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Dashboard KPIs are unavailable.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
