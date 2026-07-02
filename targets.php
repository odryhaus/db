<?php

require_once __DIR__ . '/bootstrap.php';
require_role('ceo');
ensure_finance_tables();

$selectedMonth = (string) ($_GET['month'] ?? $_POST['month'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $selectedMonth)) {
    $selectedMonth = date('Y-m');
}

$message = '';
$error = '';

function target_money($value): string
{
    return number_format((float) $value, 0, '.', ' ') . ' UAH';
}

if (is_post()) {
    $monthlyTarget = max(0, (float) str_replace(' ', '', (string) ($_POST['target_amount_uah'] ?? '0')));
    $managerNames = is_array($_POST['manager_names'] ?? null) ? array_values($_POST['manager_names']) : [];
    $managerTargets = is_array($_POST['manager_targets'] ?? null) ? array_values($_POST['manager_targets']) : [];

    if (!csrf_is_valid()) {
        $error = 'Invalid target update.';
    } else {
        $stmt = db()->prepare("
            INSERT INTO db_monthly_targets (month, target_amount_uah)
            VALUES (:month, :target_amount_uah)
            ON DUPLICATE KEY UPDATE target_amount_uah = VALUES(target_amount_uah)
        ");
        $stmt->execute([
            'month' => $selectedMonth,
            'target_amount_uah' => $monthlyTarget,
        ]);

        $managerStmt = db()->prepare("
            INSERT INTO db_manager_targets (month, manager_name, target_amount_uah)
            VALUES (:month, :manager_name, :target_amount_uah)
            ON DUPLICATE KEY UPDATE target_amount_uah = VALUES(target_amount_uah)
        ");

        foreach ($managerNames as $index => $managerName) {
            $managerName = trim((string) $managerName);
            if ($managerName === '') {
                continue;
            }
            $targetAmount = $managerTargets[$index] ?? 0;
            $managerStmt->execute([
                'month' => $selectedMonth,
                'manager_name' => substr($managerName, 0, 150),
                'target_amount_uah' => max(0, (float) str_replace(' ', '', (string) $targetAmount)),
            ]);
        }

        $message = 'Targets saved.';
    }
}

$monthlyTargetStmt = db()->prepare('SELECT target_amount_uah FROM db_monthly_targets WHERE month = :month LIMIT 1');
$monthlyTargetStmt->execute(['month' => $selectedMonth]);
$monthlyTarget = (float) ($monthlyTargetStmt->fetchColumn() ?: 4000000);

$managersStmt = db()->prepare("
    SELECT
        COALESCE(NULLIF(manager_name, ''), 'No manager') AS manager_name,
        COUNT(*) AS order_count,
        COALESCE(SUM(total_amount_uah), 0) AS sales_fact
    FROM db_orders
    WHERE order_month = :month
    GROUP BY COALESCE(NULLIF(manager_name, ''), 'No manager')
    ORDER BY manager_name
");
$managersStmt->execute(['month' => $selectedMonth]);
$managers = $managersStmt->fetchAll();

$targetsStmt = db()->prepare('SELECT manager_name, target_amount_uah FROM db_manager_targets WHERE month = :month');
$targetsStmt->execute(['month' => $selectedMonth]);
$savedTargets = [];
foreach ($targetsStmt->fetchAll() as $row) {
    $savedTargets[(string) $row['manager_name']] = (float) $row['target_amount_uah'];
}
?>
<!doctype html>
<html lang="uk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Targets | .BRAND DB</title>
    <link rel="stylesheet" href="<?= e(base_path('/assets/app.css')) ?>">
</head>
<body>
    <main class="page">
        <header class="topbar">
            <div>
                <p class="eyebrow">CEO access</p>
                <h1>Sales Targets</h1>
            </div>
            <nav class="nav">
                <a href="<?= e(base_path('/index.php?month=' . urlencode($selectedMonth))) ?>">Dashboard</a>
                <a href="<?= e(base_path('/sync_orders.php')) ?>">Sync Orders</a>
                <a href="<?= e(base_path('/users.php')) ?>">Users</a>
                <a href="<?= e(base_path('/logout.php')) ?>">Logout</a>
            </nav>
        </header>

        <?php if ($message !== ''): ?>
            <div class="notice"><?= e($message) ?></div>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
            <div class="alert"><?= e($error) ?></div>
        <?php endif; ?>

        <section class="panel">
            <form class="inline-form" method="get" action="<?= e(base_path('/targets.php')) ?>">
                <label>
                    <span>Month</span>
                    <input type="month" name="month" value="<?= e($selectedMonth) ?>">
                </label>
                <button type="submit">Open month</button>
            </form>
        </section>

        <form method="post" action="<?= e(base_path('/targets.php')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="month" value="<?= e($selectedMonth) ?>">

            <section class="panel form-section">
                <div class="section-heading">
                    <div>
                        <span class="label">Monthly target</span>
                        <h2><?= e($selectedMonth) ?></h2>
                    </div>
                    <strong><?= e(target_money($monthlyTarget)) ?></strong>
                </div>
                <label class="compact-field">
                    <span>Total monthly target, UAH</span>
                    <input type="number" step="0.01" min="0" name="target_amount_uah" value="<?= e((string) $monthlyTarget) ?>">
                </label>
            </section>

            <section class="panel table-panel">
                <div class="section-heading padded">
                    <div>
                        <span class="label">Managers from db_orders</span>
                        <h2>Manager targets</h2>
                    </div>
                    <button type="submit">Save targets</button>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Manager</th>
                                <th>Orders</th>
                                <th>Sales fact</th>
                                <th>Target, UAH</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$managers): ?>
                                <tr><td colspan="4">No managers found in db_orders for this month.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($managers as $manager): ?>
                                <?php $managerName = (string) $manager['manager_name']; ?>
                                <tr>
                                    <td><?= e($managerName) ?></td>
                                    <td><?= e((string) $manager['order_count']) ?></td>
                                    <td><?= e(target_money($manager['sales_fact'] ?? 0)) ?></td>
                                    <td>
                                        <input type="hidden" name="manager_names[]" value="<?= e($managerName) ?>">
                                        <input
                                            type="number"
                                            step="0.01"
                                            min="0"
                                            name="manager_targets[]"
                                            value="<?= e((string) ($savedTargets[$managerName] ?? 0)) ?>"
                                        >
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </form>
    </main>
</body>
</html>
