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
$backfillMonthLimit = (int) app_config('keycrm.sync_backfill_month_limit', 72);

function history_sync_month_count(string $fromMonth, string $toMonth): int
{
    $from = DateTimeImmutable::createFromFormat('!Y-m', cockpit_valid_month($fromMonth));
    $to = DateTimeImmutable::createFromFormat('!Y-m', cockpit_valid_month($toMonth));
    if (!$from || !$to || $from > $to) {
        return 0;
    }

    return (((int) $to->format('Y')) - ((int) $from->format('Y'))) * 12 + ((int) $to->format('m')) - ((int) $from->format('m')) + 1;
}

$selectedMonthCount = history_sync_month_count($fromMonth, $toMonth);

if (is_post() && (string) ($_POST['action'] ?? '') === 'enqueue_orders_backfill') {
    if (!csrf_is_valid()) {
        http_response_code(400);
        exit('Invalid CSRF token');
    }

    try {
        $result = sync_enqueue_orders_backfill($fromMonth, $toMonth, (int) (current_user()['id'] ?? 0));
        $message = 'Поставлено в чергу: ' . (string) $result['queued_count'] . ' місяців із ' . (string) count($result['months']) . '.';
        if ((int) $result['queued_count'] === 0) {
            $message = 'Нових місяців не додано: вони вже можуть бути в черзі, виконуються або вже запускались.';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

if (is_post() && (string) ($_POST['action'] ?? '') === 'process_one_job') {
    if (!csrf_is_valid()) {
        http_response_code(400);
        exit('Invalid CSRF token');
    }

    try {
        if (function_exists('set_time_limit')) {
            set_time_limit(80);
        }
        $result = sync_worker_run_once();
        if ($result === null) {
            $message = 'У черзі немає задач для обробки.';
        } else {
            $job = $result['job'] ?? [];
            $counts = is_array($result['counts'] ?? null) ? $result['counts'] : [];
            $message = 'Оброблено задачу #' . (string) ((int) ($job['id'] ?? 0))
                . ' ' . (string) ($job['job_type'] ?? '')
                . ': ' . (string) ($result['status'] ?? '')
                . ', seen=' . (string) ((int) ($counts['seen'] ?? 0))
                . ', inserted=' . (string) ((int) ($counts['inserted'] ?? 0))
                . ', updated=' . (string) ((int) ($counts['updated'] ?? 0)) . '.';
        }
    } catch (Throwable $e) {
        $error = 'Не вдалося обробити задачу: ' . $e->getMessage();
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
            <span class="status-badge">ліміт <?= e((string) $backfillMonthLimit) ?> міс.</span>
        </div>
        <div class="client-work-note">
            <strong>Важливо:</strong>
            <span>`Оновити все` на дашборді оновлює зміни, але не сканує всю стару історію.</span>
            <span>Повна база .BRAND починається з 2022-07, тому історію треба ставити в чергу тут.</span>
            <span>Якщо задачі довго `queued`, значить їх створено, але worker/cron ще не обробив.</span>
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
        <p class="muted">Обраний діапазон: <strong><?= e((string) $selectedMonthCount) ?></strong> міс. Для повної історії постав: з <strong>2022-07</strong> по <strong><?= e(date('Y-m')) ?></strong>. Місяці створюються як окремі задачі `queued`; їх має поступово забрати cron/worker.</p>
        <p class="muted">Якщо production config має менший `keycrm.sync_backfill_month_limit`, діапазон може обрізатися або показати помилку. Для 2022-07 → <?= e(date('Y-m')) ?> потрібно щонайменше <?= e((string) history_sync_month_count('2022-07', date('Y-m'))) ?> міс.</p>
        <form class="inline-form" method="post" action="<?= e(base_path('/history_sync.php?month=' . urlencode($month))) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="process_one_job">
            <button type="submit" class="button-secondary">Обробити 1 задачу зараз</button>
            <span class="muted">Якщо все стоїть `queued`, натисни для перевірки. Для повного імпорту все одно потрібен cron.</span>
        </form>
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
