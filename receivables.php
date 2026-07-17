<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/cockpit.php';
require_once __DIR__ . '/financial.php';
require_once __DIR__ . '/cockpit_layout.php';

require_login();

$month = cockpit_valid_month((string) ($_GET['month'] ?? date('Y-m')));
$notCanceled = cockpit_not_canceled_sql('o');
$rows = [];
$summary = cockpit_monthly_summary($month);

try {
    if (invoice_table_exists('db_orders')) {
        $stmt = db()->query("
            SELECT o.order_number, o.ordered_at, o.company_name, o.buyer_name, o.manager_name,
                   o.total_amount_uah, o.paid_amount_uah, o.unpaid_amount_uah, o.payment_status, o.status_name,
                   DATEDIFF(CURDATE(), o.ordered_at) AS debt_age
            FROM db_orders o
            WHERE o.unpaid_amount_uah > 0
              AND {$notCanceled}
            ORDER BY o.unpaid_amount_uah DESC, o.ordered_at ASC
            LIMIT 300
        ");
        $rows = $stmt->fetchAll();
    }
} catch (Throwable $e) {
    $rows = [];
}

?><!doctype html>
<html lang="uk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Дебіторка — .BRAND DB</title>
    <link rel="stylesheet" href="<?= e(asset_path('/assets/app.css')) ?>">
</head>
<body>
<main class="app-shell cockpit-shell">
    <?php cockpit_page_header('CEO Money Cockpit', 'Дебіторка', 'Усі неоплачені замовлення across all months.', 'receivables', $month); ?>
    <section class="kpi-grid">
        <div class="kpi-card"><span class="label">Нам повинні</span><strong><?= e(finance_money($summary['receivables_total'])) ?></strong></div>
        <div class="kpi-card"><span class="label">Кількість боргів</span><strong><?= e((string) $summary['receivables_count']) ?></strong></div>
        <div class="kpi-card"><span class="label">Найбільший борг</span><strong><?= e(finance_money($summary['largest_receivable'])) ?></strong></div>
    </section>
    <section class="panel dashboard-section">
        <div class="table-scroll">
            <table class="data-table">
                <thead><tr><th>№</th><th>Дата</th><th>Вік</th><th>Клієнт</th><th>Менеджер</th><th>Сума</th><th>Оплачено</th><th>Борг</th><th>Оплата</th><th>Статус</th></tr></thead>
                <tbody>
                <?php if (!$rows): ?><tr><td colspan="10">Боргів немає.</td></tr><?php endif; ?>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?= e((string) $row['order_number']) ?></td>
                        <td><?= e((string) $row['ordered_at']) ?></td>
                        <td><?= e((string) max(0, (int) $row['debt_age'])) ?> дн.</td>
                        <td><strong><?= e((string) (($row['company_name'] ?? '') ?: ($row['buyer_name'] ?? '—'))) ?></strong></td>
                        <td><?= e((string) ($row['manager_name'] ?: '—')) ?></td>
                        <td class="num"><?= e(finance_money($row['total_amount_uah'])) ?></td>
                        <td class="num"><?= e(finance_money($row['paid_amount_uah'])) ?></td>
                        <td class="num"><strong><?= e(finance_money($row['unpaid_amount_uah'])) ?></strong></td>
                        <td><?= e((string) ($row['payment_status'] ?: '—')) ?></td>
                        <td><?= e((string) ($row['status_name'] ?: '—')) ?></td>
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
