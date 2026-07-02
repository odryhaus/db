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

function target_progress_mini(float $progress): string
{
    $width = max(0, min(100, $progress));
    $cls = $progress >= 100 ? ' over' : '';
    return '<div class="progress-mini' . $cls . '"><span class="progress-track"><span style="width:' . e((string) $width) . '%"></span></span><span class="progress-pct">' . e((string) round($progress)) . '%</span></div>';
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

$managerTargetTotal = 0;
$managerSalesTotal = 0;
foreach ($managers as $manager) {
    $managerName = (string) $manager['manager_name'];
    $managerTargetTotal += (float) ($savedTargets[$managerName] ?? 0);
    $managerSalesTotal += (float) ($manager['sales_fact'] ?? 0);
}
$monthlyRemaining = max($monthlyTarget - $managerSalesTotal, 0);
$monthlyProgress = $monthlyTarget > 0 ? min(100, round(($managerSalesTotal / $monthlyTarget) * 100, 1)) : 0;
?>
<!doctype html>
<html lang="uk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Плани | .BRAND DB</title>
    <link rel="stylesheet" href="<?= e(base_path('/assets/app.css')) ?>">
</head>
<body>
    <main class="page">
        <header class="topbar">
            <div class="brand-block">
                <p class="eyebrow">CEO access</p>
                <h1>Плани продажів</h1>
                <p class="muted">Місячний план і план по менеджерах</p>
            </div>
            <nav class="nav">
                <a href="<?= e(base_path('/index.php?month=' . urlencode($selectedMonth))) ?>">Дашборд</a>
                <a class="active" href="<?= e(base_path('/targets.php?month=' . urlencode($selectedMonth))) ?>">Плани</a>
                <a href="<?= e(base_path('/expenses.php?month=' . urlencode($selectedMonth))) ?>">Витрати</a>
                <a href="<?= e(base_path('/sync_orders.php')) ?>">Синхронізація</a>
                <a href="<?= e(base_path('/users.php')) ?>">Користувачі</a>
                <a href="<?= e(base_path('/logout.php')) ?>">Вийти</a>
            </nav>
        </header>

        <?php if ($message !== ''): ?>
            <div class="notice"><?= e($message) ?></div>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
            <div class="alert"><?= e($error) ?></div>
        <?php endif; ?>

        <section class="kpi-grid" aria-label="Плани">
            <div class="kpi-card target">
                <span class="label">План місяця</span>
                <strong><?= e(target_money($monthlyTarget)) ?></strong>
            </div>
            <div class="kpi-card">
                <span class="label">Факт менеджерів</span>
                <strong><?= e(target_money($managerSalesTotal)) ?></strong>
                <small><?= e((string) count($managers)) ?> менеджерів</small>
            </div>
            <div class="kpi-card">
                <span class="label">Сума планів менеджерів</span>
                <strong><?= e(target_money($managerTargetTotal)) ?></strong>
            </div>
            <div class="kpi-card progress-card">
                <span class="label">Прогрес</span>
                <strong><?= e((string) $monthlyProgress) ?>%</strong>
                <small>залишилось <?= e(target_money($monthlyRemaining)) ?></small>
            </div>
        </section>

        <section class="panel dashboard-section">
            <form class="toolbar" method="get" action="<?= e(base_path('/targets.php')) ?>">
                <label>
                    <span>Місяць</span>
                    <input type="month" name="month" value="<?= e($selectedMonth) ?>">
                </label>
                <button type="submit">Показати</button>
            </form>
        </section>

        <form method="post" action="<?= e(base_path('/targets.php')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="month" value="<?= e($selectedMonth) ?>">

            <section class="panel dashboard-section">
                <div class="section-heading">
                    <div>
                        <span class="label"><?= e($selectedMonth) ?></span>
                        <h2><?= e($selectedMonth) ?></h2>
                    </div>
                    <strong><?= e(target_money($monthlyTarget)) ?></strong>
                </div>
                <label class="compact-field">
                    <span>Загальний план місяця, UAH</span>
                    <input type="number" step="0.01" min="0" name="target_amount_uah" value="<?= e((string) $monthlyTarget) ?>">
                </label>
            </section>

            <section class="panel table-panel dashboard-section">
                <div class="section-heading padded">
                    <div>
                        <span class="label">Менеджери з db_orders</span>
                        <h2>Плани менеджерів</h2>
                    </div>
                    <button type="submit">Зберегти плани</button>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Менеджер</th>
                                <th class="num">Замовлень</th>
                                <th class="num">Факт</th>
                                <th class="num">План, UAH</th>
                                <th>Прогрес</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$managers): ?>
                                <tr><td colspan="5">За цей місяць менеджерів у db_orders не знайдено.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($managers as $manager): ?>
                                <?php $managerName = (string) $manager['manager_name']; ?>
                                <?php $managerTarget = (float) ($savedTargets[$managerName] ?? 0); ?>
                                <?php $managerFact = (float) ($manager['sales_fact'] ?? 0); ?>
                                <?php $managerProgress = $managerTarget > 0 ? min(100, round(($managerFact / $managerTarget) * 100, 1)) : 0; ?>
                                <tr>
                                    <td><?= e($managerName) ?></td>
                                    <td class="num"><?= e((string) $manager['order_count']) ?></td>
                                    <td class="num"><?= e(target_money($managerFact)) ?></td>
                                    <td class="num">
                                        <input type="hidden" name="manager_names[]" value="<?= e($managerName) ?>">
                                        <input
                                            type="number"
                                            step="0.01"
                                            min="0"
                                            name="manager_targets[]"
                                            value="<?= e((string) $managerTarget) ?>"
                                        >
                                    </td>
                                    <td><?= target_progress_mini($managerProgress) ?></td>
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
