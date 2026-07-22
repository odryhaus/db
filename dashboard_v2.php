<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/cockpit.php';
require_once __DIR__ . '/financial.php';
require_once __DIR__ . '/cockpit_layout.php';
require_once __DIR__ . '/sync_core.php';

require_login();

$user = current_user();
$selectedMonth = cockpit_valid_month((string) ($_GET['month'] ?? date('Y-m')));

if (is_post() && (string) ($_POST['action'] ?? '') === 'enqueue_global_sync') {
    if (user_role() !== 'ceo') {
        http_response_code(403);
        include __DIR__ . '/partials_forbidden.php';
        exit;
    }
    if (!csrf_is_valid()) {
        http_response_code(400);
        exit('Invalid CSRF token');
    }
    sync_enqueue_global_refresh((int) ($user['id'] ?? 0));
    redirect_to('/dashboard_v2.php?month=' . urlencode($selectedMonth) . '&sync_queued=1');
}

function cockpit_status_label(array $summary): string
{
    if (!empty($summary['sync_status']['active'])) {
        return 'оновлюється зараз';
    }
    $last = $summary['sync_status']['last_global_job']['finished_at'] ?? null;
    return $last ? 'останнє оновлення ' . (string) $last : 'очікує першого оновлення';
}

function manager_cockpit_data(string $month, array $user): array
{
    $month = cockpit_valid_month($month);
    $zero = [
        'manager_name' => cockpit_manager_scope($user)['display_name'],
        'target' => 0.0,
        'sales_fact' => 0.0,
        'paid_by_order' => 0.0,
        'unpaid_by_order' => 0.0,
        'remaining_to_target' => null,
        'progress_percent' => null,
        'paid_percent' => 0.0,
        'order_count' => 0,
        'client_count' => 0,
        'receivables_total' => 0.0,
        'receivables_count' => 0,
        'largest_receivable' => 0.0,
        'clients' => [],
        'debts' => [],
        'orders' => [],
    ];

    if (!invoice_table_exists('db_orders')) {
        return $zero;
    }

    $orderColumns = finance_columns('db_orders');
    $has = static fn(string $column): bool => in_array($column, $orderColumns, true);
    $amountExpr = static fn(string $column): string => in_array($column, $orderColumns, true) ? "COALESCE(SUM(o.{$column}), 0)" : '0';
    $valueExpr = static fn(string $column): string => in_array($column, $orderColumns, true) ? "o.{$column}" : '0';
    $nullTextExpr = static fn(string $column): string => in_array($column, $orderColumns, true) ? "NULLIF(o.{$column}, '')" : 'NULL';

    $monthParams = ['month' => $month];
    if ($has('order_month')) {
        $monthWhere = 'o.order_month = :month';
    } elseif ($has('ordered_at')) {
        $bounds = cockpit_month_bounds($month);
        $monthWhere = 'o.ordered_at >= :month_start AND o.ordered_at <= :month_end';
        $monthParams = [
            'month_start' => $bounds['start']->format('Y-m-d H:i:s'),
            'month_end' => $bounds['end']->format('Y-m-d H:i:s'),
        ];
    } else {
        $monthWhere = '1=0';
        $monthParams = [];
    }

    $clientNameParts = array_filter([
        $nullTextExpr('company_name'),
        $nullTextExpr('client_name'),
        $nullTextExpr('buyer_name'),
    ], static fn(string $expr): bool => $expr !== 'NULL');
    $clientNameExpr = $clientNameParts ? 'COALESCE(' . implode(', ', $clientNameParts) . ", 'Без клієнта')" : "'Без клієнта'";

    $clientIdentityParts = [];
    foreach (['company_id', 'buyer_id', 'client_id', 'keycrm_id', 'id'] as $column) {
        if ($has($column)) {
            $clientIdentityParts[] = "NULLIF(CAST(o.{$column} AS CHAR), '0')";
        }
    }
    foreach (['company_name', 'client_name', 'buyer_name', 'order_number'] as $column) {
        if ($has($column)) {
            $clientIdentityParts[] = "NULLIF(o.{$column}, '')";
        }
    }
    $clientIdentityExpr = $clientIdentityParts ? 'COALESCE(' . implode(', ', $clientIdentityParts) . ')' : 'NULL';

    $managerNameExpr = $has('manager_name')
        ? "COALESCE(NULLIF(MAX(o.manager_name), ''), :fallback_manager_name)"
        : ':fallback_manager_name';
    $orderNumberExpr = $has('order_number') ? 'o.order_number' : ($has('keycrm_id') ? 'CAST(o.keycrm_id AS CHAR)' : "''");
    $orderedAtExpr = $has('ordered_at') ? 'o.ordered_at' : ($has('order_month') ? "CONCAT(o.order_month, '-01 00:00:00')" : "''");
    $companyNameExpr = $has('company_name') ? 'o.company_name' : "''";
    $buyerNameExpr = $has('buyer_name') ? 'o.buyer_name' : "''";
    $unpaidColumnExpr = $valueExpr('unpaid_amount_uah');
    $debtWhere = $has('unpaid_amount_uah') ? 'o.unpaid_amount_uah > :debt_threshold' : '1=0';
    $debtOrderBy = $has('unpaid_amount_uah') ? 'o.unpaid_amount_uah DESC' : '1';
    $recentOrderBy = $has('ordered_at') ? 'o.ordered_at DESC' : ($has('order_month') ? 'o.order_month DESC' : '1');
    if ($has('id')) {
        $recentOrderBy .= ', o.id DESC';
    }

    $activeOrder = cockpit_active_order_sql('o');
    $scopeFilter = cockpit_manager_scope_filter('o', 'manager_cockpit', $user);
    $monthScopeParams = array_merge($monthParams, $scopeFilter['params']);
    $summaryParams = array_merge($monthParams, [
        'fallback_manager_name' => $scopeFilter['scope']['display_name'],
    ], $scopeFilter['params']);

    $stmt = db()->prepare("
        SELECT
            {$managerNameExpr} AS manager_name,
            COUNT(*) AS order_count,
            COUNT(DISTINCT {$clientIdentityExpr}) AS client_count,
            {$amountExpr('total_amount_uah')} AS sales_fact,
            {$amountExpr('paid_amount_uah')} AS paid_by_order,
            {$amountExpr('unpaid_amount_uah')} AS unpaid_by_order
        FROM db_orders o
        WHERE {$monthWhere}
          AND {$activeOrder}
          AND {$scopeFilter['sql']}
    ");
    $stmt->execute($summaryParams);
    $data = array_merge($zero, $stmt->fetch() ?: []);
    $data['sales_fact'] = (float) ($data['sales_fact'] ?? 0);
    $data['paid_by_order'] = (float) ($data['paid_by_order'] ?? 0);
    $data['unpaid_by_order'] = (float) ($data['unpaid_by_order'] ?? 0);
    $data['order_count'] = (int) ($data['order_count'] ?? 0);
    $data['client_count'] = (int) ($data['client_count'] ?? 0);
    $data['manager_name'] = (string) (($data['manager_name'] ?? '') ?: $scopeFilter['scope']['display_name']);

    $targetNames = array_values(array_unique(array_filter(array_merge([$data['manager_name']], $scopeFilter['scope']['names']))));
    $targets = active_manager_targets(db(), $month, $targetNames);
    foreach ($targetNames as $targetName) {
        if (!empty($targets[$targetName]) && (float) ($targets[$targetName]['amount_uah'] ?? 0) > 0) {
            $data['target'] = (float) $targets[$targetName]['amount_uah'];
            break;
        }
    }
    $data['remaining_to_target'] = $data['target'] > 0 ? max($data['target'] - $data['sales_fact'], 0) : null;
    $data['progress_percent'] = $data['target'] > 0 ? min(100, round(($data['sales_fact'] / $data['target']) * 100, 1)) : null;
    $data['paid_percent'] = $data['sales_fact'] > 0 ? min(100, round(($data['paid_by_order'] / $data['sales_fact']) * 100, 1)) : 0.0;

    $debtParams = array_merge(['debt_threshold' => 100.0], $scopeFilter['params']);
    $debtStmt = db()->prepare("
        SELECT
            {$amountExpr('unpaid_amount_uah')} AS receivables_total,
            COUNT(*) AS receivables_count,
            COALESCE(MAX({$unpaidColumnExpr}), 0) AS largest_receivable
        FROM db_orders o
        WHERE {$debtWhere}
          AND {$activeOrder}
          AND {$scopeFilter['sql']}
    ");
    $debtStmt->execute($debtParams);
    $debtTotals = $debtStmt->fetch() ?: [];
    $data['receivables_total'] = (float) ($debtTotals['receivables_total'] ?? 0);
    $data['receivables_count'] = (int) ($debtTotals['receivables_count'] ?? 0);
    $data['largest_receivable'] = (float) ($debtTotals['largest_receivable'] ?? 0);

    $clientsStmt = db()->prepare("
        SELECT
            {$clientNameExpr} AS client_name,
            COUNT(*) AS order_count,
            {$amountExpr('total_amount_uah')} AS sales_fact,
            {$amountExpr('paid_amount_uah')} AS paid_by_order,
            {$amountExpr('unpaid_amount_uah')} AS unpaid_by_order
        FROM db_orders o
        WHERE {$monthWhere}
          AND {$activeOrder}
          AND {$scopeFilter['sql']}
        GROUP BY {$clientNameExpr}
        ORDER BY sales_fact DESC
        LIMIT 8
    ");
    $clientsStmt->execute($monthScopeParams);
    $data['clients'] = $clientsStmt->fetchAll();

    $debtsStmt = db()->prepare("
        SELECT
            {$orderNumberExpr} AS order_number,
            {$orderedAtExpr} AS ordered_at,
            {$companyNameExpr} AS company_name,
            {$buyerNameExpr} AS buyer_name,
            {$valueExpr('total_amount_uah')} AS total_amount_uah,
            {$valueExpr('paid_amount_uah')} AS paid_amount_uah,
            {$valueExpr('unpaid_amount_uah')} AS unpaid_amount_uah
        FROM db_orders o
        WHERE {$debtWhere}
          AND {$activeOrder}
          AND {$scopeFilter['sql']}
        ORDER BY {$debtOrderBy}, {$recentOrderBy}
        LIMIT 8
    ");
    $debtsStmt->execute($debtParams);
    $data['debts'] = $debtsStmt->fetchAll();

    $ordersStmt = db()->prepare("
        SELECT
            {$orderNumberExpr} AS order_number,
            {$orderedAtExpr} AS ordered_at,
            {$companyNameExpr} AS company_name,
            {$buyerNameExpr} AS buyer_name,
            {$valueExpr('total_amount_uah')} AS total_amount_uah,
            {$valueExpr('paid_amount_uah')} AS paid_amount_uah,
            {$valueExpr('unpaid_amount_uah')} AS unpaid_amount_uah
        FROM db_orders o
        WHERE {$monthWhere}
          AND {$activeOrder}
          AND {$scopeFilter['sql']}
        ORDER BY {$recentOrderBy}
        LIMIT 8
    ");
    $ordersStmt->execute($monthScopeParams);
    $data['orders'] = $ordersStmt->fetchAll();

    return $data;
}

$isManagerCockpit = user_role() === 'manager';
$dashboardError = '';
$summary = cockpit_zero_summary($selectedMonth);
$managers = [];
$attention = [];
$actionQueue = [];
$managerData = [];

if ($isManagerCockpit) {
    try {
        $managerData = manager_cockpit_data($selectedMonth, $user ?? []);
    } catch (Throwable $e) {
        error_log('Manager Cockpit data failed: ' . $e->getMessage());
        $scope = cockpit_manager_scope($user ?? []);
        $managerData = [
            'manager_name' => $scope['display_name'],
            'target' => 0.0,
            'sales_fact' => 0.0,
            'paid_by_order' => 0.0,
            'unpaid_by_order' => 0.0,
            'remaining_to_target' => null,
            'progress_percent' => null,
            'paid_percent' => 0.0,
            'order_count' => 0,
            'client_count' => 0,
            'receivables_total' => 0.0,
            'receivables_count' => 0,
            'largest_receivable' => 0.0,
            'clients' => [],
            'debts' => [],
            'orders' => [],
        ];
        $dashboardError = 'Manager Cockpit data is not available yet.';
    }
} else {
    try {
        $summary = cockpit_monthly_summary($selectedMonth);
        $managers = cockpit_manager_summary($selectedMonth);
        $attention = cockpit_attention_items($selectedMonth);
    } catch (Throwable $e) {
        $summary = cockpit_zero_summary($selectedMonth);
        $dashboardError = 'CEO Money Cockpit v2 data is not available yet.';
    }
    try {
        $actionQueue = cockpit_action_queue($selectedMonth);
    } catch (Throwable $e) {
        $actionQueue = [];
    }
}

if ($isManagerCockpit && !$managerData) {
    $actionQueue = [];
}

$syncQueued = isset($_GET['sync_queued']);

?><!doctype html>
<html lang="uk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CEO Money Cockpit v2 — .BRAND DB</title>
    <link rel="stylesheet" href="<?= e(asset_path('/assets/app.css')) ?>">
</head>
<body>
<main class="app-shell cockpit-shell">
    <header class="cockpit-topbar">
        <div>
            <p class="eyebrow"><?= $isManagerCockpit ? 'Manager Performance Cockpit' : 'CEO Money Cockpit v2 preview' ?></p>
            <h1>.BRAND DB</h1>
            <p class="muted"><?= $isManagerCockpit ? 'Ваш план, продажі, оплати, борги і клієнти.' : 'Performance, cash і financial health без змішування формул.' ?></p>
        </div>
        <form class="cockpit-month-form" method="get" action="<?= e(base_path('/dashboard_v2.php')) ?>">
            <label>
                <span>Місяць</span>
                <input type="month" name="month" value="<?= e($selectedMonth) ?>">
            </label>
            <button type="submit">Показати</button>
        </form>
    </header>

    <?php cockpit_nav('dashboard', $selectedMonth); ?>

    <?php if (!$isManagerCockpit): ?>
        <section class="cockpit-sync-strip">
            <span><?= e(cockpit_status_label($summary)) ?></span>
            <?php if ($syncQueued): ?><strong>Оновлення поставлено в чергу.</strong><?php endif; ?>
            <?php if (user_role() === 'ceo'): ?>
                <form method="post" action="<?= e(base_path('/dashboard_v2.php?month=' . urlencode($selectedMonth))) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="enqueue_global_sync">
                    <button type="submit">Оновити все</button>
                </form>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if ($dashboardError !== ''): ?>
        <section class="panel dashboard-section">
            <span class="status-badge status-badge--danger"><?= e($dashboardError) ?></span>
        </section>
    <?php endif; ?>

    <?php if ($isManagerCockpit): ?>
        <?php
        $managerTarget = (float) ($managerData['target'] ?? 0);
        $managerFact = (float) ($managerData['sales_fact'] ?? 0);
        $managerPaid = (float) ($managerData['paid_by_order'] ?? 0);
        $managerProgress = ($managerData['progress_percent'] ?? null) !== null ? (float) $managerData['progress_percent'] : 0.0;
        $managerPaidToPlan = $managerTarget > 0 ? min(100, round(($managerPaid / $managerTarget) * 100, 1)) : 0.0;
        ?>
        <section class="cockpit-hero manager-cockpit-hero">
            <div class="cockpit-hero-main">
                <p class="eyebrow">Performance</p>
                <h2><?= e((string) ($managerData['manager_name'] ?? format_user_name($user ?? []))) ?></h2>
                <p>Ваш план і продажі за <?= e($selectedMonth) ?>. Видно тільки ваші клієнти та замовлення.</p>
                <?= cockpit_dual_progress($managerProgress, $managerPaidToPlan, ($managerTarget > 0 ? e((string) $managerProgress) . '% план' : 'план не задано') . ' · оплачено ' . e((string) ($managerData['paid_percent'] ?? 0)) . '%') ?>
                <div class="cockpit-hero-meta">
                    <strong><?= e(money_uah_compact($managerFact)) ?></strong>
                    <span>план <?= $managerTarget > 0 ? e(money_uah_compact($managerTarget)) : 'не задано' ?> · оплачено <?= e(money_uah_compact($managerPaid)) ?></span>
                </div>
            </div>
            <div class="cockpit-attention">
                <p class="eyebrow">Фокус зараз</p>
                <?php if ((float) ($managerData['receivables_total'] ?? 0) > 0): ?>
                    <a class="attention-row warning" href="<?= e(base_path('/sales.php?' . http_build_query(['month' => $selectedMonth, 'status' => 'debt']))) ?>">
                        <span>Нагадати оплату</span>
                        <strong><?= e(money_uah_compact($managerData['receivables_total'])) ?></strong>
                    </a>
                <?php else: ?>
                    <div class="attention-row neutral">
                        <span>Критичних боргів немає</span>
                        <strong>OK</strong>
                    </div>
                <?php endif; ?>
                <?php if ($managerTarget > 0 && ($managerData['remaining_to_target'] ?? 0) > 0): ?>
                    <div class="attention-row neutral">
                        <span>Залишилось до плану</span>
                        <strong><?= e(money_uah_compact($managerData['remaining_to_target'])) ?></strong>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <section class="kpi-grid compact-kpis">
            <div class="kpi-card"><span class="label">План</span><strong><?= $managerTarget > 0 ? e(money_uah_compact($managerTarget)) : 'Не задано' ?></strong></div>
            <div class="kpi-card"><span class="label">Факт</span><strong><?= e(money_uah_compact($managerFact)) ?></strong><small><?= e((string) ($managerData['order_count'] ?? 0)) ?> замовлень</small></div>
            <div class="kpi-card"><span class="label">Оплачено</span><strong><?= e(money_uah_compact($managerPaid)) ?></strong><small><?= e((string) ($managerData['paid_percent'] ?? 0)) ?>% від факту</small></div>
            <div class="kpi-card danger"><span class="label">Не оплачено</span><strong><?= e(money_uah_compact($managerData['unpaid_by_order'] ?? 0)) ?></strong></div>
            <div class="kpi-card"><span class="label">Клієнтів</span><strong><?= e((string) ($managerData['client_count'] ?? 0)) ?></strong><small>активні у місяці</small></div>
        </section>

        <section class="cockpit-section">
            <div class="section-heading">
                <div>
                    <p class="eyebrow">Clients</p>
                    <h2>Ваші клієнти за <?= e($selectedMonth) ?></h2>
                </div>
                <a class="button-secondary small-button" href="<?= e(base_path('/client_balances.php?' . http_build_query(['month' => $selectedMonth]))) ?>">Відкрити клієнтів</a>
            </div>
            <div class="table-scroll">
                <table class="data-table">
                    <thead><tr><th>Клієнт</th><th>Факт</th><th>Оплачено</th><th>Борг</th><th>К-сть</th></tr></thead>
                    <tbody>
                    <?php if (empty($managerData['clients'])): ?><tr><td colspan="5">Немає клієнтів за місяць.</td></tr><?php endif; ?>
                    <?php foreach (($managerData['clients'] ?? []) as $client): ?>
                        <tr>
                            <td><strong><?= e((string) $client['client_name']) ?></strong></td>
                            <td class="num"><?= e(money_uah_compact($client['sales_fact'] ?? 0)) ?></td>
                            <td class="num"><?= e(money_uah_compact($client['paid_by_order'] ?? 0)) ?></td>
                            <td class="num <?= (float) ($client['unpaid_by_order'] ?? 0) > 0 ? 'danger-text' : '' ?>"><?= e(money_uah_compact($client['unpaid_by_order'] ?? 0)) ?></td>
                            <td class="num"><?= e((string) ($client['order_count'] ?? 0)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="cockpit-section">
            <div class="section-heading">
                <div>
                    <p class="eyebrow">Receivables</p>
                    <h2>Ваші борги клієнтів</h2>
                </div>
                <a class="button-secondary small-button" href="<?= e(base_path('/sales.php?' . http_build_query(['month' => $selectedMonth, 'status' => 'debt']))) ?>">Дебіторка</a>
            </div>
            <div class="table-scroll">
                <table class="data-table">
                    <thead><tr><th>№</th><th>Дата</th><th>Клієнт</th><th>Сума</th><th>Оплачено</th><th>Борг</th></tr></thead>
                    <tbody>
                    <?php if (empty($managerData['debts'])): ?><tr><td colspan="6">Боргів більше 100 UAH немає.</td></tr><?php endif; ?>
                    <?php foreach (($managerData['debts'] ?? []) as $order): ?>
                        <tr>
                            <td><strong><?= e((string) $order['order_number']) ?></strong></td>
                            <td><?= e(substr((string) ($order['ordered_at'] ?? ''), 0, 10)) ?></td>
                            <td><?= e((string) (($order['company_name'] ?? '') ?: (($order['buyer_name'] ?? '') ?: 'Без клієнта'))) ?></td>
                            <td class="num"><?= e(money_uah_compact($order['total_amount_uah'] ?? 0)) ?></td>
                            <td class="num"><?= e(money_uah_compact($order['paid_amount_uah'] ?? 0)) ?></td>
                            <td class="num danger-text"><?= e(money_uah_compact($order['unpaid_amount_uah'] ?? 0)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="cockpit-section">
            <div class="section-heading">
                <div>
                    <p class="eyebrow">Orders</p>
                    <h2>Останні ваші замовлення</h2>
                </div>
                <a class="button-secondary small-button" href="<?= e(base_path('/sales.php?' . http_build_query(['month' => $selectedMonth]))) ?>">Всі продажі</a>
            </div>
            <div class="table-scroll">
                <table class="data-table">
                    <thead><tr><th>№</th><th>Дата</th><th>Клієнт</th><th>Сума</th><th>Оплачено</th><th>Борг</th></tr></thead>
                    <tbody>
                    <?php if (empty($managerData['orders'])): ?><tr><td colspan="6">Замовлень за місяць немає.</td></tr><?php endif; ?>
                    <?php foreach (($managerData['orders'] ?? []) as $order): ?>
                        <tr>
                            <td><strong><?= e((string) $order['order_number']) ?></strong></td>
                            <td><?= e(substr((string) ($order['ordered_at'] ?? ''), 0, 10)) ?></td>
                            <td><?= e((string) (($order['company_name'] ?? '') ?: (($order['buyer_name'] ?? '') ?: 'Без клієнта'))) ?></td>
                            <td class="num"><?= e(money_uah_compact($order['total_amount_uah'] ?? 0)) ?></td>
                            <td class="num"><?= e(money_uah_compact($order['paid_amount_uah'] ?? 0)) ?></td>
                            <td class="num <?= (float) ($order['unpaid_amount_uah'] ?? 0) > 0 ? 'danger-text' : '' ?>"><?= e(money_uah_compact($order['unpaid_amount_uah'] ?? 0)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php else: ?>

    <section class="cockpit-hero">
        <div class="cockpit-hero-main">
            <p class="eyebrow">Performance</p>
            <h2><?= e(money_uah_compact($summary['sales_fact'])) ?></h2>
            <p>Факт продажів за <?= e($selectedMonth) ?> з плану <?= e(money_uah_compact($summary['target'])) ?></p>
            <?= cockpit_dual_progress((float) $summary['progress_percent'], $summary['target'] > 0 ? min(100, round(((float) $summary['sales_paid_by_order'] / (float) $summary['target']) * 100, 1)) : 0, 'чорне - факт до плану, жовте - оплачено з цих замовлень') ?>
            <div class="cockpit-hero-meta">
                <strong><?= e((string) $summary['progress_percent']) ?>%</strong>
                <span>оплачено <?= e(money_uah_compact($summary['sales_paid_by_order'])) ?> · залишилось <?= e(money_uah_compact($summary['remaining_to_target'])) ?></span>
            </div>
        </div>
        <div class="cockpit-attention">
            <p class="eyebrow">Потребує уваги</p>
            <?php if (!$attention): ?>
                <div class="attention-row neutral">
                    <span>Критичних сигналів немає</span>
                    <strong>OK</strong>
                </div>
            <?php endif; ?>
            <?php foreach ($attention as $item): ?>
                <div class="attention-row <?= e((string) $item['level']) ?>">
                    <span><?= e((string) $item['title']) ?></span>
                    <strong><?= e((string) $item['value']) ?></strong>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="cockpit-section action-queue-section">
        <div class="section-heading">
            <div>
                <p class="eyebrow">Action queue</p>
                <h2>Потрібна дія</h2>
            </div>
            <span class="status-badge">не звіт, а список роботи</span>
        </div>
        <div class="action-queue">
            <?php if (!$actionQueue): ?>
                <div class="action-card neutral">
                    <div>
                        <strong>Критичних дій немає</strong>
                        <span>Система не бачить прострочених оплат, критичних боргів або sync-помилок.</span>
                    </div>
                    <span class="status-badge status-badge--success">OK</span>
                </div>
            <?php endif; ?>
            <?php foreach ($actionQueue as $action): ?>
                <a class="action-card <?= e((string) ($action['level'] ?? 'neutral')) ?>" href="<?= e(base_path((string) ($action['href'] ?? '/dashboard_v2.php?month=' . urlencode($selectedMonth)))) ?>">
                    <div>
                        <strong><?= e((string) ($action['title'] ?? 'Дія')) ?></strong>
                        <span><?= e((string) ($action['meta'] ?? '')) ?></span>
                    </div>
                    <div class="action-card__value">
                        <strong><?= e((string) ($action['value'] ?? '')) ?></strong>
                        <small><?= e((string) ($action['cta'] ?? 'Відкрити')) ?></small>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="cockpit-section">
        <div class="section-heading">
            <div>
                <p class="eyebrow">A. Performance</p>
                <h2>План, маржа, прибуток</h2>
            </div>
            <span class="status-badge">Sales month = db_orders.order_month</span>
        </div>
        <div class="cockpit-card-grid">
            <div class="kpi-card">
                <span class="label">План</span>
                <strong><?= e(money_uah_compact($summary['target'])) ?></strong>
            </div>
            <div class="kpi-card">
                <span class="label">Факт</span>
                <a class="metric-link" href="<?= e(base_path('/sales.php?month=' . urlencode($selectedMonth))) ?>"><strong><?= e(money_uah_compact($summary['sales_fact'])) ?></strong></a>
                <small><?= e((string) $summary['order_count']) ?> замовлень</small>
            </div>
            <div class="kpi-card">
                <span class="label">Оплачено з факту</span>
                <strong><?= e(money_uah_compact($summary['sales_paid_by_order'])) ?></strong>
                <small><?= $summary['sales_fact'] > 0 ? e((string) round(((float) $summary['sales_paid_by_order'] / (float) $summary['sales_fact']) * 100, 1)) . '% від факту' : '0% від факту' ?></small>
            </div>
            <div class="kpi-card">
                <span class="label">Gross margin</span>
                <strong><?= e(money_uah_compact($summary['gross_margin'])) ?></strong>
                <small><?= e((string) $summary['gross_margin_percent']) ?>%</small>
            </div>
            <div class="kpi-card">
                <span class="label">Operating profit</span>
                <strong><?= $summary['operating_profit_status'] === 'calculated' ? e(money_uah_compact($summary['operating_profit'])) : 'Потрібна категоризація' ?></strong>
                <small>gross margin - completed operating expenses</small>
            </div>
        </div>
    </section>

    <section class="cockpit-section">
        <div class="section-heading">
            <div>
                <p class="eyebrow">B. Cash</p>
                <h2>Гроші, борги клієнтів, платежі</h2>
            </div>
            <span class="status-badge">Cash month = db_order_payments.payment_date</span>
        </div>
        <div class="cockpit-card-grid">
            <div class="kpi-card">
                <span class="label">Гроші прийшли</span>
                <strong><?= e(money_uah_compact($summary['cash_received'])) ?></strong>
                <small><?= e((string) $summary['cash_payment_count']) ?> платежів</small>
            </div>
            <div class="kpi-card">
                <span class="label">З минулих замовлень</span>
                <strong><?= e(money_uah_compact($summary['cash_from_previous_orders'])) ?></strong>
            </div>
            <div class="kpi-card">
                <span class="label">Нам повинні всього</span>
                <strong><?= e(money_uah_compact($summary['receivables_total'])) ?></strong>
                <small><?= e((string) $summary['receivables_count']) ?> боргів</small>
            </div>
            <div class="kpi-card">
                <span class="label">Ми повинні цього місяця</span>
                <strong><?= e(money_uah_compact($summary['operational_due_this_month'])) ?></strong>
                <small>overdue <?= e(money_uah_compact($summary['overdue_obligations_total'])) ?></small>
            </div>
            <div class="kpi-card">
                <span class="label">Поточний баланс</span>
                <strong><?= e(money_uah_compact($summary['current_balance'])) ?></strong>
                <small><?= e((string) $summary['unallocated_transactions_count']) ?> needs review</small>
            </div>
            <div class="kpi-card">
                <span class="label">Cash forecast</span>
                <strong><?= e(money_uah_compact($summary['cash_forecast'])) ?></strong>
                <small>balance + receivables - operational due</small>
            </div>
        </div>
    </section>

    <section class="cockpit-section">
        <div class="section-heading">
            <div>
                <p class="eyebrow">C. Financial Health</p>
                <h2>Стратегічні борги та довгі зобов’язання</h2>
            </div>
            <span class="status-badge">не змішується з операційним тиском</span>
        </div>
        <div class="cockpit-card-grid">
            <div class="kpi-card danger">
                <span class="label">Стратегічні борги</span>
                <strong><?= e(money_uah_compact($summary['strategic_debt_total'])) ?></strong>
                <small>повний залишок окремо</small>
            </div>
            <div class="kpi-card">
                <span class="label">Цього тижня до оплати</span>
                <strong><?= e(money_uah_compact($summary['operational_due_this_week'])) ?></strong>
            </div>
            <div class="kpi-card">
                <span class="label">Direct costs</span>
                <strong><?= e(money_uah_compact($summary['direct_costs'])) ?></strong>
                <small>потрібна валідація джерела</small>
            </div>
            <div class="kpi-card">
                <span class="label">Нерозподілені операції</span>
                <strong><?= e((string) $summary['unallocated_transactions_count']) ?></strong>
                <small>allocation_status = needs_review</small>
            </div>
        </div>
    </section>

    <section class="panel dashboard-section">
        <div class="section-heading">
            <div>
                <p class="eyebrow">Manager performance</p>
                <h2>Менеджери за <?= e($selectedMonth) ?></h2>
            </div>
            <a class="button-secondary small-button" href="<?= e(base_path('/targets.php?month=' . urlencode($selectedMonth))) ?>">Плани</a>
        </div>
        <div class="table-scroll">
            <table class="data-table">
                <thead>
                <tr>
                    <th>Менеджер</th>
                    <th>План</th>
                    <th>Факт</th>
                    <th>Оплачено</th>
                    <th>Не оплачено</th>
                    <th>Прогрес</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$managers): ?>
                    <tr><td colspan="6">Немає даних за місяць.</td></tr>
                <?php endif; ?>
                <?php foreach ($managers as $manager): ?>
                    <tr>
                        <td><strong><?= e((string) $manager['manager_name']) ?></strong></td>
                        <td class="num"><?= $manager['target_amount_uah'] > 0 ? e(money_uah_compact($manager['target_amount_uah'])) : '—' ?></td>
                        <td class="num"><a class="metric-link" href="<?= e(base_path('/sales.php?' . http_build_query(['month' => $selectedMonth, 'manager' => (string) $manager['manager_name']]))) ?>"><?= e(money_uah_compact($manager['sales_fact'] ?? 0)) ?></a></td>
                        <td class="num"><?= e(money_uah_compact($manager['paid_by_order'] ?? 0)) ?></td>
                        <td class="num"><a class="metric-link" href="<?= e(base_path('/sales.php?' . http_build_query(['month' => $selectedMonth, 'manager' => (string) $manager['manager_name'], 'status' => 'debt']))) ?>"><?= e(money_uah_compact($manager['unpaid_by_order'] ?? 0)) ?></a></td>
                        <td>
                            <?php
                            $managerPlanPercent = $manager['progress_percent'] !== null ? (float) $manager['progress_percent'] : 0.0;
                            $managerPaidPercent = (float) ($manager['sales_fact'] ?? 0) > 0 ? round(((float) ($manager['paid_by_order'] ?? 0) / (float) ($manager['sales_fact'] ?? 1)) * $managerPlanPercent, 1) : 0.0;
                            ?>
                            <?= cockpit_dual_progress($managerPlanPercent, $managerPaidPercent, ($manager['progress_percent'] !== null ? e((string) $manager['progress_percent']) . '% план' : 'план не задано') . ' · оплачено ' . (($manager['sales_fact'] ?? 0) > 0 ? e((string) round(((float) ($manager['paid_by_order'] ?? 0) / (float) ($manager['sales_fact'] ?? 1)) * 100, 1)) : '0') . '%') ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
    <?php endif; ?>
</main>
<?= app_version_badge() ?>
</body>
</html>
