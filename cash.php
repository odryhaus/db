<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/cockpit.php';
require_once __DIR__ . '/financial.php';
require_once __DIR__ . '/cockpit_layout.php';

require_login();

$month = cockpit_valid_month((string) ($_GET['month'] ?? date('Y-m')));
$bounds = finance_filter_month_bounds($month);
$summary = cockpit_monthly_summary($month);
$payments = [];

try {
    if (invoice_table_exists('db_order_payments')) {
        $active = finance_active_payment_sql('p');
        $stmt = db()->prepare("
            SELECT p.payment_date, p.amount, p.currency, p.payment_method_name, p.status, p.order_number,
                   o.order_month, o.company_name, o.buyer_name,
                   t.allocation_status, a.name AS account_name
            FROM db_order_payments p
            LEFT JOIN db_orders o ON o.keycrm_id = p.keycrm_order_id
            LEFT JOIN db_financial_transactions t ON t.source_type = 'keycrm_payment' AND t.source_id = p.keycrm_payment_id
            LEFT JOIN db_financial_accounts a ON a.id = t.financial_account_id
            WHERE p.payment_date >= :start_at
              AND p.payment_date <= :end_at
              AND {$active}
            ORDER BY p.payment_date DESC, p.id DESC
            LIMIT 300
        ");
        $stmt->execute([
            'start_at' => $bounds['start']->format('Y-m-d H:i:s'),
            'end_at' => $bounds['end']->format('Y-m-d H:i:s'),
        ]);
        $payments = $stmt->fetchAll();
    }
} catch (Throwable $e) {
    $payments = [];
}

?><!doctype html>
<html lang="uk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Гроші — .BRAND DB</title>
    <link rel="stylesheet" href="<?= e(asset_path('/assets/app.css')) ?>">
</head>
<body>
<main class="app-shell cockpit-shell">
    <?php cockpit_page_header('CEO Money Cockpit', 'Гроші', 'Надходження за датою платежу, не за місяцем замовлення.', 'cash', $month); ?>
    <section class="kpi-grid">
        <div class="kpi-card"><span class="label">Прийшло за місяць</span><strong><?= e(finance_money($summary['cash_received'])) ?></strong></div>
        <div class="kpi-card"><span class="label">За замовлення поточного місяця</span><strong><?= e(finance_money($summary['cash_from_selected_month_orders'])) ?></strong></div>
        <div class="kpi-card"><span class="label">За старі замовлення</span><strong><?= e(finance_money($summary['cash_from_previous_orders'])) ?></strong></div>
        <div class="kpi-card"><span class="label">Нерозподілені</span><strong><?= e((string) finance_total_balance()['unallocated_count']) ?></strong></div>
    </section>
    <section class="panel dashboard-section">
        <div class="section-heading"><div><p class="eyebrow">Надходження</p><h2>Активні оплати KeyCRM</h2></div></div>
        <div class="table-scroll">
            <table class="data-table">
                <thead><tr><th>Дата</th><th>Замовлення</th><th>Місяць замовлення</th><th>Клієнт</th><th>Метод</th><th>Рахунок</th><th>Сума</th><th>Валюта</th></tr></thead>
                <tbody>
                <?php if (!$payments): ?><tr><td colspan="8">Немає оплат.</td></tr><?php endif; ?>
                <?php foreach ($payments as $payment): ?>
                    <tr>
                        <td><?= e((string) $payment['payment_date']) ?></td>
                        <td><?= e((string) $payment['order_number']) ?></td>
                        <td><?= e((string) ($payment['order_month'] ?: '—')) ?></td>
                        <td><?= e((string) (($payment['company_name'] ?? '') ?: ($payment['buyer_name'] ?? '—'))) ?></td>
                        <td><?= e((string) ($payment['payment_method_name'] ?: '—')) ?></td>
                        <td><?= e((string) ($payment['account_name'] ?: 'needs review')) ?></td>
                        <td class="num"><strong><?= e(finance_money($payment['amount'])) ?></strong></td>
                        <td><?= e((string) ($payment['currency'] ?: 'UAH')) ?></td>
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
