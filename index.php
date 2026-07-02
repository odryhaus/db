<?php

require_once __DIR__ . '/bootstrap.php';
require_login();

$user = current_user();
$selectedMonth = (string) ($_GET['month'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $selectedMonth)) {
    $selectedMonth = date('Y-m');
}

$monthDate = DateTime::createFromFormat('!Y-m', $selectedMonth) ?: new DateTime('first day of this month');
$monthLabel = $monthDate->format('F Y');
$monthlyTarget = 4000000;
$salesFact = 0;
$paid = 0;
$unpaid = 0;
$weOwe = 0;
$remaining = $monthlyTarget;
$progress = 0;
$orderCount = 0;
$lastSyncAt = null;
$topUnpaidOrders = [];
$dashboardError = '';

function money_uah($amount): string
{
    return number_format((float) $amount, 0, '.', ' ') . ' UAH';
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
    $unpaid = (float) ($row['unpaid'] ?? 0);
    $remaining = max($monthlyTarget - $salesFact, 0);
    $progress = $monthlyTarget > 0 ? min(100, round(($salesFact / $monthlyTarget) * 100, 1)) : 0;

    $lastSyncStmt = db()->query("SELECT finished_at FROM db_sync_runs WHERE status = 'success' ORDER BY finished_at DESC LIMIT 1");
    $lastSyncAt = $lastSyncStmt->fetchColumn() ?: null;

    $unpaidStmt = db()->prepare("
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
            payment_status
        FROM db_orders
        WHERE order_month = :month
          AND unpaid_amount_uah > 0
          AND {$notCanceledSql}
        ORDER BY unpaid_amount_uah DESC, ordered_at DESC
        LIMIT 10
    ");
    $unpaidStmt->execute(['month' => $selectedMonth]);
    $topUnpaidOrders = $unpaidStmt->fetchAll();
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
    <main class="page">
        <header class="topbar">
            <div>
                <p class="eyebrow">Money dashboard v0.1</p>
                <h1>.BRAND DB</h1>
            </div>
            <nav class="nav">
                <?php if (user_role() === 'ceo'): ?>
                    <a href="<?= e(base_path('/users.php')) ?>">Users</a>
                    <a href="<?= e(base_path('/sync_orders.php')) ?>">Sync Orders</a>
                <?php endif; ?>
                <a href="<?= e(base_path('/logout.php')) ?>">Logout</a>
            </nav>
        </header>

        <?php if ($dashboardError !== ''): ?>
            <div class="alert"><?= e($dashboardError) ?></div>
        <?php endif; ?>

        <section class="panel dashboard-hero">
            <div class="dashboard-hero-main">
                <span class="label">Selected month</span>
                <strong><?= e($monthLabel) ?></strong>
                <h2>Monthly target: <?= e(money_uah($monthlyTarget)) ?></h2>
                <div class="progress-track" aria-label="Progress toward monthly target">
                    <span style="width: <?= e((string) $progress) ?>%"></span>
                </div>
                <div class="progress-row">
                    <span>Progress</span>
                    <strong><?= e((string) $progress) ?>%</strong>
                </div>
                <form class="month-form" method="get" action="<?= e(base_path('/index.php')) ?>">
                    <label>
                        <span>Month</span>
                        <input type="month" name="month" value="<?= e($selectedMonth) ?>">
                    </label>
                    <button type="submit" class="small-button">Apply</button>
                </form>
            </div>
            <div class="dashboard-user">
                <div>
                    <span class="label">Logged in as</span>
                    <strong><?= e(format_user_name($user)) ?></strong>
                    <small><?= e($user['email'] ?? '') ?></small>
                </div>
                <div>
                    <span class="label">Role</span>
                    <strong><?= e($user['db_role'] ?? 'none') ?></strong>
                </div>
                <div>
                    <span class="label">Last sync</span>
                    <strong><?= e($lastSyncAt ?: 'not synced yet') ?></strong>
                </div>
            </div>
        </section>

        <section class="money-grid" aria-label="Monthly money summary">
            <div class="money-card target">
                <span class="label">Monthly target</span>
                <strong><?= e(money_uah($monthlyTarget)) ?></strong>
            </div>
            <div class="money-card">
                <span class="label">Sales fact</span>
                <strong><?= e(money_uah($salesFact)) ?></strong>
            </div>
            <div class="money-card">
                <span class="label">Order count</span>
                <strong><?= e((string) $orderCount) ?></strong>
            </div>
            <div class="money-card">
                <span class="label">Paid</span>
                <strong><?= e(money_uah($paid)) ?></strong>
            </div>
            <div class="money-card">
                <span class="label">Unpaid</span>
                <strong><?= e(money_uah($unpaid)) ?></strong>
            </div>
            <div class="money-card">
                <span class="label">We owe</span>
                <strong><?= e(money_uah($weOwe)) ?></strong>
            </div>
            <div class="money-card">
                <span class="label">Remaining to target</span>
                <strong><?= e(money_uah($remaining)) ?></strong>
            </div>
        </section>

        <section class="panel table-panel">
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
                            <th>Payment status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$topUnpaidOrders): ?>
                            <tr>
                                <td colspan="8">No unpaid orders found for this month.</td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($topUnpaidOrders as $order): ?>
                            <?php $clientDisplay = $order['company_name'] ?: ($order['buyer_name'] ?: ($order['client_name'] ?: '—')); ?>
                            <tr>
                                <td><?= e((string) ($order['order_number'] ?: '—')) ?></td>
                                <td><?= e((string) ($order['ordered_at'] ?: '—')) ?></td>
                                <td><?= e((string) $clientDisplay) ?></td>
                                <td><?= e((string) ($order['manager_name'] ?: '—')) ?></td>
                                <td><?= e(money_uah($order['total_amount_uah'] ?? 0)) ?></td>
                                <td><?= e(money_uah($order['paid_amount_uah'] ?? 0)) ?></td>
                                <td><?= e(money_uah($order['unpaid_amount_uah'] ?? 0)) ?></td>
                                <td><?= e((string) ($order['payment_status'] ?: '—')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="panel action-panel">
            <div>
                <span class="label">Main next action</span>
                <h2>Review synced order data</h2>
                <p>Dashboard reads from local <code>db_orders</code>; KeyCRM is used only by CEO manual sync.</p>
            </div>
        </section>
    </main>
</body>
</html>
