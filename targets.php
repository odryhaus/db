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
    $action = (string) ($_POST['action'] ?? '');
    $targetAmount = max(0, (float) str_replace(' ', '', (string) ($_POST['amount_uah'] ?? '0')));
    $effectiveFrom = trim((string) ($_POST['effective_from'] ?? date('Y-m-d')));
    $managerName = trim((string) ($_POST['manager_name'] ?? ''));

    if (!csrf_is_valid() || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $effectiveFrom)) {
        $error = 'Invalid target update.';
    } elseif ($action === 'save_company') {
        $stmt = db()->prepare("
            INSERT INTO db_sales_targets
                (target_type, manager_name, amount_uah, effective_from, created_by_user_id)
            VALUES
                ('company', NULL, :amount_uah, :effective_from, :created_by_user_id)
        ");
        $stmt->execute([
            'amount_uah' => $targetAmount,
            'effective_from' => $effectiveFrom,
            'created_by_user_id' => (int) (current_user()['id'] ?? 0),
        ]);

        $message = 'Company target saved.';
    } elseif ($action === 'save_manager' && $managerName !== '') {
        $stmt = db()->prepare("
            INSERT INTO db_sales_targets
                (target_type, manager_name, amount_uah, effective_from, created_by_user_id)
            VALUES
                ('manager', :manager_name, :amount_uah, :effective_from, :created_by_user_id)
        ");
        $stmt->execute([
            'manager_name' => substr($managerName, 0, 150),
            'amount_uah' => $targetAmount,
            'effective_from' => $effectiveFrom,
            'created_by_user_id' => (int) (current_user()['id'] ?? 0),
        ]);

        $message = 'Manager target saved.';
    } else {
        $error = 'Invalid target update.';
    }
}

$companyTarget = active_company_target(db(), $selectedMonth);
$monthlyTarget = (float) $companyTarget['amount_uah'];
$monthEnd = month_end_date($selectedMonth);

$notCanceledSql = "
    LOWER(COALESCE(status_name, '')) NOT LIKE '%cancel%'
    AND LOWER(COALESCE(status_name, '')) NOT LIKE '%deleted%'
    AND LOWER(COALESCE(status_name, '')) NOT LIKE '%скас%'
    AND LOWER(COALESCE(payment_status, '')) NOT LIKE '%cancel%'
    AND LOWER(COALESCE(payment_status, '')) NOT LIKE '%deleted%'
    AND LOWER(COALESCE(payment_status, '')) NOT LIKE '%скас%'
";
$managersStmt = db()->prepare("
    SELECT
        COALESCE(NULLIF(manager_name, ''), 'No manager') AS manager_name,
        COUNT(*) AS order_count,
        COALESCE(SUM(total_amount_uah), 0) AS sales_fact
    FROM db_orders
    WHERE order_month = :month
      AND {$notCanceledSql}
    GROUP BY COALESCE(NULLIF(manager_name, ''), 'No manager')
    ORDER BY manager_name
");
$managersStmt->execute(['month' => $selectedMonth]);
$managers = $managersStmt->fetchAll();

$managerNames = array_map(static function (array $manager): string {
    return (string) $manager['manager_name'];
}, $managers);
$activeManagerTargets = active_manager_targets(db(), $selectedMonth, $managerNames);

$managerTargetTotal = 0;
$managerSalesTotal = 0;
foreach ($managers as $manager) {
    $managerName = (string) $manager['manager_name'];
    $managerTargetTotal += (float) ($activeManagerTargets[$managerName]['amount_uah'] ?? 0);
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
    <link rel="stylesheet" href="<?= e(asset_path('/assets/app.css')) ?>">
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
                <a href="<?= e(base_path('/invoices.php')) ?>">Рахунки</a>
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
                <span class="label">Активний план компанії</span>
                <strong><?= e(target_money($monthlyTarget)) ?></strong>
                <small>діє з <?= e($companyTarget['effective_from'] ?: 'fallback') ?></small>
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

        <section class="panel dashboard-section">
            <div class="section-heading">
                <div>
                    <span class="label">Company target</span>
                    <h2>План компанії</h2>
                </div>
                <strong><?= e(target_money($monthlyTarget)) ?></strong>
            </div>
            <form class="toolbar" method="post" action="<?= e(base_path('/targets.php')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_company">
                <input type="hidden" name="month" value="<?= e($selectedMonth) ?>">
                <label>
                    <span>Новий план, UAH</span>
                    <input class="compact-money-input" type="number" step="0.01" min="0" name="amount_uah" value="<?= e((string) $monthlyTarget) ?>">
                </label>
                <label>
                    <span>Діє з</span>
                    <input class="compact-date-input" type="date" name="effective_from" value="<?= e(date('Y-m-d')) ?>">
                </label>
                <button type="submit">Зберегти</button>
            </form>
        </section>

        <section class="panel table-panel dashboard-section">
            <div class="section-heading padded">
                <div>
                    <span class="label">Активні на <?= e($monthEnd) ?></span>
                    <h2>Плани менеджерів</h2>
                </div>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Менеджер</th>
                            <th class="num">Факт</th>
                            <th class="num">Активний план</th>
                            <th>Діє з</th>
                            <th>Прогрес</th>
                            <th class="num">Новий план</th>
                            <th>Нова дата</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$managers): ?>
                            <tr><td colspan="8">За цей місяць менеджерів у db_orders не знайдено.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($managers as $manager): ?>
                            <?php $managerName = (string) $manager['manager_name']; ?>
                            <?php $activeTarget = $activeManagerTargets[$managerName] ?? ['amount_uah' => 0, 'effective_from' => null]; ?>
                            <?php $managerTarget = (float) ($activeTarget['amount_uah'] ?? 0); ?>
                            <?php $managerFact = (float) ($manager['sales_fact'] ?? 0); ?>
                            <?php $managerProgress = $managerTarget > 0 ? min(100, round(($managerFact / $managerTarget) * 100, 1)) : 0; ?>
                            <?php $formId = 'manager-target-' . md5($managerName); ?>
                            <tr>
                                <td><?= e($managerName) ?></td>
                                <td class="num"><?= e(target_money($managerFact)) ?></td>
                                <td class="num"><?= $managerTarget > 0 ? e(target_money($managerTarget)) : '<span class="status-badge status-badge--muted">не задано</span>' ?></td>
                                <td><?= e($activeTarget['effective_from'] ?: '—') ?></td>
                                <td><?= $managerTarget > 0 ? target_progress_mini($managerProgress) : '—' ?></td>
                                <td class="num">
                                    <input form="<?= e($formId) ?>" class="compact-money-input" type="number" step="0.01" min="0" name="amount_uah" value="<?= e($managerTarget > 0 ? (string) $managerTarget : '') ?>">
                                </td>
                                <td>
                                    <input form="<?= e($formId) ?>" class="compact-date-input" type="date" name="effective_from" value="<?= e(date('Y-m-d')) ?>">
                                </td>
                                <td>
                                    <form id="<?= e($formId) ?>" method="post" action="<?= e(base_path('/targets.php')) ?>">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="save_manager">
                                        <input type="hidden" name="month" value="<?= e($selectedMonth) ?>">
                                        <input type="hidden" name="manager_name" value="<?= e($managerName) ?>">
                                    </form>
                                    <button form="<?= e($formId) ?>" type="submit" class="small-button">Save</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
        <?= app_version_badge() ?>
    </main>
</body>
</html>
