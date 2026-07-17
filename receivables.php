<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/cockpit.php';
require_once __DIR__ . '/financial.php';
require_once __DIR__ . '/cockpit_layout.php';

require_login();

$month = cockpit_valid_month((string) ($_GET['month'] ?? date('Y-m')));
$notCanceled = cockpit_not_canceled_sql('o');
$statusFilter = trim((string) ($_GET['status'] ?? 'all'));
$managerFilter = trim((string) ($_GET['manager'] ?? 'all'));
$rows = [];
$summary = cockpit_monthly_summary($month);
$statusOptions = [];
$managerOptions = [];

try {
    if (invoice_table_exists('db_orders')) {
        $statusOptions = db()->query("
            SELECT COALESCE(NULLIF(o.payment_status, ''), 'unknown') AS value, COUNT(*) AS count_rows
            FROM db_orders o
            WHERE o.unpaid_amount_uah > 0
              AND {$notCanceled}
            GROUP BY COALESCE(NULLIF(o.payment_status, ''), 'unknown')
            ORDER BY count_rows DESC
        ")->fetchAll();
        $managerOptions = db()->query("
            SELECT COALESCE(NULLIF(o.manager_name, ''), 'Без менеджера') AS value, COUNT(*) AS count_rows
            FROM db_orders o
            WHERE o.unpaid_amount_uah > 0
              AND {$notCanceled}
            GROUP BY COALESCE(NULLIF(o.manager_name, ''), 'Без менеджера')
            ORDER BY count_rows DESC
        ")->fetchAll();

        $where = ["o.unpaid_amount_uah > 0", $notCanceled];
        $params = [];
        if ($statusFilter !== 'all' && $statusFilter !== '') {
            $where[] = "COALESCE(NULLIF(o.payment_status, ''), 'unknown') = :status_filter";
            $params['status_filter'] = $statusFilter;
        }
        if ($managerFilter !== 'all' && $managerFilter !== '') {
            $where[] = "COALESCE(NULLIF(o.manager_name, ''), 'Без менеджера') = :manager_filter";
            $params['manager_filter'] = $managerFilter;
        }
        $stmt = db()->prepare("
            SELECT o.order_number, o.ordered_at, o.company_name, o.buyer_name, o.manager_name,
                   o.total_amount_uah, o.paid_amount_uah, o.unpaid_amount_uah, o.payment_status, o.status_name,
                   DATEDIFF(CURDATE(), o.ordered_at) AS debt_age
            FROM db_orders o
            WHERE " . implode(' AND ', $where) . "
            ORDER BY o.unpaid_amount_uah DESC, o.ordered_at ASC
            LIMIT 300
        ");
        $stmt->execute($params);
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
        <form class="filter-switchboard" method="get">
            <input type="hidden" name="month" value="<?= e($month) ?>">
            <div>
                <span class="label">Статус</span>
                <div class="segmented-scroll">
                    <a class="<?= $statusFilter === 'all' ? 'active' : '' ?>" href="<?= e(base_path('/receivables.php?' . http_build_query(['month' => $month, 'status' => 'all', 'manager' => $managerFilter]))) ?>">Усі</a>
                    <?php foreach ($statusOptions as $option): ?>
                        <?php $value = (string) $option['value']; ?>
                        <a class="<?= $statusFilter === $value ? 'active' : '' ?>" href="<?= e(base_path('/receivables.php?' . http_build_query(['month' => $month, 'status' => $value, 'manager' => $managerFilter]))) ?>"><?= e($value) ?> <small><?= e((string) $option['count_rows']) ?></small></a>
                    <?php endforeach; ?>
                </div>
            </div>
            <div>
                <span class="label">Менеджер</span>
                <div class="segmented-scroll">
                    <a class="<?= $managerFilter === 'all' ? 'active' : '' ?>" href="<?= e(base_path('/receivables.php?' . http_build_query(['month' => $month, 'status' => $statusFilter, 'manager' => 'all']))) ?>">Усі</a>
                    <?php foreach ($managerOptions as $option): ?>
                        <?php $value = (string) $option['value']; ?>
                        <a class="<?= $managerFilter === $value ? 'active' : '' ?>" href="<?= e(base_path('/receivables.php?' . http_build_query(['month' => $month, 'status' => $statusFilter, 'manager' => $value]))) ?>"><?= e($value) ?> <small><?= e((string) $option['count_rows']) ?></small></a>
                    <?php endforeach; ?>
                </div>
            </div>
        </form>
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
