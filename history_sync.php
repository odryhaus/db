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
$currentMonth = date('Y-m');
$rangeSelected = !is_post() && (isset($_GET['from_month']) || isset($_GET['to_month']));

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

function history_sync_worker_message(?array $result): string
{
    if ($result === null) {
        return 'У черзі немає історичних задач orders_backfill для обробки.';
    }

    $job = $result['job'] ?? [];
    $counts = is_array($result['counts'] ?? null) ? $result['counts'] : [];
    return '#' . (string) ((int) ($job['id'] ?? 0))
        . ' ' . (string) ($job['job_type'] ?? '')
        . ': ' . (string) ($result['status'] ?? '')
        . ', seen=' . (string) ((int) ($counts['seen'] ?? 0))
        . ', inserted=' . (string) ((int) ($counts['inserted'] ?? 0))
        . ', updated=' . (string) ((int) ($counts['updated'] ?? 0));
}

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
        $result = sync_worker_run_orders_backfill_once();
        $message = $result === null ? history_sync_worker_message(null) : 'Оброблено: ' . history_sync_worker_message($result) . '.';
    } catch (Throwable $e) {
        $error = 'Не вдалося обробити задачу: ' . $e->getMessage();
    }
}

if (is_post() && (string) ($_POST['action'] ?? '') === 'process_batch_jobs') {
    if (!csrf_is_valid()) {
        http_response_code(400);
        exit('Invalid CSRF token');
    }

    try {
        if (function_exists('set_time_limit')) {
            set_time_limit(240);
        }
        $batchSize = min(3, max(1, (int) ($_POST['batch_size'] ?? 3)));
        $messages = [];
        for ($i = 0; $i < $batchSize; $i++) {
            $result = sync_worker_run_orders_backfill_once();
            if ($result === null) {
                if (!$messages) {
                    $messages[] = history_sync_worker_message(null);
                }
                break;
            }
            $messages[] = history_sync_worker_message($result);
            if (($result['status'] ?? '') === 'failed') {
                break;
            }
        }
        $message = 'Обробка історії: ' . implode(' | ', $messages) . '.';
    } catch (Throwable $e) {
        $error = 'Не вдалося обробити пакет історії: ' . $e->getMessage();
    }
}

if (is_post() && (string) ($_POST['action'] ?? '') === 'clear_queued_backfill') {
    if (!csrf_is_valid()) {
        http_response_code(400);
        exit('Invalid CSRF token');
    }

    try {
        $stmt = db()->prepare("
            DELETE FROM db_sync_jobs
            WHERE status = 'queued'
              AND job_type LIKE 'orders_backfill_%'
        ");
        $stmt->execute();
        $message = 'Видалено queued історичних задач: ' . (string) $stmt->rowCount() . '. Успішні, failed і running задачі не чіпали.';
    } catch (Throwable $e) {
        $error = 'Не вдалося очистити queued історію: ' . $e->getMessage();
    }
}

$jobs = [];
$jobSummary = ['queued_count' => 0, 'running_count' => 0, 'success_count' => 0, 'failed_count' => 0, 'partial_count' => 0];
try {
    if (invoice_table_exists('db_sync_jobs')) {
        $summary = db()->query("
            SELECT
                SUM(status = 'queued') AS queued_count,
                SUM(status = 'running') AS running_count,
                SUM(status = 'success') AS success_count,
                SUM(status = 'failed') AS failed_count,
                SUM(status = 'partial') AS partial_count
            FROM db_sync_jobs
            WHERE job_type LIKE 'orders_backfill_%'
        ")->fetch() ?: [];
        foreach ($jobSummary as $key => $value) {
            $jobSummary[$key] = (int) ($summary[$key] ?? 0);
        }
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

if ($rangeSelected && $message === '' && $error === '') {
    $message = 'Діапазон вибрано: ' . $fromMonth . ' → ' . $toMonth . '. Натисни `Дозавантажити`, щоб поставити ці місяці в чергу.';
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
        <div class="quick-range-grid">
            <span class="client-filter-label">Поставити частинами</span>
            <?php
            $ranges = [
                ['2022-07', '2022-12', '2022 H2'],
                ['2023-01', '2023-06', '2023 H1'],
                ['2023-07', '2023-12', '2023 H2'],
                ['2024-01', '2024-06', '2024 H1'],
                ['2024-07', '2024-12', '2024 H2'],
                ['2025-01', '2025-06', '2025 H1'],
                ['2025-07', '2025-12', '2025 H2'],
                ['2026-01', $currentMonth, '2026'],
            ];
            ?>
            <?php foreach ($ranges as [$rangeFrom, $rangeTo, $rangeLabel]): ?>
                <form method="post" action="<?= e(base_path('/history_sync.php?month=' . urlencode($month))) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="enqueue_orders_backfill">
                    <input type="hidden" name="from_month" value="<?= e($rangeFrom) ?>">
                    <input type="hidden" name="to_month" value="<?= e($rangeTo) ?>">
                    <button type="submit" class="button-secondary"><?= e($rangeLabel) ?></button>
                </form>
            <?php endforeach; ?>
        </div>
        <form class="inline-form" method="post" action="<?= e(base_path('/history_sync.php?month=' . urlencode($month))) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="process_one_job">
            <button type="submit" class="button-secondary">Обробити 1 історичний місяць</button>
            <span class="muted">Працює тільки з `orders_backfill_*`, не забирає `buyers/companies` із загальної черги.</span>
        </form>
        <form class="inline-form" method="post" action="<?= e(base_path('/history_sync.php?month=' . urlencode($month))) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="process_batch_jobs">
            <input type="hidden" name="batch_size" value="3">
            <button type="submit" class="button-secondary">Обробити до 3 історичних місяців</button>
            <span class="muted">Зручно для ручного запуску частинами. Якщо падає timeout, обробляй по 1 місяцю.</span>
        </form>
        <form class="inline-form" method="post" action="<?= e(base_path('/history_sync.php?month=' . urlencode($month))) ?>" onsubmit="return confirm('Видалити тільки queued історичні задачі orders_backfill? Успішні/failed/running не чіпаємо.');">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="clear_queued_backfill">
            <button type="submit" class="button-secondary">Очистити queued історію</button>
            <span class="muted">Після очищення можна ставити імпорт частинами: 2022 H2, 2023 H1 тощо.</span>
        </form>
    </section>

    <section class="panel dashboard-section">
        <div class="section-heading">
            <div>
                <p class="eyebrow">Черга</p>
                <h2>Останні історичні імпорти</h2>
            </div>
            <span class="status-badge">queued <?= e((string) $jobSummary['queued_count']) ?> · running <?= e((string) $jobSummary['running_count']) ?> · success <?= e((string) $jobSummary['success_count']) ?> · failed <?= e((string) $jobSummary['failed_count']) ?></span>
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
