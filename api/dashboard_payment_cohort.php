<?php

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/cockpit.php';

require_login();
ensure_finance_tables();
header('Content-Type: application/json; charset=utf-8');

$month = cockpit_valid_month((string) ($_GET['month'] ?? date('Y-m')));
$bounds = cockpit_month_bounds($month);

try {
    $rows = [];
    if (invoice_table_exists('db_order_payments')) {
        $active = cockpit_active_payment_sql('p');
        $stmt = db()->prepare("
            SELECT COALESCE(o.order_month, 'без місяця') AS order_month,
                   COALESCE(SUM(p.amount), 0) AS cash_received,
                   COUNT(*) AS payment_count
            FROM db_order_payments p
            LEFT JOIN db_orders o ON o.keycrm_id = p.keycrm_order_id
            WHERE p.payment_date >= :month_start
              AND p.payment_date <= :month_end
              AND {$active}
            GROUP BY COALESCE(o.order_month, 'без місяця')
            ORDER BY order_month DESC
        ");
        $stmt->execute([
            'month_start' => $bounds['start']->format('Y-m-d H:i:s'),
            'month_end' => $bounds['end']->format('Y-m-d H:i:s'),
        ]);
        $rows = $stmt->fetchAll();
    }

    echo json_encode(['ok' => true, 'month' => $month, 'data' => $rows], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Payment cohort data is unavailable.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
