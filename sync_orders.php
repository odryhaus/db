<?php

require_once __DIR__ . '/bootstrap.php';
require_role('ceo');

$includeFull = 'products,products.offer,status,shipping,manager,buyer,company';
$includeSafe = 'products,products.offer,status,shipping,manager';
$perPage = 50;
$maxPages = 20;
$now = new DateTimeImmutable('now');
$currentMonthStart = $now->modify('first day of this month')->setTime(0, 0, 0);
$previousMonthStart = $currentMonthStart->modify('-1 month');
$nextMonthStart = $currentMonthStart->modify('+1 month');
$monthFrom = $previousMonthStart->format('Y-m');
$monthTo = $currentMonthStart->format('Y-m');
$summary = null;
$error = '';

function sync_money($value): float
{
    return round((float) ($value ?? 0), 2);
}

function sync_get_path(array $data, array $paths)
{
    foreach ($paths as $path) {
        $current = $data;
        foreach (explode('.', $path) as $part) {
            if (!is_array($current) || !array_key_exists($part, $current)) {
                $current = null;
                break;
            }
            $current = $current[$part];
        }
        if (is_scalar($current) && $current !== '') {
            return $current;
        }
    }

    return null;
}

function sync_compact_date($value): ?string
{
    if (!$value) {
        return null;
    }
    $time = strtotime((string) $value);
    return $time ? date('Y-m-d H:i:s', $time) : null;
}

function sync_order_month($orderedAt): ?string
{
    if (!$orderedAt) {
        return null;
    }
    $time = strtotime((string) $orderedAt);
    return $time ? date('Y-m', $time) : null;
}

function sync_keycrm_request(string $endpoint, string $apiKey): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP cURL extension is not available.');
    }

    $baseUrl = rtrim((string) app_config('keycrm.base_url', 'https://openapi.keycrm.app/v1'), '/');
    $url = $baseUrl . '/' . ltrim($endpoint, '/');
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
            'Expect:',
        ],
    ]);

    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    $json = json_decode($body === false ? '' : (string) $body, true);

    return [
        'status' => $status,
        'body' => $body === false ? '' : (string) $body,
        'json' => is_array($json) ? $json : null,
        'error' => $curlError,
    ];
}

function sync_fetch_order_page(int $page, int $perPage, string $include, string $apiKey): array
{
    $endpoint = 'order?' . http_build_query([
        'per_page' => $perPage,
        'page' => $page,
        'include' => $include,
    ]);

    return sync_keycrm_request($endpoint, $apiKey);
}

function sync_response_orders(array $response): array
{
    $json = $response['json'] ?? null;
    if (!is_array($json)) {
        return [];
    }

    if (isset($json['data']) && is_array($json['data'])) {
        return $json['data'];
    }

    return array_keys($json) === range(0, count($json) - 1) ? $json : [];
}

function sync_extract_order(array $order): array
{
    $grandTotal = sync_money($order['grand_total'] ?? 0);
    $paidTotal = sync_money($order['payments_total'] ?? 0);
    $unpaid = max($grandTotal - $paidTotal, 0);
    $orderedAt = sync_compact_date($order['ordered_at'] ?? null);
    $buyer = is_array($order['buyer'] ?? null) ? $order['buyer'] : [];
    $company = is_array($order['company'] ?? null) ? $order['company'] : [];
    $rawJson = json_encode($order, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return [
        'keycrm_id' => (int) ($order['id'] ?? 0),
        'order_number' => (string) (($order['number'] ?? null) ?: ($order['source_uuid'] ?? ($order['id'] ?? ''))),
        'ordered_at' => $orderedAt,
        'order_month' => sync_order_month($orderedAt),
        'source_created_at' => sync_compact_date($order['created_at'] ?? null),
        'source_updated_at' => sync_compact_date($order['updated_at'] ?? null),
        'closed_at' => sync_compact_date($order['closed_at'] ?? null),
        'status_changed_at' => sync_compact_date($order['status_changed_at'] ?? null),
        'status_id' => sync_get_path($order, ['status.id']),
        'status_group_id' => $order['status_group_id'] ?? null,
        'status_name' => sync_get_path($order, ['status.name']),
        'payment_status' => $order['payment_status'] ?? null,
        'manager_id' => sync_get_path($order, ['manager.id']),
        'manager_name' => sync_get_path($order, ['manager.full_name', 'manager.name']),
        'manager_email' => sync_get_path($order, ['manager.email']),
        'client_id' => $order['client_id'] ?? sync_get_path($order, ['client.id']),
        'client_name' => sync_get_path($order, ['client.name', 'client.full_name']),
        'buyer_id' => $buyer['id'] ?? null,
        'buyer_name' => $buyer['full_name'] ?? ($buyer['name'] ?? null),
        'buyer_email' => $buyer['email'] ?? null,
        'buyer_phone' => $buyer['phone'] ?? null,
        'company_id' => $company['id'] ?? null,
        'company_name' => $company['name'] ?? null,
        'total_amount_uah' => $grandTotal,
        'paid_amount_uah' => $paidTotal,
        'unpaid_amount_uah' => $unpaid,
        'products_total_uah' => sync_money($order['products_total'] ?? 0),
        'expenses_sum_uah' => sync_money($order['expenses_sum'] ?? 0),
        'margin_sum_uah' => sync_money($order['margin_sum'] ?? 0),
        'shipping_date_actual' => sync_compact_date(sync_get_path($order, ['shipping.shipping_date_actual'])),
        'shipping_status' => sync_get_path($order, ['shipping.shipping_status']),
        'tracking_code' => sync_get_path($order, ['shipping.tracking_code']),
        'raw_json' => $rawJson === false ? null : $rawJson,
    ];
}

function sync_upsert_order(PDO $pdo, array $data): void
{
    $columns = [
        'keycrm_id', 'order_number', 'ordered_at', 'order_month', 'source_created_at', 'source_updated_at',
        'closed_at', 'status_changed_at', 'status_id', 'status_group_id', 'status_name', 'payment_status',
        'manager_id', 'manager_name', 'manager_email', 'client_id', 'client_name', 'buyer_id', 'buyer_name',
        'buyer_email', 'buyer_phone', 'company_id', 'company_name', 'total_amount_uah', 'paid_amount_uah',
        'unpaid_amount_uah', 'products_total_uah', 'expenses_sum_uah', 'margin_sum_uah', 'shipping_date_actual',
        'shipping_status', 'tracking_code', 'raw_json',
    ];
    $placeholders = [];
    $updates = [];
    foreach ($columns as $column) {
        $placeholders[] = ':' . $column;
        if ($column !== 'keycrm_id') {
            $updates[] = "{$column}=VALUES({$column})";
        }
    }
    $updates[] = 'synced_at=NOW()';

    $sql = 'INSERT INTO db_orders (' . implode(',', $columns) . ', synced_at) VALUES (' . implode(',', $placeholders) . ', NOW())'
        . ' ON DUPLICATE KEY UPDATE ' . implode(',', $updates);

    $stmt = $pdo->prepare($sql);
    foreach ($columns as $column) {
        $stmt->bindValue(':' . $column, $data[$column] ?? null);
    }
    $stmt->execute();
}

function sync_create_run(PDO $pdo, string $monthFrom, string $monthTo, int $userId): int
{
    $stmt = $pdo->prepare("
        INSERT INTO db_sync_runs
            (sync_type, started_at, status, month_from, month_to, orders_seen, orders_upserted, created_by_user_id)
        VALUES
            ('manual_current_previous_month', NOW(), 'running', :month_from, :month_to, 0, 0, :created_by_user_id)
    ");
    $stmt->execute([
        'month_from' => $monthFrom,
        'month_to' => $monthTo,
        'created_by_user_id' => (int) $userId,
    ]);

    return (int) $pdo->lastInsertId();
}

function sync_finish_run(PDO $pdo, int $runId, string $status, int $seen, int $upserted, ?string $errorMessage): void
{
    $stmt = $pdo->prepare("
        UPDATE db_sync_runs
        SET finished_at = NOW(),
            status = :status,
            orders_seen = :orders_seen,
            orders_upserted = :orders_upserted,
            error_message = :error_message
        WHERE id = :id
    ");
    $stmt->execute([
        'status' => $status,
        'orders_seen' => $seen,
        'orders_upserted' => $upserted,
        'error_message' => $errorMessage,
        'id' => $runId,
    ]);
}

function sync_run(DateTimeImmutable $previousMonthStart, DateTimeImmutable $nextMonthStart, string $monthFrom, string $monthTo, int $perPage, int $maxPages, string $includeFull, string $includeSafe): array
{
    $apiKey = (string) app_config('keycrm.api_key', '');
    if ($apiKey === '' || $apiKey === 'CHANGE_ME_IN_REAL_CONFIG') {
        throw new RuntimeException('KeyCRM API key is not configured in config/config.php.');
    }

    $pdo = db();
    $runId = sync_create_run($pdo, $monthFrom, $monthTo, (int) (current_user()['id'] ?? 0));
    $seen = 0;
    $upserted = 0;
    $errors = [];
    $include = $includeFull;

    try {
        for ($page = 1; $page <= $maxPages; $page++) {
            $response = sync_fetch_order_page($page, $perPage, $include, $apiKey);

            if (($response['status'] < 200 || $response['status'] >= 300) && $include === $includeFull) {
                $errors[] = 'Full include failed; retried without buyer/company includes.';
                $include = $includeSafe;
                $response = sync_fetch_order_page($page, $perPage, $include, $apiKey);
            }

            if ($response['status'] < 200 || $response['status'] >= 300) {
                throw new RuntimeException('KeyCRM request failed with HTTP ' . $response['status']);
            }

            $orders = sync_response_orders($response);
            if (!$orders) {
                break;
            }

            $oldOrdersOnPage = 0;
            foreach ($orders as $order) {
                if (!is_array($order)) {
                    continue;
                }
                $seen++;
                $orderedAtRaw = $order['ordered_at'] ?? null;
                $orderedTime = $orderedAtRaw ? strtotime((string) $orderedAtRaw) : false;
                if (!$orderedTime) {
                    continue;
                }

                $orderedDate = (new DateTimeImmutable('@' . $orderedTime))->setTimezone(new DateTimeZone(date_default_timezone_get()));
                if ($orderedDate < $previousMonthStart) {
                    $oldOrdersOnPage++;
                    continue;
                }
                if ($orderedDate >= $nextMonthStart) {
                    continue;
                }

                $data = sync_extract_order($order);
                if (!$data['keycrm_id'] || !$data['order_month']) {
                    continue;
                }
                sync_upsert_order($pdo, $data);
                $upserted++;
            }

            if ($oldOrdersOnPage === count($orders)) {
                break;
            }
        }

        $message = $errors ? implode(' ', $errors) : null;
        sync_finish_run($pdo, $runId, 'success', $seen, $upserted, $message);

        return [
            'status' => 'success',
            'month_from' => $monthFrom,
            'month_to' => $monthTo,
            'orders_seen' => $seen,
            'orders_upserted' => $upserted,
            'error_message' => $message,
        ];
    } catch (Throwable $e) {
        sync_finish_run($pdo, $runId, 'failed', $seen, $upserted, $e->getMessage());
        throw $e;
    }
}

if (is_post()) {
    if (!csrf_is_valid()) {
        $error = 'Invalid sync request. Refresh the page and try again.';
    } else {
        try {
            $summary = sync_run($previousMonthStart, $nextMonthStart, $monthFrom, $monthTo, $perPage, $maxPages, $includeFull, $includeSafe);
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

try {
    $runs = db()->query("
        SELECT id, sync_type, started_at, finished_at, status, month_from, month_to, orders_seen, orders_upserted, error_message
        FROM db_sync_runs
        ORDER BY started_at DESC
        LIMIT 10
    ")->fetchAll();
} catch (Throwable $e) {
    $runs = [];
    if ($error === '') {
        $error = 'Sync runs table is not available.';
    }
}
?>
<!doctype html>
<html lang="uk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sync Orders | .BRAND DB</title>
    <link rel="stylesheet" href="<?= e(base_path('/assets/app.css')) ?>">
</head>
<body>
    <main class="page">
        <header class="topbar">
            <div>
                <p class="eyebrow">CEO only</p>
                <h1>Sync Orders</h1>
            </div>
            <nav class="nav">
                <a href="<?= e(base_path('/index.php')) ?>">Dashboard</a>
                <a href="<?= e(base_path('/targets.php')) ?>">Targets</a>
                <a href="<?= e(base_path('/expenses.php')) ?>">Expenses</a>
                <a href="<?= e(base_path('/users.php')) ?>">Users</a>
                <a href="<?= e(base_path('/logout.php')) ?>">Logout</a>
            </nav>
        </header>

        <?php if ($error !== ''): ?>
            <div class="alert"><?= e($error) ?></div>
        <?php endif; ?>

        <?php if ($summary): ?>
            <div class="notice">
                Sync <?= e($summary['status']) ?>:
                <?= e((string) $summary['orders_seen']) ?> seen,
                <?= e((string) $summary['orders_upserted']) ?> upserted.
                <?php if (!empty($summary['error_message'])): ?>
                    <?= e((string) $summary['error_message']) ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <section class="panel action-panel">
            <div>
                <span class="label">Sync scope</span>
                <h2><?= e($monthFrom) ?> to <?= e($monthTo) ?></h2>
                <p>Manual server-side KeyCRM sync. Current month and previous month only.</p>
            </div>
            <form method="post" action="<?= e(base_path('/sync_orders.php')) ?>">
                <?= csrf_field() ?>
                <button type="submit">Run sync</button>
            </form>
        </section>

        <section class="panel table-panel">
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Type</th>
                            <th>Started</th>
                            <th>Finished</th>
                            <th>Status</th>
                            <th>Months</th>
                            <th>Seen</th>
                            <th>Upserted</th>
                            <th>Error</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$runs): ?>
                            <tr><td colspan="9">No sync runs yet.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($runs as $run): ?>
                            <tr>
                                <td><?= e((string) $run['id']) ?></td>
                                <td><?= e((string) $run['sync_type']) ?></td>
                                <td><?= e((string) $run['started_at']) ?></td>
                                <td><?= e((string) ($run['finished_at'] ?: '—')) ?></td>
                                <td><?= e((string) $run['status']) ?></td>
                                <td><?= e((string) $run['month_from']) ?> – <?= e((string) $run['month_to']) ?></td>
                                <td><?= e((string) $run['orders_seen']) ?></td>
                                <td><?= e((string) $run['orders_upserted']) ?></td>
                                <td><?= e((string) ($run['error_message'] ?: '—')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</body>
</html>
