<?php

require_once dirname(__DIR__) . '/bootstrap.php';
require_login();
ensure_finance_tables();

header('Content-Type: application/json; charset=utf-8');

$month = (string) ($_GET['month'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}

$monthDate = DateTimeImmutable::createFromFormat('!Y-m', $month) ?: new DateTimeImmutable('first day of this month');
$monthStart = $monthDate->modify('first day of this month')->setTime(0, 0, 0);
$monthEnd = $monthDate->modify('last day of this month')->setTime(23, 59, 59);
$notCanceledSql = "
    LOWER(COALESCE(status_name, '')) NOT LIKE '%cancel%'
    AND LOWER(COALESCE(status_name, '')) NOT LIKE '%deleted%'
    AND LOWER(COALESCE(status_name, '')) NOT LIKE '%скас%'
    AND LOWER(COALESCE(payment_status, '')) NOT LIKE '%cancel%'
    AND LOWER(COALESCE(payment_status, '')) NOT LIKE '%deleted%'
    AND LOWER(COALESCE(payment_status, '')) NOT LIKE '%скас%'
";

try {
    $orders = db()->prepare("
        SELECT COUNT(*) AS order_count,
               COALESCE(SUM(total_amount_uah), 0) AS sales_fact,
               COALESCE(SUM(paid_amount_uah), 0) AS paid_from_orders,
               COALESCE(SUM(unpaid_amount_uah), 0) AS unpaid
        FROM db_orders
        WHERE order_month = :month
          AND {$notCanceledSql}
    ");
    $orders->execute(['month' => $month]);
    $row = $orders->fetch() ?: [];

    $paidFromPayments = null;
    if (invoice_table_exists('db_order_payments')) {
        $payments = db()->prepare("
            SELECT COALESCE(SUM(amount), 0)
            FROM db_order_payments
            WHERE payment_date >= :month_start
              AND payment_date <= :month_end
              AND LOWER(COALESCE(status, '')) NOT LIKE '%cancel%'
              AND LOWER(COALESCE(status, '')) NOT LIKE '%deleted%'
              AND LOWER(COALESCE(status, '')) NOT LIKE '%скас%'
        ");
        $payments->execute([
            'month_start' => $monthStart->format('Y-m-d H:i:s'),
            'month_end' => $monthEnd->format('Y-m-d H:i:s'),
        ]);
        $paidFromPayments = (float) ($payments->fetchColumn() ?: 0);
    }

    $receivables = db()->query("
        SELECT COALESCE(SUM(unpaid_amount_uah), 0)
        FROM db_orders
        WHERE unpaid_amount_uah > 0
          AND {$notCanceledSql}
    ")->fetchColumn();

    $obligations = 0.0;
    if (invoice_table_exists('db_payment_obligations')) {
        $obligations = (float) db()->query("
            SELECT COALESCE(SUM(GREATEST(amount_uah - paid_amount_uah, 0)), 0)
            FROM db_payment_obligations
            WHERE status IN ('planned','moved','problem')
              AND is_strategic = 0
        ")->fetchColumn();
    }

    echo json_encode([
        'ok' => true,
        'month' => $month,
        'order_count' => (int) ($row['order_count'] ?? 0),
        'sales_fact' => (float) ($row['sales_fact'] ?? 0),
        'paid' => $paidFromPayments !== null && $paidFromPayments > 0 ? $paidFromPayments : (float) ($row['paid_from_orders'] ?? 0),
        'paid_source' => $paidFromPayments !== null && $paidFromPayments > 0 ? 'db_order_payments' : 'db_orders',
        'unpaid_month' => (float) ($row['unpaid'] ?? 0),
        'receivables_total' => (float) $receivables,
        'planned_obligations_unpaid' => $obligations,
        'expected_net_cash' => (float) $receivables - $obligations,
        'checked_at' => date('Y-m-d H:i:s'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Dashboard KPIs are unavailable.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
