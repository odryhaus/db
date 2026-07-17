<?php

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/cockpit.php';

require_login();
ensure_finance_tables();
header('Content-Type: application/json; charset=utf-8');

try {
    $rows = [];
    if (invoice_table_exists('db_orders')) {
        $notCanceled = cockpit_not_canceled_sql('o');
        $rows = db()->query("
            SELECT
                COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), o.ordered_at) <= 7 THEN o.unpaid_amount_uah ELSE 0 END), 0) AS fresh_0_7,
                COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), o.ordered_at) BETWEEN 8 AND 30 THEN o.unpaid_amount_uah ELSE 0 END), 0) AS days_8_30,
                COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), o.ordered_at) > 30 THEN o.unpaid_amount_uah ELSE 0 END), 0) AS days_31_plus
            FROM db_orders o
            WHERE o.unpaid_amount_uah > 0
              AND {$notCanceled}
        ")->fetch() ?: [];
    }
    echo json_encode(['ok' => true, 'data' => $rows], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Aging data is unavailable.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
