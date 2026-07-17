<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/cockpit.php';
require_once __DIR__ . '/financial.php';
require_once __DIR__ . '/cockpit_layout.php';

require_login();

$month = cockpit_valid_month((string) ($_GET['month'] ?? date('Y-m')));
$rows = cockpit_manager_summary($month);
$summary = cockpit_monthly_summary($month);

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
    <?php cockpit_page_header('CEO Money Cockpit', 'Менеджери', 'План, факт, оплати в замовленнях і борг за місяць.', 'managers', $month); ?>
    <section class="kpi-grid">
        <div class="kpi-card"><span class="label">Факт продажів</span><strong><?= e(finance_money($summary['sales_fact'])) ?></strong></div>
        <div class="kpi-card"><span class="label">План</span><strong><?= e(finance_money($summary['target'])) ?></strong></div>
        <div class="kpi-card"><span class="label">Прогрес</span><strong><?= e((string) $summary['progress_percent']) ?>%</strong></div>
        <div class="kpi-card"><span class="label">Менеджерів</span><strong><?= e((string) count($rows)) ?></strong></div>
    </section>
    <section class="panel dashboard-section">
        <div class="table-scroll">
            <table class="data-table">
                <thead><tr><th>Менеджер</th><th>План</th><th>Факт</th><th>Оплачено в замовленнях</th><th>Не оплачено</th><th>К-сть</th><th>Залишилось</th><th>Прогрес</th></tr></thead>
                <tbody>
                <?php if (!$rows): ?><tr><td colspan="8">Немає даних.</td></tr><?php endif; ?>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><strong><?= e((string) $row['manager_name']) ?></strong></td>
                        <td class="num"><?= $row['target_amount_uah'] > 0 ? e(finance_money($row['target_amount_uah'])) : '—' ?></td>
                        <td class="num"><a class="metric-link" href="<?= e(base_path('/sales.php?' . http_build_query(['month' => $month, 'manager' => (string) $row['manager_name']]))) ?>"><?= e(finance_money($row['sales_fact'])) ?></a></td>
                        <td class="num"><?= e(finance_money($row['paid_by_order'])) ?></td>
                        <td class="num"><a class="metric-link" href="<?= e(base_path('/receivables.php?' . http_build_query(['month' => $month, 'manager' => (string) $row['manager_name']]))) ?>"><?= e(finance_money($row['unpaid_by_order'])) ?></a></td>
                        <td><a class="metric-link" href="<?= e(base_path('/sales.php?' . http_build_query(['month' => $month, 'manager' => (string) $row['manager_name']]))) ?>"><?= e((string) $row['order_count']) ?></a></td>
                        <td class="num"><?= $row['remaining_to_target'] !== null ? e(finance_money($row['remaining_to_target'])) : '—' ?></td>
                        <td>
                            <?php
                            $planPercent = $row['progress_percent'] !== null ? (float) $row['progress_percent'] : 0.0;
                            $paidPercent = (float) ($row['sales_fact'] ?? 0) > 0 ? round(((float) ($row['paid_by_order'] ?? 0) / (float) ($row['sales_fact'] ?? 1)) * $planPercent, 1) : 0.0;
                            ?>
                            <?= cockpit_dual_progress($planPercent, $paidPercent, ($row['progress_percent'] !== null ? e((string) $row['progress_percent']) . '% план' : 'план не задано') . ' · оплачено ' . (($row['sales_fact'] ?? 0) > 0 ? e((string) round(((float) ($row['paid_by_order'] ?? 0) / (float) ($row['sales_fact'] ?? 1)) * 100, 1)) : '0') . '%') ?>
                        </td>
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
