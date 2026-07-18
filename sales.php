<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/cockpit.php';
require_once __DIR__ . '/financial.php';
require_once __DIR__ . '/cockpit_layout.php';

require_login();

$month = cockpit_valid_month((string) ($_GET['month'] ?? date('Y-m')));
$fromMonth = isset($_GET['from_month']) ? cockpit_valid_month((string) $_GET['from_month']) : '';
$toMonth = isset($_GET['to_month']) ? cockpit_valid_month((string) $_GET['to_month']) : '';
$managerFilter = trim((string) ($_GET['manager'] ?? ''));
$clientKeyFilter = trim((string) ($_GET['client_key'] ?? ''));
$searchFilter = trim((string) ($_GET['q'] ?? ''));
$notCanceled = cockpit_not_canceled_sql('o');
$orders = [];
$orderItems = [];
$summary = cockpit_monthly_summary($month);
$canSeeCosts = in_array(user_role(), ['ceo', 'accountant'], true);
$rangeMode = $fromMonth !== '' && $toMonth !== '' && $fromMonth <= $toMonth;
$pageTitle = $rangeMode ? $fromMonth . ' - ' . $toMonth : $month;
$orderColumns = invoice_table_exists('db_orders') ? finance_columns('db_orders') : [];

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
    }
} catch (Throwable $e) {
    $orders = [];
}

$visibleSalesTotal = array_sum(array_map(static fn($order) => (float) ($order['total_amount_uah'] ?? 0), $orders));
$visiblePaidTotal = array_sum(array_map(static fn($order) => (float) ($order['paid_amount_uah'] ?? 0), $orders));
$visibleUnpaidTotal = array_sum(array_map(static fn($order) => (float) ($order['unpaid_amount_uah'] ?? 0), $orders));
$visibleMarginTotal = array_sum(array_map(static fn($order) => (float) ($order['margin_sum_uah'] ?? 0), $orders));
$displaySalesTotal = ($rangeMode || $clientKeyFilter !== '' || $searchFilter !== '' || $managerFilter !== '') ? $visibleSalesTotal : (float) $summary['sales_fact'];
$displayOrderCount = ($rangeMode || $clientKeyFilter !== '' || $searchFilter !== '' || $managerFilter !== '') ? count($orders) : (int) $summary['order_count'];
$displayMargin = ($rangeMode || $clientKeyFilter !== '' || $searchFilter !== '' || $managerFilter !== '') ? $visibleMarginTotal : (float) $summary['gross_margin'];
$displayUnpaid = ($rangeMode || $clientKeyFilter !== '' || $searchFilter !== '' || $managerFilter !== '') ? $visibleUnpaidTotal : (float) $summary['sales_unpaid_by_order'];
$displayMarginPercent = $displaySalesTotal > 0 ? round(($displayMargin / $displaySalesTotal) * 100, 1) : 0;

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
    <?php cockpit_page_header('CEO Money Cockpit', 'Продажі', 'Замовлення, товари, оплата і борг: ' . $pageTitle . ($managerFilter !== '' ? ' · Менеджер: ' . $managerFilter : '') . ($clientKeyFilter !== '' ? ' · клієнт' : ''), 'sales', $month, !$rangeMode); ?>

    <section class="kpi-grid">
        <div class="kpi-card"><span class="label">Факт</span><strong><?= e(finance_money($displaySalesTotal)) ?></strong></div>
        <div class="kpi-card"><span class="label">Замовлення</span><strong><?= e((string) $displayOrderCount) ?></strong></div>
        <div class="kpi-card"><span class="label">Маржа</span><strong><?= e(finance_money($displayMargin)) ?></strong><small><?= e((string) $displayMarginPercent) ?>%</small></div>
        <div class="kpi-card"><span class="label">Борг</span><strong><?= e(finance_money($displayUnpaid)) ?></strong></div>
    </section>

    <section class="panel dashboard-section sales-workstation">
        <div class="section-heading">
            <div><p class="eyebrow">Деталізація</p><h2>Замовлення за <?= e($pageTitle) ?></h2></div>
            <span class="status-badge">товари показані всередині замовлення</span>
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
</main>
<?= app_version_badge() ?>
</body>
</html>
