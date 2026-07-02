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
    return $name !== '' ? $name : 'No manager';
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
    $targetStmt = db()->prepare('SELECT target_amount_uah FROM db_monthly_targets WHERE month = :month LIMIT 1');
    $targetStmt->execute(['month' => $selectedMonth]);
    $savedTarget = $targetStmt->fetchColumn();
    if ($savedTarget !== false) {
        $monthlyTarget = (float) $savedTarget;
    }

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

    $managerTargetsStmt = db()->prepare('SELECT manager_name, target_amount_uah FROM db_manager_targets WHERE month = :month');
    $managerTargetsStmt->execute(['month' => $selectedMonth]);
    $managerTargets = [];
    foreach ($managerTargetsStmt->fetchAll() as $target) {
        $managerTargets[(string) $target['manager_name']] = (float) $target['target_amount_uah'];
    }

    foreach ($managerSummary as &$manager) {
        $target = $managerTargets[(string) $manager['manager_name']] ?? 0;
        $fact = (float) ($manager['sales_fact'] ?? 0);
        $manager['target_amount_uah'] = $target;
        $manager['remaining_to_target'] = max($target - $fact, 0);
        $manager['progress'] = $target > 0 ? min(100, round(($fact / $target) * 100, 1)) : 0;
    }
    unset($manager);

    foreach ($managerTargets as $managerName => $target) {
        $exists = false;
        foreach ($managerSummary as $manager) {
            if ((string) $manager['manager_name'] === $managerName) {
                $exists = true;
                break;
            }
        }
        if (!$exists && $target > 0) {
            $managerSummary[] = [
                'manager_name' => $managerName,
                'order_count' => 0,
                'sales_fact' => 0,
                'paid' => 0,
                'unpaid' => 0,
                'target_amount_uah' => $target,
                'remaining_to_target' => $target,
                'progress' => 0,
            ];
        }
    }

    $operationalStmt = db()->prepare("
        SELECT COALESCE(SUM(amount_uah), 0)
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
        SELECT COALESCE(SUM(COALESCE(total_debt_amount_uah, amount_uah) - paid_amount_uah), 0)
        FROM db_expenses
        WHERE status <> 'canceled'
          AND (is_strategic = 1 OR expense_type = 'strategic_debt')
    ");
    $strategicDebtTotal = (float) ($strategicStmt->fetchColumn() ?: 0);
} catch (Throwable $e) {
    $dashboardError = 'Dashboard data is not available yet. Run CEO sync after production config is ready.';
}
?>
<!doctype html>
<html lang="uk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>.BRAND DB</title>
    <link rel="stylesheet" href="<?= e(base_path('/assets/app.css')) ?>">
</head>
<body>
    <main class="page dashboard-page">
        <header class="dashboard-header">
            <div class="brand-block">
                <p class="eyebrow">Money dashboard</p>
                <h1>.BRAND DB</h1>
                <p class="muted">Last sync: <?= e($lastSyncAt ?: 'not synced yet') ?></p>
            </div>
            <form class="month-picker" method="get" action="<?= e(base_path('/index.php')) ?>">
                <label>
                    <span>Місяць</span>
                    <input type="month" name="month" value="<?= e($selectedMonth) ?>">
                </label>
                <?php if ($debtManager !== ''): ?>
                    <input type="hidden" name="debt_manager" value="<?= e($debtManager) ?>">
                <?php endif; ?>
                <button type="submit" class="small-button">Apply</button>
            </form>
            <div class="header-actions">
                <span class="sync-pill"><?= e(format_user_name($user)) ?> · <?= e((string) ($user['db_role'] ?? 'none')) ?></span>
                <nav class="nav">
                    <?php if (user_role() === 'ceo'): ?>
                        <a href="<?= e(base_path('/targets.php?month=' . urlencode($selectedMonth))) ?>">Targets</a>
                        <a href="<?= e(base_path('/sync_orders.php')) ?>">Sync Orders</a>
                        <a href="<?= e(base_path('/users.php')) ?>">Users</a>
                    <?php endif; ?>
                    <?php if (can_manage_expenses()): ?>
                        <a href="<?= e(base_path('/expenses.php?month=' . urlencode($selectedMonth))) ?>">Expenses</a>
                    <?php endif; ?>
                    <a href="<?= e(base_path('/logout.php')) ?>">Logout</a>
                </nav>
            </div>
        </header>

        <?php if ($dashboardError !== ''): ?>
            <div class="alert"><?= e($dashboardError) ?></div>
        <?php endif; ?>

        <section class="kpi-grid dashboard-kpis" aria-label="Money KPIs">
            <div class="kpi-card target">
                <span class="label">План</span>
                <strong><?= e(money_uah($monthlyTarget)) ?></strong>
            </div>
            <div class="kpi-card">
                <span class="label">Факт</span>
                <strong><?= e(money_uah($salesFact)) ?></strong>
                <small><?= e((string) $orderCount) ?> orders · <?= e($monthLabel) ?></small>
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
                <small><?= e((string) $receivablesCount) ?> orders</small>
            </div>
            <div class="kpi-card">
                <span class="label">Ми повинні цього місяця</span>
                <strong><?= e(money_uah($operationalDueThisMonth)) ?></strong>
                <small>operational only</small>
            </div>
            <div class="kpi-card danger">
                <span class="label">Стратегічні борги</span>
                <strong><?= e(money_uah($strategicDebtTotal)) ?></strong>
                <small>shown separately</small>
            </div>
            <div class="kpi-card progress-card">
                <span class="label">Progress</span>
                <strong><?= e((string) $progress) ?>%</strong>
                <small>remaining <?= e(money_uah($remaining)) ?></small>
            </div>
        </section>

        <section class="panel dashboard-section">
            <div class="section-heading">
                <div>
                    <span class="label">Target / fact / progress first</span>
                    <h2>План продажів</h2>
                </div>
                <strong><?= e((string) $progress) ?>%</strong>
            </div>
            <div class="progress-track" aria-label="Progress toward monthly target">
                <span style="width: <?= e((string) $progress) ?>%"></span>
            </div>
            <dl class="plan-list tight-plan">
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
                    <span class="label">Receivables second</span>
                    <h2>Нам повинні</h2>
                    <?php if ($debtManager !== ''): ?>
                        <p class="muted">Filter: <?= e($debtManager) ?> · <?= e(money_uah($filteredReceivablesTotal)) ?></p>
                    <?php endif; ?>
                </div>
                <div class="pagination">
                    <?php if ($debtManager !== ''): ?>
                        <a href="<?= e(dashboard_url(['month' => $selectedMonth])) ?>">All managers</a>
                    <?php endif; ?>
                    <?php if ($debtPage > 1): ?>
                        <a href="<?= e(dashboard_url(['month' => $selectedMonth, 'debt_manager' => $debtManager, 'debt_page' => $debtPage - 1])) ?>">Prev</a>
                    <?php endif; ?>
                    <span>Page <?= e((string) $debtPage) ?> / <?= e((string) $totalDebtPages) ?></span>
                    <?php if ($debtPage < $totalDebtPages): ?>
                        <a href="<?= e(dashboard_url(['month' => $selectedMonth, 'debt_manager' => $debtManager, 'debt_page' => $debtPage + 1])) ?>">Next</a>
                    <?php endif; ?>
                </div>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Order</th>
                            <th>Ordered</th>
                            <th>Client / buyer / company</th>
                            <th>Manager</th>
                            <th>Total</th>
                            <th>Paid</th>
                            <th>Unpaid</th>
                            <th>Payment</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$receivableOrders): ?>
                            <tr><td colspan="9">No unpaid client debt found.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($receivableOrders as $order): ?>
                            <tr>
                                <td><?= e((string) ($order['order_number'] ?: '—')) ?></td>
                                <td><?= e((string) ($order['ordered_at'] ?: '—')) ?></td>
                                <td><?= e(dashboard_client_name($order)) ?></td>
                                <td><?= e(dashboard_manager_key($order['manager_name'] ?? '')) ?></td>
                                <td><?= e(money_uah($order['total_amount_uah'] ?? 0)) ?></td>
                                <td><?= e(money_uah($order['paid_amount_uah'] ?? 0)) ?></td>
                                <td><strong><?= e(money_uah($order['unpaid_amount_uah'] ?? 0)) ?></strong></td>
                                <td><?= e((string) ($order['payment_status'] ?: '—')) ?></td>
                                <td><?= e((string) ($order['status_name'] ?: '—')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="dashboard-grid lower-grid">
            <div class="panel table-panel">
                <div class="section-heading padded">
                    <div>
                        <span class="label">Receivables by manager</span>
                        <h2>Debt drilldown</h2>
                    </div>
                </div>
                <div class="table-wrap">
                    <table class="compact-table">
                        <thead>
                            <tr>
                                <th>Manager</th>
                                <th>Total unpaid</th>
                                <th>Orders</th>
                                <th>Largest</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$receivablesByManager): ?>
                                <tr><td colspan="4">No receivables by manager.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($receivablesByManager as $manager): ?>
                                <?php $managerName = (string) $manager['manager_name']; ?>
                                <tr>
                                    <td>
                                        <a href="<?= e(dashboard_url(['month' => $selectedMonth, 'debt_manager' => $managerName])) ?>">
                                            <?= e($managerName) ?>
                                        </a>
                                    </td>
                                    <td><?= e(money_uah($manager['total_unpaid'] ?? 0)) ?></td>
                                    <td><?= e((string) $manager['unpaid_count']) ?></td>
                                    <td><?= e(money_uah($manager['largest_unpaid'] ?? 0)) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="panel table-panel">
                <div class="section-heading padded">
                    <div>
                        <span class="label">Selected month</span>
                        <h2>Top unpaid orders</h2>
                    </div>
                </div>
                <div class="table-wrap">
                    <table class="compact-table">
                        <thead>
                            <tr>
                                <th>Order</th>
                                <th>Client</th>
                                <th>Manager</th>
                                <th>Unpaid</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$monthlyUnpaidOrders): ?>
                                <tr><td colspan="4">No unpaid orders found for <?= e($monthLabel) ?>.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($monthlyUnpaidOrders as $order): ?>
                                <tr>
                                    <td><?= e((string) ($order['order_number'] ?: '—')) ?></td>
                                    <td><?= e(dashboard_client_name($order)) ?></td>
                                    <td><?= e(dashboard_manager_key($order['manager_name'] ?? '')) ?></td>
                                    <td><strong><?= e(money_uah($order['unpaid_amount_uah'] ?? 0)) ?></strong></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <section class="panel table-panel dashboard-section">
            <div class="section-heading padded">
                <div>
                    <span class="label">Manager performance third</span>
                    <h2>Manager targets</h2>
                </div>
                <?php if (user_role() === 'ceo'): ?>
                    <a class="button secondary" href="<?= e(base_path('/targets.php?month=' . urlencode($selectedMonth))) ?>">Edit targets</a>
                <?php endif; ?>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Manager</th>
                            <th>Target</th>
                            <th>Sales fact</th>
                            <th>Paid</th>
                            <th>Unpaid</th>
                            <th>Remaining</th>
                            <th>Progress</th>
                            <th>Orders</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$managerSummary): ?>
                            <tr><td colspan="8">No manager data for <?= e($monthLabel) ?>.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($managerSummary as $manager): ?>
                            <tr>
                                <td><?= e((string) $manager['manager_name']) ?></td>
                                <td><?= e(money_uah($manager['target_amount_uah'] ?? 0)) ?></td>
                                <td><?= e(money_uah($manager['sales_fact'] ?? 0)) ?></td>
                                <td><?= e(money_uah($manager['paid'] ?? 0)) ?></td>
                                <td><?= e(money_uah($manager['unpaid'] ?? 0)) ?></td>
                                <td><?= e(money_uah($manager['remaining_to_target'] ?? 0)) ?></td>
                                <td><?= e((string) ($manager['progress'] ?? 0)) ?>%</td>
                                <td><?= e((string) $manager['order_count']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="panel dashboard-section">
            <div class="section-heading">
                <div>
                    <span class="label">Expenses fourth</span>
                    <h2>Upcoming expenses foundation</h2>
                </div>
                <?php if (can_manage_expenses()): ?>
                    <a class="button secondary" href="<?= e(base_path('/expenses.php?month=' . urlencode($selectedMonth))) ?>">Manage expenses</a>
                <?php endif; ?>
            </div>
            <dl class="plan-list tight-plan">
                <div>
                    <dt>Ми повинні цього місяця</dt>
                    <dd><?= e(money_uah($operationalDueThisMonth)) ?></dd>
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
