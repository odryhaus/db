<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/cockpit.php';
require_once __DIR__ . '/financial.php';
require_once __DIR__ . '/cockpit_layout.php';

require_login();

$month = cockpit_valid_month((string) ($_GET['month'] ?? date('Y-m')));
$managerFilter = trim((string) ($_GET['manager'] ?? ''));
$notCanceled = cockpit_not_canceled_sql('o');
$orders = [];
$orderItems = [];
$summary = cockpit_monthly_summary($month);
$canSeeCosts = in_array(user_role(), ['ceo', 'accountant'], true);

try {
    if (invoice_table_exists('db_orders')) {
        $itemJoin = invoice_table_exists('db_order_items')
            ? "LEFT JOIN (SELECT keycrm_order_id, COUNT(*) AS item_count, COALESCE(SUM(total_amount), 0) AS items_total FROM db_order_items WHERE COALESCE(is_deleted, 0) = 0 GROUP BY keycrm_order_id) i ON i.keycrm_order_id = o.keycrm_id"
            : "";
        $itemSelect = invoice_table_exists('db_order_items')
            ? "COALESCE(i.item_count, 0) AS item_count, COALESCE(i.items_total, 0) AS items_total,"
            : "0 AS item_count, 0 AS items_total,";
        $where = ["o.order_month = :month", $notCanceled];
        $params = ['month' => $month];
        if ($managerFilter !== '') {
            $where[] = "COALESCE(NULLIF(o.manager_name, ''), 'Без менеджера') = :manager";
            $params['manager'] = $managerFilter;
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
    <?php cockpit_page_header('CEO Money Cockpit', 'Продажі', 'Замовлення, товари, оплата і борг за вибраний місяць.' . ($managerFilter !== '' ? ' Менеджер: ' . $managerFilter : ''), 'sales', $month); ?>

    <section class="kpi-grid">
        <div class="kpi-card"><span class="label">Факт</span><strong><?= e(finance_money($summary['sales_fact'])) ?></strong></div>
        <div class="kpi-card"><span class="label">Замовлення</span><strong><?= e((string) $summary['order_count']) ?></strong></div>
        <div class="kpi-card"><span class="label">Маржа</span><strong><?= e(finance_money($summary['gross_margin'])) ?></strong><small><?= e((string) $summary['gross_margin_percent']) ?>%</small></div>
        <div class="kpi-card"><span class="label">Борг за місяць</span><strong><?= e(finance_money($summary['sales_unpaid_by_order'])) ?></strong></div>
    </section>

    <section class="panel dashboard-section sales-workstation">
        <div class="section-heading">
            <div><p class="eyebrow">Деталізація</p><h2>Замовлення за <?= e($month) ?></h2></div>
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
