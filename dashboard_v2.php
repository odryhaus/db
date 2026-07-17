<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/cockpit.php';
require_once __DIR__ . '/financial.php';
require_once __DIR__ . '/sync_core.php';

require_login();

$user = current_user();
$selectedMonth = cockpit_valid_month((string) ($_GET['month'] ?? date('Y-m')));

if (is_post() && (string) ($_POST['action'] ?? '') === 'enqueue_global_sync') {
    if (user_role() !== 'ceo') {
        http_response_code(403);
        include __DIR__ . '/partials_forbidden.php';
        exit;
    }
    if (!csrf_is_valid()) {
        http_response_code(400);
        exit('Invalid CSRF token');
    }
    sync_enqueue_global_refresh((int) ($user['id'] ?? 0));
    redirect_to('/dashboard_v2.php?month=' . urlencode($selectedMonth) . '&sync_queued=1');
}

function cockpit_status_label(array $summary): string
{
    if (!empty($summary['sync_status']['active'])) {
        return 'оновлюється зараз';
    }
    $last = $summary['sync_status']['last_global_job']['finished_at'] ?? null;
    return $last ? 'останнє оновлення ' . (string) $last : 'очікує першого оновлення';
}

try {
    $summary = cockpit_monthly_summary($selectedMonth);
    $managers = cockpit_manager_summary($selectedMonth);
    $attention = cockpit_attention_items($selectedMonth);
    $dashboardError = '';
} catch (Throwable $e) {
    $summary = cockpit_zero_summary($selectedMonth);
    $managers = [];
    $attention = [];
    $dashboardError = 'CEO Money Cockpit v2 data is not available yet.';
}

$syncQueued = isset($_GET['sync_queued']);

?><!doctype html>
<html lang="uk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CEO Money Cockpit v2 — .BRAND DB</title>
    <link rel="stylesheet" href="<?= e(asset_path('/assets/app.css')) ?>">
</head>
<body>
<main class="app-shell cockpit-shell">
    <header class="cockpit-topbar">
        <div>
            <p class="eyebrow">CEO Money Cockpit v2 preview</p>
            <h1>.BRAND DB</h1>
            <p class="muted">Performance, cash і financial health без змішування формул.</p>
        </div>
        <form class="cockpit-month-form" method="get" action="<?= e(base_path('/dashboard_v2.php')) ?>">
            <label>
                <span>Місяць</span>
                <input type="month" name="month" value="<?= e($selectedMonth) ?>">
            </label>
            <button type="submit">Показати</button>
        </form>
    </header>

    <nav class="nav cockpit-nav">
        <span><?= e(format_user_name($user ?? [])) ?> · <?= e(user_role()) ?></span>
        <a class="active" href="<?= e(base_path('/dashboard_v2.php?month=' . urlencode($selectedMonth))) ?>">Dashboard v2</a>
        <a href="<?= e(base_path('/sales.php?month=' . urlencode($selectedMonth))) ?>">Продажі</a>
        <a href="<?= e(base_path('/cash.php?month=' . urlencode($selectedMonth))) ?>">Гроші</a>
        <a href="<?= e(base_path('/receivables.php?month=' . urlencode($selectedMonth))) ?>">Дебіторка</a>
        <a href="<?= e(base_path('/managers.php?month=' . urlencode($selectedMonth))) ?>">Менеджери</a>
        <a href="<?= e(base_path('/payments.php?month=' . urlencode($selectedMonth))) ?>">Операції</a>
        <a href="<?= e(base_path('/accounts.php')) ?>">Рахунки</a>
        <a href="<?= e(base_path('/index.php?month=' . urlencode($selectedMonth))) ?>">Поточний дашборд</a>
        <a href="<?= e(base_path('/targets.php?month=' . urlencode($selectedMonth))) ?>">Плани</a>
        <?php if (user_role() === 'ceo'): ?>
            <a href="<?= e(base_path('/payment_sync_check.php')) ?>">Payment check</a>
            <a href="<?= e(base_path('/users.php')) ?>">Користувачі</a>
        <?php endif; ?>
        <a href="<?= e(base_path('/logout.php')) ?>">Вийти</a>
    </nav>

    <section class="cockpit-sync-strip">
        <span><?= e(cockpit_status_label($summary)) ?></span>
        <?php if ($syncQueued): ?><strong>Оновлення поставлено в чергу.</strong><?php endif; ?>
        <?php if (user_role() === 'ceo'): ?>
            <form method="post" action="<?= e(base_path('/dashboard_v2.php?month=' . urlencode($selectedMonth))) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="enqueue_global_sync">
                <button type="submit">Оновити все</button>
            </form>
        <?php endif; ?>
    </section>

    <?php if ($dashboardError !== ''): ?>
        <section class="panel dashboard-section">
            <span class="status-badge status-badge--danger"><?= e($dashboardError) ?></span>
        </section>
    <?php endif; ?>

    <section class="cockpit-hero">
        <div class="cockpit-hero-main">
            <p class="eyebrow">Performance</p>
            <h2><?= e(money_uah_compact($summary['sales_fact'])) ?></h2>
            <p>Факт продажів за <?= e($selectedMonth) ?> з плану <?= e(money_uah_compact($summary['target'])) ?></p>
            <div class="progress-track"><span style="width: <?= e((string) min(100, (float) $summary['progress_percent'])) ?>%"></span></div>
            <div class="cockpit-hero-meta">
                <strong><?= e((string) $summary['progress_percent']) ?>%</strong>
                <span>залишилось <?= e(money_uah_compact($summary['remaining_to_target'])) ?></span>
            </div>
        </div>
        <div class="cockpit-attention">
            <p class="eyebrow">Потребує уваги</p>
            <?php if (!$attention): ?>
                <div class="attention-row neutral">
                    <span>Критичних сигналів немає</span>
                    <strong>OK</strong>
                </div>
            <?php endif; ?>
            <?php foreach ($attention as $item): ?>
                <div class="attention-row <?= e((string) $item['level']) ?>">
                    <span><?= e((string) $item['title']) ?></span>
                    <strong><?= e((string) $item['value']) ?></strong>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="cockpit-section">
        <div class="section-heading">
            <div>
                <p class="eyebrow">A. Performance</p>
                <h2>План, маржа, прибуток</h2>
            </div>
            <span class="status-badge">Sales month = db_orders.order_month</span>
        </div>
        <div class="cockpit-card-grid">
            <div class="kpi-card">
                <span class="label">План</span>
                <strong><?= e(money_uah_compact($summary['target'])) ?></strong>
            </div>
            <div class="kpi-card">
                <span class="label">Факт</span>
                <strong><?= e(money_uah_compact($summary['sales_fact'])) ?></strong>
                <small><?= e((string) $summary['order_count']) ?> замовлень</small>
            </div>
            <div class="kpi-card">
                <span class="label">Gross margin</span>
                <strong><?= e(money_uah_compact($summary['gross_margin'])) ?></strong>
                <small><?= e((string) $summary['gross_margin_percent']) ?>%</small>
            </div>
            <div class="kpi-card">
                <span class="label">Operating profit</span>
                <strong><?= $summary['operating_profit_status'] === 'calculated' ? e(money_uah_compact($summary['operating_profit'])) : 'Потрібна категоризація' ?></strong>
                <small>gross margin - completed operating expenses</small>
            </div>
        </div>
    </section>

    <section class="cockpit-section">
        <div class="section-heading">
            <div>
                <p class="eyebrow">B. Cash</p>
                <h2>Гроші, борги клієнтів, платежі</h2>
            </div>
            <span class="status-badge">Cash month = db_order_payments.payment_date</span>
        </div>
        <div class="cockpit-card-grid">
            <div class="kpi-card">
                <span class="label">Гроші прийшли</span>
                <strong><?= e(money_uah_compact($summary['cash_received'])) ?></strong>
                <small><?= e((string) $summary['cash_payment_count']) ?> платежів</small>
            </div>
            <div class="kpi-card">
                <span class="label">З минулих замовлень</span>
                <strong><?= e(money_uah_compact($summary['cash_from_previous_orders'])) ?></strong>
            </div>
            <div class="kpi-card">
                <span class="label">Нам повинні всього</span>
                <strong><?= e(money_uah_compact($summary['receivables_total'])) ?></strong>
                <small><?= e((string) $summary['receivables_count']) ?> боргів</small>
            </div>
            <div class="kpi-card">
                <span class="label">Ми повинні цього місяця</span>
                <strong><?= e(money_uah_compact($summary['operational_due_this_month'])) ?></strong>
                <small>overdue <?= e(money_uah_compact($summary['overdue_obligations_total'])) ?></small>
            </div>
            <div class="kpi-card">
                <span class="label">Поточний баланс</span>
                <strong><?= e(money_uah_compact($summary['current_balance'])) ?></strong>
                <small><?= e((string) $summary['unallocated_transactions_count']) ?> needs review</small>
            </div>
            <div class="kpi-card">
                <span class="label">Cash forecast</span>
                <strong><?= e(money_uah_compact($summary['cash_forecast'])) ?></strong>
                <small>balance + receivables - operational due</small>
            </div>
        </div>
    </section>

    <section class="cockpit-section">
        <div class="section-heading">
            <div>
                <p class="eyebrow">C. Financial Health</p>
                <h2>Стратегічні борги та довгі зобов’язання</h2>
            </div>
            <span class="status-badge">не змішується з операційним тиском</span>
        </div>
        <div class="cockpit-card-grid">
            <div class="kpi-card danger">
                <span class="label">Стратегічні борги</span>
                <strong><?= e(money_uah_compact($summary['strategic_debt_total'])) ?></strong>
                <small>повний залишок окремо</small>
            </div>
            <div class="kpi-card">
                <span class="label">Цього тижня до оплати</span>
                <strong><?= e(money_uah_compact($summary['operational_due_this_week'])) ?></strong>
            </div>
            <div class="kpi-card">
                <span class="label">Direct costs</span>
                <strong><?= e(money_uah_compact($summary['direct_costs'])) ?></strong>
                <small>потрібна валідація джерела</small>
            </div>
            <div class="kpi-card">
                <span class="label">Нерозподілені операції</span>
                <strong><?= e((string) $summary['unallocated_transactions_count']) ?></strong>
                <small>allocation_status = needs_review</small>
            </div>
        </div>
    </section>

    <section class="panel dashboard-section">
        <div class="section-heading">
            <div>
                <p class="eyebrow">Manager performance</p>
                <h2>Менеджери за <?= e($selectedMonth) ?></h2>
            </div>
            <a class="button-secondary small-button" href="<?= e(base_path('/targets.php?month=' . urlencode($selectedMonth))) ?>">Плани</a>
        </div>
        <div class="table-scroll">
            <table class="data-table">
                <thead>
                <tr>
                    <th>Менеджер</th>
                    <th>План</th>
                    <th>Факт</th>
                    <th>Оплачено в замовленнях</th>
                    <th>Не оплачено</th>
                    <th>Прогрес</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$managers): ?>
                    <tr><td colspan="6">Немає даних за місяць.</td></tr>
                <?php endif; ?>
                <?php foreach ($managers as $manager): ?>
                    <tr>
                        <td><strong><?= e((string) $manager['manager_name']) ?></strong></td>
                        <td class="num"><?= $manager['target_amount_uah'] > 0 ? e(money_uah_compact($manager['target_amount_uah'])) : '—' ?></td>
                        <td class="num"><?= e(money_uah_compact($manager['sales_fact'] ?? 0)) ?></td>
                        <td class="num"><?= e(money_uah_compact($manager['paid_by_order'] ?? 0)) ?></td>
                        <td class="num"><?= e(money_uah_compact($manager['unpaid_by_order'] ?? 0)) ?></td>
                        <td><?= $manager['progress_percent'] !== null ? e((string) $manager['progress_percent']) . '%' : '—' ?></td>
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
