<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/cockpit.php';
require_once __DIR__ . '/financial.php';
require_once __DIR__ . '/cockpit_layout.php';

require_login();

$monthInput = (string) ($_GET['month'] ?? ($_GET['quarter'] ?? date('Y-m')));
$search = trim((string) ($_GET['q'] ?? ''));
$managerFilter = trim((string) ($_GET['manager'] ?? ''));
$trendFilter = trim((string) ($_GET['trend'] ?? ''));
if (!array_key_exists($trendFilter, ['down' => 1, 'sleeping' => 1, 'new' => 1, 'returned' => 1, 'up' => 1, 'active' => 1, 'idle' => 1])) {
    $trendFilter = '';
}
$rowsByKey = [];
$trend = [];
$pageError = '';
$hasOrders = invoice_table_exists('db_orders');
$hasPayments = invoice_table_exists('db_order_payments');
$orderColumns = $hasOrders ? finance_columns('db_orders') : [];
if (!in_array('manager_name', $orderColumns, true)) {
    $managerFilter = '';
}
$hasClientCompanies = invoice_table_exists('db_client_companies');
$hasClientContacts = invoice_table_exists('db_client_contacts');
$clientCompanyColumns = $hasClientCompanies ? finance_columns('db_client_companies') : [];
$clientContactColumns = $hasClientContacts ? finance_columns('db_client_contacts') : [];
$managerOptions = [];

function client_balances_quarter_start(string $month): string
{
    $month = cockpit_valid_month($month);
    $year = (int) substr($month, 0, 4);
    $monthNumber = (int) substr($month, 5, 2);
    $quarterMonth = ((int) floor(($monthNumber - 1) / 3) * 3) + 1;
    return sprintf('%04d-%02d', $year, $quarterMonth);
}

function client_balances_quarter_months(string $quarterStart): array
{
    $date = DateTimeImmutable::createFromFormat('!Y-m', client_balances_quarter_start($quarterStart)) ?: new DateTimeImmutable('first day of this month');
    return [
        $date->format('Y-m'),
        $date->modify('+1 month')->format('Y-m'),
        $date->modify('+2 months')->format('Y-m'),
    ];
}

function client_balances_month_label(string $month): string
{
    $labels = [
        1 => 'Січень',
        2 => 'Лютий',
        3 => 'Березень',
        4 => 'Квітень',
        5 => 'Травень',
        6 => 'Червень',
        7 => 'Липень',
        8 => 'Серпень',
        9 => 'Вересень',
        10 => 'Жовтень',
        11 => 'Листопад',
        12 => 'Грудень',
    ];
    return ($labels[(int) substr($month, 5, 2)] ?? $month) . ' ' . substr($month, 0, 4);
}

function client_balances_query(array $params): string
{
    $query = array_filter($params, static fn($value) => $value !== null && $value !== '');
    return http_build_query($query);
}

function client_balances_months(string $month, int $count = 6): array
{
    $date = DateTimeImmutable::createFromFormat('!Y-m', cockpit_valid_month($month)) ?: new DateTimeImmutable('first day of this month');
    $months = [];
    for ($i = $count - 1; $i >= 0; $i--) {
        $months[] = $date->modify("-{$i} months")->format('Y-m');
    }
    return $months;
}

function client_balances_coalesce(array $expressions, string $fallback): string
{
    $parts = $expressions;
    $parts[] = "'" . str_replace("'", "''", $fallback) . "'";
    return 'COALESCE(' . implode(', ', $parts) . ')';
}

function client_balances_exprs(array $orderColumns, bool $hasClientCompanies, bool $hasClientContacts, array $clientCompanyColumns, array $clientContactColumns): array
{
    $hasCompanyId = in_array('company_id', $orderColumns, true);
    $hasBuyerId = in_array('buyer_id', $orderColumns, true);
    $hasClientId = in_array('client_id', $orderColumns, true);
    $hasCompanyName = in_array('company_name', $orderColumns, true);
    $hasBuyerName = in_array('buyer_name', $orderColumns, true);
    $hasClientName = in_array('client_name', $orderColumns, true);
    $hasBuyerEmail = in_array('buyer_email', $orderColumns, true);
    $hasBuyerPhone = in_array('buyer_phone', $orderColumns, true);
    $hasOrderNumber = in_array('order_number', $orderColumns, true);
    $hasManagerName = in_array('manager_name', $orderColumns, true);

    $companyJoin = $hasClientCompanies && $hasCompanyId ? "LEFT JOIN db_client_companies cc ON cc.keycrm_company_id = o.company_id" : "";
    $contactJoin = $hasClientContacts && $hasBuyerId ? "LEFT JOIN db_client_contacts ct ON ct.keycrm_buyer_id = o.buyer_id" : "";

    $companyExpressions = [];
    if ($hasClientCompanies && $hasCompanyId) {
        foreach (['keycrm_name', 'name', 'display_name'] as $column) {
            if (in_array($column, $clientCompanyColumns, true)) {
                $companyExpressions[] = "NULLIF(cc.{$column}, '')";
            }
        }
    }
    if ($hasCompanyName) {
        $companyExpressions[] = "NULLIF(o.company_name, '')";
    }
    if ($hasClientName) {
        $companyExpressions[] = "NULLIF(o.client_name, '')";
    }
    if ($hasBuyerName) {
        $companyExpressions[] = "NULLIF(o.buyer_name, '')";
    }
    $companyName = client_balances_coalesce($companyExpressions, 'Без компанії');

    $buyerExpressions = [];
    if ($hasClientContacts && $hasBuyerId) {
        if (in_array('full_name', $clientContactColumns, true)) {
            $buyerExpressions[] = "NULLIF(ct.full_name, '')";
        }
    }
    if ($hasBuyerName) {
        $buyerExpressions[] = "NULLIF(o.buyer_name, '')";
    }
    if ($hasClientName) {
        $buyerExpressions[] = "NULLIF(o.client_name, '')";
    }
    if ($hasBuyerEmail) {
        $buyerExpressions[] = "NULLIF(o.buyer_email, '')";
    }
    $buyerName = client_balances_coalesce($buyerExpressions, 'Без покупця');

    if ($hasCompanyId) {
        $key = "CASE WHEN COALESCE(o.company_id, 0) > 0 THEN CONCAT('company:', o.company_id) ELSE CONCAT('company-name:', {$companyName}) END";
    } elseif ($hasClientId) {
        $key = "CASE WHEN COALESCE(o.client_id, 0) > 0 THEN CONCAT('client:', o.client_id) ELSE CONCAT('company-name:', {$companyName}) END";
    } else {
        $key = "CONCAT('company-name:', {$companyName})";
    }

    $searchFields = [];
    foreach ([
        $hasOrderNumber ? "o.order_number" : null,
        $hasCompanyName ? "o.company_name" : null,
        $hasBuyerName ? "o.buyer_name" : null,
        $hasClientName ? "o.client_name" : null,
        $hasBuyerEmail ? "o.buyer_email" : null,
        $hasBuyerPhone ? "o.buyer_phone" : null,
        $hasManagerName ? "o.manager_name" : null,
    ] as $field) {
        if ($field !== null) {
            $searchFields[] = $field;
        }
    }
    if ($hasClientCompanies && $hasCompanyId) {
        foreach (['keycrm_name', 'keycrm_title', 'display_name', 'name', 'title'] as $column) {
            if (in_array($column, $clientCompanyColumns, true)) {
                $searchFields[] = "cc.{$column}";
            }
        }
    }
    if ($hasClientContacts && $hasBuyerId) {
        foreach (['full_name', 'email', 'phone'] as $column) {
            if (in_array($column, $clientContactColumns, true)) {
                $searchFields[] = "ct.{$column}";
            }
        }
    }
    $searchFields[] = $companyName;
    $searchFields[] = $buyerName;
    if (in_array('raw_json', $orderColumns, true)) {
        $searchFields[] = "o.raw_json";
    }
    $searchHaystack = "LOWER(CONCAT_WS(' ', " . implode(', ', array_map(static fn($field) => "COALESCE(CAST({$field} AS CHAR), '')", $searchFields)) . "))";

    return [
        'key' => $key,
        'company_name' => $companyName,
        'buyer_name' => $buyerName,
        'manager_name' => $hasManagerName ? "COALESCE(NULLIF(MAX(o.manager_name), ''), 'Без менеджера')" : "'Без менеджера'",
        'joins' => trim($companyJoin . "\n" . $contactJoin),
        'search_haystack' => $searchHaystack,
    ];
}

function client_balances_search_filter(string $search, string $haystack): array
{
    $normalizedSearch = function_exists('mb_strtolower')
        ? mb_strtolower(trim($search), 'UTF-8')
        : strtolower(trim($search));
    $tokens = preg_split('/\s+/u', $normalizedSearch, -1, PREG_SPLIT_NO_EMPTY);
    if (!$tokens) {
        return ['sql' => '', 'params' => []];
    }

    $parts = [];
    $params = [];
    foreach (array_values($tokens) as $index => $token) {
        $key = 'search_' . $index;
        $parts[] = "{$haystack} LIKE :{$key}";
        $params[$key] = '%' . $token . '%';
    }

    return [
        'sql' => ' AND (' . implode(' AND ', $parts) . ')',
        'params' => $params,
    ];
}

function client_balances_manager_filter(string $manager): array
{
    if ($manager === '') {
        return ['sql' => '', 'params' => []];
    }

    return [
        'sql' => " AND COALESCE(NULLIF(o.manager_name, ''), 'Без менеджера') = :manager_filter",
        'params' => ['manager_filter' => $manager],
    ];
}

function client_balances_blank_row(string $key, string $name = ''): array
{
    return [
        'client_key' => $key,
        'client_name' => $name !== '' ? $name : 'Без компанії',
        'buyers' => [],
        'buyers_count' => 0,
        'manager_name' => '',
        'order_count' => 0,
        'sales_month' => 0.0,
        'paid_by_order' => 0.0,
        'unpaid_by_order' => 0.0,
        'total_purchases' => 0.0,
        'receivable_total' => 0.0,
        'receivable_count' => 0,
        'largest_receivable' => 0.0,
        'cash_received' => 0.0,
        'cash_count' => 0,
        'previous_month_sales' => 0.0,
        'first_order_month' => '',
        'last_order_month' => '',
        'last_before_month' => '',
        'active_month_count' => 0,
        'lifetime_order_count' => 0,
    ];
}

function client_balances_take_row(array &$rowsByKey, array $row): void
{
    $key = (string) ($row['client_key'] ?? '');
    if ($key === '') {
        $key = 'unknown';
    }
    if (!isset($rowsByKey[$key])) {
        $rowsByKey[$key] = client_balances_blank_row($key, trim((string) ($row['client_name'] ?? '')));
    }
    if ($rowsByKey[$key]['client_name'] === 'Без компанії' && !empty($row['client_name'])) {
        $rowsByKey[$key]['client_name'] = (string) $row['client_name'];
    }
    if ($rowsByKey[$key]['manager_name'] === '' && !empty($row['manager_name'])) {
        $rowsByKey[$key]['manager_name'] = (string) $row['manager_name'];
    }
}

function client_balances_split_buyers(?string $buyers): array
{
    $buyers = trim((string) $buyers);
    if ($buyers === '') {
        return [];
    }
    $parts = array_values(array_filter(array_map('trim', explode('|||', $buyers)), static fn($item) => $item !== ''));
    return array_slice(array_values(array_unique($parts)), 0, 8);
}

function client_balances_trend_labels(): array
{
    return [
        'down' => 'падає',
        'sleeping' => 'спить',
        'new' => 'новий',
        'returned' => 'повернувся',
        'up' => 'росте',
        'active' => 'активний',
        'idle' => 'немає руху',
    ];
}

function client_balances_trend_rank(string $trendClass): int
{
    $ranks = ['down' => 0, 'sleeping' => 1, 'returned' => 2, 'new' => 3, 'up' => 4, 'active' => 5, 'idle' => 6];
    return $ranks[$trendClass] ?? 5;
}

function client_balances_segment(float $totalPurchases): array
{
    if ($totalPurchases >= 2000000) {
        return ['class' => 'vip', 'label' => 'VIP'];
    }
    if ($totalPurchases >= 1000000) {
        return ['class' => 'large', 'label' => 'великий'];
    }
    if ($totalPurchases >= 250000) {
        return ['class' => 'medium', 'label' => 'середній'];
    }
    if ($totalPurchases > 0) {
        return ['class' => 'small', 'label' => 'малий'];
    }
    return ['class' => 'none', 'label' => 'без закупок'];
}

function client_balances_count_label(int $count, string $one, string $few, string $many): string
{
    $mod10 = $count % 10;
    $mod100 = $count % 100;
    if ($mod10 === 1 && $mod100 !== 11) {
        return $one;
    }
    if (in_array($mod10, [2, 3, 4], true) && !in_array($mod100, [12, 13, 14], true)) {
        return $few;
    }
    return $many;
}

function client_balances_trend(array $trend, string $clientKey, string $selectedMonth, string $previousMonth, string $twoMonthsAgo, ?string $firstOrderMonth, ?string $lastBeforeMonth): array
{
    $current = (float) ($trend[$clientKey][$selectedMonth]['sales'] ?? 0);
    $previous = (float) ($trend[$clientKey][$previousMonth]['sales'] ?? 0);
    $beforePrevious = (float) ($trend[$clientKey][$twoMonthsAgo]['sales'] ?? 0);

    $class = 'idle';
    if ($current > 0 && $firstOrderMonth === $selectedMonth) {
        $class = 'new';
    } elseif ($current > 0 && $previous <= 0 && $lastBeforeMonth !== null && $lastBeforeMonth < $previousMonth) {
        $class = 'returned';
    } elseif ($current > 0 && $beforePrevious > 0 && $previous > $beforePrevious && $current > $previous) {
        $class = 'up';
    } elseif ($current <= 0 && $previous > 0) {
        $class = 'down';
    } elseif ($current <= 0 && $previous <= 0 && $beforePrevious <= 0 && $lastBeforeMonth !== null) {
        $class = 'sleeping';
    } elseif ($current > 0) {
        $class = 'active';
    }

    $labels = client_balances_trend_labels();

    return [
        'class' => $class,
        'label' => $labels[$class],
        'current' => $current,
        'previous' => $previous,
        'before_previous' => $beforePrevious,
    ];
}

$selectedMonth = cockpit_valid_month($monthInput);
$monthDate = DateTimeImmutable::createFromFormat('!Y-m', $selectedMonth) ?: new DateTimeImmutable('first day of this month');
$previousMonth = $monthDate->modify('-1 month')->format('Y-m');
$twoMonthsAgo = $monthDate->modify('-2 months')->format('Y-m');
$threeMonthsAgo = $monthDate->modify('-3 months')->format('Y-m');
$allTrendMonths = [$threeMonthsAgo, $twoMonthsAgo, $previousMonth, $selectedMonth];
$boundsStart = $monthDate->format('Y-m-01 00:00:00');
$boundsEnd = $monthDate->modify('last day of this month')->format('Y-m-d 23:59:59');
$prevMonthUrl = base_path('/client_balances.php?' . client_balances_query([
    'month' => $monthDate->modify('-1 month')->format('Y-m'),
    'q' => $search,
    'manager' => $managerFilter,
]));
$nextMonthUrl = base_path('/client_balances.php?' . client_balances_query([
    'month' => $monthDate->modify('+1 month')->format('Y-m'),
    'q' => $search,
    'manager' => $managerFilter,
]));

try {
    if ($hasOrders) {
        $expr = client_balances_exprs($orderColumns, $hasClientCompanies, $hasClientContacts, $clientCompanyColumns, $clientContactColumns);
        $notCanceled = cockpit_not_canceled_sql('o');
        $searchFilter = client_balances_search_filter($search, (string) $expr['search_haystack']);
        $searchSql = (string) $searchFilter['sql'];
        $managerFilterSql = client_balances_manager_filter($managerFilter);
        $managerSql = (string) $managerFilterSql['sql'];
        $searchParams = array_merge($searchFilter['params'], $managerFilterSql['params']);

        if (in_array('manager_name', $orderColumns, true)) {
            $managerStmt = db()->query("
                SELECT COALESCE(NULLIF(manager_name, ''), 'Без менеджера') AS manager_name, COUNT(*) AS order_count
                FROM db_orders o
                WHERE {$notCanceled}
                GROUP BY COALESCE(NULLIF(manager_name, ''), 'Без менеджера')
                ORDER BY manager_name ASC
            ");
            $managerOptions = $managerStmt->fetchAll();
        }

        $stmt = db()->prepare("
            SELECT
                {$expr['key']} AS client_key,
                {$expr['company_name']} AS client_name,
                {$expr['manager_name']} AS manager_name,
                COUNT(*) AS order_count,
                COALESCE(SUM(o.total_amount_uah), 0) AS sales_month,
                COALESCE(SUM(o.paid_amount_uah), 0) AS paid_by_order,
                COALESCE(SUM(o.unpaid_amount_uah), 0) AS unpaid_by_order
            FROM db_orders o
            {$expr['joins']}
            WHERE o.order_month = :selected_month
              AND {$notCanceled}
              {$searchSql}
              {$managerSql}
            GROUP BY client_key, client_name
        ");
        $stmt->execute(array_merge([
            'selected_month' => $selectedMonth,
        ], $searchParams));
        foreach ($stmt->fetchAll() as $row) {
            $key = (string) ($row['client_key'] ?? 'unknown');
            $rowsByKey[$key] = client_balances_blank_row($key, (string) $row['client_name']);
            $rowsByKey[$key]['manager_name'] = (string) ($row['manager_name'] ?? '');
            $rowsByKey[$key]['order_count'] = (int) ($row['order_count'] ?? 0);
            $rowsByKey[$key]['sales_month'] = (float) ($row['sales_month'] ?? 0);
            $rowsByKey[$key]['paid_by_order'] = (float) ($row['paid_by_order'] ?? 0);
            $rowsByKey[$key]['unpaid_by_order'] = (float) ($row['unpaid_by_order'] ?? 0);
        }

        $stmt = db()->prepare("
            SELECT
                {$expr['key']} AS client_key,
                {$expr['company_name']} AS client_name,
                {$expr['manager_name']} AS manager_name,
                COALESCE(SUM(o.total_amount_uah), 0) AS total_purchases,
                MIN(o.order_month) AS first_order_month,
                MAX(o.order_month) AS last_order_month,
                MAX(CASE WHEN o.order_month < :selected_month THEN o.order_month ELSE NULL END) AS last_before_month,
                COUNT(DISTINCT o.order_month) AS active_month_count,
                COUNT(*) AS lifetime_order_count
            FROM db_orders o
            {$expr['joins']}
            WHERE {$notCanceled}
              {$searchSql}
              {$managerSql}
            GROUP BY client_key, client_name
        ");
        $stmt->execute(array_merge(['selected_month' => $selectedMonth], $searchParams));
        foreach ($stmt->fetchAll() as $row) {
            $key = (string) ($row['client_key'] ?? 'unknown');
            client_balances_take_row($rowsByKey, $row);
            $rowsByKey[$key]['total_purchases'] = (float) ($row['total_purchases'] ?? 0);
            $rowsByKey[$key]['first_order_month'] = (string) ($row['first_order_month'] ?? '');
            $rowsByKey[$key]['last_order_month'] = (string) ($row['last_order_month'] ?? '');
            $rowsByKey[$key]['last_before_month'] = (string) ($row['last_before_month'] ?? '');
            $rowsByKey[$key]['active_month_count'] = (int) ($row['active_month_count'] ?? 0);
            $rowsByKey[$key]['lifetime_order_count'] = (int) ($row['lifetime_order_count'] ?? 0);
        }

        $stmt = db()->prepare("
            SELECT
                {$expr['key']} AS client_key,
                {$expr['company_name']} AS client_name,
                {$expr['manager_name']} AS manager_name,
                COUNT(*) AS receivable_count,
                COALESCE(SUM(o.unpaid_amount_uah), 0) AS receivable_total,
                COALESCE(MAX(o.unpaid_amount_uah), 0) AS largest_receivable
            FROM db_orders o
            {$expr['joins']}
            WHERE o.unpaid_amount_uah > 0
              AND {$notCanceled}
              {$searchSql}
              {$managerSql}
            GROUP BY client_key, client_name
        ");
        $stmt->execute($searchParams);
        foreach ($stmt->fetchAll() as $row) {
            $key = (string) ($row['client_key'] ?? 'unknown');
            client_balances_take_row($rowsByKey, $row);
            $rowsByKey[$key]['manager_name'] = $rowsByKey[$key]['manager_name'] ?: (string) ($row['manager_name'] ?? '');
            $rowsByKey[$key]['receivable_count'] = (int) ($row['receivable_count'] ?? 0);
            $rowsByKey[$key]['receivable_total'] = (float) ($row['receivable_total'] ?? 0);
            $rowsByKey[$key]['largest_receivable'] = (float) ($row['largest_receivable'] ?? 0);
        }

        $stmt = db()->prepare("
            SELECT
                {$expr['key']} AS client_key,
                {$expr['company_name']} AS client_name,
                COUNT(DISTINCT {$expr['buyer_name']}) AS buyers_count,
                GROUP_CONCAT(DISTINCT {$expr['buyer_name']} ORDER BY {$expr['buyer_name']} SEPARATOR '|||') AS buyers
            FROM db_orders o
            {$expr['joins']}
            WHERE {$notCanceled}
              {$searchSql}
              {$managerSql}
            GROUP BY client_key, client_name
        ");
        $stmt->execute($searchParams);
        foreach ($stmt->fetchAll() as $row) {
            $key = (string) ($row['client_key'] ?? 'unknown');
            client_balances_take_row($rowsByKey, $row);
            $rowsByKey[$key]['buyers'] = client_balances_split_buyers((string) ($row['buyers'] ?? ''));
            $rowsByKey[$key]['buyers_count'] = (int) ($row['buyers_count'] ?? 0);
        }

        $trendMonthPlaceholders = implode(',', array_map(static fn($idx) => ':trend_month_' . $idx, array_keys($allTrendMonths)));
        $trendParams = $searchParams;
        foreach ($allTrendMonths as $idx => $trendMonth) {
            $trendParams['trend_month_' . $idx] = $trendMonth;
        }
        $stmt = db()->prepare("
            SELECT
                {$expr['key']} AS client_key,
                {$expr['company_name']} AS client_name,
                o.order_month,
                COUNT(*) AS order_count,
                COALESCE(SUM(o.total_amount_uah), 0) AS sales_month,
                COALESCE(SUM(o.paid_amount_uah), 0) AS paid_by_order,
                COALESCE(SUM(o.unpaid_amount_uah), 0) AS unpaid_by_order
            FROM db_orders o
            {$expr['joins']}
            WHERE o.order_month IN ({$trendMonthPlaceholders})
              AND {$notCanceled}
              {$searchSql}
              {$managerSql}
            GROUP BY client_key, client_name, o.order_month
        ");
        $stmt->execute($trendParams);
        foreach ($stmt->fetchAll() as $row) {
            $key = (string) ($row['client_key'] ?? 'unknown');
            client_balances_take_row($rowsByKey, $row);
            $trend[$key][(string) $row['order_month']] = [
                'sales' => (float) ($row['sales_month'] ?? 0),
                'paid' => (float) ($row['paid_by_order'] ?? 0),
                'unpaid' => (float) ($row['unpaid_by_order'] ?? 0),
                'count' => (int) ($row['order_count'] ?? 0),
            ];
        }

        if ($hasPayments) {
            $activePayment = cockpit_active_payment_sql('p');
            $stmt = db()->prepare("
                SELECT
                    {$expr['key']} AS client_key,
                    {$expr['company_name']} AS client_name,
                    {$expr['manager_name']} AS manager_name,
                    COUNT(*) AS cash_count,
                    COALESCE(SUM(p.amount), 0) AS cash_received
                FROM db_order_payments p
                INNER JOIN db_orders o ON o.keycrm_id = p.keycrm_order_id
                {$expr['joins']}
                WHERE p.payment_date >= :month_start
                  AND p.payment_date <= :month_end
                  AND {$activePayment}
                  AND {$notCanceled}
                  {$searchSql}
                  {$managerSql}
                GROUP BY client_key, client_name
            ");
            $stmt->execute(array_merge([
                'month_start' => $boundsStart,
                'month_end' => $boundsEnd,
            ], $searchParams));
            foreach ($stmt->fetchAll() as $row) {
                $key = (string) ($row['client_key'] ?? 'unknown');
                client_balances_take_row($rowsByKey, $row);
                $rowsByKey[$key]['manager_name'] = $rowsByKey[$key]['manager_name'] ?: (string) ($row['manager_name'] ?? '');
                $rowsByKey[$key]['cash_count'] = (int) ($row['cash_count'] ?? 0);
                $rowsByKey[$key]['cash_received'] = (float) ($row['cash_received'] ?? 0);
            }
        }
    }
} catch (Throwable $e) {
    error_log('client_balances failed: ' . $e->getMessage());
    $pageError = 'Не вдалося побудувати баланс клієнтів. Помилка записана в server error log.';
    $rowsByKey = [];
}

$allRows = array_values($rowsByKey);
foreach ($allRows as &$rowRef) {
    $trendInfo = client_balances_trend(
        $trend,
        (string) $rowRef['client_key'],
        $selectedMonth,
        $previousMonth,
        $twoMonthsAgo,
        $rowRef['first_order_month'] !== '' ? (string) $rowRef['first_order_month'] : null,
        $rowRef['last_before_month'] !== '' ? (string) $rowRef['last_before_month'] : null
    );
    $segmentInfo = client_balances_segment((float) $rowRef['total_purchases']);
    $rowRef['trend_class'] = $trendInfo['class'];
    $rowRef['trend_label'] = $trendInfo['label'];
    $rowRef['current_month_sales'] = $trendInfo['current'];
    $rowRef['previous_month_sales'] = $trendInfo['previous'];
    $rowRef['segment_class'] = $segmentInfo['class'];
    $rowRef['segment_label'] = $segmentInfo['label'];
}
unset($rowRef);

$monthSalesTotal = array_sum(array_map(static fn($row) => (float) $row['sales_month'], $allRows));
$monthPaidTotal = array_sum(array_map(static fn($row) => (float) $row['paid_by_order'], $allRows));
$monthCashTotal = array_sum(array_map(static fn($row) => (float) $row['cash_received'], $allRows));
$activeClientCount = count(array_filter($allRows, static fn($row) => (float) $row['sales_month'] > 0));

$trendCounts = ['down' => 0, 'sleeping' => 0, 'new' => 0, 'returned' => 0, 'up' => 0, 'active' => 0, 'idle' => 0];
foreach ($allRows as $row) {
    $trendCounts[$row['trend_class']] = ($trendCounts[$row['trend_class']] ?? 0) + 1;
}

$filteredRows = $trendFilter !== ''
    ? array_values(array_filter($allRows, static fn($row) => $row['trend_class'] === $trendFilter))
    : $allRows;

usort($filteredRows, static function (array $a, array $b): int {
    $rankCompare = client_balances_trend_rank((string) $a['trend_class']) <=> client_balances_trend_rank((string) $b['trend_class']);
    if ($rankCompare !== 0) {
        return $rankCompare;
    }
    $receivableCompare = (float) $b['receivable_total'] <=> (float) $a['receivable_total'];
    if ($receivableCompare !== 0) {
        return $receivableCompare;
    }
    return (float) $b['total_purchases'] <=> (float) $a['total_purchases'];
});
$rows = array_slice($filteredRows, 0, 200);

?><!doctype html>
<html lang="uk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Баланси клієнтів — .BRAND DB</title>
    <link rel="stylesheet" href="<?= e(asset_path('/assets/app.css')) ?>">
</head>
<body>
<main class="app-shell cockpit-shell">
    <?php cockpit_page_header('CEO Money Cockpit', 'Клієнти', 'Клієнтська база: хто росте, хто падає, хто спить і кому треба увага.', 'client_balances', $selectedMonth, false); ?>

    <section class="kpi-grid compact-kpis">
        <div class="kpi-card"><span class="label">Факт за місяць</span><strong><?= e(finance_money($monthSalesTotal)) ?></strong><small><?= e(client_balances_month_label($selectedMonth)) ?></small></div>
        <div class="kpi-card"><span class="label">Оплачено</span><strong><?= e(finance_money($monthPaidTotal)) ?></strong><small>у замовленнях цього місяця</small></div>
        <div class="kpi-card"><span class="label">Гроші прийшли</span><strong><?= e(finance_money($monthCashTotal)) ?></strong><small>за датою платежу у місяці</small></div>
        <div class="kpi-card"><span class="label">Активні у місяці</span><strong><?= e((string) $activeClientCount) ?></strong><small>компаній</small></div>
    </section>

    <?php if ($pageError !== ''): ?>
        <section class="panel dashboard-section">
            <span class="status-badge status-badge--danger"><?= e($pageError) ?></span>
        </section>
    <?php endif; ?>

    <section class="panel dashboard-section client-command-toolbar">
        <div class="client-month-nav">
            <a class="quarter-arrow" href="<?= e($prevMonthUrl) ?>" aria-label="Попередній місяць">‹</a>
            <div>
                <span class="label">Місяць</span>
                <strong><?= e(client_balances_month_label($selectedMonth)) ?></strong>
            </div>
            <a class="quarter-arrow" href="<?= e($nextMonthUrl) ?>" aria-label="Наступний місяць">›</a>
        </div>
        <form class="client-balance-toolbar client-balance-toolbar--clients" method="get" action="<?= e(base_path('/client_balances.php')) ?>">
            <input type="hidden" name="month" value="<?= e($selectedMonth) ?>">
            <label class="client-balance-search">
                <span>Пошук</span>
                <input type="search" name="q" value="<?= e($search) ?>" placeholder="компанія, покупець, email, телефон, номер">
            </label>
            <label>
                <span>Менеджер</span>
                <select name="manager">
                    <option value="">Всі менеджери</option>
                    <?php foreach ($managerOptions as $managerOption): ?>
                        <?php $managerName = (string) $managerOption['manager_name']; ?>
                        <option value="<?= e($managerName) ?>" <?= $managerFilter === $managerName ? 'selected' : '' ?>><?= e($managerName) ?> · <?= e((string) $managerOption['order_count']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button type="submit">Показати</button>
        </form>
        <div class="segmented-scroll client-trend-filter">
            <a class="<?= $trendFilter === '' ? 'active' : '' ?>" href="<?= e(base_path('/client_balances.php?' . client_balances_query(['month' => $selectedMonth, 'q' => $search, 'manager' => $managerFilter]))) ?>">Всі <small><?= e((string) count($allRows)) ?></small></a>
            <?php foreach (client_balances_trend_labels() as $trendKey => $trendLabelText): ?>
                <a class="<?= $trendFilter === $trendKey ? 'active' : '' ?>" href="<?= e(base_path('/client_balances.php?' . client_balances_query(['month' => $selectedMonth, 'q' => $search, 'manager' => $managerFilter, 'trend' => $trendKey]))) ?>"><?= e($trendLabelText) ?> <small><?= e((string) ($trendCounts[$trendKey] ?? 0)) ?></small></a>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="panel dashboard-section client-command-panel">
        <div class="section-heading">
            <div>
                <p class="eyebrow">Top clients</p>
                <h2>Компанії за сумою закупок</h2>
            </div>
            <span class="status-badge">натисни назву, щоб відкрити замовлення</span>
        </div>
        <div class="client-command-list">
            <?php if (!$rows && $trendFilter !== ''): ?>
                <div class="empty-state">Немає клієнтів у категорії «<?= e(client_balances_trend_labels()[$trendFilter] ?? $trendFilter) ?>».</div>
            <?php elseif (!$rows): ?>
                <div class="empty-state">Даних немає.</div>
            <?php endif; ?>
            <?php foreach ($rows as $row): ?>
                <?php
                $clientKey = (string) $row['client_key'];
                $trendClass = (string) $row['trend_class'];
                $trendLabel = (string) $row['trend_label'];
                $currentMonthSales = (float) $row['current_month_sales'];
                $previousMonthSales = (float) $row['previous_month_sales'];
                $trendArrow = $trendClass === 'up' ? '↑' : ($trendClass === 'down' ? '↓' : (in_array($trendClass, ['new', 'returned', 'active'], true) ? '→' : ($trendClass === 'idle' ? '–' : '')));
                $lastOrderLabel = $row['last_order_month'] !== '' ? client_balances_month_label((string) $row['last_order_month']) : 'немає';
                $extraBuyers = (int) $row['buyers_count'] - count($row['buyers']);
                $salesLink = base_path('/sales.php?' . client_balances_query([
                    'month' => $selectedMonth,
                    'client_key' => $clientKey,
                ]));
                ?>
                <article class="client-row <?= e($trendClass) ?>">
                    <div class="client-row-head">
                        <a class="client-row-name" href="<?= e($salesLink) ?>"><?= e((string) $row['client_name']) ?></a>
                        <span class="client-row-segment <?= e((string) $row['segment_class']) ?>"><?= e((string) $row['segment_label']) ?></span>
                        <span class="client-row-status <?= e($trendClass) ?> <?= $trendClass !== 'idle' ? 'chip' : '' ?>">
                            <?= e($trendArrow !== '' ? $trendArrow . ' ' : '') ?><?= e($trendLabel) ?>
                        </span>
                        <?php if ($row['buyers']): ?>
                            <details class="client-row-contacts">
                                <summary><?= e((string) $row['buyers_count']) ?> <?= e(client_balances_count_label((int) $row['buyers_count'], 'контакт', 'контакти', 'контактів')) ?></summary>
                                <div class="client-row-contacts-list">
                                    <?= e(implode(' / ', $row['buyers'])) ?><?= $extraBuyers > 0 ? ' +' . e((string) $extraBuyers) . ' ще' : '' ?>
                                </div>
                            </details>
                        <?php endif; ?>
                        <span class="client-manager-tag"><?= e((string) ($row['manager_name'] ?: 'Без менеджера')) ?></span>
                    </div>
                    <div class="client-stat-row">
                        <div class="client-stat"><span>Купив всього</span><strong><?= e(finance_money($row['total_purchases'])) ?></strong><small>&nbsp;</small></div>
                        <div class="client-stat"><span>Борг</span><strong class="<?= (float) $row['receivable_total'] > 0 ? 'danger-text' : '' ?>"><?= e(finance_money($row['receivable_total'])) ?></strong><small><?= (int) $row['receivable_count'] > 0 ? e((string) $row['receivable_count']) . ' борг.' : '&nbsp;' ?></small></div>
                        <div class="client-stat"><span>Оплатили</span><strong><?= e(finance_money($row['cash_received'])) ?></strong><small><?= (int) $row['cash_count'] > 0 ? e((string) $row['cash_count']) . ' пл.' : '&nbsp;' ?></small></div>
                        <div class="client-stat"><span>Місяць</span><strong><?= e(finance_money($currentMonthSales)) ?></strong><small>було <?= e(finance_money($previousMonthSales)) ?></small></div>
                        <div class="client-stat"><span>Останнє</span><strong><?= e($lastOrderLabel) ?></strong><small><?= e((string) $row['active_month_count']) ?> акт. міс.</small></div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
        <div class="client-work-note">
            <strong>Як працювати з базою:</strong>
            <span>↑ росте - підтримати і запропонувати наступний проєкт.</span>
            <span>повернувся - швидко закріпити контакт, щоб не втратити повторно.</span>
            <span>↓ падає - менеджеру подзвонити до того, як клієнт зникне.</span>
            <span>спить - перевірити останній контакт і причину паузи.</span>
            <span>VIP - клієнт від 2 млн UAH за весь час.</span>
            <span>борг - узгодити оплату або дату нагадування.</span>
        </div>
    </section>
</main>
<?= app_version_badge() ?>
</body>
</html>
