<?php

require_once __DIR__ . '/bootstrap.php';
require_login();
ensure_finance_tables();

$user = current_user();
$selectedMonth = (string) ($_GET['month'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $selectedMonth)) {
    $selectedMonth = date('Y-m');
}

$debtManager = trim((string) ($_GET['debt_manager'] ?? ''));
$debtPage = max(1, (int) ($_GET['debt_page'] ?? 1));
$debtPerPage = 25;
$debtOffset = ($debtPage - 1) * $debtPerPage;

$monthDate = DateTimeImmutable::createFromFormat('!Y-m', $selectedMonth) ?: new DateTimeImmutable('first day of this month');
$monthStart = $monthDate->modify('first day of this month')->setTime(0, 0, 0);
$monthEnd = $monthDate->modify('last day of this month')->setTime(23, 59, 59);
$today = (new DateTimeImmutable('today'))->setTime(0, 0, 0);
$monthLabel = $monthDate->format('F Y');

$monthlyTarget = 4000000;
$salesFact = 0;
$paid = 0;
$monthlyUnpaid = 0;
$receivablesTotal = 0;
$receivablesCount = 0;
$largestReceivable = 0;
$filteredReceivablesTotal = 0;
$filteredReceivablesCount = 0;
$remaining = $monthlyTarget;
$progress = 0;
$orderCount = 0;
$dailyRequiredLabel = 'місяць закрито';
$lastSyncAt = null;
$monthlyUnpaidOrders = [];
$receivableOrders = [];
$managerSummary = [];
$receivablesByManager = [];
$operationalDueThisMonth = 0;
$strategicDebtTotal = 0;
$operationalDueThisWeek = 0;
$overdueTotal = 0;
$overdueCount = 0;
$dashboardError = '';
$totalDebtPages = 1;

function money_uah($amount): string
{
    return number_format((float) $amount, 0, '.', ' ') . ' UAH';
}

function dashboard_client_name(array $order): string
{
    return (string) ($order['company_name'] ?: ($order['buyer_name'] ?: ($order['client_name'] ?: '—')));
}

function dashboard_url(array $params = []): string
{
    return base_path('/index.php') . '?' . http_build_query($params);
}

function dashboard_manager_key($managerName): string
{
    $name = trim((string) $managerName);
    return $name !== '' && $name !== 'No manager' ? $name : 'Без менеджера';
}

function dashboard_payment_badge(array $order): string
{
    $total = (float) ($order['total_amount_uah'] ?? 0);
    $unpaid = (float) ($order['unpaid_amount_uah'] ?? 0);
    $paid = $total - $unpaid;

    if ($unpaid <= 0) {
        return '<span class="status-badge status-badge--success">Оплачено</span>';
    }
    if ($paid > 0) {
        return '<span class="status-badge status-badge--warning">Частково</span>';
    }
    return '<span class="status-badge status-badge--danger">Не оплачено</span>';
}

function dashboard_progress_mini(float $progress): string
{
    $width = max(0, min(100, $progress));
    $cls = $progress >= 100 ? ' over' : '';
    return '<div class="progress-mini' . $cls . '"><span class="progress-track"><span style="width:' . e((string) $width) . '%"></span></span><span class="progress-pct">' . e((string) round($progress)) . '%</span></div>';
}

$notCanceledSql = "
    LOWER(COALESCE(status_name, '')) NOT LIKE '%cancel%'
    AND LOWER(COALESCE(status_name, '')) NOT LIKE '%deleted%'
    AND LOWER(COALESCE(status_name, '')) NOT LIKE '%скас%'
    AND LOWER(COALESCE(payment_status, '')) NOT LIKE '%cancel%'
    AND LOWER(COALESCE(payment_status, '')) NOT LIKE '%deleted%'
    AND LOWER(COALESCE(payment_status, '')) NOT LIKE '%скас%'
";

try {
    $companyTarget = active_company_target(db(), $selectedMonth);
    $monthlyTarget = (float) $companyTarget['amount_uah'];

    $metrics = db()->prepare("
        SELECT
            COUNT(*) AS order_count,
            COALESCE(SUM(total_amount_uah), 0) AS sales_fact,
            COALESCE(SUM(paid_amount_uah), 0) AS paid,
            COALESCE(SUM(unpaid_amount_uah), 0) AS unpaid
        FROM db_orders
        WHERE order_month = :month
          AND {$notCanceledSql}
    ");
    $metrics->execute(['month' => $selectedMonth]);
    $row = $metrics->fetch() ?: [];

    $orderCount = (int) ($row['order_count'] ?? 0);
    $salesFact = (float) ($row['sales_fact'] ?? 0);
    $paid = (float) ($row['paid'] ?? 0);
    $monthlyUnpaid = (float) ($row['unpaid'] ?? 0);
    $remaining = max($monthlyTarget - $salesFact, 0);
    $progress = $monthlyTarget > 0 ? min(100, round(($salesFact / $monthlyTarget) * 100, 1)) : 0;

    if ($monthEnd >= $today && $remaining > 0) {
        $daysFrom = $monthStart > $today ? $monthStart : $today;
        $remainingDays = max(1, (int) $daysFrom->diff($monthEnd)->format('%a') + 1);
        $dailyRequiredLabel = money_uah($remaining / $remainingDays) . ' / день';
    } elseif ($remaining <= 0) {
        $dailyRequiredLabel = 'план виконано';
    }

    $lastSyncStmt = db()->query("SELECT finished_at FROM db_sync_runs WHERE status = 'success' ORDER BY finished_at DESC LIMIT 1");
    $lastSyncAt = $lastSyncStmt->fetchColumn() ?: null;

    $receivablesStmt = db()->query("
        SELECT
            COALESCE(SUM(unpaid_amount_uah), 0) AS total_unpaid,
            COUNT(*) AS unpaid_count,
            COALESCE(MAX(unpaid_amount_uah), 0) AS largest_unpaid
        FROM db_orders
        WHERE unpaid_amount_uah > 0
          AND {$notCanceledSql}
    ");
    $receivables = $receivablesStmt->fetch() ?: [];
    $receivablesTotal = (float) ($receivables['total_unpaid'] ?? 0);
    $receivablesCount = (int) ($receivables['unpaid_count'] ?? 0);
    $largestReceivable = (float) ($receivables['largest_unpaid'] ?? 0);

    $receivablesByManagerStmt = db()->query("
        SELECT
            COALESCE(NULLIF(manager_name, ''), 'No manager') AS manager_name,
            COALESCE(SUM(unpaid_amount_uah), 0) AS total_unpaid,
            COUNT(*) AS unpaid_count,
            COALESCE(MAX(unpaid_amount_uah), 0) AS largest_unpaid
        FROM db_orders
        WHERE unpaid_amount_uah > 0
          AND {$notCanceledSql}
        GROUP BY COALESCE(NULLIF(manager_name, ''), 'No manager')
        ORDER BY total_unpaid DESC
    ");
    $receivablesByManager = $receivablesByManagerStmt->fetchAll();

    $debtWhere = "unpaid_amount_uah > 0 AND {$notCanceledSql}";
    $debtParams = [];
    if ($debtManager !== '') {
        $debtWhere .= " AND COALESCE(NULLIF(manager_name, ''), 'No manager') = :debt_manager";
        $debtParams['debt_manager'] = $debtManager;
    }

    $filteredTotalsStmt = db()->prepare("
        SELECT
            COALESCE(SUM(unpaid_amount_uah), 0) AS total_unpaid,
            COUNT(*) AS unpaid_count
        FROM db_orders
        WHERE {$debtWhere}
    ");
    $filteredTotalsStmt->execute($debtParams);
    $filteredTotals = $filteredTotalsStmt->fetch() ?: [];
    $filteredReceivablesTotal = (float) ($filteredTotals['total_unpaid'] ?? 0);
    $filteredReceivablesCount = (int) ($filteredTotals['unpaid_count'] ?? 0);
    $totalDebtPages = max(1, (int) ceil($filteredReceivablesCount / $debtPerPage));
    if ($debtPage > $totalDebtPages) {
        $debtPage = $totalDebtPages;
        $debtOffset = ($debtPage - 1) * $debtPerPage;
    }

    $debtStmt = db()->prepare("
        SELECT
            order_number,
            ordered_at,
            client_name,
            buyer_name,
            company_name,
            manager_name,
            total_amount_uah,
            paid_amount_uah,
            unpaid_amount_uah,
            payment_status,
            status_name
        FROM db_orders
        WHERE {$debtWhere}
        ORDER BY unpaid_amount_uah DESC, ordered_at DESC
        LIMIT :limit OFFSET :offset
    ");
    foreach ($debtParams as $key => $value) {
        $debtStmt->bindValue(':' . $key, $value);
    }
    $debtStmt->bindValue(':limit', $debtPerPage, PDO::PARAM_INT);
    $debtStmt->bindValue(':offset', $debtOffset, PDO::PARAM_INT);
    $debtStmt->execute();
    $receivableOrders = $debtStmt->fetchAll();

    $monthlyUnpaidStmt = db()->prepare("
        SELECT
            order_number,
            client_name,
            buyer_name,
            company_name,
            manager_name,
            unpaid_amount_uah,
            payment_status,
            status_name
        FROM db_orders
        WHERE order_month = :month
          AND unpaid_amount_uah > 0
          AND {$notCanceledSql}
        ORDER BY unpaid_amount_uah DESC, ordered_at DESC
        LIMIT 10
    ");
    $monthlyUnpaidStmt->execute(['month' => $selectedMonth]);
    $monthlyUnpaidOrders = $monthlyUnpaidStmt->fetchAll();

    $managerStmt = db()->prepare("
        SELECT
            COALESCE(NULLIF(manager_name, ''), 'No manager') AS manager_name,
            COUNT(*) AS order_count,
            COALESCE(SUM(total_amount_uah), 0) AS sales_fact,
            COALESCE(SUM(paid_amount_uah), 0) AS paid,
            COALESCE(SUM(unpaid_amount_uah), 0) AS unpaid
        FROM db_orders
        WHERE order_month = :month
          AND {$notCanceledSql}
        GROUP BY COALESCE(NULLIF(manager_name, ''), 'No manager')
        ORDER BY sales_fact DESC
    ");
    $managerStmt->execute(['month' => $selectedMonth]);
    $managerSummary = $managerStmt->fetchAll();

    $managerNames = array_map(static function (array $manager): string {
        return (string) $manager['manager_name'];
    }, $managerSummary);
    $managerTargets = active_manager_targets(db(), $selectedMonth, $managerNames);

    foreach ($managerSummary as &$manager) {
        $targetData = $managerTargets[(string) $manager['manager_name']] ?? ['amount_uah' => 0, 'is_fallback' => true];
        $target = (float) ($targetData['amount_uah'] ?? 0);
        $fact = (float) ($manager['sales_fact'] ?? 0);
        $manager['target_amount_uah'] = $target;
        $manager['has_target'] = $target > 0 && empty($targetData['is_fallback']);
        $manager['target_effective_from'] = $targetData['effective_from'] ?? null;
        $manager['remaining_to_target'] = $manager['has_target'] ? max($target - $fact, 0) : null;
        $manager['progress'] = $manager['has_target'] ? min(100, round(($fact / $target) * 100, 1)) : null;
    }
    unset($manager);

    $operationalStmt = db()->prepare("
        SELECT COALESCE(SUM(GREATEST(amount_uah - paid_amount_uah, 0)), 0)
        FROM db_expenses
        WHERE status = 'planned'
          AND is_strategic = 0
          AND expense_type <> 'strategic_debt'
          AND (
            (due_date BETWEEN :month_start AND :month_end)
            OR (
                expense_type = 'monthly_subscription'
                AND repeat_day IS NOT NULL
                AND (due_date IS NULL OR due_date <= :month_end_repeat)
                AND (repeat_until IS NULL OR repeat_until >= :month_start_repeat)
            )
          )
    ");
    $operationalStmt->execute([
        'month_start' => $monthStart->format('Y-m-d'),
        'month_end' => $monthEnd->format('Y-m-d'),
        'month_end_repeat' => $monthEnd->format('Y-m-d'),
        'month_start_repeat' => $monthStart->format('Y-m-d'),
    ]);
    $operationalDueThisMonth = (float) ($operationalStmt->fetchColumn() ?: 0);

    $strategicStmt = db()->query("
        SELECT COALESCE(SUM(GREATEST(COALESCE(total_debt_amount_uah, amount_uah) - paid_amount_uah, 0)), 0)
        FROM db_expenses
        WHERE status <> 'canceled'
          AND (is_strategic = 1 OR expense_type = 'strategic_debt')
    ");
    $strategicDebtTotal = (float) ($strategicStmt->fetchColumn() ?: 0);

    $weekStart = $today->modify('-' . ((int) $today->format('N') - 1) . ' days');
    $weekEnd = $weekStart->modify('+6 days');
    $weeklyStmt = db()->prepare("
        SELECT COALESCE(SUM(GREATEST(amount_uah - paid_amount_uah, 0)), 0)
        FROM db_expenses
        WHERE status = 'planned'
          AND is_strategic = 0
          AND expense_type <> 'strategic_debt'
          AND due_date BETWEEN :week_start AND :week_end
    ");
    $weeklyStmt->execute([
        'week_start' => $weekStart->format('Y-m-d'),
        'week_end' => $weekEnd->format('Y-m-d'),
    ]);
    $operationalDueThisWeek = (float) ($weeklyStmt->fetchColumn() ?: 0);

    $overdueStmt = db()->prepare("
        SELECT COALESCE(SUM(GREATEST(amount_uah - paid_amount_uah, 0)), 0) AS overdue_total, COUNT(*) AS overdue_count
        FROM db_expenses
        WHERE status = 'planned'
          AND is_strategic = 0
          AND expense_type <> 'strategic_debt'
          AND due_date < :today
    ");
    $overdueStmt->execute(['today' => $today->format('Y-m-d')]);
    $overdueRow = $overdueStmt->fetch() ?: [];
    $overdueTotal = (float) ($overdueRow['overdue_total'] ?? 0);
    $overdueCount = (int) ($overdueRow['overdue_count'] ?? 0);
} catch (Throwable $e) {
    $dashboardError = 'Dashboard data is not available yet. Run CEO sync after production config is ready.';
}
?>
<!doctype html>
<html lang="uk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>.BRAND DB — Дашборд</title>
    <link rel="stylesheet" href="<?= e(asset_path('/assets/app.css')) ?>">
</head>
<body>
    <main class="page dashboard-page">
        <header class="dashboard-header">
            <div class="brand-block">
                <p class="eyebrow">Money dashboard</p>
                <h1>.BRAND DB</h1>
                <p class="muted">Синхронізація: <?= e($lastSyncAt ?: 'ще не було') ?></p>
            </div>
            <form class="month-picker" method="get" action="<?= e(base_path('/index.php')) ?>">
                <label>
                    <span>Місяць</span>
                    <input type="month" name="month" value="<?= e($selectedMonth) ?>">
                </label>
                <?php if ($debtManager !== ''): ?>
                    <input type="hidden" name="debt_manager" value="<?= e($debtManager) ?>">
                <?php endif; ?>
                <button type="submit" class="small-button">Показати</button>
            </form>
            <div class="header-actions">
                <span class="sync-pill"><?= e(format_user_name($user)) ?> · <?= e((string) ($user['db_role'] ?? 'none')) ?></span>
                <nav class="nav">
                    <a class="active" href="<?= e(base_path('/index.php')) ?>">Дашборд</a>
                    <?php if (user_role() === 'ceo'): ?>
                        <a href="<?= e(base_path('/targets.php?month=' . urlencode($selectedMonth))) ?>">Плани</a>
                    <?php endif; ?>
                    <?php if (can_manage_expenses()): ?>
                        <a href="<?= e(base_path('/invoices.php')) ?>">Рахунки</a>
                        <a href="<?= e(base_path('/expenses.php?month=' . urlencode($selectedMonth))) ?>">Витрати</a>
                    <?php endif; ?>
                    <?php if (user_role() === 'ceo'): ?>
                        <a href="<?= e(base_path('/sync_orders.php')) ?>">Синхронізація</a>
                        <a href="<?= e(base_path('/users.php')) ?>">Користувачі</a>
                    <?php endif; ?>
                    <a href="<?= e(base_path('/logout.php')) ?>">Вийти</a>
                </nav>
            </div>
        </header>

        <?php if ($dashboardError !== ''): ?>
            <div class="alert"><?= e($dashboardError) ?></div>
        <?php endif; ?>

        <section class="kpi-grid dashboard-kpis" aria-label="Ключові показники">
            <div class="kpi-card target">
                <span class="label">План</span>
                <strong><?= e(money_uah($monthlyTarget)) ?></strong>
            </div>
            <div class="kpi-card">
                <span class="label">Факт</span>
                <strong><?= e(money_uah($salesFact)) ?></strong>
                <small><?= e((string) $orderCount) ?> замовлень</small>
            </div>
            <div class="kpi-card">
                <span class="label">Оплачено</span>
                <strong><?= e(money_uah($paid)) ?></strong>
            </div>
            <div class="kpi-card warn">
                <span class="label">Не оплачено за місяць</span>
                <strong><?= e(money_uah($monthlyUnpaid)) ?></strong>
            </div>
            <div class="kpi-card danger">
                <span class="label">Нам повинні всього</span>
                <strong><?= e(money_uah($receivablesTotal)) ?></strong>
                <small><?= e((string) $receivablesCount) ?> замовлень</small>
            </div>
            <div class="kpi-card">
                <span class="label">Ми повинні цього місяця</span>
                <strong><?= e(money_uah($operationalDueThisMonth)) ?></strong>
                <small>операційні</small>
            </div>
            <div class="kpi-card progress-card">
                <span class="label">Прогрес</span>
                <strong><?= e((string) $progress) ?>%</strong>
                <small>залишилось <?= e(money_uah($remaining)) ?></small>
            </div>
        </section>

        <section class="panel dashboard-section">
            <div class="section-heading">
                <div>
                    <span class="label"><?= e($monthLabel) ?><?= !empty($companyTarget['effective_from']) ? ' · план з ' . e((string) $companyTarget['effective_from']) : ' · fallback' ?></span>
                    <h2>План продажів</h2>
                </div>
                <strong><?= e((string) $progress) ?>%</strong>
            </div>
            <div class="progress-track" aria-label="Прогрес виконання плану">
                <span style="width: <?= e((string) $progress) ?>%"></span>
            </div>
            <dl class="plan-list">
                <div>
                    <dt>План</dt>
                    <dd><?= e(money_uah($monthlyTarget)) ?></dd>
                </div>
                <div>
                    <dt>Факт</dt>
                    <dd><?= e(money_uah($salesFact)) ?></dd>
                </div>
                <div>
                    <dt>Залишилось</dt>
                    <dd><?= e(money_uah($remaining)) ?></dd>
                </div>
                <div>
                    <dt>Потрібно в день</dt>
                    <dd><?= e($dailyRequiredLabel) ?></dd>
                </div>
            </dl>
        </section>

        <section class="panel table-panel dashboard-section">
            <div class="section-heading padded">
                <div>
                    <span class="label">Менеджери</span>
                    <h2>План продажів по менеджерах</h2>
                </div>
                <?php if (user_role() === 'ceo'): ?>
                    <a class="button-secondary small-button" href="<?= e(base_path('/targets.php?month=' . urlencode($selectedMonth))) ?>">Редагувати плани</a>
                <?php endif; ?>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Менеджер</th>
                            <th class="num">План</th>
                            <th class="num">Факт</th>
                            <th>%</th>
                            <th class="num">Оплачено</th>
                            <th class="num">Борг</th>
                            <th class="num">Залишилось</th>
                            <th class="num">Замовлень</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$managerSummary): ?>
                            <tr><td colspan="8">Немає даних по менеджерах за <?= e($monthLabel) ?>.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($managerSummary as $manager): ?>
                            <tr>
                                <td><?= e(dashboard_manager_key($manager['manager_name'] ?? '')) ?></td>
                                <td class="num">
                                    <?php if (!empty($manager['has_target'])): ?>
                                        <?= e(money_uah($manager['target_amount_uah'] ?? 0)) ?>
                                    <?php else: ?>
                                        <span class="status-badge status-badge--muted">не задано</span>
                                    <?php endif; ?>
                                </td>
                                <td class="num"><?= e(money_uah($manager['sales_fact'] ?? 0)) ?></td>
                                <td><?= !empty($manager['has_target']) ? dashboard_progress_mini((float) ($manager['progress'] ?? 0)) : '—' ?></td>
                                <td class="num"><?= e(money_uah($manager['paid'] ?? 0)) ?></td>
                                <td class="num"><?= e(money_uah($manager['unpaid'] ?? 0)) ?></td>
                                <td class="num"><?= !empty($manager['has_target']) ? e(money_uah($manager['remaining_to_target'] ?? 0)) : '—' ?></td>
                                <td class="num"><?= e((string) $manager['order_count']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="panel table-panel dashboard-section">
            <div class="section-heading padded">
                <div>
                    <span class="label">Усі місяці · <?= e((string) $receivablesCount) ?> замовлень · найбільше <?= e(money_uah($largestReceivable)) ?></span>
                    <h2><?= $debtManager !== '' ? 'Борги менеджера: ' . e(dashboard_manager_key($debtManager)) : 'Нам повинні' ?> — <?= e(money_uah($debtManager !== '' ? $filteredReceivablesTotal : $receivablesTotal)) ?></h2>
                    <?php if ($debtManager !== ''): ?>
                        <p class="muted">Фільтр: <?= e(dashboard_manager_key($debtManager)) ?> · <?= e(money_uah($filteredReceivablesTotal)) ?> (<?= e((string) $filteredReceivablesCount) ?>)</p>
                    <?php endif; ?>
                </div>
                <div class="pagination">
                    <?php if ($debtManager !== ''): ?>
                        <a href="<?= e(dashboard_url(['month' => $selectedMonth])) ?>">Показати всі</a>
                    <?php endif; ?>
                    <?php if ($debtPage > 1): ?>
                        <a href="<?= e(dashboard_url(['month' => $selectedMonth, 'debt_manager' => $debtManager, 'debt_page' => $debtPage - 1])) ?>">Назад</a>
                    <?php endif; ?>
                    <span><?= e((string) $debtPage) ?> / <?= e((string) $totalDebtPages) ?></span>
                    <?php if ($debtPage < $totalDebtPages): ?>
                        <a href="<?= e(dashboard_url(['month' => $selectedMonth, 'debt_manager' => $debtManager, 'debt_page' => $debtPage + 1])) ?>">Далі</a>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($receivablesByManager): ?>
                <div class="table-wrap">
                    <table class="compact-table">
                        <thead>
                            <tr>
                                <th>Менеджер</th>
                                <th class="num">Борг всього</th>
                                <th class="num">Замовлень</th>
                                <th class="num">Найбільше</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($receivablesByManager as $manager): ?>
                                <?php $managerName = (string) $manager['manager_name']; ?>
                                <tr>
                                    <td>
                                        <a href="<?= e(dashboard_url(['month' => $selectedMonth, 'debt_manager' => $managerName])) ?>">
                                            <?= e(dashboard_manager_key($managerName)) ?>
                                        </a>
                                    </td>
                                    <td class="num"><?= e(money_uah($manager['total_unpaid'] ?? 0)) ?></td>
                                    <td class="num"><?= e((string) $manager['unpaid_count']) ?></td>
                                    <td class="num"><?= e(money_uah($manager['largest_unpaid'] ?? 0)) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <div class="table-wrap table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>№</th>
                            <th>Дата</th>
                            <th>Клієнт</th>
                            <th>Менеджер</th>
                            <th class="num">Сума</th>
                            <th class="num">Оплачено</th>
                            <th class="num">Борг</th>
                            <th>Оплата</th>
                            <th>Статус</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$receivableOrders): ?>
                            <tr><td colspan="9">Несплачених замовлень не знайдено.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($receivableOrders as $order): ?>
                            <tr>
                                <td><?= e((string) ($order['order_number'] ?: '—')) ?></td>
                                <td><?= e((string) ($order['ordered_at'] ?: '—')) ?></td>
                                <td><?= e(dashboard_client_name($order)) ?></td>
                                <td><?= e(dashboard_manager_key($order['manager_name'] ?? '')) ?></td>
                                <td class="num"><?= e(money_uah($order['total_amount_uah'] ?? 0)) ?></td>
                                <td class="num"><?= e(money_uah($order['paid_amount_uah'] ?? 0)) ?></td>
                                <td class="num"><strong><?= e(money_uah($order['unpaid_amount_uah'] ?? 0)) ?></strong></td>
                                <td><?= dashboard_payment_badge($order) ?></td>
                                <td><span class="status-badge status-badge--muted"><?= e((string) ($order['status_name'] ?: '—')) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="panel table-panel dashboard-section">
            <div class="section-heading padded">
                <div>
                    <span class="label"><?= e($monthLabel) ?></span>
                    <h2>Топ несплачених за місяць</h2>
                </div>
            </div>
            <div class="table-wrap">
                <table class="compact-table">
                    <thead>
                        <tr>
                            <th>№</th>
                            <th>Клієнт</th>
                            <th>Менеджер</th>
                            <th class="num">Борг</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$monthlyUnpaidOrders): ?>
                            <tr><td colspan="4">Немає несплачених замовлень за <?= e($monthLabel) ?>.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($monthlyUnpaidOrders as $order): ?>
                            <tr>
                                <td><?= e((string) ($order['order_number'] ?: '—')) ?></td>
                                <td><?= e(dashboard_client_name($order)) ?></td>
                                <td><?= e(dashboard_manager_key($order['manager_name'] ?? '')) ?></td>
                                <td class="num"><strong><?= e(money_uah($order['unpaid_amount_uah'] ?? 0)) ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="panel dashboard-section">
            <div class="section-heading">
                <div>
                    <span class="label">Операційний тиск окремо від стратегічного</span>
                    <h2>Ми повинні</h2>
                </div>
                <?php if (can_manage_expenses()): ?>
                    <a class="button-secondary small-button" href="<?= e(base_path('/expenses.php?month=' . urlencode($selectedMonth))) ?>">Керувати витратами</a>
                <?php endif; ?>
            </div>
            <dl class="plan-list">
                <div>
                    <dt>Операційні платежі цього місяця</dt>
                    <dd><?= e(money_uah($operationalDueThisMonth)) ?></dd>
                </div>
                <div>
                    <dt>Платежі цього тижня</dt>
                    <dd><?= e(money_uah($operationalDueThisWeek)) ?></dd>
                </div>
                <div>
                    <dt>Прострочені платежі</dt>
                    <dd><?= e(money_uah($overdueTotal)) ?><?php if ($overdueCount > 0): ?> <small>· <?= e((string) $overdueCount) ?></small><?php endif; ?></dd>
                </div>
                <div>
                    <dt>Стратегічні борги</dt>
                    <dd><?= e(money_uah($strategicDebtTotal)) ?></dd>
                </div>
            </dl>
        </section>
    </main>
</body>
</html>
