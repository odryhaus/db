<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/cockpit.php';
require_once __DIR__ . '/financial.php';
require_once __DIR__ . '/cockpit_layout.php';

require_login();

$month = cockpit_valid_month((string) ($_GET['month'] ?? date('Y-m')));
$bounds = finance_filter_month_bounds($month);
$direction = trim((string) ($_GET['direction'] ?? 'all'));
$status = trim((string) ($_GET['status'] ?? 'all'));
$allocation = trim((string) ($_GET['allocation_status'] ?? 'all'));
$search = trim((string) ($_GET['q'] ?? ''));
$rows = [];
$totals = ['income' => 0.0, 'expense' => 0.0, 'net' => 0.0];
$needsReviewCount = 0;

try {
    if (invoice_table_exists('db_financial_transactions')) {
        $where = ['t.transaction_date >= :start_at', 't.transaction_date <= :end_at'];
        $params = [
            'start_at' => $bounds['start']->format('Y-m-d H:i:s'),
            'end_at' => $bounds['end']->format('Y-m-d H:i:s'),
        ];
        if (in_array($direction, ['income', 'expense'], true)) {
            $where[] = 't.direction = :direction';
            $params['direction'] = $direction;
        }
        if ($status !== 'all' && $status !== '') {
            $where[] = 't.status = :status';
            $params['status'] = $status;
        }
        if ($allocation !== 'all' && $allocation !== '') {
            $where[] = 't.allocation_status = :allocation_status';
            $params['allocation_status'] = $allocation;
        }
        if ($search !== '') {
            $where[] = "(t.counterparty_name LIKE :q OR t.payment_purpose LIKE :q OR t.order_number LIKE :q OR t.source_id LIKE :q)";
            $params['q'] = '%' . $search . '%';
        }
        $whereSql = implode(' AND ', $where);

        $stmt = db()->prepare("
            SELECT t.*, a.name AS account_name, c.short_name AS seller_name
            FROM db_financial_transactions t
            LEFT JOIN db_financial_accounts a ON a.id = t.financial_account_id
            LEFT JOIN db_our_companies c ON c.id = t.seller_company_id
            WHERE {$whereSql}
            ORDER BY t.transaction_date DESC, t.id DESC
            LIMIT 400
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $sumStmt = db()->prepare("
            SELECT
                COALESCE(SUM(CASE WHEN direction = 'income' AND status <> 'canceled' THEN amount ELSE 0 END), 0) AS income_total,
                COALESCE(SUM(CASE WHEN direction = 'expense' AND status <> 'canceled' THEN amount ELSE 0 END), 0) AS expense_total
            FROM db_financial_transactions t
            WHERE {$whereSql}
        ");
        $sumStmt->execute($params);
        $sum = $sumStmt->fetch() ?: [];
        $totals['income'] = (float) ($sum['income_total'] ?? 0);
        $totals['expense'] = (float) ($sum['expense_total'] ?? 0);
        $totals['net'] = $totals['income'] - $totals['expense'];

        $needsReviewCount = (int) db()->query("
            SELECT COUNT(*)
            FROM db_financial_transactions
            WHERE allocation_status = 'needs_review'
              AND status <> 'canceled'
        ")->fetchColumn();
    }
} catch (Throwable $e) {
    $rows = [];
}

?><!doctype html>
<html lang="uk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Операції — .BRAND DB</title>
    <link rel="stylesheet" href="<?= e(asset_path('/assets/app.css')) ?>">
</head>
<body>
<main class="app-shell cockpit-shell">
    <?php cockpit_page_header('CEO Money Cockpit', 'Фінансові операції', 'Єдиний read-only журнал db_financial_transactions.', 'payments', $month); ?>
    <section class="kpi-grid">
        <div class="kpi-card"><span class="label">Надходження</span><strong><?= e(finance_money($totals['income'])) ?></strong></div>
        <div class="kpi-card"><span class="label">Витрати</span><strong><?= e(finance_money($totals['expense'])) ?></strong></div>
        <div class="kpi-card"><span class="label">Чистий рух</span><strong><?= e(finance_money($totals['net'])) ?></strong></div>
        <div class="kpi-card danger"><span class="label">Потребує розподілу</span><strong><?= e((string) $needsReviewCount) ?></strong></div>
    </section>
    <section class="panel dashboard-section">
        <form class="toolbar" method="get">
            <label><span>Місяць</span><input type="month" name="month" value="<?= e($month) ?>"></label>
            <label><span>Напрямок</span><select name="direction"><option value="all">усі</option><option value="income" <?= $direction === 'income' ? 'selected' : '' ?>>income</option><option value="expense" <?= $direction === 'expense' ? 'selected' : '' ?>>expense</option></select></label>
            <label><span>Статус</span><select name="status"><option value="all">усі</option><option value="completed" <?= $status === 'completed' ? 'selected' : '' ?>>completed</option><option value="canceled" <?= $status === 'canceled' ? 'selected' : '' ?>>canceled</option></select></label>
            <label><span>Розподіл</span><select name="allocation_status"><option value="all">усі</option><option value="allocated" <?= $allocation === 'allocated' ? 'selected' : '' ?>>allocated</option><option value="needs_review" <?= $allocation === 'needs_review' ? 'selected' : '' ?>>needs_review</option></select></label>
            <label><span>Пошук</span><input type="search" name="q" value="<?= e($search) ?>" placeholder="контрагент, №, текст"></label>
            <button type="submit">Фільтр</button>
        </form>
    </section>
    <section class="panel dashboard-section">
        <div class="table-scroll">
            <table class="data-table">
                <thead><tr><th>Дата</th><th>Напрямок</th><th>Тип</th><th>Компанія</th><th>Рахунок</th><th>Контрагент</th><th>Призначення</th><th>№</th><th>Надходження</th><th>Витрата</th><th>Валюта</th><th>Джерело</th><th>Статус</th><th>Розподіл</th></tr></thead>
                <tbody>
                <?php if (!$rows): ?><tr><td colspan="14">Немає операцій.</td></tr><?php endif; ?>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?= e((string) $row['transaction_date']) ?></td>
                        <td><?= e((string) $row['direction']) ?></td>
                        <td><?= e((string) ($row['transaction_type'] ?: '—')) ?></td>
                        <td><?= e((string) ($row['seller_name'] ?: '—')) ?></td>
                        <td><?= e((string) ($row['account_name'] ?: '—')) ?></td>
                        <td><?= e((string) ($row['counterparty_name'] ?: '—')) ?></td>
                        <td><?= e((string) ($row['payment_purpose'] ?: '—')) ?></td>
                        <td><?= e((string) ($row['order_number'] ?: '—')) ?></td>
                        <td class="num"><?= $row['direction'] === 'income' ? e(finance_money($row['amount'])) : '—' ?></td>
                        <td class="num"><?= $row['direction'] === 'expense' ? e(finance_money($row['amount'])) : '—' ?></td>
                        <td><?= e((string) ($row['currency'] ?: 'UAH')) ?></td>
                        <td><?= e((string) (($row['source_type'] ?? '') . ':' . ($row['source_id'] ?? ''))) ?></td>
                        <td><?= e((string) $row['status']) ?></td>
                        <td><?= e((string) ($row['allocation_status'] ?: '—')) ?></td>
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
