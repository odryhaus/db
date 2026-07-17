<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/cockpit.php';
require_once __DIR__ . '/sync_core.php';

require_role('ceo');
ensure_finance_tables();

$user = current_user();
$query = trim((string) ($_GET['order'] ?? ''));
$order = null;
$payments = [];
$validation = null;
$error = '';

if ($query !== '') {
    try {
        if (!invoice_table_exists('db_orders')) {
            throw new RuntimeException('db_orders table is not available.');
        }

        $stmt = db()->prepare("
            SELECT *
            FROM db_orders
            WHERE keycrm_id = :id OR order_number = :number
            LIMIT 1
        ");
        $stmt->execute([
            'id' => ctype_digit($query) ? (int) $query : 0,
            'number' => $query,
        ]);
        $order = $stmt->fetch() ?: null;

        if (!$order) {
            $error = 'Замовлення не знайдено у локальній базі.';
        } elseif (invoice_table_exists('db_order_payments')) {
            $stmt = db()->prepare("
                SELECT *
                FROM db_order_payments
                WHERE keycrm_order_id = :order_id
                ORDER BY payment_date DESC, id DESC
            ");
            $stmt->execute(['order_id' => (int) $order['keycrm_id']]);
            $payments = $stmt->fetchAll();

            $activeTotal = 0.0;
            foreach ($payments as $payment) {
                if ((int) ($payment['is_deleted'] ?? 0) === 0 && sync_payment_counts_as_paid($payment['status'] ?? null)) {
                    $activeTotal += (float) ($payment['amount'] ?? 0);
                }
            }
            $total = (float) ($order['total_amount_uah'] ?? 0);
            $dbPaid = (float) ($order['paid_amount_uah'] ?? 0);
            $dbUnpaid = (float) ($order['unpaid_amount_uah'] ?? 0);
            $calculatedUnpaid = max($total - $activeTotal, 0);
            $difference = round(($activeTotal - $dbPaid) + ($calculatedUnpaid - $dbUnpaid), 2);
            $validation = [
                'active_total' => $activeTotal,
                'calculated_unpaid' => $calculatedUnpaid,
                'difference' => $difference,
                'status' => abs($difference) <= 0.01 ? 'OK' : 'DIFFERENCE',
            ];
        }
    } catch (Throwable $e) {
        $error = 'Перевірка недоступна.';
    }
}

?><!doctype html>
<html lang="uk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Payment Sync Check — .BRAND DB</title>
    <link rel="stylesheet" href="<?= e(asset_path('/assets/app.css')) ?>">
</head>
<body>
<main class="app-shell">
    <header class="topbar">
        <div>
            <p class="eyebrow">CEO Tool</p>
            <h1>Payment Sync Check</h1>
            <p class="muted">Звірка локальних платежів KeyCRM з сумами в db_orders.</p>
        </div>
        <nav class="nav">
            <span><?= e(format_user_name($user ?? [])) ?> · <?= e(user_role()) ?></span>
            <a href="<?= e(base_path('/dashboard_v2.php')) ?>">Cockpit v2</a>
            <a href="<?= e(base_path('/index.php')) ?>">Дашборд</a>
            <a href="<?= e(base_path('/logout.php')) ?>">Вийти</a>
        </nav>
    </header>

    <section class="panel dashboard-section">
        <form class="toolbar" method="get" action="<?= e(base_path('/payment_sync_check.php')) ?>">
            <label>
                <span>Order number / KeyCRM ID</span>
                <input type="text" name="order" value="<?= e($query) ?>" placeholder="Наприклад 9124">
            </label>
            <button type="submit">Перевірити</button>
        </form>
    </section>

    <?php if ($error !== ''): ?>
        <section class="panel dashboard-section">
            <span class="status-badge status-badge--danger"><?= e($error) ?></span>
        </section>
    <?php endif; ?>

    <?php if ($order): ?>
        <section class="kpi-grid">
            <div class="kpi-card">
                <span class="label">Замовлення</span>
                <strong><?= e((string) ($order['order_number'] ?? $order['keycrm_id'])) ?></strong>
                <small><?= e((string) ($order['order_month'] ?? '')) ?></small>
            </div>
            <div class="kpi-card">
                <span class="label">Сума замовлення</span>
                <strong><?= e(money_uah_compact($order['total_amount_uah'] ?? 0)) ?></strong>
            </div>
            <div class="kpi-card">
                <span class="label">db_orders paid/unpaid</span>
                <strong><?= e(money_uah_compact($order['paid_amount_uah'] ?? 0)) ?></strong>
                <small><?= e(money_uah_compact($order['unpaid_amount_uah'] ?? 0)) ?> борг</small>
            </div>
            <?php if ($validation): ?>
                <div class="kpi-card <?= $validation['status'] === 'OK' ? '' : 'danger' ?>">
                    <span class="label">Validation</span>
                    <strong><?= e($validation['status']) ?></strong>
                    <small>diff <?= e(money_uah_compact($validation['difference'])) ?></small>
                </div>
            <?php endif; ?>
        </section>

        <?php if ($validation): ?>
            <section class="panel dashboard-section">
                <div class="section-heading">
                    <div>
                        <p class="eyebrow">Payment rows</p>
                        <h2>Активна сума: <?= e(money_uah_compact($validation['active_total'])) ?></h2>
                    </div>
                    <span class="status-badge">Розрахований борг: <?= e(money_uah_compact($validation['calculated_unpaid'])) ?></span>
                </div>
                <div class="table-scroll">
                    <table class="data-table">
                        <thead>
                        <tr>
                            <th>ID</th>
                            <th>Дата платежу</th>
                            <th>Метод</th>
                            <th>Статус</th>
                            <th>Сума</th>
                            <th>Deleted</th>
                            <th>Synced</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (!$payments): ?>
                            <tr><td colspan="7">Платежів не знайдено.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($payments as $payment): ?>
                            <tr>
                                <td><?= e((string) $payment['keycrm_payment_id']) ?></td>
                                <td><?= e((string) ($payment['payment_date'] ?: '—')) ?></td>
                                <td><?= e((string) ($payment['payment_method_name'] ?: '—')) ?></td>
                                <td><?= e((string) ($payment['status'] ?: '—')) ?></td>
                                <td class="num"><?= e(money_uah_compact($payment['amount'] ?? 0)) ?></td>
                                <td><?= (int) ($payment['is_deleted'] ?? 0) === 1 ? 'так' : 'ні' ?></td>
                                <td><?= e((string) ($payment['synced_at'] ?: '—')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endif; ?>
    <?php endif; ?>
</main>
<?= app_version_badge() ?>
</body>
</html>
