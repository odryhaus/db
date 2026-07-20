<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/cockpit.php';
require_once __DIR__ . '/financial.php';
require_once __DIR__ . '/cockpit_layout.php';

require_login();

$autoloadPath = __DIR__ . '/vendor/autoload.php';
if (is_file($autoloadPath)) {
    require_once $autoloadPath;
}

$month = cockpit_valid_month((string) ($_GET['month'] ?? date('Y-m')));
$fromMonth = isset($_GET['from_month']) ? cockpit_valid_month((string) $_GET['from_month']) : '';
$toMonth = isset($_GET['to_month']) ? cockpit_valid_month((string) $_GET['to_month']) : '';
$managerFilter = trim((string) ($_GET['manager'] ?? ''));
$clientKeyFilter = trim((string) ($_GET['client_key'] ?? ''));
$searchFilter = trim((string) ($_GET['q'] ?? ''));
$statusFilter = trim((string) ($_GET['status'] ?? 'all'));
if (!in_array($statusFilter, ['all', 'debt'], true)) {
    $statusFilter = 'all';
}
$notCanceled = cockpit_not_canceled_sql('o');
$orders = [];
$orderItems = [];
$cashPayments = [];
$monthlyChart = [];
$summary = cockpit_monthly_summary($month);
$canSeeCosts = in_array(user_role(), ['ceo', 'accountant'], true);
$rangeMode = $fromMonth !== '' && $toMonth !== '' && $fromMonth <= $toMonth;
$pageTitle = $rangeMode ? $fromMonth . ' - ' . $toMonth : $month;
$orderColumns = invoice_table_exists('db_orders') ? finance_columns('db_orders') : [];
$hasClientFilter = $clientKeyFilter !== '';
$periodFromMonth = $rangeMode ? $fromMonth : $month;
$periodToMonth = $rangeMode ? $toMonth : $month;
$periodStart = $periodFromMonth . '-01 00:00:00';
$periodEndDate = DateTimeImmutable::createFromFormat('!Y-m', $periodToMonth);
$periodEnd = ($periodEndDate ?: new DateTimeImmutable('first day of this month'))->modify('last day of this month')->format('Y-m-d 23:59:59');
$clientTitle = '';

function sales_month_label_short(string $month): string
{
    $labels = [
        1 => 'Січ',
        2 => 'Лют',
        3 => 'Бер',
        4 => 'Кві',
        5 => 'Тра',
        6 => 'Чер',
        7 => 'Лип',
        8 => 'Сер',
        9 => 'Вер',
        10 => 'Жов',
        11 => 'Лис',
        12 => 'Гру',
    ];
    return $labels[(int) substr($month, 5, 2)] ?? $month;
}

function sales_month_range(string $fromMonth, string $toMonth): array
{
    $from = DateTimeImmutable::createFromFormat('!Y-m', cockpit_valid_month($fromMonth));
    $to = DateTimeImmutable::createFromFormat('!Y-m', cockpit_valid_month($toMonth));
    if (!$from || !$to || $from > $to) {
        return [cockpit_valid_month($toMonth)];
    }

    $months = [];
    $cursor = $from;
    while ($cursor <= $to) {
        $months[] = $cursor->format('Y-m');
        $cursor = $cursor->modify('+1 month');
        if (count($months) >= 60) {
            break;
        }
    }
    return $months;
}

function sales_current_query(array $overrides = []): string
{
    $params = array_merge($_GET, $overrides);
    return http_build_query(array_filter($params, static fn($value) => $value !== null && $value !== ''));
}

function sales_debt_summary_html(array $orders, string $clientTitle, string $fromMonth, string $toMonth): string
{
    $total = array_sum(array_map(static fn($order) => (float) ($order['unpaid_amount_uah'] ?? 0), $orders));
    $client = $clientTitle !== '' ? $clientTitle : 'Клієнт';
    ob_start();
    ?><!doctype html>
<html lang="uk">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, Arial, sans-serif; color: #111; font-size: 12px; }
        h1 { font-size: 22px; margin: 0 0 4px; }
        .meta { color: #666; margin-bottom: 22px; }
        .total { border: 1px solid #111; padding: 12px; margin-bottom: 18px; font-size: 16px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border-bottom: 1px solid #ddd; padding: 8px 6px; text-align: left; vertical-align: top; }
        th { color: #666; font-size: 10px; text-transform: uppercase; }
        .num { text-align: right; white-space: nowrap; }
        .danger { color: #d92d20; font-weight: 700; }
    </style>
</head>
<body>
    <h1>Звірка боргу</h1>
    <div class="meta"><?= e($client) ?> · <?= e($fromMonth) ?> → <?= e($toMonth) ?> · сформовано <?= e(date('d.m.Y H:i')) ?></div>
    <div class="total"><strong>Загальний борг:</strong> <?= e(finance_money($total)) ?> · <?= e((string) count($orders)) ?> замовлень</div>
    <table>
        <thead>
        <tr>
            <th>№</th>
            <th>Дата</th>
            <th>Покупець</th>
            <th class="num">Сума</th>
            <th class="num">Оплачено</th>
            <th class="num">Борг</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($orders as $order): ?>
            <tr>
                <td><?= e((string) $order['order_number']) ?></td>
                <td><?= e(substr((string) $order['ordered_at'], 0, 10)) ?></td>
                <td><?= e((string) (($order['buyer_name'] ?? '') ?: ($order['company_name'] ?? ''))) ?></td>
                <td class="num"><?= e(finance_money($order['total_amount_uah'])) ?></td>
                <td class="num"><?= e(finance_money($order['paid_amount_uah'])) ?></td>
                <td class="num danger"><?= e(finance_money($order['unpaid_amount_uah'])) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</body>
</html><?php
    return (string) ob_get_clean();
}

function sales_download_debt_summary(array $orders, string $clientTitle, string $fromMonth, string $toMonth): void
{
    $html = sales_debt_summary_html($orders, $clientTitle, $fromMonth, $toMonth);
    $safeClient = preg_replace('/[^A-Za-z0-9А-Яа-яЇїІіЄєҐґ_-]+/u', '_', $clientTitle !== '' ? $clientTitle : 'client');
    $fileName = 'DEBT_' . trim((string) $safeClient, '_') . '_' . $fromMonth . '_' . $toMonth . '.pdf';

    if (class_exists('\\Dompdf\\Dompdf')) {
        $options = new \Dompdf\Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);
        $dompdf = new \Dompdf\Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $fileName . '"');
        echo $dompdf->output();
        exit;
    }

    header('Content-Type: text/html; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . pathinfo($fileName, PATHINFO_FILENAME) . '.html"');
    echo $html;
    exit;
}

function sales_search_filter(string $search, array $orderColumns): array
{
    if ($search === '') {
        return ['sql' => '', 'params' => []];
    }
    $normalized = function_exists('mb_strtolower') ? mb_strtolower(trim($search), 'UTF-8') : strtolower(trim($search));
    $tokens = preg_split('/\s+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY);
    if (!$tokens) {
        return ['sql' => '', 'params' => []];
    }
    $fields = [];
    foreach (['order_number', 'company_name', 'buyer_name', 'client_name', 'manager_name'] as $column) {
        if (in_array($column, $orderColumns, true)) {
            $fields[] = $column === 'order_number'
                ? "COALESCE(CAST(o.{$column} AS CHAR), '')"
                : "COALESCE(o.{$column}, '')";
        }
    }
    if (!$fields) {
        return ['sql' => '', 'params' => []];
    }
    $haystack = "LOWER(CONCAT_WS(' ', " . implode(', ', $fields) . "))";
    $parts = [];
    $params = [];
    foreach ($tokens as $idx => $token) {
        $key = 'sales_q_' . $idx;
        $parts[] = "{$haystack} LIKE :{$key}";
        $params[$key] = '%' . $token . '%';
    }
    return ['sql' => ' AND (' . implode(' AND ', $parts) . ')', 'params' => $params];
}

function sales_client_key_filter(string $clientKey, array $orderColumns): array
{
    if ($clientKey === '') {
        return ['sql' => '', 'params' => []];
    }
    if (preg_match('/^company:(\d+)$/', $clientKey, $m) && in_array('company_id', $orderColumns, true)) {
        return ['sql' => ' AND o.company_id = :client_company_id', 'params' => ['client_company_id' => (int) $m[1]]];
    }
    if (preg_match('/^client:(\d+)$/', $clientKey, $m) && in_array('client_id', $orderColumns, true)) {
        return ['sql' => ' AND o.client_id = :client_id', 'params' => ['client_id' => (int) $m[1]]];
    }
    if (substr($clientKey, 0, 13) === 'company-name:') {
        $nameFields = [];
        foreach (['company_name', 'client_name', 'buyer_name'] as $column) {
            if (in_array($column, $orderColumns, true)) {
                $nameFields[] = "NULLIF(o.{$column}, '')";
            }
        }
        if ($nameFields) {
            return ['sql' => ' AND COALESCE(' . implode(', ', $nameFields) . ') = :client_name', 'params' => ['client_name' => substr($clientKey, 13)]];
        }
    }
    return ['sql' => '', 'params' => []];
}

try {
    if (invoice_table_exists('db_orders')) {
        $itemJoin = invoice_table_exists('db_order_items')
            ? "LEFT JOIN (SELECT keycrm_order_id, COUNT(*) AS item_count, COALESCE(SUM(total_amount), 0) AS items_total FROM db_order_items WHERE COALESCE(is_deleted, 0) = 0 GROUP BY keycrm_order_id) i ON i.keycrm_order_id = o.keycrm_id"
            : "";
        $itemSelect = invoice_table_exists('db_order_items')
            ? "COALESCE(i.item_count, 0) AS item_count, COALESCE(i.items_total, 0) AS items_total,"
            : "0 AS item_count, 0 AS items_total,";
        $where = [$notCanceled];
        $params = [];
        if ($rangeMode) {
            $where[] = "o.order_month >= :from_month";
            $where[] = "o.order_month <= :to_month";
            $params['from_month'] = $fromMonth;
            $params['to_month'] = $toMonth;
        } else {
            $where[] = "o.order_month = :month";
            $params['month'] = $month;
        }
        if ($managerFilter !== '') {
            $where[] = "COALESCE(NULLIF(o.manager_name, ''), 'Без менеджера') = :manager";
            $params['manager'] = $managerFilter;
        }
        $clientFilter = sales_client_key_filter($clientKeyFilter, $orderColumns);
        if ($clientFilter['sql'] !== '') {
            $where[] = substr((string) $clientFilter['sql'], 5);
            $params = array_merge($params, $clientFilter['params']);
        }
        $searchSql = sales_search_filter($searchFilter, $orderColumns);
        if ($searchSql['sql'] !== '') {
            $where[] = substr((string) $searchSql['sql'], 5);
            $params = array_merge($params, $searchSql['params']);
        }
        if ($statusFilter === 'debt') {
            $where[] = 'o.unpaid_amount_uah > 0';
        }
        $stmt = db()->prepare("
            SELECT o.order_number, o.ordered_at, o.company_name, o.buyer_name, o.manager_name,
                   o.status_name, o.total_amount_uah, o.paid_amount_uah, o.unpaid_amount_uah,
                   o.expenses_sum_uah, o.margin_sum_uah, {$itemSelect}
                   o.keycrm_id
            FROM db_orders o
            {$itemJoin}
            WHERE " . implode(' AND ', $where) . "
            ORDER BY o.ordered_at DESC, o.id DESC
            LIMIT 300
        ");
        $stmt->execute($params);
        $orders = $stmt->fetchAll();
        if ($orders) {
            $firstOrder = $orders[0];
            $clientTitle = (string) (($firstOrder['company_name'] ?? '') ?: (($firstOrder['buyer_name'] ?? '') ?: ''));
        }

        if ($orders && invoice_table_exists('db_order_items')) {
            $orderIds = array_values(array_filter(array_map(static fn($order) => (int) ($order['keycrm_id'] ?? 0), $orders)));
            if ($orderIds) {
                $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
                $itemStmt = db()->prepare("
                    SELECT keycrm_order_id, name, properties_text, comment, quantity, unit, purchase_price,
                           product_price, discount_amount, discount_percent, sale_price, total_amount
                    FROM db_order_items
                    WHERE COALESCE(is_deleted, 0) = 0
                      AND keycrm_order_id IN ({$placeholders})
                    ORDER BY id ASC
                ");
                $itemStmt->execute($orderIds);
                foreach ($itemStmt->fetchAll() as $item) {
                    $orderItems[(int) $item['keycrm_order_id']][] = $item;
                }
            }
        }

        if (invoice_table_exists('db_order_payments')) {
            $activePayment = cockpit_active_payment_sql('p');
            $paymentWhere = [$notCanceled, $activePayment, 'p.payment_date >= :payment_period_start', 'p.payment_date <= :payment_period_end'];
            $paymentParams = [
                'payment_period_start' => $periodStart,
                'payment_period_end' => $periodEnd,
            ];
            if ($managerFilter !== '') {
                $paymentWhere[] = "COALESCE(NULLIF(o.manager_name, ''), 'Без менеджера') = :payment_manager";
                $paymentParams['payment_manager'] = $managerFilter;
            }
            if ($clientFilter['sql'] !== '') {
                $paymentWhere[] = substr((string) $clientFilter['sql'], 5);
                $paymentParams = array_merge($paymentParams, $clientFilter['params']);
            }
            if ($searchSql['sql'] !== '') {
                $paymentWhere[] = substr((string) $searchSql['sql'], 5);
                $paymentParams = array_merge($paymentParams, $searchSql['params']);
            }
            $paymentStmt = db()->prepare("
                SELECT p.payment_date, p.amount, p.currency, p.payment_method_name, p.status,
                       COALESCE(p.order_number, o.order_number) AS order_number,
                       o.order_month, o.company_name, o.buyer_name, o.manager_name, o.total_amount_uah
                FROM db_order_payments p
                INNER JOIN db_orders o ON o.keycrm_id = p.keycrm_order_id
                WHERE " . implode(' AND ', $paymentWhere) . "
                ORDER BY p.payment_date DESC, p.id DESC
                LIMIT 200
            ");
            $paymentStmt->execute($paymentParams);
            $cashPayments = $paymentStmt->fetchAll();
        }

        $chartTemplate = [];
        foreach (sales_month_range($periodFromMonth, $periodToMonth) as $chartMonth) {
            $chartTemplate[$chartMonth] = [
                'month' => $chartMonth,
                'sales' => 0.0,
                'paid_by_order' => 0.0,
                'cash_received' => 0.0,
                'unpaid' => 0.0,
                'order_count' => 0,
            ];
        }
        $monthlyChart = $chartTemplate;
        $chartWhere = [$notCanceled, 'o.order_month >= :chart_period_start', 'o.order_month <= :chart_period_end'];
        $chartParams = [
            'chart_period_start' => $periodFromMonth,
            'chart_period_end' => $periodToMonth,
        ];
        if ($managerFilter !== '') {
            $chartWhere[] = "COALESCE(NULLIF(o.manager_name, ''), 'Без менеджера') = :chart_manager";
            $chartParams['chart_manager'] = $managerFilter;
        }
        if ($clientFilter['sql'] !== '') {
            $chartWhere[] = substr((string) $clientFilter['sql'], 5);
            $chartParams = array_merge($chartParams, $clientFilter['params']);
        }
        if ($searchSql['sql'] !== '') {
            $chartWhere[] = substr((string) $searchSql['sql'], 5);
            $chartParams = array_merge($chartParams, $searchSql['params']);
        }
        if ($statusFilter === 'debt') {
            $chartWhere[] = 'o.unpaid_amount_uah > 0';
        }
        $chartStmt = db()->prepare("
            SELECT o.order_month,
                   COUNT(*) AS order_count,
                   COALESCE(SUM(o.total_amount_uah), 0) AS sales,
                   COALESCE(SUM(o.paid_amount_uah), 0) AS paid_by_order,
                   COALESCE(SUM(o.unpaid_amount_uah), 0) AS unpaid
            FROM db_orders o
            WHERE " . implode(' AND ', $chartWhere) . "
            GROUP BY o.order_month
        ");
        $chartStmt->execute($chartParams);
        foreach ($chartStmt->fetchAll() as $row) {
            $chartMonth = (string) ($row['order_month'] ?? '');
            if (isset($monthlyChart[$chartMonth])) {
                $monthlyChart[$chartMonth]['sales'] = (float) ($row['sales'] ?? 0);
                $monthlyChart[$chartMonth]['paid_by_order'] = (float) ($row['paid_by_order'] ?? 0);
                $monthlyChart[$chartMonth]['unpaid'] = (float) ($row['unpaid'] ?? 0);
                $monthlyChart[$chartMonth]['order_count'] = (int) ($row['order_count'] ?? 0);
            }
        }

        if (invoice_table_exists('db_order_payments')) {
            $activePayment = cockpit_active_payment_sql('p');
            $chartPaymentWhere = [$notCanceled, $activePayment, 'p.payment_date >= :chart_payment_start', 'p.payment_date <= :chart_payment_end'];
            $chartPaymentParams = [
                'chart_payment_start' => $periodStart,
                'chart_payment_end' => $periodEnd,
            ];
            if ($managerFilter !== '') {
                $chartPaymentWhere[] = "COALESCE(NULLIF(o.manager_name, ''), 'Без менеджера') = :chart_payment_manager";
                $chartPaymentParams['chart_payment_manager'] = $managerFilter;
            }
            if ($clientFilter['sql'] !== '') {
                $chartPaymentWhere[] = substr((string) $clientFilter['sql'], 5);
                $chartPaymentParams = array_merge($chartPaymentParams, $clientFilter['params']);
            }
            if ($searchSql['sql'] !== '') {
                $chartPaymentWhere[] = substr((string) $searchSql['sql'], 5);
                $chartPaymentParams = array_merge($chartPaymentParams, $searchSql['params']);
            }
            $chartPaymentStmt = db()->prepare("
                SELECT DATE_FORMAT(p.payment_date, '%Y-%m') AS payment_month,
                       COALESCE(SUM(p.amount), 0) AS cash_received
                FROM db_order_payments p
                INNER JOIN db_orders o ON o.keycrm_id = p.keycrm_order_id
                WHERE " . implode(' AND ', $chartPaymentWhere) . "
                GROUP BY DATE_FORMAT(p.payment_date, '%Y-%m')
            ");
            $chartPaymentStmt->execute($chartPaymentParams);
            foreach ($chartPaymentStmt->fetchAll() as $row) {
                $chartMonth = (string) ($row['payment_month'] ?? '');
                if (isset($monthlyChart[$chartMonth])) {
                    $monthlyChart[$chartMonth]['cash_received'] = (float) ($row['cash_received'] ?? 0);
                }
            }
        }
    }
} catch (Throwable $e) {
    $orders = [];
    $cashPayments = [];
    $monthlyChart = [];
}

$visibleSalesTotal = array_sum(array_map(static fn($order) => (float) ($order['total_amount_uah'] ?? 0), $orders));
$visiblePaidTotal = array_sum(array_map(static fn($order) => (float) ($order['paid_amount_uah'] ?? 0), $orders));
$visibleUnpaidTotal = array_sum(array_map(static fn($order) => (float) ($order['unpaid_amount_uah'] ?? 0), $orders));
$visibleMarginTotal = array_sum(array_map(static fn($order) => (float) ($order['margin_sum_uah'] ?? 0), $orders));
$visibleCashTotal = array_sum(array_map(static fn($payment) => (float) ($payment['amount'] ?? 0), $cashPayments));
$visibleCashForPeriodOrders = array_sum(array_map(static function ($payment) use ($periodFromMonth, $periodToMonth) {
    $orderMonth = (string) ($payment['order_month'] ?? '');
    return $orderMonth >= $periodFromMonth && $orderMonth <= $periodToMonth ? (float) ($payment['amount'] ?? 0) : 0.0;
}, $cashPayments));
$visibleCashForOtherOrders = max($visibleCashTotal - $visibleCashForPeriodOrders, 0);
$debtOrders = array_values(array_filter($orders, static fn($order) => (float) ($order['unpaid_amount_uah'] ?? 0) > 0));
$isFilteredView = $rangeMode || $clientKeyFilter !== '' || $searchFilter !== '' || $managerFilter !== '';
$displaySalesTotal = $isFilteredView ? $visibleSalesTotal : (float) $summary['sales_fact'];
$displayOrderCount = $isFilteredView ? count($orders) : (int) $summary['order_count'];
$displayPaid = $isFilteredView ? $visiblePaidTotal : (float) $summary['sales_paid_by_order'];
$displayCashOther = $isFilteredView ? $visibleCashForOtherOrders : (float) $summary['cash_from_previous_orders'];
$displayMargin = $isFilteredView ? $visibleMarginTotal : (float) $summary['gross_margin'];
$displayUnpaid = $isFilteredView ? $visibleUnpaidTotal : (float) $summary['sales_unpaid_by_order'];
$displayMarginPercent = $displaySalesTotal > 0 ? round(($displayMargin / $displaySalesTotal) * 100, 1) : 0;
$chartMax = 0.0;
foreach ($monthlyChart as $chartRow) {
    $chartMax = max($chartMax, (float) $chartRow['sales'], (float) $chartRow['cash_received']);
}

if (isset($_GET['debt_pdf'])) {
    sales_download_debt_summary($debtOrders, $clientTitle, $periodFromMonth, $periodToMonth);
}

?><!doctype html>
<html lang="uk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Продажі — .BRAND DB</title>
    <link rel="stylesheet" href="<?= e(asset_path('/assets/app.css')) ?>">
</head>
<body>
<main class="app-shell cockpit-shell">
    <?php cockpit_page_header('CEO Money Cockpit', 'Продажі', 'Замовлення, товари, оплата і борг: ' . $pageTitle . ($managerFilter !== '' ? ' · Менеджер: ' . $managerFilter : '') . ($clientKeyFilter !== '' ? ' · ' . ($clientTitle !== '' ? $clientTitle : 'клієнт') : ''), 'sales', $month, !$rangeMode); ?>

    <section class="panel dashboard-section sales-filter-panel">
        <div class="section-heading">
            <div>
                <p class="eyebrow">Період</p>
                <h2><?= e($hasClientFilter ? ($clientTitle !== '' ? $clientTitle : 'Клієнт') : 'Всі продажі') ?></h2>
            </div>
            <span class="status-badge"><?= $hasClientFilter ? 'обрана компанія' : 'загальний режим' ?> · <?= e($periodFromMonth) ?> → <?= e($periodToMonth) ?></span>
        </div>
        <?php if (!$hasClientFilter): ?>
            <p class="muted">Це загальний графік. Щоб побачити одну компанію, відкрий `Клієнти` і натисни на назву компанії.</p>
        <?php endif; ?>
        <div class="sales-money-legend">
            <span><strong>Замовили</strong> — сума замовлень, створених у вибраному періоді.</span>
            <span><strong>Оплатили з цих</strong> — скільки вже оплачено саме з цих замовлень.</span>
            <span><strong>Прийшло за старі</strong> — платежі у цьому періоді за замовлення з інших періодів.</span>
        </div>
        <form class="sales-period-toolbar" method="get" action="<?= e(base_path('/sales.php')) ?>">
            <input type="hidden" name="month" value="<?= e($periodToMonth) ?>">
            <input type="hidden" name="view" value="client_drilldown">
            <?php if ($hasClientFilter): ?><input type="hidden" name="client_key" value="<?= e($clientKeyFilter) ?>"><?php endif; ?>
            <input type="hidden" name="status" value="<?= e($statusFilter) ?>">
            <label>
                <span>З місяця</span>
                <input type="month" name="from_month" value="<?= e($periodFromMonth) ?>">
            </label>
            <label>
                <span>По місяць</span>
                <input type="month" name="to_month" value="<?= e($periodToMonth) ?>">
            </label>
            <label>
                <span>Пошук</span>
                <input type="search" name="q" value="<?= e($searchFilter) ?>" placeholder="номер, товар, клієнт">
            </label>
            <?php if ($managerFilter !== ''): ?><input type="hidden" name="manager" value="<?= e($managerFilter) ?>"><?php endif; ?>
            <button type="submit">Показати</button>
        </form>
        <div class="sales-filter-actions">
            <div class="segmented-scroll client-trend-filter">
                <a class="<?= $statusFilter === 'all' ? 'active' : '' ?>" href="<?= e(base_path('/sales.php?' . sales_current_query(['status' => 'all', 'debt_pdf' => null]))) ?>">Всі замовлення</a>
                <a class="<?= $statusFilter === 'debt' ? 'active' : '' ?>" href="<?= e(base_path('/sales.php?' . sales_current_query(['status' => 'debt', 'debt_pdf' => null]))) ?>">Дебіторка</a>
            </div>
            <a class="button-secondary" href="<?= e(base_path('/sales.php?' . sales_current_query(['status' => 'debt', 'debt_pdf' => '1']))) ?>">PDF боргу</a>
        </div>
    </section>

    <section class="kpi-grid">
        <div class="kpi-card"><span class="label">Замовили</span><strong><?= e(finance_money($displaySalesTotal)) ?></strong><small>замовлення, створені у періоді</small></div>
        <div class="kpi-card"><span class="label">Замовлення</span><strong><?= e((string) $displayOrderCount) ?></strong></div>
        <div class="kpi-card"><span class="label">Оплатили з цих</span><strong><?= e(finance_money($displayPaid)) ?></strong><small>оплата саме цих замовлень</small></div>
        <div class="kpi-card"><span class="label">Прийшло за старі</span><strong><?= e(finance_money($displayCashOther)) ?></strong><small>платежі за інші замовлення у періоді</small></div>
        <div class="kpi-card"><span class="label">Борг</span><strong><?= e(finance_money($displayUnpaid)) ?></strong></div>
        <?php if ($canSeeCosts): ?><div class="kpi-card"><span class="label">Маржа</span><strong><?= e(finance_money($displayMargin)) ?></strong><small><?= e((string) $displayMarginPercent) ?>%</small></div><?php endif; ?>
    </section>

    <section class="panel dashboard-section sales-chart-panel">
        <div class="section-heading">
            <div><p class="eyebrow"><?= $hasClientFilter ? 'Графік компанії' : 'Загальний графік' ?></p><h2><?= e($periodFromMonth) ?> → <?= e($periodToMonth) ?> по місяцях<?= $hasClientFilter && $clientTitle !== '' ? ' · ' . e($clientTitle) : '' ?></h2></div>
            <span class="status-badge">чорне — продажі · жовте — гроші прийшли</span>
        </div>
        <div class="sales-year-chart">
            <?php foreach ($monthlyChart as $chartRow): ?>
                <?php
                $salesHeight = $chartMax > 0 ? max(2, round(((float) $chartRow['sales'] / $chartMax) * 100, 1)) : 0;
                $cashHeight = $chartMax > 0 ? max(2, round(((float) $chartRow['cash_received'] / $chartMax) * 100, 1)) : 0;
                ?>
                <div class="sales-month-bar">
                    <div class="sales-month-bar__plot">
                        <i class="sales-bar-sales" style="height: <?= e((string) $salesHeight) ?>%"></i>
                        <i class="sales-bar-cash" style="height: <?= e((string) $cashHeight) ?>%"></i>
                    </div>
                    <strong><?= e(sales_month_label_short((string) $chartRow['month'])) ?></strong>
                    <span><?= e(finance_money($chartRow['sales'])) ?></span>
                    <small><?= e(finance_money($chartRow['cash_received'])) ?></small>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="panel dashboard-section sales-workstation">
        <div class="section-heading">
            <div><p class="eyebrow">Деталізація</p><h2>Замовлення за <?= e($pageTitle) ?></h2></div>
            <span class="status-badge"><?= $statusFilter === 'debt' ? 'тільки неоплачені / частково оплачені' : 'товари показані всередині замовлення' ?></span>
        </div>
        <div class="order-feed">
            <?php if (!$orders): ?><div class="empty-state">Немає даних.</div><?php endif; ?>
            <?php foreach ($orders as $order): ?>
                <?php
                $items = $orderItems[(int) ($order['keycrm_id'] ?? 0)] ?? [];
                $clientName = (string) (($order['company_name'] ?? '') ?: ($order['buyer_name'] ?? '—'));
                $buyerName = trim((string) ($order['buyer_name'] ?? ''));
                $unpaid = (float) ($order['unpaid_amount_uah'] ?? 0);
                ?>
                <article class="order-card <?= $unpaid > 0 ? 'has-debt' : '' ?>">
                    <div class="order-card__main">
                        <div class="order-card__identity">
                            <span class="order-number">№ <?= e((string) $order['order_number']) ?></span>
                            <strong><?= e($clientName) ?></strong>
                            <?php if ($buyerName !== '' && $buyerName !== $clientName): ?><small><?= e($buyerName) ?></small><?php endif; ?>
                            <small><?= e((string) $order['ordered_at']) ?> · <?= e((string) ($order['manager_name'] ?: 'Без менеджера')) ?></small>
                        </div>
                        <div class="order-card__status">
                            <span class="status-badge"><?= e((string) ($order['status_name'] ?: '—')) ?></span>
                            <small><?= e((string) $order['item_count']) ?> поз.</small>
                        </div>
                        <div class="order-card__money">
                            <div><span>Сума</span><strong><?= e(finance_money($order['total_amount_uah'])) ?></strong></div>
                            <div><span>Оплачено</span><strong><?= e(finance_money($order['paid_amount_uah'])) ?></strong></div>
                            <div><span>Борг</span><strong class="<?= $unpaid > 0 ? 'danger-text' : '' ?>"><?= e(finance_money($order['unpaid_amount_uah'])) ?></strong></div>
                            <div><span>Маржа</span><strong><?= e(finance_money($order['margin_sum_uah'])) ?></strong></div>
                        </div>
                    </div>
                    <?php if ($items): ?>
                        <div class="order-products">
                            <div class="order-products__caption">Позиції замовлення</div>
                            <?php foreach ($items as $item): ?>
                                <div class="order-product">
                                    <div>
                                        <strong><?= e((string) ($item['name'] ?: 'Позиція')) ?></strong>
                                        <?php if (!empty($item['properties_text'])): ?><small><?= e((string) $item['properties_text']) ?></small><?php endif; ?>
                                        <?php if (!empty($item['comment'])): ?><small><?= e((string) $item['comment']) ?></small><?php endif; ?>
                                    </div>
                                    <span><?= e((string) ($item['quantity'] ?? '0')) ?> <?= e((string) (($item['unit'] ?? '') ?: 'шт')) ?></span>
                                    <span><?= e(finance_money($item['sale_price'] ?? $item['product_price'] ?? 0)) ?></span>
                                    <?php if ($canSeeCosts): ?><span class="muted">с/в <?= e(finance_money($item['purchase_price'] ?? 0)) ?></span><?php endif; ?>
                                    <strong><?= e(finance_money($item['total_amount'] ?? 0)) ?></strong>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    </section>

    <?php if ($cashPayments): ?>
        <section class="panel dashboard-section sales-workstation">
            <div class="section-heading">
                <div><p class="eyebrow">Оплати</p><h2>Платежі, які прийшли за <?= e($periodFromMonth) ?> → <?= e($periodToMonth) ?></h2></div>
                <span class="status-badge"><?= e((string) count($cashPayments)) ?> платежів</span>
            </div>
            <div class="table-scroll">
                <table class="data-table">
                    <thead>
                    <tr>
                        <th>Дата</th>
                        <th>Замовлення</th>
                        <th>Місяць замовлення</th>
                        <th>Клієнт</th>
                        <th>Метод</th>
                        <th class="num">Сума</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($cashPayments as $payment): ?>
                        <tr>
                            <td><?= e((string) $payment['payment_date']) ?></td>
                            <td><?= e((string) ($payment['order_number'] ?: '—')) ?></td>
                            <td><?= e((string) ($payment['order_month'] ?: '—')) ?></td>
                            <td><?= e((string) (($payment['company_name'] ?? '') ?: ($payment['buyer_name'] ?? '—'))) ?></td>
                            <td><?= e((string) ($payment['payment_method_name'] ?: '—')) ?></td>
                            <td class="num"><strong><?= e(finance_money($payment['amount'])) ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>
</main>
<?= app_version_badge() ?>
</body>
</html>
