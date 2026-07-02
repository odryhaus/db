<?php

require_once __DIR__ . '/bootstrap.php';
require_login();

$user = current_user();
$selectedMonth = (string) ($_GET['month'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $selectedMonth)) {
    $selectedMonth = date('Y-m');
}

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
$remaining = $monthlyTarget;
$progress = 0;
$orderCount = 0;
$dailyRequired = null;
$dailyRequiredLabel = 'month closed';
$lastSyncAt = null;
$monthlyUnpaidOrders = [];
$receivableOrders = [];
$managerSummary = [];
$dashboardError = '';

function money_uah($amount): string
{
    return number_format((float) $amount, 0, '.', ' ') . ' UAH';
}

function dashboard_client_name(array $order): string
{
    return (string) ($order['company_name'] ?: ($order['buyer_name'] ?: ($order['client_name'] ?: '—')));
}

function dashboard_month_url(string $selectedMonth, int $debtPage): string
{
    return base_path('/index.php') . '?' . http_build_query([
        'month' => $selectedMonth,
        'debt_page' => $debtPage,
    ]);
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
        $dailyRequired = $remaining / $remainingDays;
        $dailyRequiredLabel = money_uah($dailyRequired) . ' / day';
    } elseif ($remaining <= 0) {
        $dailyRequiredLabel = 'target reached';
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
    $totalDebtPages = max(1, (int) ceil($receivablesCount / $debtPerPage));
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
        WHERE unpaid_amount_uah > 0
          AND {$notCanceledSql}
        ORDER BY unpaid_amount_uah DESC, ordered_at DESC
        LIMIT :limit OFFSET :offset
    ");
    $debtStmt->bindValue(':limit', $debtPerPage, PDO::PARAM_INT);
    $debtStmt->bindValue(':offset', $debtOffset, PDO::PARAM_INT);
    $debtStmt->execute();
    $receivableOrders = $debtStmt->fetchAll();

    $monthlyUnpaidStmt = db()->prepare("
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
} catch (Throwable $e) {
    $dashboardError = 'Dashboard data is not available yet. Run CEO sync after production config is ready.';
    $totalDebtPages = 1;
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
                <p class="eyebrow">Money dashboard v0.3</p>
                <h1>.BRAND DB</h1>
                <p class="muted">Logged in: <?= e(format_user_name($user)) ?> · <?= e((string) ($user['db_role'] ?? 'none')) ?></p>
            </div>
            <form class="month-picker" method="get" action="<?= e(base_path('/index.php')) ?>">
                <label>
                    <span>Selected month</span>
                    <input type="month" name="month" value="<?= e($selectedMonth) ?>">
                </label>
                <button type="submit" class="small-button">Apply</button>
            </form>
            <div class="header-actions">
                <span class="sync-pill">Last sync: <?= e($lastSyncAt ?: 'not synced yet') ?></span>
                <nav class="nav">
                    <?php if (user_role() === 'ceo'): ?>
                        <a href="<?= e(base_path('/sync_orders.php')) ?>">Sync Orders</a>
                        <a href="<?= e(base_path('/users.php')) ?>">Users</a>
                    <?php endif; ?>
                    <a href="<?= e(base_path('/logout.php')) ?>">Logout</a>
                </nav>
            </div>
        </header>

        <?php if ($dashboardError !== ''): ?>
            <div class="alert"><?= e($dashboardError) ?></div>
        <?php endif; ?>

        <section class="kpi-grid" aria-label="Money KPIs">
            <div class="kpi-card target">
                <span class="label">Monthly target</span>
                <strong><?= e(money_uah($monthlyTarget)) ?></strong>
            </div>
            <div class="kpi-card">
                <span class="label">Sales fact</span>
                <strong><?= e(money_uah($salesFact)) ?></strong>
                <small><?= e((string) $orderCount) ?> orders in <?= e($monthLabel) ?></small>
            </div>
            <div class="kpi-card">
                <span class="label">Paid</span>
                <strong><?= e(money_uah($paid)) ?></strong>
            </div>
            <div class="kpi-card warn">
                <span class="label">Unpaid this month</span>
                <strong><?= e(money_uah($monthlyUnpaid)) ?></strong>
            </div>
            <div class="kpi-card danger">
                <span class="label">Нам повинні всього</span>
                <strong><?= e(money_uah($receivablesTotal)) ?></strong>
                <small><?= e((string) $receivablesCount) ?> unpaid orders</small>
            </div>
            <div class="kpi-card">
                <span class="label">Remaining to target</span>
                <strong><?= e(money_uah($remaining)) ?></strong>
            </div>
            <div class="kpi-card progress-card">
                <span class="label">Progress</span>
                <strong><?= e((string) $progress) ?>%</strong>
            </div>
        </section>

        <section class="dashboard-grid">
            <div class="panel plan-panel">
                <div class="section-heading">
                    <div>
                        <span class="label">Monthly plan</span>
                        <h2><?= e($monthLabel) ?></h2>
                    </div>
                    <strong><?= e((string) $progress) ?>%</strong>
                </div>
                <div class="progress-track" aria-label="Progress toward monthly target">
                    <span style="width: <?= e((string) $progress) ?>%"></span>
                </div>
                <dl class="plan-list">
                    <div>
                        <dt>Target</dt>
                        <dd><?= e(money_uah($monthlyTarget)) ?></dd>
                    </div>
                    <div>
                        <dt>Fact</dt>
                        <dd><?= e(money_uah($salesFact)) ?></dd>
                    </div>
                    <div>
                        <dt>Remaining</dt>
                        <dd><?= e(money_uah($remaining)) ?></dd>
                    </div>
                    <div>
                        <dt>Daily required</dt>
                        <dd><?= e($dailyRequiredLabel) ?></dd>
                    </div>
                </dl>
            </div>

            <div class="panel plan-panel">
                <div class="section-heading">
                    <div>
                        <span class="label">Receivables action</span>
                        <h2>Нам повинні</h2>
                    </div>
                </div>
                <dl class="plan-list">
                    <div>
                        <dt>Total receivables</dt>
                        <dd><?= e(money_uah($receivablesTotal)) ?></dd>
                    </div>
                    <div>
                        <dt>Unpaid orders</dt>
                        <dd><?= e((string) $receivablesCount) ?></dd>
                    </div>
                    <div>
                        <dt>Largest unpaid order</dt>
                        <dd><?= e(money_uah($largestReceivable)) ?></dd>
                    </div>
                </dl>
            </div>
        </section>

        <section class="panel table-panel dashboard-section">
            <div class="section-heading padded">
                <div>
                    <span class="label">All months</span>
                    <h2>Нам повинні</h2>
                </div>
                <div class="pagination">
                    <?php if ($debtPage > 1): ?>
                        <a href="<?= e(dashboard_month_url($selectedMonth, $debtPage - 1)) ?>">Prev</a>
                    <?php endif; ?>
                    <span>Page <?= e((string) $debtPage) ?> / <?= e((string) $totalDebtPages) ?></span>
                    <?php if ($debtPage < $totalDebtPages): ?>
                        <a href="<?= e(dashboard_month_url($selectedMonth, $debtPage + 1)) ?>">Next</a>
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
                                <td><?= e((string) ($order['manager_name'] ?: '—')) ?></td>
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
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$monthlyUnpaidOrders): ?>
                                <tr><td colspan="5">No unpaid orders found for <?= e($monthLabel) ?>.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($monthlyUnpaidOrders as $order): ?>
                                <tr>
                                    <td><?= e((string) ($order['order_number'] ?: '—')) ?></td>
                                    <td><?= e(dashboard_client_name($order)) ?></td>
                                    <td><?= e((string) ($order['manager_name'] ?: '—')) ?></td>
                                    <td><strong><?= e(money_uah($order['unpaid_amount_uah'] ?? 0)) ?></strong></td>
                                    <td><?= e((string) ($order['payment_status'] ?: ($order['status_name'] ?: '—'))) ?></td>
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
                        <h2>Manager summary</h2>
                    </div>
                </div>
                <div class="table-wrap">
                    <table class="compact-table">
                        <thead>
                            <tr>
                                <th>Manager</th>
                                <th>Sales</th>
                                <th>Paid</th>
                                <th>Unpaid</th>
                                <th>Orders</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$managerSummary): ?>
                                <tr><td colspan="5">No manager data for <?= e($monthLabel) ?>.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($managerSummary as $manager): ?>
                                <tr>
                                    <td><?= e((string) $manager['manager_name']) ?></td>
                                    <td><?= e(money_uah($manager['sales_fact'] ?? 0)) ?></td>
                                    <td><?= e(money_uah($manager['paid'] ?? 0)) ?></td>
                                    <td><?= e(money_uah($manager['unpaid'] ?? 0)) ?></td>
                                    <td><?= e((string) $manager['order_count']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </main>
</body>
</html>
