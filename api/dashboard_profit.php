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
            'sales_fact' => $summary['sales_fact'],
            'direct_costs' => $summary['direct_costs'],
            'gross_margin' => $summary['gross_margin'],
            'gross_margin_percent' => $summary['gross_margin_percent'],
            'operating_costs' => $summary['operating_costs'],
            'operating_profit' => $summary['operating_profit'],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Profitability data is unavailable.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
