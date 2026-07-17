<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/cockpit.php';
require_once __DIR__ . '/financial.php';
require_once __DIR__ . '/cockpit_layout.php';

require_login();

$month = cockpit_valid_month((string) ($_GET['month'] ?? date('Y-m')));
$rows = [];
$companyBalances = [];
$currencyBalances = [];
$top = finance_total_balance();

try {
    if (invoice_table_exists('db_financial_accounts')) {
        $rows = db()->query("
            SELECT a.*, c.short_name AS seller_name,
                   " . (invoice_table_exists('db_financial_transactions') ? finance_account_balance_select() : '0') . " AS calculated_balance
            FROM db_financial_accounts a
            LEFT JOIN db_our_companies c ON c.id = a.seller_company_id
            " . (invoice_table_exists('db_financial_transactions') ? 'LEFT JOIN db_financial_transactions t ON t.financial_account_id = a.id' : '') . "
            GROUP BY a.id
            ORDER BY a.is_active DESC, a.include_in_total_balance DESC, a.name ASC
        ")->fetchAll();

        if (invoice_table_exists('db_financial_transactions')) {
            $companyBalances = db()->query("
                SELECT COALESCE(c.short_name, 'Без компанії') AS label,
                       " . finance_account_balance_select() . " AS balance
                FROM db_financial_accounts a
                LEFT JOIN db_our_companies c ON c.id = a.seller_company_id
                LEFT JOIN db_financial_transactions t ON t.financial_account_id = a.id
                WHERE a.is_active = 1 AND a.include_in_total_balance = 1
                GROUP BY COALESCE(c.short_name, 'Без компанії')
                ORDER BY balance DESC
            ")->fetchAll();
            $currencyBalances = db()->query("
                SELECT COALESCE(a.currency, 'UAH') AS label,
                       " . finance_account_balance_select() . " AS balance
                FROM db_financial_accounts a
                LEFT JOIN db_financial_transactions t ON t.financial_account_id = a.id
                WHERE a.is_active = 1 AND a.include_in_total_balance = 1
                GROUP BY COALESCE(a.currency, 'UAH')
                ORDER BY balance DESC
            ")->fetchAll();
        }
    }
} catch (Throwable $e) {
    $rows = [];
}

?><!doctype html>
<html lang="uk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Баланси — .BRAND DB</title>
    <link rel="stylesheet" href="<?= e(asset_path('/assets/app.css')) ?>">
</head>
<body>
<main class="app-shell cockpit-shell">
    <?php cockpit_page_header('CEO Money Cockpit', 'Баланси', 'Фінансові рахунки і баланс з completed операцій.', 'accounts', $month, false); ?>
    <section class="kpi-grid">
        <div class="kpi-card"><span class="label">Загальний баланс</span><strong><?= e(finance_money($top['total'])) ?></strong></div>
        <div class="kpi-card"><span class="label">Рахунків</span><strong><?= e((string) count($rows)) ?></strong></div>
        <div class="kpi-card danger"><span class="label">Нерозподілені операції</span><strong><?= e((string) $top['unallocated_count']) ?></strong></div>
    </section>
    <section class="split-grid">
        <div class="panel dashboard-section">
            <p class="eyebrow">За компаніями</p>
            <?php foreach ($companyBalances as $row): ?><p><strong><?= e((string) $row['label']) ?></strong> · <?= e(finance_money($row['balance'])) ?></p><?php endforeach; ?>
            <?php if (!$companyBalances): ?><p class="muted">Немає даних.</p><?php endif; ?>
        </div>
        <div class="panel dashboard-section">
            <p class="eyebrow">За валютами</p>
            <?php foreach ($currencyBalances as $row): ?><p><strong><?= e((string) $row['label']) ?></strong> · <?= e(finance_money($row['balance'])) ?></p><?php endforeach; ?>
            <?php if (!$currencyBalances): ?><p class="muted">Немає даних.</p><?php endif; ?>
        </div>
    </section>
    <section class="panel dashboard-section">
        <div class="table-scroll">
            <table class="data-table">
                <thead><tr><th>Назва</th><th>Компанія</th><th>Тип</th><th>Валюта</th><th>Банк / сервіс</th><th>IBAN / ID</th><th>Баланс</th><th>У загальному</th><th>Активний</th></tr></thead>
                <tbody>
                <?php if (!$rows): ?><tr><td colspan="9">Немає рахунків.</td></tr><?php endif; ?>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><strong><?= e((string) $row['name']) ?></strong></td>
                        <td><?= e((string) ($row['seller_name'] ?: '—')) ?></td>
                        <td><?= e((string) $row['account_type']) ?></td>
                        <td><?= e((string) ($row['currency'] ?: 'UAH')) ?></td>
                        <td><?= e((string) (($row['bank_name'] ?? '') ?: ($row['external_service_name'] ?? '—'))) ?></td>
                        <td><?= e((string) (($row['iban'] ?? '') ?: ($row['external_account_identifier'] ?? '—'))) ?></td>
                        <td class="num"><strong><?= e(finance_money($row['calculated_balance'])) ?></strong></td>
                        <td><?= (int) ($row['include_in_total_balance'] ?? 0) === 1 ? 'так' : 'ні' ?></td>
                        <td><?= (int) ($row['is_active'] ?? 0) === 1 ? 'так' : 'ні' ?></td>
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
