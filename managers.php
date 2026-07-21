<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/cockpit.php';
require_once __DIR__ . '/financial.php';
require_once __DIR__ . '/cockpit_layout.php';

require_login();
if (function_exists('ensure_analytics_exclusion_columns')) {
    ensure_analytics_exclusion_columns();
}

$month = cockpit_valid_month((string) ($_GET['month'] ?? date('Y-m')));
$fromMonth = isset($_GET['from_month']) ? cockpit_valid_month((string) $_GET['from_month']) : substr($month, 0, 4) . '-01';
$toMonth = isset($_GET['to_month']) ? cockpit_valid_month((string) $_GET['to_month']) : $month;
if ($fromMonth > $toMonth) {
    [$fromMonth, $toMonth] = [$toMonth, $fromMonth];
}

$rows = [];
$chartRows = [];
$chartMax = 0.0;

function managers_month_range(string $fromMonth, string $toMonth): array
{
    $from = DateTimeImmutable::createFromFormat('!Y-m', cockpit_valid_month($fromMonth));
    $to = DateTimeImmutable::createFromFormat('!Y-m', cockpit_valid_month($toMonth));
    if (!$from || !$to) {
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

function managers_month_short(string $month): string
{
    $labels = [1 => 'Січ', 2 => 'Лют', 3 => 'Бер', 4 => 'Кві', 5 => 'Тра', 6 => 'Чер', 7 => 'Лип', 8 => 'Сер', 9 => 'Вер', 10 => 'Жов', 11 => 'Лис', 12 => 'Гру'];
    return $labels[(int) substr($month, 5, 2)] ?? $month;
}

if (invoice_table_exists('db_orders')) {
    $activeOrder = cockpit_active_order_sql('o');
    $stmt = db()->prepare("
        SELECT
            COALESCE(NULLIF(o.manager_name, ''), 'Без менеджера') AS manager_name,
            COUNT(*) AS order_count,
            COALESCE(SUM(o.total_amount_uah), 0) AS sales_fact,
            COALESCE(SUM(o.paid_amount_uah), 0) AS paid_by_order,
            COALESCE(SUM(o.unpaid_amount_uah), 0) AS unpaid_by_order
        FROM db_orders o
        WHERE o.order_month >= :from_month
          AND o.order_month <= :to_month
          AND {$activeOrder}
        GROUP BY COALESCE(NULLIF(o.manager_name, ''), 'Без менеджера')
        ORDER BY sales_fact DESC
    ");
    $stmt->execute(['from_month' => $fromMonth, 'to_month' => $toMonth]);
    $rows = $stmt->fetchAll();

    $months = managers_month_range($fromMonth, $toMonth);
    $managerNames = array_map(static fn($row) => (string) $row['manager_name'], $rows);
    $targets = array_fill_keys($managerNames, 0.0);
    foreach ($months as $targetMonth) {
        foreach (active_manager_targets(db(), $targetMonth, $managerNames) as $managerName => $targetRow) {
            $targets[(string) $managerName] = ($targets[(string) $managerName] ?? 0.0) + (float) ($targetRow['amount_uah'] ?? 0);
        }
    }
    foreach ($rows as &$row) {
        $target = (float) ($targets[(string) $row['manager_name']] ?? 0);
        $fact = (float) ($row['sales_fact'] ?? 0);
        $paid = (float) ($row['paid_by_order'] ?? 0);
        $row['target_amount_uah'] = $target;
        $row['remaining_to_target'] = $target > 0 ? max($target - $fact, 0) : null;
        $row['progress_percent'] = $target > 0 ? min(100, round(($fact / $target) * 100, 1)) : null;
        $row['paid_percent'] = $fact > 0 ? min(100, round(($paid / $fact) * 100, 1)) : 0.0;
    }
    unset($row);

    foreach ($managerNames as $managerName) {
        foreach ($months as $chartMonth) {
            $chartRows[$managerName][$chartMonth] = ['sales' => 0.0, 'paid' => 0.0];
        }
    }

    $chartStmt = db()->prepare("
        SELECT
            COALESCE(NULLIF(o.manager_name, ''), 'Без менеджера') AS manager_name,
            o.order_month,
            COALESCE(SUM(o.total_amount_uah), 0) AS sales,
            COALESCE(SUM(o.paid_amount_uah), 0) AS paid
        FROM db_orders o
        WHERE o.order_month >= :from_month
          AND o.order_month <= :to_month
          AND {$activeOrder}
        GROUP BY COALESCE(NULLIF(o.manager_name, ''), 'Без менеджера'), o.order_month
    ");
    $chartStmt->execute(['from_month' => $fromMonth, 'to_month' => $toMonth]);
    foreach ($chartStmt->fetchAll() as $chartRow) {
        $managerName = (string) $chartRow['manager_name'];
        $chartMonth = (string) $chartRow['order_month'];
        if (isset($chartRows[$managerName][$chartMonth])) {
            $chartRows[$managerName][$chartMonth]['sales'] = (float) $chartRow['sales'];
            $chartRows[$managerName][$chartMonth]['paid'] = (float) $chartRow['paid'];
            $chartMax = max($chartMax, (float) $chartRow['sales']);
        }
    }
}

$periodSales = array_sum(array_map(static fn($row) => (float) ($row['sales_fact'] ?? 0), $rows));
$periodPaid = array_sum(array_map(static fn($row) => (float) ($row['paid_by_order'] ?? 0), $rows));
$periodUnpaid = array_sum(array_map(static fn($row) => (float) ($row['unpaid_by_order'] ?? 0), $rows));
$periodOrders = array_sum(array_map(static fn($row) => (int) ($row['order_count'] ?? 0), $rows));

?><!doctype html>
<html lang="uk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Менеджери — .BRAND DB</title>
    <link rel="stylesheet" href="<?= e(asset_path('/assets/app.css')) ?>">
</head>
<body>
<main class="app-shell cockpit-shell">
    <?php cockpit_page_header('CEO Money Cockpit', 'Менеджери', 'Продажі, оплати і борг по менеджерах за вибраний період.', 'managers', $toMonth, false); ?>

    <section class="panel dashboard-section sales-filter-panel">
        <div class="section-heading">
            <div><p class="eyebrow">Період</p><h2><?= e($fromMonth) ?> → <?= e($toMonth) ?></h2></div>
            <a class="button-secondary" href="<?= e(base_path('/targets.php?month=' . urlencode($toMonth))) ?>">Плани</a>
        </div>
        <form class="sales-period-toolbar" method="get" action="<?= e(base_path('/managers.php')) ?>">
            <input type="hidden" name="month" value="<?= e($toMonth) ?>">
            <label><span>З місяця</span><input type="month" name="from_month" value="<?= e($fromMonth) ?>"></label>
            <label><span>По місяць</span><input type="month" name="to_month" value="<?= e($toMonth) ?>"></label>
            <button type="submit">Показати</button>
        </form>
    </section>

    <section class="kpi-grid">
        <div class="kpi-card"><span class="label">Факт</span><strong><?= e(finance_money($periodSales)) ?></strong></div>
        <div class="kpi-card"><span class="label">Оплачено</span><strong><?= e(finance_money($periodPaid)) ?></strong></div>
        <div class="kpi-card"><span class="label">Не оплачено</span><strong><?= e(finance_money($periodUnpaid)) ?></strong></div>
        <div class="kpi-card"><span class="label">Замовлення</span><strong><?= e((string) $periodOrders) ?></strong><small><?= e((string) count($rows)) ?> менеджерів</small></div>
    </section>

    <section class="panel dashboard-section">
        <div class="table-scroll">
            <table class="data-table">
                <thead><tr><th>Менеджер</th><th>План</th><th>Факт</th><th>Оплачено</th><th>Не оплачено</th><th>К-сть</th><th>Залишилось</th><th>Прогрес</th></tr></thead>
                <tbody>
                <?php if (!$rows): ?><tr><td colspan="8">Немає даних.</td></tr><?php endif; ?>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><strong><?= e((string) $row['manager_name']) ?></strong></td>
                        <td class="num"><?= $row['target_amount_uah'] > 0 ? e(finance_money($row['target_amount_uah'])) : '—' ?></td>
                        <td class="num"><a class="metric-link" href="<?= e(base_path('/sales.php?' . http_build_query(['month' => $toMonth, 'from_month' => $fromMonth, 'to_month' => $toMonth, 'manager' => (string) $row['manager_name']]))) ?>"><?= e(finance_money($row['sales_fact'])) ?></a></td>
                        <td class="num"><?= e(finance_money($row['paid_by_order'])) ?></td>
                        <td class="num"><a class="metric-link" href="<?= e(base_path('/sales.php?' . http_build_query(['month' => $toMonth, 'from_month' => $fromMonth, 'to_month' => $toMonth, 'manager' => (string) $row['manager_name'], 'status' => 'debt']))) ?>"><?= e(finance_money($row['unpaid_by_order'])) ?></a></td>
                        <td><a class="metric-link" href="<?= e(base_path('/sales.php?' . http_build_query(['month' => $toMonth, 'from_month' => $fromMonth, 'to_month' => $toMonth, 'manager' => (string) $row['manager_name']]))) ?>"><?= e((string) $row['order_count']) ?></a></td>
                        <td class="num"><?= $row['remaining_to_target'] !== null ? e(finance_money($row['remaining_to_target'])) : '—' ?></td>
                        <td><?= cockpit_dual_progress((float) ($row['progress_percent'] ?? 0), (float) ($row['paid_percent'] ?? 0), ($row['progress_percent'] !== null ? e((string) $row['progress_percent']) . '% план' : 'план не задано') . ' · оплачено ' . e((string) ($row['paid_percent'] ?? 0)) . '%') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="panel dashboard-section">
        <div class="section-heading">
            <div><p class="eyebrow">Графіки</p><h2>Продажі менеджерів по місяцях</h2></div>
            <span class="status-badge">чорне — факт · жовте — оплачено</span>
        </div>
        <div class="manager-chart-list">
            <?php foreach ($rows as $row): ?>
                <?php $managerName = (string) $row['manager_name']; ?>
                <div class="manager-chart-row">
                    <strong><?= e($managerName) ?></strong>
                    <div class="manager-chart-bars">
                        <?php foreach (($chartRows[$managerName] ?? []) as $chartMonth => $chartRow): ?>
                            <?php
                            $salesHeight = $chartMax > 0 ? max(2, round(((float) $chartRow['sales'] / $chartMax) * 100, 1)) : 0;
                            $paidHeight = $chartMax > 0 ? max(2, round(((float) $chartRow['paid'] / $chartMax) * 100, 1)) : 0;
                            ?>
                            <span class="manager-month-bar" title="<?= e($chartMonth . ': ' . finance_money($chartRow['sales']) . ' / оплачено ' . finance_money($chartRow['paid'])) ?>">
                                <i class="sales-bar-sales" style="height: <?= e((string) $salesHeight) ?>%"></i>
                                <i class="sales-bar-cash" style="height: <?= e((string) $paidHeight) ?>%"></i>
                                <em><?= e(managers_month_short((string) $chartMonth)) ?></em>
                            </span>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
</main>
<?= app_version_badge() ?>
</body>
</html>
