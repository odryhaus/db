<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/cockpit.php';
require_once __DIR__ . '/financial.php';
require_once __DIR__ . '/cockpit_layout.php';

require_login();
if (function_exists('ensure_analytics_exclusion_columns')) {
    ensure_analytics_exclusion_columns();
}

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
if (!in_array($statusFilter, ['all', 'debt', 'inactive'], true)) {
    $statusFilter = 'all';
}
$notCanceled = cockpit_active_order_sql('o');
$notCanceledOnly = cockpit_not_canceled_sql('o');
$inactiveOrders = '(' . cockpit_order_excluded_sql('o') . ' OR NOT (' . cockpit_order_client_not_excluded_sql('o') . '))';
$orders = [];
$orderItems = [];
$cashPayments = [];
$monthlyChart = [];
$debtManagerRows = [];
$managerOptions = [];
$summary = cockpit_monthly_summary($month);
$canSeeCosts = in_array(user_role(), ['ceo', 'accountant'], true);
$canManageAnalytics = user_role() === 'ceo';
$isManagerRole = user_role() === 'manager';
$managerScopeFilter = $isManagerRole ? cockpit_manager_scope_filter('o', 'current_manager_sales') : ['sql' => '1=1', 'params' => [], 'scope' => []];
$debtThresholdUah = 100.0;
$rangeMode = $fromMonth !== '' && $toMonth !== '' && $fromMonth <= $toMonth;
$pageTitle = $rangeMode ? $fromMonth . ' - ' . $toMonth : $month;
$orderColumns = invoice_table_exists('db_orders') ? finance_columns('db_orders') : [];
if (!in_array('manager_name', $orderColumns, true)) {
    $managerFilter = '';
}
if ($isManagerRole) {
    $managerFilter = '';
    if ($statusFilter === 'inactive') {
        $statusFilter = 'all';
    }
}
$clientExcludedSelect = cockpit_order_client_not_excluded_sql('o') !== '1=1'
    ? "CASE WHEN NOT (" . cockpit_order_client_not_excluded_sql('o') . ") THEN 1 ELSE 0 END AS analytics_client_excluded"
    : "0 AS analytics_client_excluded";
$hasClientFilter = $clientKeyFilter !== '';
$periodFromMonth = $rangeMode ? $fromMonth : $month;
$periodToMonth = $rangeMode ? $toMonth : $month;
$periodStart = $periodFromMonth . '-01 00:00:00';
$periodEndDate = DateTimeImmutable::createFromFormat('!Y-m', $periodToMonth);
$periodEnd = ($periodEndDate ?: new DateTimeImmutable('first day of this month'))->modify('last day of this month')->format('Y-m-d 23:59:59');
$clientTitle = '';
$isDebtMode = $statusFilter === 'debt';

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

function sales_redirect_back(): void
{
    $returnTo = (string) ($_POST['return_to'] ?? '');
    if ($returnTo === '' || $returnTo[0] !== '/' || str_starts_with($returnTo, '//') || str_contains($returnTo, "\n") || str_contains($returnTo, "\r")) {
        $returnTo = '/sales.php';
    }
    header('Location: ' . $returnTo);
    exit;
}

function sales_debt_summary_html(array $orders, string $clientTitle, string $managerTitle, string $fromMonth, string $toMonth): string
{
    $total = array_sum(array_map(static fn($order) => (float) ($order['unpaid_amount_uah'] ?? 0), $orders));
    $paid = array_sum(array_map(static fn($order) => (float) ($order['paid_amount_uah'] ?? 0), $orders));
    $ordered = array_sum(array_map(static fn($order) => (float) ($order['total_amount_uah'] ?? 0), $orders));
    $largest = $orders ? max(array_map(static fn($order) => (float) ($order['unpaid_amount_uah'] ?? 0), $orders)) : 0.0;
    $scopeLabel = $managerTitle !== ''
        ? 'Менеджер: ' . $managerTitle
        : ($clientTitle !== '' ? 'Клієнт: ' . $clientTitle : 'Усі клієнти');
    ob_start();
    ?><!doctype html>
<html lang="uk">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 22mm 16mm; }
        body { font-family: DejaVu Sans, Arial, sans-serif; color: #111; font-size: 11px; line-height: 1.35; }
        h1 { font-size: 24px; margin: 0; letter-spacing: 0; }
        .top { display: table; width: 100%; margin-bottom: 22px; }
        .top-left, .top-right { display: table-cell; vertical-align: top; }
        .top-right { text-align: right; color: #6b7280; font-size: 10px; }
        .scope { margin-top: 7px; color: #374151; font-size: 13px; font-weight: 700; }
        .meta { margin-top: 3px; color: #6b7280; }
        .summary { display: table; width: 100%; margin: 0 0 18px; border-top: 2px solid #111; border-bottom: 1px solid #d1d5db; }
        .metric { display: table-cell; padding: 11px 10px 10px 0; }
        .metric span { display: block; color: #6b7280; font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; }
        .metric strong { display: block; margin-top: 3px; font-size: 16px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border-bottom: 1px solid #e5e7eb; padding: 8px 6px; text-align: left; vertical-align: top; }
        th { color: #6b7280; font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; }
        td:first-child, th:first-child { padding-left: 0; }
        td:last-child, th:last-child { padding-right: 0; }
        .num { text-align: right; white-space: nowrap; }
        .danger { color: #d92d20; font-weight: 800; }
        .muted { color: #6b7280; }
        .client { font-weight: 700; }
    </style>
</head>
<body>
    <div class="top">
        <div class="top-left">
            <h1>Звірка боргу</h1>
            <div class="scope"><?= e($scopeLabel) ?></div>
            <div class="meta">Період замовлень: <?= e($fromMonth) ?> → <?= e($toMonth) ?></div>
        </div>
        <div class="top-right">
            .BRAND DB<br>
            сформовано <?= e(date('d.m.Y H:i')) ?>
        </div>
    </div>
    <div class="summary">
        <div class="metric"><span>Борг</span><strong class="danger"><?= e(finance_money($total)) ?></strong></div>
        <div class="metric"><span>Замовлень</span><strong><?= e((string) count($orders)) ?></strong></div>
        <div class="metric"><span>Сума</span><strong><?= e(finance_money($ordered)) ?></strong></div>
        <div class="metric"><span>Оплачено</span><strong><?= e(finance_money($paid)) ?></strong></div>
        <div class="metric"><span>Найбільший</span><strong><?= e(finance_money($largest)) ?></strong></div>
    </div>
    <table>
        <thead>
        <tr>
            <th>№</th>
            <th>Дата</th>
            <th>Клієнт / покупець</th>
            <th>Менеджер</th>
            <th class="num">Сума</th>
            <th class="num">Оплачено</th>
            <th class="num">Борг</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($orders as $order): ?>
            <?php
            $companyName = trim((string) ($order['company_name'] ?? ''));
            $buyerName = trim((string) ($order['buyer_name'] ?? ''));
            $clientName = $companyName !== '' && $buyerName !== '' && $buyerName !== $companyName
                ? $companyName . ' / ' . $buyerName
                : ($companyName !== '' ? $companyName : ($buyerName !== '' ? $buyerName : ''));
            ?>
            <tr>
                <td><?= e((string) $order['order_number']) ?></td>
                <td><?= e(substr((string) $order['ordered_at'], 0, 10)) ?></td>
                <td class="client"><?= e($clientName) ?></td>
                <td class="muted"><?= e((string) (($order['manager_name'] ?? '') ?: 'Без менеджера')) ?></td>
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

function sales_download_debt_summary(array $orders, string $clientTitle, string $managerTitle, string $fromMonth, string $toMonth): void
{
    $html = sales_debt_summary_html($orders, $clientTitle, $managerTitle, $fromMonth, $toMonth);
    $scopeForName = $managerTitle !== '' ? 'manager_' . $managerTitle : ($clientTitle !== '' ? $clientTitle : 'all');
    $safeScope = preg_replace('/[^A-Za-z0-9А-Яа-яЇїІіЄєҐґ_-]+/u', '_', $scopeForName);
    $fileName = 'DEBT_' . trim((string) $safeScope, '_') . '_' . $fromMonth . '_' . $toMonth . '.pdf';

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

function sales_render_order_card(array $order, array $orderItems, bool $canSeeCosts, bool $canManageAnalytics): void
{
    $items = $orderItems[(int) ($order['keycrm_id'] ?? 0)] ?? [];
    $companyName = trim((string) ($order['company_name'] ?? ''));
    $buyerName = trim((string) ($order['buyer_name'] ?? ''));
    $clientName = $companyName !== '' && $buyerName !== '' && $buyerName !== $companyName
        ? $companyName . ' / ' . $buyerName
        : ($companyName !== '' ? $companyName : ($buyerName !== '' ? $buyerName : '—'));
    $unpaid = (float) ($order['unpaid_amount_uah'] ?? 0);
    $isExcluded = (int) ($order['analytics_excluded'] ?? 0) === 1;
    $isClientExcluded = (int) ($order['analytics_client_excluded'] ?? 0) === 1;
    ?>
    <article class="order-card <?= $unpaid > 0 ? 'has-debt' : '' ?> <?= $isExcluded ? 'is-inactive' : '' ?>">
        <div class="order-card__main">
            <div class="order-card__identity">
                <span class="order-number">№ <?= e((string) $order['order_number']) ?></span>
                <strong><?= e($clientName) ?></strong>
                <small><?= e((string) $order['ordered_at']) ?> · <?= e((string) ($order['manager_name'] ?: 'Без менеджера')) ?></small>
            </div>
            <div class="order-card__status">
                <span class="status-badge"><?= e((string) ($order['status_name'] ?: '—')) ?></span>
                <small><?= e((string) $order['item_count']) ?> поз.</small>
                <?php if ($isClientExcluded && !$isExcluded): ?>
                    <span class="status-badge">клієнт виключено</span>
                <?php elseif ($canManageAnalytics): ?>
                    <form class="inline-form" method="post" action="<?= e(base_path('/sales.php?' . sales_current_query())) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="toggle_order_analytics">
                        <input type="hidden" name="keycrm_id" value="<?= e((string) ($order['keycrm_id'] ?? 0)) ?>">
                        <input type="hidden" name="excluded" value="<?= $isExcluded ? '0' : '1' ?>">
                        <input type="hidden" name="return_to" value="<?= e((string) ($_SERVER['REQUEST_URI'] ?? '/sales.php')) ?>">
                        <button class="text-action <?= $isExcluded ? 'success' : 'danger' ?>" type="submit"><?= $isExcluded ? 'Повернути' : 'Не рахувати' ?></button>
                    </form>
                <?php endif; ?>
            </div>
            <div class="order-card__money">
                <div><span>Сума</span><strong><?= e(finance_money($order['total_amount_uah'])) ?></strong></div>
                <div><span>Оплачено</span><strong><?= e(finance_money($order['paid_amount_uah'])) ?></strong></div>
                <div><span>Борг</span><strong class="<?= $unpaid > 0 ? 'danger-text' : '' ?>"><?= e(finance_money($order['unpaid_amount_uah'])) ?></strong></div>
                <div><span>Маржа</span><strong><?= e(finance_money($order['margin_sum_uah'])) ?></strong></div>
            </div>
        </div>
        <?php if ($items): ?>
            <details class="order-products">
                <summary>Позиції замовлення · <?= e((string) count($items)) ?></summary>
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
            </details>
        <?php endif; ?>
    </article>
    <?php
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
        if (is_post() && post_string('action') === 'toggle_order_analytics' && $canManageAnalytics) {
            if (!csrf_is_valid()) {
                http_response_code(400);
                exit('Invalid CSRF token');
            }
            $keycrmId = max(0, (int) post_string('keycrm_id'));
            $excluded = post_string('excluded') === '1' ? 1 : 0;
            if ($keycrmId > 0) {
                $stmt = db()->prepare("
                    UPDATE db_orders
                    SET analytics_excluded = :excluded,
                        analytics_excluded_at = CASE WHEN :excluded_at = 1 THEN NOW() ELSE NULL END,
                        analytics_excluded_by_user_id = CASE WHEN :excluded_user = 1 THEN :user_id ELSE NULL END
                    WHERE keycrm_id = :keycrm_id
                    LIMIT 1
                ");
                $stmt->execute([
                    'excluded' => $excluded,
                    'excluded_at' => $excluded,
                    'excluded_user' => $excluded,
                    'user_id' => (int) (current_user()['id'] ?? 0),
                    'keycrm_id' => $keycrmId,
                ]);
            }
            sales_redirect_back();
        }

        if ($isManagerRole) {
            $managerOptions = [[
                'manager_name' => (string) ($managerScopeFilter['scope']['display_name'] ?? format_user_name(current_user() ?? [])),
                'order_count' => 0,
            ]];
        } elseif (in_array('manager_name', $orderColumns, true)) {
            $managerStmt = db()->query("
                SELECT COALESCE(NULLIF(manager_name, ''), 'Без менеджера') AS manager_name, COUNT(*) AS order_count
                FROM db_orders o
                WHERE {$notCanceled}
                GROUP BY COALESCE(NULLIF(manager_name, ''), 'Без менеджера')
                ORDER BY manager_name ASC
            ");
            $managerOptions = $managerStmt->fetchAll();
        }

        $itemJoin = invoice_table_exists('db_order_items')
            ? "LEFT JOIN (SELECT keycrm_order_id, COUNT(*) AS item_count, COALESCE(SUM(total_amount), 0) AS items_total FROM db_order_items WHERE COALESCE(is_deleted, 0) = 0 GROUP BY keycrm_order_id) i ON i.keycrm_order_id = o.keycrm_id"
            : "";
        $itemSelect = invoice_table_exists('db_order_items')
            ? "COALESCE(i.item_count, 0) AS item_count, COALESCE(i.items_total, 0) AS items_total,"
            : "0 AS item_count, 0 AS items_total,";
        $where = [$statusFilter === 'inactive' ? $notCanceledOnly . ' AND ' . $inactiveOrders : $notCanceled];
        $params = [];
        if ($isManagerRole) {
            $where[] = (string) $managerScopeFilter['sql'];
            $params = array_merge($params, $managerScopeFilter['params']);
        }
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
            $where[] = 'o.unpaid_amount_uah > :debt_threshold';
            $params['debt_threshold'] = $debtThresholdUah;
        }
        $orderSortSql = $isDebtMode
            ? "o.unpaid_amount_uah DESC, o.ordered_at ASC, o.id DESC"
            : "o.ordered_at DESC, o.id DESC";
        $stmt = db()->prepare("
            SELECT o.order_number, o.ordered_at, o.company_name, o.buyer_name, o.manager_name,
                   o.status_name, o.total_amount_uah, o.paid_amount_uah, o.unpaid_amount_uah,
                   o.expenses_sum_uah, o.margin_sum_uah, {$itemSelect}
                   o.keycrm_id" . (in_array('analytics_excluded', $orderColumns, true) ? ", o.analytics_excluded" : ", 0 AS analytics_excluded") . ",
                   {$clientExcludedSelect}
            FROM db_orders o
            {$itemJoin}
            WHERE " . implode(' AND ', $where) . "
            ORDER BY {$orderSortSql}
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

        if (invoice_table_exists('db_order_payments') && !$isDebtMode) {
            $activePayment = cockpit_active_payment_sql('p');
            $paymentWhere = [$statusFilter === 'inactive' ? $notCanceledOnly . ' AND ' . $inactiveOrders : $notCanceled, $activePayment, 'p.payment_date >= :payment_period_start', 'p.payment_date <= :payment_period_end'];
            $paymentParams = [
                'payment_period_start' => $periodStart,
                'payment_period_end' => $periodEnd,
            ];
            if ($isManagerRole) {
                $paymentWhere[] = (string) $managerScopeFilter['sql'];
                $paymentParams = array_merge($paymentParams, $managerScopeFilter['params']);
            }
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
        $chartWhere = [$statusFilter === 'inactive' ? $notCanceledOnly . ' AND ' . $inactiveOrders : $notCanceled, 'o.order_month >= :chart_period_start', 'o.order_month <= :chart_period_end'];
        $chartParams = [
            'chart_period_start' => $periodFromMonth,
            'chart_period_end' => $periodToMonth,
        ];
        if ($isManagerRole) {
            $chartWhere[] = (string) $managerScopeFilter['sql'];
            $chartParams = array_merge($chartParams, $managerScopeFilter['params']);
        }
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
            $chartWhere[] = 'o.unpaid_amount_uah > :chart_debt_threshold';
            $chartParams['chart_debt_threshold'] = $debtThresholdUah;
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

        if ($isDebtMode) {
            $debtManagerStmt = db()->prepare("
                SELECT COALESCE(NULLIF(o.manager_name, ''), 'Без менеджера') AS manager_name,
                       COUNT(*) AS debt_order_count,
                       COALESCE(SUM(o.total_amount_uah), 0) AS total_amount,
                       COALESCE(SUM(o.paid_amount_uah), 0) AS paid_amount,
                       COALESCE(SUM(o.unpaid_amount_uah), 0) AS unpaid_amount,
                       COALESCE(MAX(o.unpaid_amount_uah), 0) AS largest_debt
                FROM db_orders o
                WHERE " . implode(' AND ', $where) . "
                GROUP BY COALESCE(NULLIF(o.manager_name, ''), 'Без менеджера')
                ORDER BY unpaid_amount DESC, debt_order_count DESC
            ");
            $debtManagerStmt->execute($params);
            $debtManagerRows = $debtManagerStmt->fetchAll();
        }

        if (invoice_table_exists('db_order_payments') && !$isDebtMode) {
            $activePayment = cockpit_active_payment_sql('p');
            $chartPaymentWhere = [$statusFilter === 'inactive' ? $notCanceledOnly . ' AND ' . $inactiveOrders : $notCanceled, $activePayment, 'p.payment_date >= :chart_payment_start', 'p.payment_date <= :chart_payment_end'];
            $chartPaymentParams = [
                'chart_payment_start' => $periodStart,
                'chart_payment_end' => $periodEnd,
            ];
            if ($isManagerRole) {
                $chartPaymentWhere[] = (string) $managerScopeFilter['sql'];
                $chartPaymentParams = array_merge($chartPaymentParams, $managerScopeFilter['params']);
            }
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
$debtOrders = array_values(array_filter($orders, fn($order) => (float) ($order['unpaid_amount_uah'] ?? 0) > $debtThresholdUah));
$largestDebt = $debtOrders ? max(array_map(static fn($order) => (float) ($order['unpaid_amount_uah'] ?? 0), $debtOrders)) : 0.0;
$isFilteredView = $rangeMode || $clientKeyFilter !== '' || $searchFilter !== '' || $managerFilter !== '' || $statusFilter !== 'all';
$displaySalesTotal = $isFilteredView ? $visibleSalesTotal : (float) $summary['sales_fact'];
$displayOrderCount = $isFilteredView ? count($orders) : (int) $summary['order_count'];
$displayPaid = $isFilteredView ? $visiblePaidTotal : (float) $summary['sales_paid_by_order'];
$displayCashOther = $isFilteredView ? $visibleCashForOtherOrders : (float) $summary['cash_from_previous_orders'];
$displayMargin = $isFilteredView ? $visibleMarginTotal : (float) $summary['gross_margin'];
$displayUnpaid = $isFilteredView ? $visibleUnpaidTotal : (float) $summary['sales_unpaid_by_order'];
$displayMarginPercent = $displaySalesTotal > 0 ? round(($displayMargin / $displaySalesTotal) * 100, 1) : 0;
$chartMax = 0.0;
foreach ($monthlyChart as $chartRow) {
    $chartMax = max($chartMax, (float) $chartRow['sales'], $isDebtMode ? (float) $chartRow['unpaid'] : (float) $chartRow['cash_received']);
}
$orderPreviewLimit = $isDebtMode ? 25 : 10;
$previewOrders = array_slice($orders, 0, $orderPreviewLimit);
$hiddenOrders = array_slice($orders, $orderPreviewLimit);

if (isset($_GET['debt_pdf'])) {
    $debtPdfManagerTitle = $isManagerRole ? (string) ($managerScopeFilter['scope']['display_name'] ?? '') : $managerFilter;
    sales_download_debt_summary($debtOrders, $clientTitle, $debtPdfManagerTitle, $periodFromMonth, $periodToMonth);
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
    <?php cockpit_page_header('CEO Money Cockpit', $isDebtMode ? 'Дебіторка' : 'Продажі', ($isDebtMode ? 'Борги клієнтів і відповідальні менеджери: ' : 'Замовлення, товари, оплата і борг: ') . $pageTitle . ($managerFilter !== '' ? ' · Менеджер: ' . $managerFilter : '') . ($clientKeyFilter !== '' ? ' · ' . ($clientTitle !== '' ? $clientTitle : 'клієнт') : ''), 'sales', $month, !$rangeMode); ?>

    <section class="panel dashboard-section sales-filter-panel">
        <div class="section-heading">
            <div>
                <p class="eyebrow"><?= $isDebtMode ? 'Контроль боргів' : 'Період' ?></p>
                <h2><?= e($hasClientFilter ? ($clientTitle !== '' ? $clientTitle : 'Клієнт') : ($isDebtMode ? 'Вся дебіторка' : 'Всі продажі')) ?></h2>
            </div>
            <span class="status-badge"><?= $hasClientFilter ? 'обрана компанія' : ($isDebtMode ? 'тільки борги' : 'загальний режим') ?> · <?= e($periodFromMonth) ?> → <?= e($periodToMonth) ?></span>
        </div>
        <?php if (!$hasClientFilter): ?>
            <p class="muted">Це загальний графік. Щоб побачити одну компанію, відкрий сторінку Клієнти і натисни на назву компанії.</p>
        <?php endif; ?>
        <div class="sales-money-legend">
            <?php if ($isDebtMode): ?>
                <span><strong>Борг</strong> — тільки неоплачені або частково оплачені замовлення.</span>
                <span><strong>Поріг</strong> — розбіжності до <?= e(finance_money($debtThresholdUah)) ?> не показуються як борг.</span>
                <span><strong>Період</strong> — місяць створення замовлення, не дата оплати.</span>
            <?php else: ?>
                <span><strong>Замовили</strong> — сума замовлень, створених у вибраному періоді.</span>
                <span><strong>Оплатили з цих</strong> — скільки вже оплачено саме з цих замовлень.</span>
                <span><strong>Прийшло за старі</strong> — платежі у цьому періоді за замовлення з інших періодів.</span>
            <?php endif; ?>
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
            <?php if (!$isManagerRole): ?>
                <label>
                    <span>Менеджер</span>
                    <select name="manager">
                        <option value="">Всі</option>
                        <?php foreach ($managerOptions as $managerOption): ?>
                            <?php $managerName = (string) ($managerOption['manager_name'] ?? ''); ?>
                            <option value="<?= e($managerName) ?>" <?= $managerFilter === $managerName ? 'selected' : '' ?>><?= e($managerName) ?> · <?= e((string) ($managerOption['order_count'] ?? 0)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            <?php endif; ?>
            <button type="submit">Показати</button>
        </form>
        <div class="sales-filter-actions">
            <div class="segmented-scroll client-trend-filter">
                <a class="<?= $statusFilter === 'all' ? 'active' : '' ?>" href="<?= e(base_path('/sales.php?' . sales_current_query(['status' => 'all', 'debt_pdf' => null]))) ?>">Всі замовлення</a>
                <a class="<?= $statusFilter === 'debt' ? 'active' : '' ?>" href="<?= e(base_path('/sales.php?' . sales_current_query(['status' => 'debt', 'debt_pdf' => null]))) ?>">Дебіторка</a>
                <?php if ($canManageAnalytics): ?>
                    <a class="<?= $statusFilter === 'inactive' ? 'active' : '' ?>" href="<?= e(base_path('/sales.php?' . sales_current_query(['status' => 'inactive', 'debt_pdf' => null]))) ?>">Виключені</a>
                <?php endif; ?>
            </div>
            <a class="button-secondary" href="<?= e(base_path('/sales.php?' . sales_current_query(['status' => 'debt', 'debt_pdf' => '1']))) ?>">PDF боргу</a>
        </div>
    </section>

    <section class="kpi-grid">
        <?php if ($isDebtMode): ?>
            <div class="kpi-card danger"><span class="label">Борг</span><strong><?= e(finance_money($displayUnpaid)) ?></strong><small>нам винні за вибраний період</small></div>
            <div class="kpi-card"><span class="label">Замовлень з боргом</span><strong><?= e((string) count($debtOrders)) ?></strong></div>
            <div class="kpi-card"><span class="label">Найбільший борг</span><strong><?= e(finance_money($largestDebt)) ?></strong></div>
            <div class="kpi-card"><span class="label">Вже оплачено</span><strong><?= e(finance_money($displayPaid)) ?></strong><small>частина цих замовлень</small></div>
            <div class="kpi-card"><span class="label">Сума замовлень</span><strong><?= e(finance_money($displaySalesTotal)) ?></strong></div>
        <?php else: ?>
            <div class="kpi-card"><span class="label">Замовили</span><strong><?= e(finance_money($displaySalesTotal)) ?></strong><small>замовлення, створені у періоді</small></div>
            <div class="kpi-card"><span class="label">Замовлення</span><strong><?= e((string) $displayOrderCount) ?></strong></div>
            <div class="kpi-card"><span class="label">Оплатили з цих</span><strong><?= e(finance_money($displayPaid)) ?></strong><small>оплата саме цих замовлень</small></div>
            <div class="kpi-card"><span class="label">Прийшло за старі</span><strong><?= e(finance_money($displayCashOther)) ?></strong><small>платежі за інші замовлення у періоді</small></div>
            <div class="kpi-card"><span class="label">Борг</span><strong><?= e(finance_money($displayUnpaid)) ?></strong></div>
            <?php if ($canSeeCosts): ?><div class="kpi-card"><span class="label">Маржа</span><strong><?= e(finance_money($displayMargin)) ?></strong><small><?= e((string) $displayMarginPercent) ?>%</small></div><?php endif; ?>
        <?php endif; ?>
    </section>

    <?php if ($isDebtMode): ?>
        <section class="panel dashboard-section sales-workstation">
            <div class="section-heading">
                <div><p class="eyebrow">Менеджери</p><h2>У кого яка дебіторка</h2></div>
                <span class="status-badge"><?= e((string) count($debtManagerRows)) ?> менеджерів</span>
            </div>
            <div class="table-scroll">
                <table class="data-table">
                    <thead>
                    <tr>
                        <th>Менеджер</th>
                        <th class="num">Борг</th>
                        <th class="num">Замовлень</th>
                        <th class="num">Найбільший</th>
                        <th class="num">Оплачено</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($debtManagerRows as $managerRow): ?>
                        <?php $debtManagerName = (string) ($managerRow['manager_name'] ?? 'Без менеджера'); ?>
                        <tr>
                            <td><a class="metric-link" href="<?= e(base_path('/sales.php?' . sales_current_query(['status' => 'debt', 'manager' => $debtManagerName, 'debt_pdf' => null]))) ?>"><?= e($debtManagerName) ?></a></td>
                            <td class="num"><strong class="danger-text"><?= e(finance_money($managerRow['unpaid_amount'] ?? 0)) ?></strong></td>
                            <td class="num"><?= e((string) ($managerRow['debt_order_count'] ?? 0)) ?></td>
                            <td class="num"><?= e(finance_money($managerRow['largest_debt'] ?? 0)) ?></td>
                            <td class="num"><?= e(finance_money($managerRow['paid_amount'] ?? 0)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$debtManagerRows): ?>
                        <tr><td colspan="5">Боргів за цей період немає.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>

    <section class="panel dashboard-section sales-chart-panel">
        <div class="section-heading">
            <div><p class="eyebrow"><?= $hasClientFilter ? 'Графік компанії' : 'Загальний графік' ?></p><h2><?= e($periodFromMonth) ?> → <?= e($periodToMonth) ?> по місяцях<?= $hasClientFilter && $clientTitle !== '' ? ' · ' . e($clientTitle) : '' ?></h2></div>
            <span class="status-badge"><?= $isDebtMode ? 'чорне — сума боргових замовлень · жовте — борг' : 'чорне — продажі · жовте — гроші прийшли' ?></span>
        </div>
        <div class="sales-year-chart">
            <?php foreach ($monthlyChart as $chartRow): ?>
                <?php
                $salesHeight = $chartMax > 0 && (float) $chartRow['sales'] > 0 ? max(2, round(((float) $chartRow['sales'] / $chartMax) * 100, 1)) : 0;
                $yellowValue = $isDebtMode ? (float) $chartRow['unpaid'] : (float) $chartRow['cash_received'];
                $cashHeight = $chartMax > 0 && $yellowValue > 0 ? max(2, round(($yellowValue / $chartMax) * 100, 1)) : 0;
                ?>
                <div class="sales-month-bar">
                    <div class="sales-month-bar__plot">
                        <i class="sales-bar-sales" style="height: <?= e((string) $salesHeight) ?>%"></i>
                        <i class="sales-bar-cash" style="height: <?= e((string) $cashHeight) ?>%"></i>
                    </div>
                    <strong><?= e(sales_month_label_short((string) $chartRow['month'])) ?></strong>
                    <span><?= e(finance_money($chartRow['sales'])) ?></span>
                    <small><?= e(finance_money($yellowValue)) ?></small>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <?php if ($cashPayments && !$isDebtMode): ?>
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

    <section class="panel dashboard-section sales-workstation">
        <div class="section-heading">
            <div><p class="eyebrow"><?= $isDebtMode ? 'Борги' : 'Деталізація' ?></p><h2><?= $isDebtMode ? 'Замовлення з боргом' : 'Замовлення за ' . e($pageTitle) ?></h2></div>
            <span class="status-badge"><?= $isDebtMode ? 'від більшого боргу до меншого' : 'останні ' . e((string) min($orderPreviewLimit, count($orders))) . ' із ' . e((string) count($orders)) ?></span>
        </div>
        <div class="order-feed">
            <?php if (!$orders): ?><div class="empty-state">Немає даних.</div><?php endif; ?>
            <?php foreach ($previewOrders as $order): ?>
                <?php sales_render_order_card($order, $orderItems, $canSeeCosts, $canManageAnalytics); ?>
            <?php endforeach; ?>
            <?php if ($hiddenOrders): ?>
                <details class="orders-more">
                    <summary>+ ще <?= e((string) count($hiddenOrders)) ?> замовлень</summary>
                    <div class="order-feed orders-more__feed">
                        <?php foreach ($hiddenOrders as $order): ?>
                            <?php sales_render_order_card($order, $orderItems, $canSeeCosts, $canManageAnalytics); ?>
                        <?php endforeach; ?>
                    </div>
                </details>
            <?php endif; ?>
        </div>
    </section>
</main>
<?= app_version_badge() ?>
</body>
</html>
