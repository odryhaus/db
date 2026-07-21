<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/cockpit.php';
require_once __DIR__ . '/financial.php';
require_once __DIR__ . '/cockpit_layout.php';
require_once __DIR__ . '/client_health.php';

require_login();
if (function_exists('ensure_analytics_exclusion_columns')) {
    ensure_analytics_exclusion_columns();
}

$monthInput = (string) ($_GET['month'] ?? ($_GET['quarter'] ?? date('Y-m')));
$search = trim((string) ($_GET['q'] ?? ''));
$managerFilter = trim((string) ($_GET['manager'] ?? ''));
$trendFilter = trim((string) ($_GET['trend'] ?? ''));
if (!array_key_exists($trendFilter, ['down' => 1, 'sleeping' => 1, 'new' => 1, 'returned' => 1, 'up' => 1, 'active' => 1, 'idle' => 1])) {
    $trendFilter = '';
}
$segmentFilter = trim((string) ($_GET['segment'] ?? ''));
if (!array_key_exists($segmentFilter, ['vip' => 1, 'large' => 1, 'medium' => 1, 'small' => 1])) {
    $segmentFilter = '';
}
$valueScope = trim((string) ($_GET['scope'] ?? 'all'));
if (!array_key_exists($valueScope, ['all' => 1, 'year' => 1, 'month' => 1])) {
    $valueScope = 'all';
}
$inactiveMode = (string) ($_GET['inactive'] ?? '') === '1';
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
$hasInvoices = invoice_table_exists('db_invoices');
$clientCompanyColumns = $hasClientCompanies ? finance_columns('db_client_companies') : [];
$clientContactColumns = $hasClientContacts ? finance_columns('db_client_contacts') : [];
$invoiceColumns = $hasInvoices ? finance_columns('db_invoices') : [];
$managerOptions = [];
$canManageAnalytics = user_role() === 'ceo';

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

function client_balances_redirect_back(): void
{
    $returnTo = (string) ($_POST['return_to'] ?? '');
    if ($returnTo === '' || $returnTo[0] !== '/' || str_starts_with($returnTo, '//') || str_contains($returnTo, "\n") || str_contains($returnTo, "\r")) {
        $returnTo = '/client_balances.php';
    }
    header('Location: ' . $returnTo);
    exit;
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
        'scope_purchases' => 0.0,
        'scope_active_month_count' => 0,
        'receivable_total' => 0.0,
        'receivable_count' => 0,
        'largest_receivable' => 0.0,
        'cash_received' => 0.0,
        'cash_count' => 0,
        'previous_month_sales' => 0.0,
        'first_order_month' => '',
        'last_order_month' => '',
        'last_before_month' => '',
        'order_dates' => '',
        'active_month_count' => 0,
        'lifetime_order_count' => 0,
        'payment_due_date' => '',
        'undefined_payment_due_count' => 0,
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
        return ['class' => 'large', 'label' => 'ключовий'];
    }
    if ($totalPurchases >= 250000) {
        return ['class' => 'medium', 'label' => 'основний'];
    }
    if ($totalPurchases > 0) {
        return ['class' => 'small', 'label' => 'стартовий'];
    }
    return ['class' => 'none', 'label' => 'без закупок'];
}

function client_balances_segment_labels(): array
{
    return [
        'vip' => 'VIP',
        'large' => 'ключові',
        'medium' => 'основні',
        'small' => 'стартові',
    ];
}

function client_balances_scope_labels(): array
{
    return [
        'all' => 'весь час',
        'year' => '12 міс.',
        'month' => 'місяць',
    ];
}

function client_balances_month_distance(string $selectedMonth, string $lastOrderMonth): int
{
    if ($lastOrderMonth === '') {
        return 999;
    }
    $selected = DateTimeImmutable::createFromFormat('!Y-m', cockpit_valid_month($selectedMonth));
    $last = DateTimeImmutable::createFromFormat('!Y-m', cockpit_valid_month($lastOrderMonth));
    if (!$selected || !$last) {
        return 999;
    }
    $months = (((int) $selected->format('Y')) - ((int) $last->format('Y'))) * 12;
    $months += ((int) $selected->format('m')) - ((int) $last->format('m'));
    return max(0, $months);
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
$scopeStartMonth = $valueScope === 'year' ? $monthDate->modify('-11 months')->format('Y-m') : $selectedMonth;
$boundsStart = $monthDate->format('Y-m-01 00:00:00');
$boundsEnd = $monthDate->modify('last day of this month')->format('Y-m-d 23:59:59');
$prevMonthUrl = base_path('/client_balances.php?' . client_balances_query([
    'month' => $monthDate->modify('-1 month')->format('Y-m'),
    'q' => $search,
    'manager' => $managerFilter,
    'trend' => $trendFilter,
    'segment' => $segmentFilter,
    'scope' => $valueScope,
    'inactive' => $inactiveMode ? '1' : '',
]));
$nextMonthUrl = base_path('/client_balances.php?' . client_balances_query([
    'month' => $monthDate->modify('+1 month')->format('Y-m'),
    'q' => $search,
    'manager' => $managerFilter,
    'trend' => $trendFilter,
    'segment' => $segmentFilter,
    'scope' => $valueScope,
    'inactive' => $inactiveMode ? '1' : '',
]));

try {
    if ($hasOrders) {
        if (is_post() && post_string('action') === 'toggle_client_analytics' && $canManageAnalytics) {
            if (!csrf_is_valid()) {
                http_response_code(400);
                exit('Invalid CSRF token');
            }
            $keycrmCompanyId = max(0, (int) post_string('keycrm_company_id'));
            $excluded = post_string('excluded') === '1' ? 1 : 0;
            if ($keycrmCompanyId > 0 && $hasClientCompanies) {
                $stmt = db()->prepare("
                    UPDATE db_client_companies
                    SET analytics_excluded = :excluded,
                        analytics_excluded_at = CASE WHEN :excluded_at = 1 THEN NOW() ELSE NULL END,
                        analytics_excluded_by_user_id = CASE WHEN :excluded_user = 1 THEN :user_id ELSE NULL END
                    WHERE keycrm_company_id = :keycrm_company_id
                    LIMIT 1
                ");
                $stmt->execute([
                    'excluded' => $excluded,
                    'excluded_at' => $excluded,
                    'excluded_user' => $excluded,
                    'user_id' => (int) (current_user()['id'] ?? 0),
                    'keycrm_company_id' => $keycrmCompanyId,
                ]);
            }
            client_balances_redirect_back();
        }

        $expr = client_balances_exprs($orderColumns, $hasClientCompanies, $hasClientContacts, $clientCompanyColumns, $clientContactColumns);
        $notCanceled = $inactiveMode ? cockpit_not_canceled_sql('o') : cockpit_active_order_sql('o');
        $clientScopeSql = '';
        if ($hasClientCompanies && in_array('analytics_excluded', $clientCompanyColumns, true) && str_contains((string) $expr['joins'], 'db_client_companies')) {
            $clientScopeSql = $inactiveMode
                ? ' AND COALESCE(cc.analytics_excluded, 0) = 1'
                : ' AND (cc.id IS NULL OR COALESCE(cc.analytics_excluded, 0) = 0)';
        } elseif ($inactiveMode) {
            $clientScopeSql = ' AND 1=0';
        }
        $searchFilter = client_balances_search_filter($search, (string) $expr['search_haystack']);
        $searchSql = (string) $searchFilter['sql'];
        $managerFilterSql = client_balances_manager_filter($managerFilter);
        $managerSql = (string) $managerFilterSql['sql'];
        $searchParams = array_merge($searchFilter['params'], $managerFilterSql['params']);
        $orderDateExpr = in_array('ordered_at', $orderColumns, true) ? 'DATE(o.ordered_at)' : "CONCAT(o.order_month, '-01')";
        $hasOrderKeycrmId = in_array('keycrm_id', $orderColumns, true);
        $canJoinInvoiceDue = $hasInvoices
            && $hasOrderKeycrmId
            && in_array('keycrm_order_id', $invoiceColumns, true)
            && (in_array('payment_due_date', $invoiceColumns, true) || in_array('expected_payment_date', $invoiceColumns, true));
        $invoiceDueExprs = [];
        if (in_array('payment_due_date', $invoiceColumns, true)) {
            $invoiceDueExprs[] = 'i.payment_due_date';
        }
        if (in_array('expected_payment_date', $invoiceColumns, true)) {
            $invoiceDueExprs[] = 'i.expected_payment_date';
        }
        $invoiceDueExpr = $invoiceDueExprs ? 'COALESCE(' . implode(', ', $invoiceDueExprs) . ')' : 'NULL';
        $invoiceJoin = $canJoinInvoiceDue
            ? "LEFT JOIN (
                SELECT keycrm_order_id, MIN({$invoiceDueExpr}) AS invoice_payment_due_date
                FROM db_invoices i
                WHERE keycrm_order_id IS NOT NULL
                GROUP BY keycrm_order_id
            ) inv ON inv.keycrm_order_id = o.keycrm_id"
            : '';
        $clientPaymentDueExpr = $canJoinInvoiceDue ? 'inv.invoice_payment_due_date' : 'NULL';

        if (in_array('manager_name', $orderColumns, true)) {
            $managerStmt = db()->query("
                SELECT COALESCE(NULLIF(manager_name, ''), 'Без менеджера') AS manager_name, COUNT(*) AS order_count
                FROM db_orders o
                WHERE " . cockpit_active_order_sql('o') . "
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
              {$clientScopeSql}
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

        $scopePurchaseSql = 'COALESCE(SUM(o.total_amount_uah), 0)';
        $scopeActiveSql = 'COUNT(DISTINCT o.order_month)';
        $historyParams = array_merge(['history_selected_month' => $selectedMonth], $searchParams);
        if ($valueScope === 'year') {
            $scopePurchaseSql = "COALESCE(SUM(CASE WHEN o.order_month >= :scope_start AND o.order_month <= :scope_end THEN o.total_amount_uah ELSE 0 END), 0)";
            $scopeActiveSql = "COUNT(DISTINCT CASE WHEN o.order_month >= :scope_start AND o.order_month <= :scope_end THEN o.order_month ELSE NULL END)";
            $historyParams['scope_start'] = $scopeStartMonth;
            $historyParams['scope_end'] = $selectedMonth;
        } elseif ($valueScope === 'month') {
            $scopePurchaseSql = "COALESCE(SUM(CASE WHEN o.order_month = :scope_month THEN o.total_amount_uah ELSE 0 END), 0)";
            $scopeActiveSql = "COUNT(DISTINCT CASE WHEN o.order_month = :scope_month THEN o.order_month ELSE NULL END)";
            $historyParams['scope_month'] = $selectedMonth;
        }

        $stmt = db()->prepare("
            SELECT
                {$expr['key']} AS client_key,
                {$expr['company_name']} AS client_name,
                {$expr['manager_name']} AS manager_name,
                COALESCE(SUM(o.total_amount_uah), 0) AS total_purchases,
                {$scopePurchaseSql} AS scope_purchases,
                MIN(o.order_month) AS first_order_month,
                MAX(o.order_month) AS last_order_month,
                MAX(CASE WHEN o.order_month < :history_selected_month THEN o.order_month ELSE NULL END) AS last_before_month,
                COUNT(DISTINCT o.order_month) AS active_month_count,
                {$scopeActiveSql} AS scope_active_month_count,
                COUNT(*) AS lifetime_order_count,
                GROUP_CONCAT(DISTINCT {$orderDateExpr} ORDER BY {$orderDateExpr} SEPARATOR '|||') AS order_dates
            FROM db_orders o
            {$expr['joins']}
            WHERE {$notCanceled}
              {$clientScopeSql}
              {$searchSql}
              {$managerSql}
            GROUP BY client_key, client_name
        ");
        $stmt->execute($historyParams);
        foreach ($stmt->fetchAll() as $row) {
            $key = (string) ($row['client_key'] ?? 'unknown');
            client_balances_take_row($rowsByKey, $row);
            $rowsByKey[$key]['total_purchases'] = (float) ($row['total_purchases'] ?? 0);
            $rowsByKey[$key]['scope_purchases'] = (float) ($row['scope_purchases'] ?? 0);
            $rowsByKey[$key]['first_order_month'] = (string) ($row['first_order_month'] ?? '');
            $rowsByKey[$key]['last_order_month'] = (string) ($row['last_order_month'] ?? '');
            $rowsByKey[$key]['last_before_month'] = (string) ($row['last_before_month'] ?? '');
            $rowsByKey[$key]['order_dates'] = (string) ($row['order_dates'] ?? '');
            $rowsByKey[$key]['active_month_count'] = (int) ($row['active_month_count'] ?? 0);
            $rowsByKey[$key]['scope_active_month_count'] = (int) ($row['scope_active_month_count'] ?? 0);
            $rowsByKey[$key]['lifetime_order_count'] = (int) ($row['lifetime_order_count'] ?? 0);
        }

        $stmt = db()->prepare("
            SELECT
                {$expr['key']} AS client_key,
                {$expr['company_name']} AS client_name,
                {$expr['manager_name']} AS manager_name,
                COUNT(*) AS receivable_count,
                COALESCE(SUM(o.unpaid_amount_uah), 0) AS receivable_total,
                COALESCE(MAX(o.unpaid_amount_uah), 0) AS largest_receivable,
                MIN(CASE WHEN o.unpaid_amount_uah > 0 THEN {$clientPaymentDueExpr} ELSE NULL END) AS payment_due_date,
                SUM(CASE WHEN o.unpaid_amount_uah > 0 AND {$clientPaymentDueExpr} IS NULL THEN 1 ELSE 0 END) AS undefined_payment_due_count
            FROM db_orders o
            {$expr['joins']}
            {$invoiceJoin}
            WHERE o.unpaid_amount_uah > 0
              AND {$notCanceled}
              {$clientScopeSql}
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
            $rowsByKey[$key]['payment_due_date'] = (string) ($row['payment_due_date'] ?? '');
            $rowsByKey[$key]['undefined_payment_due_count'] = (int) ($row['undefined_payment_due_count'] ?? 0);
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
              {$clientScopeSql}
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
              {$clientScopeSql}
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
                  {$clientScopeSql}
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
    $monthsSinceLast = client_balances_month_distance($selectedMonth, (string) $rowRef['last_order_month']);
    $healthInfo = client_health_calculate([
        'selected_month' => $selectedMonth,
        'trend_class' => (string) $trendInfo['class'],
        'value_segment' => $segmentInfo,
        'order_dates' => client_health_parse_order_dates((string) $rowRef['order_dates']),
        'receivable_total' => (float) $rowRef['receivable_total'],
        'payment_due_date' => (string) $rowRef['payment_due_date'],
    ]);
    $rowRef['trend_class'] = $trendInfo['class'];
    $rowRef['trend_label'] = $trendInfo['label'];
    $rowRef['current_month_sales'] = $trendInfo['current'];
    $rowRef['previous_month_sales'] = $trendInfo['previous'];
    $rowRef['segment_class'] = $segmentInfo['class'];
    $rowRef['segment_label'] = $segmentInfo['label'];
    $rowRef['health_score'] = $healthInfo['score'];
    $rowRef['health_class'] = $healthInfo['class'];
    $rowRef['health_label'] = $healthInfo['label'];
    $rowRef['health_reasons'] = $healthInfo['reasons'];
    $rowRef['cycle_label'] = (string) ($healthInfo['cycle']['cycle_label'] ?? 'цикл невідомий');
    $rowRef['cycle_deviation_label'] = (string) ($healthInfo['cycle']['deviation_label'] ?? '');
    $rowRef['payment_status_label'] = (string) ($healthInfo['payment']['status'] ?? 'Строк не визначено');
    $rowRef['payment_due_label'] = (string) ($healthInfo['payment']['detail'] ?? '');
    $rowRef['payment_days_overdue'] = $healthInfo['payment']['days_overdue'] ?? null;
    $rowRef['churn_risk_class'] = (string) ($healthInfo['churn_risk']['class'] ?? 'low');
    $rowRef['churn_risk_label'] = (string) ($healthInfo['churn_risk']['label'] ?? 'низький');
    $rowRef['work_priority_class'] = (string) ($healthInfo['priority']['class'] ?? 'low');
    $rowRef['work_priority_label'] = (string) ($healthInfo['priority']['label'] ?? 'низький');
    $rowRef['recommended_action'] = (string) ($healthInfo['priority']['action'] ?? 'підтримувати регулярний контакт');
    $rowRef['months_since_last'] = $monthsSinceLast;
}
unset($rowRef);

$monthSalesTotal = array_sum(array_map(static fn($row) => (float) $row['sales_month'], $allRows));
$monthPaidTotal = array_sum(array_map(static fn($row) => (float) $row['paid_by_order'], $allRows));
$monthCashTotal = array_sum(array_map(static fn($row) => (float) $row['cash_received'], $allRows));
$activeClientCount = count(array_filter($allRows, static fn($row) => (float) $row['sales_month'] > 0));

$trendCounts = ['down' => 0, 'sleeping' => 0, 'new' => 0, 'returned' => 0, 'up' => 0, 'active' => 0, 'idle' => 0];
$segmentCounts = ['vip' => 0, 'large' => 0, 'medium' => 0, 'small' => 0];
foreach ($allRows as $row) {
    $trendCounts[$row['trend_class']] = ($trendCounts[$row['trend_class']] ?? 0) + 1;
    if (isset($segmentCounts[$row['segment_class']])) {
        $segmentCounts[$row['segment_class']]++;
    }
}

$filteredRows = $allRows;
if ($trendFilter !== '') {
    $filteredRows = array_values(array_filter($filteredRows, static fn($row) => $row['trend_class'] === $trendFilter));
}
if ($segmentFilter !== '') {
    $filteredRows = array_values(array_filter($filteredRows, static fn($row) => $row['segment_class'] === $segmentFilter));
}

usort($filteredRows, static function (array $a, array $b): int {
    $lifetimeCompare = (float) $b['total_purchases'] <=> (float) $a['total_purchases'];
    if ($lifetimeCompare !== 0) {
        return $lifetimeCompare;
    }
    return (float) $b['receivable_total'] <=> (float) $a['receivable_total'];
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
        <div class="client-work-note client-work-note--top">
            <strong>Як читати клієнтів:</strong>
            <span>Health 80-100 - здорові відносини; 60-79 - потребує уваги; 40-59 - ризик; 0-39 - критичний стан.</span>
            <span>Health рахує стан відносин, а не історичну цінність. VIP не отримує бонус у Health автоматично.</span>
            <span>Наприклад, 49 / 100 ризик означає: клієнт суттєво відхилився від звичного циклу або є невирішена проблема.</span>
            <span>VIP / ключові / основні / стартові рахуються за всі покупки за весь час.</span>
            <span>Борг не штрафує Health, якщо строк оплати не настав або строк не визначено.</span>
        </div>
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
            <input type="hidden" name="scope" value="<?= e($valueScope) ?>">
            <input type="hidden" name="trend" value="<?= e($trendFilter) ?>">
            <input type="hidden" name="segment" value="<?= e($segmentFilter) ?>">
            <?php if ($inactiveMode): ?><input type="hidden" name="inactive" value="1"><?php endif; ?>
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
        <div class="client-filter-groups">
            <?php if ($canManageAnalytics): ?>
                <div class="segmented-scroll client-trend-filter">
                    <span class="client-filter-label">Облік</span>
                    <a class="<?= !$inactiveMode ? 'active' : '' ?>" href="<?= e(base_path('/client_balances.php?' . client_balances_query(['month' => $selectedMonth, 'q' => $search, 'manager' => $managerFilter, 'trend' => $trendFilter, 'segment' => $segmentFilter, 'scope' => $valueScope]))) ?>">Активні</a>
                    <a class="<?= $inactiveMode ? 'active' : '' ?>" href="<?= e(base_path('/client_balances.php?' . client_balances_query(['month' => $selectedMonth, 'q' => $search, 'manager' => $managerFilter, 'trend' => $trendFilter, 'segment' => $segmentFilter, 'scope' => $valueScope, 'inactive' => '1']))) ?>">Виключені</a>
                </div>
            <?php endif; ?>
            <div class="segmented-scroll client-trend-filter">
                <span class="client-filter-label">Цінність</span>
                <?php foreach (client_balances_scope_labels() as $scopeKey => $scopeLabelText): ?>
                    <a class="<?= $valueScope === $scopeKey ? 'active' : '' ?>" href="<?= e(base_path('/client_balances.php?' . client_balances_query(['month' => $selectedMonth, 'q' => $search, 'manager' => $managerFilter, 'trend' => $trendFilter, 'segment' => $segmentFilter, 'scope' => $scopeKey, 'inactive' => $inactiveMode ? '1' : '']))) ?>"><?= e($scopeLabelText) ?></a>
                <?php endforeach; ?>
            </div>
            <div class="segmented-scroll client-trend-filter">
                <span class="client-filter-label">Здоровʼя</span>
                <a class="<?= $trendFilter === '' ? 'active' : '' ?>" href="<?= e(base_path('/client_balances.php?' . client_balances_query(['month' => $selectedMonth, 'q' => $search, 'manager' => $managerFilter, 'segment' => $segmentFilter, 'scope' => $valueScope, 'inactive' => $inactiveMode ? '1' : '']))) ?>">Всі <small><?= e((string) count($allRows)) ?></small></a>
                <?php foreach (client_balances_trend_labels() as $trendKey => $trendLabelText): ?>
                    <a class="<?= $trendFilter === $trendKey ? 'active' : '' ?>" href="<?= e(base_path('/client_balances.php?' . client_balances_query(['month' => $selectedMonth, 'q' => $search, 'manager' => $managerFilter, 'trend' => $trendKey, 'segment' => $segmentFilter, 'scope' => $valueScope, 'inactive' => $inactiveMode ? '1' : '']))) ?>"><?= e($trendLabelText) ?> <small><?= e((string) ($trendCounts[$trendKey] ?? 0)) ?></small></a>
                <?php endforeach; ?>
            </div>
            <div class="segmented-scroll client-trend-filter">
                <span class="client-filter-label">Сегмент за весь час</span>
                <a class="<?= $segmentFilter === '' ? 'active' : '' ?>" href="<?= e(base_path('/client_balances.php?' . client_balances_query(['month' => $selectedMonth, 'q' => $search, 'manager' => $managerFilter, 'trend' => $trendFilter, 'scope' => $valueScope, 'inactive' => $inactiveMode ? '1' : '']))) ?>">Всі <small><?= e((string) count($allRows)) ?></small></a>
                <?php foreach (client_balances_segment_labels() as $segmentKey => $segmentLabelText): ?>
                    <a class="<?= $segmentFilter === $segmentKey ? 'active' : '' ?>" href="<?= e(base_path('/client_balances.php?' . client_balances_query(['month' => $selectedMonth, 'q' => $search, 'manager' => $managerFilter, 'trend' => $trendFilter, 'segment' => $segmentKey, 'scope' => $valueScope, 'inactive' => $inactiveMode ? '1' : '']))) ?>"><?= e($segmentLabelText) ?> <small><?= e((string) ($segmentCounts[$segmentKey] ?? 0)) ?></small></a>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <section class="panel dashboard-section client-command-panel">
        <div class="section-heading">
            <div>
                <p class="eyebrow">Top clients</p>
                <h2>Компанії за сумою закупок за весь час</h2>
            </div>
            <span class="status-badge">сортування: найбільші покупки → менші</span>
        </div>
        <div class="client-command-list">
            <?php if (!$rows && ($trendFilter !== '' || $segmentFilter !== '')): ?>
                <div class="empty-state">Немає клієнтів у вибраному фільтрі.</div>
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
                $scopeLabel = client_balances_scope_labels()[$valueScope] ?? 'період';
                $extraBuyers = (int) $row['buyers_count'] - count($row['buyers']);
                $salesLink = base_path('/sales.php?' . client_balances_query([
                    'month' => $selectedMonth,
                    'from_month' => substr($selectedMonth, 0, 4) . '-01',
                    'to_month' => $selectedMonth,
                    'year' => substr($selectedMonth, 0, 4),
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
                        <?php if ($canManageAnalytics && preg_match('/^company:(\d+)$/', $clientKey, $clientMatch)): ?>
                            <form class="inline-form client-row-action" method="post" action="<?= e(base_path('/client_balances.php?' . client_balances_query(['month' => $selectedMonth, 'q' => $search, 'manager' => $managerFilter, 'trend' => $trendFilter, 'segment' => $segmentFilter, 'scope' => $valueScope, 'inactive' => $inactiveMode ? '1' : '']))) ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="toggle_client_analytics">
                                <input type="hidden" name="keycrm_company_id" value="<?= e($clientMatch[1]) ?>">
                                <input type="hidden" name="excluded" value="<?= $inactiveMode ? '0' : '1' ?>">
                                <input type="hidden" name="return_to" value="<?= e((string) ($_SERVER['REQUEST_URI'] ?? '/client_balances.php')) ?>">
                                <button class="text-action <?= $inactiveMode ? 'success' : 'danger' ?>" type="submit"><?= $inactiveMode ? 'Повернути' : 'Не рахувати' ?></button>
                            </form>
                        <?php endif; ?>
                    </div>
                    <div class="client-stat-row">
                        <div class="client-stat"><span>Закупки всього</span><strong><?= e(finance_money($row['total_purchases'])) ?></strong><small><?= e($scopeLabel) ?> <?= e(finance_money($row['scope_purchases'])) ?></small></div>
                        <div class="client-stat"><span>Борг</span><strong class="<?= (float) $row['receivable_total'] > 0 ? 'danger-text' : '' ?>"><?= e(finance_money($row['receivable_total'])) ?></strong><small><?= (int) $row['receivable_count'] > 0 ? e((string) $row['receivable_count']) . ' борг.' : '&nbsp;' ?></small></div>
                        <div class="client-stat"><span>Оплатили</span><strong><?= e(finance_money($row['cash_received'])) ?></strong><small><?= (int) $row['cash_count'] > 0 ? e((string) $row['cash_count']) . ' пл.' : '&nbsp;' ?></small></div>
                        <div class="client-stat"><span>Місяць</span><strong><?= e(finance_money($currentMonthSales)) ?></strong><small>було <?= e(finance_money($previousMonthSales)) ?></small></div>
                        <div class="client-stat"><span>Останнє</span><strong><?= e($lastOrderLabel) ?></strong><small><?= e((string) $row['active_month_count']) ?> акт. міс.</small></div>
                        <div class="client-stat"><span>Цикл</span><strong><?= e((string) $row['cycle_label']) ?></strong><small><?= e((string) $row['cycle_deviation_label']) ?></small></div>
                        <div class="client-stat"><span>Строк оплати</span><strong><?= e((string) $row['payment_status_label']) ?></strong><small><?= e((string) $row['payment_due_label']) ?></small></div>
                        <div class="client-stat client-health-stat">
                            <span>Health</span>
                            <strong><?= e((string) $row['health_score']) ?> / 100</strong>
                            <small><?= e((string) $row['health_label']) ?></small>
                            <span class="client-health-bar"><i class="<?= e((string) $row['health_class']) ?>" style="width: <?= e((string) $row['health_score']) ?>%"></i></span>
                        </div>
                        <div class="client-stat"><span>Ризик втрати</span><strong><?= e((string) $row['churn_risk_label']) ?></strong><small>пріоритет: <?= e((string) $row['work_priority_label']) ?></small></div>
                        <div class="client-stat client-reason-stat"><span>Причини</span><strong><?= e(implode('; ', (array) $row['health_reasons'])) ?></strong><small><?= e((string) $row['recommended_action']) ?></small></div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
</main>
<?= app_version_badge() ?>
</body>
</html>
