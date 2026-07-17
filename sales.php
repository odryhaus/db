<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/cockpit.php';
require_once __DIR__ . '/financial.php';
require_once __DIR__ . '/cockpit_layout.php';

require_login();

$month = cockpit_valid_month((string) ($_GET['month'] ?? date('Y-m')));
$notCanceled = cockpit_not_canceled_sql('o');
$orders = [];
$summary = cockpit_monthly_summary($month);

try {
    if (invoice_table_exists('db_orders')) {
        $itemJoin = invoice_table_exists('db_order_items')
            ? "LEFT JOIN (SELECT keycrm_order_id, COUNT(*) AS item_count, COALESCE(SUM(total_amount), 0) AS items_total FROM db_order_items WHERE COALESCE(is_deleted, 0) = 0 GROUP BY keycrm_order_id) i ON i.keycrm_order_id = o.keycrm_id"
            : "";
        $itemSelect = invoice_table_exists('db_order_items')
            ? "COALESCE(i.item_count, 0) AS item_count, COALESCE(i.items_total, 0) AS items_total,"
            : "0 AS item_count, 0 AS items_total,";
        $stmt = db()->prepare("
            SELECT o.order_number, o.ordered_at, o.company_name, o.buyer_name, o.manager_name,
                   o.status_name, o.total_amount_uah, o.paid_amount_uah, o.unpaid_amount_uah,
                   o.expenses_sum_uah, o.margin_sum_uah, {$itemSelect}
                   o.keycrm_id
            FROM db_orders o
            {$itemJoin}
            WHERE o.order_month = :month
              AND {$notCanceled}
            ORDER BY o.ordered_at DESC, o.id DESC
            LIMIT 300
        ");
        $stmt->execute(['month' => $month]);
        $orders = $stmt->fetchAll();
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
    <?php cockpit_page_header('CEO Money Cockpit', 'Продажі', 'Замовлення за місяцем продажу: db_orders.order_month.', 'sales', $month); ?>

    <section class="kpi-grid">
        <div class="kpi-card"><span class="label">Факт</span><strong><?= e(finance_money($summary['sales_fact'])) ?></strong></div>
        <div class="kpi-card"><span class="label">Замовлення</span><strong><?= e((string) $summary['order_count']) ?></strong></div>
        <div class="kpi-card"><span class="label">Gross margin</span><strong><?= e(finance_money($summary['gross_margin'])) ?></strong><small><?= e((string) $summary['gross_margin_percent']) ?>%</small></div>
        <div class="kpi-card"><span class="label">Борг за місяць</span><strong><?= e(finance_money($summary['sales_unpaid_by_order'])) ?></strong></div>
    </section>

    <section class="panel dashboard-section">
        <div class="section-heading">
            <div><p class="eyebrow">Деталізація</p><h2>Замовлення за <?= e($month) ?></h2></div>
        </div>
        <div class="table-scroll">
            <table class="data-table">
                <thead><tr><th>№</th><th>Дата</th><th>Клієнт</th><th>Менеджер</th><th>Статус</th><th>Товари</th><th>Сума</th><th>Оплачено</th><th>Борг</th><th>Margin</th></tr></thead>
                <tbody>
                <?php if (!$orders): ?><tr><td colspan="10">Немає даних.</td></tr><?php endif; ?>
                <?php foreach ($orders as $order): ?>
                    <tr>
                        <td><?= e((string) $order['order_number']) ?></td>
                        <td><?= e((string) $order['ordered_at']) ?></td>
                        <td><strong><?= e((string) (($order['company_name'] ?? '') ?: ($order['buyer_name'] ?? '—'))) ?></strong><small><?= e((string) ($order['buyer_name'] ?? '')) ?></small></td>
                        <td><?= e((string) ($order['manager_name'] ?: '—')) ?></td>
                        <td><?= e((string) ($order['status_name'] ?: '—')) ?></td>
                        <td><?= e((string) $order['item_count']) ?></td>
                        <td class="num"><?= e(finance_money($order['total_amount_uah'])) ?></td>
                        <td class="num"><?= e(finance_money($order['paid_amount_uah'])) ?></td>
                        <td class="num"><strong><?= e(finance_money($order['unpaid_amount_uah'])) ?></strong></td>
                        <td class="num"><?= e(finance_money($order['margin_sum_uah'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
<?= app_version_badge() ?>
</body>
</html>
