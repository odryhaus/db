<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/cockpit.php';
require_once __DIR__ . '/financial.php';
require_once __DIR__ . '/cockpit_layout.php';
require_once __DIR__ . '/sync_core.php';

require_role('ceo');

$month = cockpit_valid_month((string) ($_GET['month'] ?? date('Y-m')));
$fromMonth = cockpit_valid_month((string) ($_POST['from_month'] ?? $_GET['from_month'] ?? '2022-07'));
$toMonth = cockpit_valid_month((string) ($_POST['to_month'] ?? $_GET['to_month'] ?? date('Y-m')));
$message = '';
$error = '';

if (is_post() && (string) ($_POST['action'] ?? '') === 'enqueue_orders_backfill') {
    if (!csrf_is_valid()) {
        http_response_code(400);
        exit('Invalid CSRF token');
    }

    try {
        $result = sync_enqueue_orders_backfill($fromMonth, $toMonth, (int) (current_user()['id'] ?? 0));
        $message = 'Поставлено в чергу: ' . (string) $result['queued_count'] . ' місяців.';
        if ((int) $result['queued_count'] === 0) {
            $message = 'Нових місяців не додано: вони вже можуть бути в черзі або виконуються.';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$jobs = [];
try {
    if (invoice_table_exists('db_sync_jobs')) {
        $jobs = db()->query("
            SELECT id, job_type, status, started_at, finished_at, records_seen, records_inserted,
                   records_updated, records_unchanged, error_message, created_at
            FROM db_sync_jobs
            WHERE job_type LIKE 'orders_backfill_%'
            ORDER BY id DESC
            LIMIT 30
        ")->fetchAll();
    }
} catch (Throwable $e) {
    $jobs = [];
}

?><!doctype html>
<html lang="uk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Імпорт історії — .BRAND DB</title>
    <link rel="stylesheet" href="<?= e(asset_path('/assets/app.css')) ?>">
</head>
<body>
<main class="app-shell cockpit-shell">
    <?php cockpit_page_header('CEO Money Cockpit', 'Імпорт історії', 'Безпечне дозавантаження замовлень помісячно з KeyCRM у локальний кеш.', 'history_sync', $month); ?>

    <?php if ($message !== ''): ?>
        <section class="panel dashboard-section"><span class="status-badge status-badge--success"><?= e($message) ?></span></section>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <section class="panel dashboard-section"><span class="status-badge status-badge--danger"><?= e($error) ?></span></section>
    <?php endif; ?>

    <section class="panel dashboard-section">
        <div class="section-heading">
            <div>
                <p class="eyebrow">Backfill</p>
                <h2>Дозавантажити замовлення за місяці</h2>
            </div>
            <span class="status-badge">filter[created_between] → ordered_at</span>
        </div>
        <form class="client-balance-toolbar" method="post" action="<?= e(base_path('/history_sync.php?month=' . urlencode($month))) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="enqueue_orders_backfill">
            <label>
                <span>З місяця</span>
                <input type="month" name="from_month" value="<?= e($fromMonth) ?>">
            </label>
            <label>
                <span>По місяць</span>
                <input type="month" name="to_month" value="<?= e($toMonth) ?>">
            </label>
            <button type="submit">Дозавантажити</button>
        </form>
        <p class="muted">Для повної історії постав: з <strong>2022-07</strong> по <strong><?= e(date('Y-m')) ?></strong>. Місяці створюються як окремі задачі `queued`; їх має поступово забрати cron/worker. Якщо все довго висить у `queued`, треба перевірити cron `cron/sync_worker.php`.</p>
    </section>

    <section class="panel dashboard-section">
        <div class="section-heading">
            <div>
                <p class="eyebrow">Черга</p>
                <h2>Останні історичні імпорти</h2>
            </div>
        </div>
        <div class="table-scroll">
            <table class="data-table">
                <thead>
                <tr>
                    <th>ID</th>
                    <th>Місяць</th>
                    <th>Статус</th>
                    <th>Старт</th>
                    <th>Фініш</th>
                    <th class="num">Seen</th>
                    <th class="num">Inserted</th>
                    <th class="num">Updated</th>
                    <th class="num">Unchanged</th>
                    <th>Помилка</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$jobs): ?><tr><td colspan="10">Історичних імпортів ще немає.</td></tr><?php endif; ?>
                <?php foreach ($jobs as $job): ?>
                    <?php $jobMonth = sync_backfill_month_from_job((string) $job['job_type']) ?: '—'; ?>
                    <tr>
                        <td><?= e((string) $job['id']) ?></td>
                        <td><strong><?= e($jobMonth) ?></strong></td>
                        <td><span class="status-badge"><?= e((string) $job['status']) ?></span></td>
                        <td><?= e((string) ($job['started_at'] ?: $job['created_at'])) ?></td>
                        <td><?= e((string) ($job['finished_at'] ?: '—')) ?></td>
                        <td class="num"><?= e((string) $job['records_seen']) ?></td>
                        <td class="num"><?= e((string) $job['records_inserted']) ?></td>
                        <td class="num"><?= e((string) $job['records_updated']) ?></td>
                        <td class="num"><?= e((string) $job['records_unchanged']) ?></td>
                        <td class="wrap"><?= e((string) ($job['error_message'] ?: '')) ?></td>
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
