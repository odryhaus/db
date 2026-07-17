<?php

function sync_job_types(): array
{
    return ['orders', 'unpaid_orders', 'payments', 'companies', 'buyers', 'order_expenses', 'statuses'];
}

function sync_key_for_job(string $jobType): string
{
    return 'keycrm_' . $jobType;
}

function sync_api_key(): string
{
    $apiKey = trim((string) app_config('keycrm.api_key', ''));
    if ($apiKey === '' || $apiKey === 'CHANGE_ME_IN_REAL_CONFIG') {
        throw new RuntimeException('KeyCRM API key is not configured in config/config.php.');
    }

    return $apiKey;
}

function sync_http_get(string $endpoint, string $apiKey): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP cURL extension is not available.');
    }

    $baseUrl = rtrim((string) app_config('keycrm.base_url', 'https://openapi.keycrm.app/v1'), '/');
    $ch = curl_init($baseUrl . '/' . ltrim($endpoint, '/'));
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
    $error = curl_error($ch);
    curl_close($ch);

    $json = json_decode($body === false ? '' : (string) $body, true);
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException('KeyCRM request failed with HTTP ' . $status . ($error ? ': ' . $error : ''));
    }

    return [
        'status' => $status,
        'json' => is_array($json) ? $json : [],
    ];
}

function sync_rows(array $response): array
{
    $json = $response['json'] ?? [];
    if (isset($json['data']) && is_array($json['data'])) {
        return $json['data'];
    }

    return is_array($json) && array_keys($json) === range(0, count($json) - 1) ? $json : [];
}

function sync_scalar_path(array $data, array $paths)
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
        if (is_scalar($current) && trim((string) $current) !== '') {
            return $current;
        }
    }

    return null;
}

function sync_datetime($value): ?string
{
    $time = $value ? strtotime((string) $value) : false;
    return $time ? gmdate('Y-m-d H:i:s', $time) : null;
}

function sync_source_hash(array $row): string
{
    return hash('sha256', (string) json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function sync_state_row(string $syncKey): array
{
    $stmt = db()->prepare('SELECT * FROM db_sync_state WHERE sync_key = :sync_key LIMIT 1');
    $stmt->execute(['sync_key' => $syncKey]);
    $row = $stmt->fetch();
    return $row ?: [];
}

function sync_window_params(string $syncKey): array
{
    $state = sync_state_row($syncKey);
    $last = (string) ($state['last_successful_sync_at'] ?? '');
    if ($last === '') {
        return [];
    }

    $from = gmdate('Y-m-d H:i:s', max(0, strtotime($last . ' UTC') - 120));
    $to = gmdate('Y-m-d H:i:s');

    return ['filter[updated_between]' => $from . ',' . $to];
}

function sync_endpoint(string $jobType, int $page, int $limit): string
{
    $params = [
        'limit' => $limit,
        'page' => $page,
    ];
    $syncKey = sync_key_for_job($jobType);
    foreach (sync_window_params($syncKey) as $key => $value) {
        $params[$key] = $value;
    }

    if (in_array($jobType, ['orders', 'payments', 'order_expenses'], true)) {
        $params['include'] = 'buyer,products.offer,status,manager,payments,expenses';
        return 'order?' . http_build_query($params);
    }
    if ($jobType === 'buyers') {
        $params['include'] = 'company';
        return 'buyer?' . http_build_query($params);
    }
    if ($jobType === 'companies') {
        return 'companies?' . http_build_query($params);
    }
    if ($jobType === 'statuses') {
        return 'order/status?' . http_build_query(['limit' => $limit, 'page' => $page]);
    }

    throw new InvalidArgumentException('Unknown sync job type: ' . $jobType);
}

function sync_order_detail_endpoint(int $orderId): string
{
    return 'order/' . $orderId . '?' . http_build_query([
        'include' => 'buyer,products.offer,status,manager,payments,expenses',
    ]);
}

function sync_order_filter_endpoint(int $orderId): string
{
    return 'order?' . http_build_query([
        'limit' => 1,
        'page' => 1,
        'filter[order_id]' => $orderId,
        'include' => 'buyer,products.offer,status,manager,payments,expenses',
    ]);
}

function sync_enqueue_global_refresh(int $userId): int
{
    ensure_sync_tables();
    sync_recover_stale_jobs();
    $pdo = db();
    $active = $pdo->query("
        SELECT id
        FROM db_sync_jobs
        WHERE job_type = 'global_refresh'
          AND status IN ('queued','running')
        ORDER BY id DESC
        LIMIT 1
    ")->fetchColumn();
    if ($active) {
        sync_ensure_parent_jobs((int) $active, $userId);
        return (int) $active;
    }

    $pdo->beginTransaction();
    $stmt = $pdo->prepare("
        INSERT INTO db_sync_jobs (parent_job_id, job_type, status, created_by_user_id)
        VALUES (NULL, 'global_refresh', 'running', :created_by_user_id)
    ");
    $stmt->execute(['created_by_user_id' => $userId ?: null]);
    $parentId = (int) $pdo->lastInsertId();
    $child = $pdo->prepare("
        INSERT INTO db_sync_jobs (parent_job_id, job_type, status, created_by_user_id)
        VALUES (:parent_job_id, :job_type, 'queued', :created_by_user_id)
    ");
    foreach (sync_job_types() as $jobType) {
        $child->execute([
            'parent_job_id' => $parentId,
            'job_type' => $jobType,
            'created_by_user_id' => $userId ?: null,
        ]);
    }
    $pdo->commit();

    return $parentId;
}

function sync_ensure_parent_jobs(int $parentId, ?int $userId = null): void
{
    if ($parentId <= 0) {
        return;
    }

    $stmt = db()->prepare("
        INSERT INTO db_sync_jobs (parent_job_id, job_type, status, created_by_user_id)
        SELECT :parent_job_id, :job_type, 'queued', :created_by_user_id
        WHERE NOT EXISTS (
            SELECT 1
            FROM db_sync_jobs
            WHERE parent_job_id = :parent_job_id_check
              AND job_type = :job_type_check
            LIMIT 1
        )
    ");
    foreach (sync_job_types() as $jobType) {
        $stmt->execute([
            'parent_job_id' => $parentId,
            'job_type' => $jobType,
            'created_by_user_id' => $userId,
            'parent_job_id_check' => $parentId,
            'job_type_check' => $jobType,
        ]);
    }
}

function sync_enqueue_delta_jobs(?int $userId = null): int
{
    ensure_sync_tables();
    $count = 0;
    $stmt = db()->prepare("
        INSERT INTO db_sync_jobs (parent_job_id, job_type, status, created_by_user_id)
        SELECT NULL, :job_type, 'queued', :created_by_user_id
        WHERE NOT EXISTS (
            SELECT 1 FROM db_sync_jobs
            WHERE job_type = :job_type_check
              AND status IN ('queued','running')
            LIMIT 1
        )
    ");
    foreach (sync_job_types() as $jobType) {
        $stmt->execute([
            'job_type' => $jobType,
            'job_type_check' => $jobType,
            'created_by_user_id' => $userId,
        ]);
        $count += $stmt->rowCount();
    }

    return $count;
}

function sync_claim_job(): ?array
{
    sync_recover_stale_jobs();
    $pdo = db();
    $pdo->beginTransaction();
    $job = $pdo->query("
        SELECT *
        FROM db_sync_jobs
        WHERE status = 'queued'
          AND job_type <> 'global_refresh'
        ORDER BY id ASC
        LIMIT 1
        FOR UPDATE
    ")->fetch();
    if (!$job) {
        $pdo->commit();
        return null;
    }

    $running = $pdo->prepare("
        SELECT id
        FROM db_sync_jobs
        WHERE job_type = :job_type
          AND status = 'running'
          AND id <> :id
        LIMIT 1
    ");
    $running->execute([
        'job_type' => $job['job_type'],
        'id' => (int) $job['id'],
    ]);
    if ($running->fetchColumn()) {
        $pdo->commit();
        return null;
    }

    $stmt = $pdo->prepare("UPDATE db_sync_jobs SET status = 'running', started_at = NOW() WHERE id = :id");
    $stmt->execute(['id' => (int) $job['id']]);
    $pdo->commit();
    $job['status'] = 'running';

    return $job;
}

function sync_finish_job(array $job, string $status, array $counts, ?string $error = null): void
{
    $stmt = db()->prepare("
        UPDATE db_sync_jobs
        SET status = :status,
            finished_at = NOW(),
            records_seen = :seen,
            records_inserted = :inserted,
            records_updated = :updated,
            records_unchanged = :unchanged,
            error_message = :error
        WHERE id = :id
    ");
    $stmt->execute([
        'status' => $status,
        'seen' => (int) ($counts['seen'] ?? 0),
        'inserted' => (int) ($counts['inserted'] ?? 0),
        'updated' => (int) ($counts['updated'] ?? 0),
        'unchanged' => (int) ($counts['unchanged'] ?? 0),
        'error' => $error,
        'id' => (int) $job['id'],
    ]);
    sync_update_state(sync_key_for_job((string) $job['job_type']), $status, $error, $counts['_source_updated_at'] ?? null);
    sync_update_parent_job((int) ($job['parent_job_id'] ?? 0));
}

function sync_update_state(string $syncKey, string $status, ?string $error = null, ?string $sourceUpdatedAt = null): void
{
    $success = in_array($status, ['success','partial'], true);
    $stmt = db()->prepare("
        INSERT INTO db_sync_state (sync_key, last_attempt_at, last_successful_sync_at, last_source_updated_at, status, error_message)
        VALUES (:sync_key, NOW(), " . ($success ? 'NOW()' : 'NULL') . ", :source_updated_at, :status, :error)
        ON DUPLICATE KEY UPDATE
            last_attempt_at = NOW(),
            last_successful_sync_at = " . ($success ? 'NOW()' : 'last_successful_sync_at') . ",
            last_source_updated_at = COALESCE(:source_updated_at_update, last_source_updated_at),
            status = :status_update,
            error_message = :error_update
    ");
    $stmt->execute([
        'sync_key' => $syncKey,
        'source_updated_at' => $sourceUpdatedAt,
        'status' => $status,
        'error' => $error,
        'source_updated_at_update' => $sourceUpdatedAt,
        'status_update' => $status,
        'error_update' => $error,
    ]);
}

function sync_update_parent_job(int $parentId): void
{
    if ($parentId <= 0) {
        return;
    }
    $stmt = db()->prepare("
        SELECT
            COUNT(*) AS total_count,
            SUM(status IN ('queued','running')) AS active_count,
            SUM(status = 'failed') AS failed_count,
            SUM(status = 'partial') AS partial_count
        FROM db_sync_jobs
        WHERE parent_job_id = :parent_id
    ");
    $stmt->execute(['parent_id' => $parentId]);
    $row = $stmt->fetch() ?: [];
    if ((int) ($row['active_count'] ?? 0) > 0) {
        return;
    }
    $status = ((int) ($row['failed_count'] ?? 0) > 0) ? 'partial' : (((int) ($row['partial_count'] ?? 0) > 0) ? 'partial' : 'success');
    db()->prepare("UPDATE db_sync_jobs SET status = :status, finished_at = NOW() WHERE id = :id")->execute([
        'status' => $status,
        'id' => $parentId,
    ]);
}

function sync_recover_stale_jobs(): void
{
    if (!invoice_table_exists('db_sync_jobs')) {
        return;
    }

    $timeoutMinutes = max(5, (int) app_config('keycrm.sync_job_timeout_minutes', 15));
    db()->exec("
        UPDATE db_sync_jobs
        SET status = 'failed',
            finished_at = NOW(),
            error_message = COALESCE(error_message, 'Sync job timed out and was auto-recovered.')
        WHERE status = 'running'
          AND job_type <> 'global_refresh'
          AND started_at IS NOT NULL
          AND started_at < DATE_SUB(NOW(), INTERVAL {$timeoutMinutes} MINUTE)
    ");

    $parents = db()->query("
        SELECT DISTINCT parent_job_id
        FROM db_sync_jobs
        WHERE parent_job_id IS NOT NULL
    ")->fetchAll();
    foreach ($parents as $parent) {
        sync_update_parent_job((int) ($parent['parent_job_id'] ?? 0));
    }

    db()->exec("
        UPDATE db_sync_jobs parent
        SET parent.status = 'partial',
            parent.finished_at = NOW(),
            parent.error_message = COALESCE(parent.error_message, 'Global refresh timed out and was auto-recovered.')
        WHERE parent.job_type = 'global_refresh'
          AND parent.status = 'running'
          AND parent.created_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE)
          AND NOT EXISTS (
              SELECT 1
              FROM db_sync_jobs child
              WHERE child.parent_job_id = parent.id
                AND child.status IN ('queued','running')
          )
    ");
}

function sync_upsert_order_from_keycrm(array $order): string
{
    $hash = sync_source_hash($order);
    $id = (int) ($order['id'] ?? 0);
    if ($id <= 0) {
        return 'unchanged';
    }
    $stmt = db()->prepare('SELECT source_hash FROM db_orders WHERE keycrm_id = :id LIMIT 1');
    $stmt->execute(['id' => $id]);
    $oldHash = (string) ($stmt->fetchColumn() ?: '');
    if ($oldHash === $hash) {
        db()->prepare('UPDATE db_orders SET synced_at = NOW() WHERE keycrm_id = :id')->execute(['id' => $id]);
        return 'unchanged';
    }

    $buyer = is_array($order['buyer'] ?? null) ? $order['buyer'] : [];
    $company = is_array($buyer['company'] ?? null) ? $buyer['company'] : [];
    $status = is_array($order['status'] ?? null) ? $order['status'] : [];
    $manager = is_array($order['manager'] ?? null) ? $order['manager'] : [];
    $total = round((float) ($order['grand_total'] ?? 0), 2);
    $paid = round((float) (($order['payments_total'] ?? null) ?? 0), 2);
    $orderedAt = sync_datetime($order['ordered_at'] ?? null);
    $rawJson = json_encode($order, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $data = [
        'keycrm_id' => $id,
        'order_number' => (string) (($order['number'] ?? '') ?: (($order['source_uuid'] ?? '') ?: $id)),
        'ordered_at' => $orderedAt,
        'order_month' => $orderedAt ? substr($orderedAt, 0, 7) : null,
        'source_created_at' => sync_datetime($order['created_at'] ?? null),
        'source_updated_at' => sync_datetime($order['updated_at'] ?? null),
        'status_id' => $status['id'] ?? null,
        'status_name' => $status['name'] ?? null,
        'payment_status' => $order['payment_status'] ?? null,
        'manager_id' => $manager['id'] ?? null,
        'manager_name' => ($manager['full_name'] ?? null) ?: ($manager['name'] ?? null),
        'buyer_id' => ($buyer['id'] ?? null) ?: ($order['buyer_id'] ?? null),
        'buyer_name' => ($buyer['full_name'] ?? null) ?: ($buyer['name'] ?? null),
        'buyer_email' => $buyer['email'] ?? null,
        'buyer_phone' => $buyer['phone'] ?? null,
        'company_id' => ($company['id'] ?? null) ?: ($buyer['company_id'] ?? null),
        'company_name' => ($company['name'] ?? null) ?: ($company['title'] ?? null),
        'total_amount_uah' => $total,
        'paid_amount_uah' => $paid,
        'unpaid_amount_uah' => max($total - $paid, 0),
        'products_total_uah' => round((float) ($order['products_total'] ?? 0), 2),
        'expenses_sum_uah' => round((float) ($order['expenses_sum'] ?? 0), 2),
        'margin_sum_uah' => round((float) ($order['margin_sum'] ?? 0), 2),
        'raw_json' => $rawJson ?: null,
        'source_hash' => $hash,
    ];

    $columns = array_keys($data);
    $updates = [];
    foreach ($columns as $column) {
        if ($column !== 'keycrm_id') {
            $updates[] = "{$column}=VALUES({$column})";
        }
    }
    $updates[] = 'synced_at=NOW()';
    $sql = 'INSERT INTO db_orders (' . implode(',', $columns) . ', synced_at) VALUES (:' . implode(',:', $columns) . ', NOW()) ON DUPLICATE KEY UPDATE ' . implode(',', $updates);
    $insert = $oldHash === '';
    $stmt = db()->prepare($sql);
    $stmt->execute($data);

    return $insert ? 'inserted' : 'updated';
}

function sync_upsert_payment(array $payment, int $orderId): string
{
    $id = (string) (($payment['id'] ?? '') ?: (($payment['uuid'] ?? '') ?: hash('sha1', $orderId . json_encode($payment))));
    $hash = sync_source_hash($payment);
    $stmt = db()->prepare('SELECT source_hash FROM db_order_payments WHERE keycrm_payment_id = :id LIMIT 1');
    $stmt->execute(['id' => $id]);
    $oldHash = (string) ($stmt->fetchColumn() ?: '');
    if ($oldHash === $hash) {
        return 'unchanged';
    }
    $method = (string) (($payment['payment_method'] ?? '') ?: ($payment['payment_method_name'] ?? ''));
    $stmt = db()->prepare("
        INSERT INTO db_order_payments
            (keycrm_payment_id, keycrm_order_id, payment_method_id, payment_method_name, amount, currency, status, payment_date, source_updated_at, source_hash, raw_json, synced_at)
        VALUES
            (:keycrm_payment_id, :keycrm_order_id, :payment_method_id, :payment_method_name, :amount, :currency, :status, :payment_date, :source_updated_at, :source_hash, :raw_json, NOW())
        ON DUPLICATE KEY UPDATE
            keycrm_order_id = VALUES(keycrm_order_id),
            payment_method_id = VALUES(payment_method_id),
            payment_method_name = VALUES(payment_method_name),
            amount = VALUES(amount),
            currency = VALUES(currency),
            status = VALUES(status),
            payment_date = VALUES(payment_date),
            source_updated_at = VALUES(source_updated_at),
            source_hash = VALUES(source_hash),
            raw_json = VALUES(raw_json),
            synced_at = NOW()
    ");
    $stmt->execute([
        'keycrm_payment_id' => $id,
        'keycrm_order_id' => $orderId,
        'payment_method_id' => $payment['payment_method_id'] ?? null,
        'payment_method_name' => $method !== '' ? $method : null,
        'amount' => round((float) ($payment['amount'] ?? 0), 2),
        'currency' => (string) (($payment['actual_currency'] ?? '') ?: (($payment['currency'] ?? '') ?: 'UAH')),
        'status' => $payment['status'] ?? null,
        'payment_date' => sync_datetime($payment['payment_date'] ?? null),
        'source_updated_at' => sync_datetime($payment['updated_at'] ?? null),
        'source_hash' => $hash,
        'raw_json' => json_encode($payment, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);

    sync_recalculate_order_payment_totals($orderId);

    return $oldHash === '' ? 'inserted' : 'updated';
}

function sync_payment_counts_as_paid(?string $status): bool
{
    $rawStatus = trim((string) $status);
    $status = function_exists('mb_strtolower') ? mb_strtolower($rawStatus, 'UTF-8') : strtolower($rawStatus);
    if ($status === '') {
        return true;
    }

    foreach (['cancel', 'deleted', 'скас', 'refund', 'returned', 'failed', 'error'] as $blocked) {
        if (str_contains($status, $blocked)) {
            return false;
        }
    }

    return true;
}

function sync_recalculate_order_payment_totals(int $orderId): void
{
    if ($orderId <= 0 || !invoice_table_exists('db_orders') || !invoice_table_exists('db_order_payments')) {
        return;
    }

    $payments = db()->prepare("
        SELECT amount, status
        FROM db_order_payments
        WHERE keycrm_order_id = :order_id
    ");
    $payments->execute(['order_id' => $orderId]);
    $paid = 0.0;
    foreach ($payments->fetchAll() as $payment) {
        if (sync_payment_counts_as_paid($payment['status'] ?? null)) {
            $paid += (float) ($payment['amount'] ?? 0);
        }
    }

    $order = db()->prepare('SELECT total_amount_uah FROM db_orders WHERE keycrm_id = :order_id LIMIT 1');
    $order->execute(['order_id' => $orderId]);
    $total = $order->fetchColumn();
    if ($total === false) {
        return;
    }

    $totalAmount = (float) $total;
    $paid = min(max($paid, 0), max($totalAmount, 0));
    $unpaid = max($totalAmount - $paid, 0);
    $paymentStatus = $unpaid <= 0 ? 'paid' : ($paid > 0 ? 'part_paid' : 'not_paid');

    db()->prepare("
        UPDATE db_orders
        SET paid_amount_uah = :paid,
            unpaid_amount_uah = :unpaid,
            payment_status = :payment_status,
            synced_at = NOW()
        WHERE keycrm_id = :order_id
    ")->execute([
        'paid' => $paid,
        'unpaid' => $unpaid,
        'payment_status' => $paymentStatus,
        'order_id' => $orderId,
    ]);
}

function sync_upsert_expense(array $expense, int $orderId): string
{
    $id = (string) (($expense['id'] ?? '') ?: (($expense['uuid'] ?? '') ?: hash('sha1', $orderId . json_encode($expense))));
    $hash = sync_source_hash($expense);
    $stmt = db()->prepare('SELECT source_hash FROM db_order_expenses WHERE keycrm_expense_id = :id LIMIT 1');
    $stmt->execute(['id' => $id]);
    $oldHash = (string) ($stmt->fetchColumn() ?: '');
    if ($oldHash === $hash) {
        return 'unchanged';
    }
    $stmt = db()->prepare("
        INSERT INTO db_order_expenses
            (keycrm_expense_id, keycrm_order_id, expense_type_id, expense_type_name, amount, currency, status, payment_date, source_updated_at, source_hash, raw_json, synced_at)
        VALUES
            (:keycrm_expense_id, :keycrm_order_id, :expense_type_id, :expense_type_name, :amount, :currency, :status, :payment_date, :source_updated_at, :source_hash, :raw_json, NOW())
        ON DUPLICATE KEY UPDATE
            keycrm_order_id = VALUES(keycrm_order_id),
            expense_type_id = VALUES(expense_type_id),
            expense_type_name = VALUES(expense_type_name),
            amount = VALUES(amount),
            currency = VALUES(currency),
            status = VALUES(status),
            payment_date = VALUES(payment_date),
            source_updated_at = VALUES(source_updated_at),
            source_hash = VALUES(source_hash),
            raw_json = VALUES(raw_json),
            synced_at = NOW()
    ");
    $stmt->execute([
        'keycrm_expense_id' => $id,
        'keycrm_order_id' => $orderId,
        'expense_type_id' => $expense['expense_type_id'] ?? null,
        'expense_type_name' => ($expense['expense_type'] ?? null) ?: ($expense['expense_type_name'] ?? null),
        'amount' => round((float) ($expense['amount'] ?? 0), 2),
        'currency' => (string) (($expense['actual_currency'] ?? '') ?: (($expense['currency'] ?? '') ?: 'UAH')),
        'status' => $expense['status'] ?? null,
        'payment_date' => sync_datetime($expense['payment_date'] ?? null),
        'source_updated_at' => sync_datetime($expense['updated_at'] ?? null),
        'source_hash' => $hash,
        'raw_json' => json_encode($expense, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);

    return $oldHash === '' ? 'inserted' : 'updated';
}

function sync_run_job_type(string $jobType): array
{
    $apiKey = sync_api_key();
    $limit = 50;
    $maxPages = in_array($jobType, ['statuses'], true) ? 10 : (int) app_config('keycrm.sync_delta_pages', 10);
    $counts = ['seen' => 0, 'inserted' => 0, 'updated' => 0, 'unchanged' => 0, '_source_updated_at' => null];

    if ($jobType === 'unpaid_orders') {
        return sync_run_unpaid_orders_refresh($apiKey);
    }

    for ($page = 1; $page <= $maxPages; $page++) {
        $response = sync_http_get(sync_endpoint($jobType, $page, $limit), $apiKey);
        $rows = sync_rows($response);
        if (!$rows) {
            break;
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $counts['seen']++;
            $rowUpdatedAt = sync_datetime($row['updated_at'] ?? null);
            if ($rowUpdatedAt !== null && ((string) ($counts['_source_updated_at'] ?? '') === '' || $rowUpdatedAt > (string) $counts['_source_updated_at'])) {
                $counts['_source_updated_at'] = $rowUpdatedAt;
            }
            if (in_array($jobType, ['orders','payments','order_expenses'], true)) {
                $orderId = (int) ($row['id'] ?? 0);
                if ($jobType === 'orders') {
                    $result = sync_upsert_order_from_keycrm($row);
                    $counts[$result]++;
                }
                if (in_array($jobType, ['orders','payments'], true)) {
                    foreach ((array) ($row['payments'] ?? []) as $payment) {
                        if (is_array($payment) && $orderId > 0) {
                            $counts[sync_upsert_payment($payment, $orderId)]++;
                        }
                    }
                }
                if (in_array($jobType, ['orders','order_expenses'], true)) {
                    foreach ((array) ($row['expenses'] ?? []) as $expense) {
                        if (is_array($expense) && $orderId > 0) {
                            $counts[sync_upsert_expense($expense, $orderId)]++;
                        }
                    }
                }
            } elseif ($jobType === 'companies') {
                $counts[sync_upsert_company($row)]++;
            } elseif ($jobType === 'buyers') {
                $counts[sync_upsert_buyer($row)]++;
            } elseif ($jobType === 'statuses') {
                sync_upsert_status($row, 'order');
                $counts['updated']++;
            }
        }
        if (count($rows) < $limit) {
            break;
        }
    }

    if ($jobType === 'statuses') {
        sync_run_product_statuses($apiKey, $counts);
    }

    return $counts;
}

function sync_run_unpaid_orders_refresh(string $apiKey): array
{
    $counts = ['seen' => 0, 'inserted' => 0, 'updated' => 0, 'unchanged' => 0, '_source_updated_at' => null];
    if (!invoice_table_exists('db_orders')) {
        return $counts;
    }

    $limit = (int) app_config('keycrm.unpaid_refresh_limit', 50);
    $stmt = db()->prepare("
        SELECT keycrm_id
        FROM db_orders
        WHERE unpaid_amount_uah > 0
          AND keycrm_id IS NOT NULL
          AND keycrm_id > 0
          AND LOWER(COALESCE(status_name, '')) NOT LIKE '%cancel%'
          AND LOWER(COALESCE(status_name, '')) NOT LIKE '%deleted%'
          AND LOWER(COALESCE(status_name, '')) NOT LIKE '%скас%'
        ORDER BY unpaid_amount_uah DESC, ordered_at ASC
        LIMIT :limit
    ");
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $ids = array_map('intval', array_column($stmt->fetchAll(), 'keycrm_id'));

    foreach ($ids as $orderId) {
        $order = null;
        try {
            $response = sync_http_get(sync_order_detail_endpoint($orderId), $apiKey);
            $json = $response['json'] ?? [];
            if (is_array($json)) {
                $order = is_array($json['data'] ?? null) ? $json['data'] : $json;
            }
        } catch (Throwable $e) {
            $response = sync_http_get(sync_order_filter_endpoint($orderId), $apiKey);
            $rows = sync_rows($response);
            $order = is_array($rows[0] ?? null) ? $rows[0] : null;
        }

        if (!$order || !is_array($order)) {
            continue;
        }

        $counts['seen']++;
        $rowUpdatedAt = sync_datetime($order['updated_at'] ?? null);
        if ($rowUpdatedAt !== null && ((string) ($counts['_source_updated_at'] ?? '') === '' || $rowUpdatedAt > (string) $counts['_source_updated_at'])) {
            $counts['_source_updated_at'] = $rowUpdatedAt;
        }
        $counts[sync_upsert_order_from_keycrm($order)]++;

        foreach ((array) ($order['payments'] ?? []) as $payment) {
            if (is_array($payment)) {
                $counts[sync_upsert_payment($payment, $orderId)]++;
            }
        }
        sync_recalculate_order_payment_totals($orderId);

        foreach ((array) ($order['expenses'] ?? []) as $expense) {
            if (is_array($expense)) {
                $counts[sync_upsert_expense($expense, $orderId)]++;
            }
        }
    }

    return $counts;
}

function sync_upsert_status(array $status, string $type): void
{
    $id = (int) ($status['id'] ?? 0);
    if ($id <= 0) {
        return;
    }
    $hash = sync_source_hash($status);
    db()->prepare("
        INSERT INTO db_keycrm_statuses (status_type, keycrm_status_id, name, source_hash, raw_json, synced_at)
        VALUES (:status_type, :keycrm_status_id, :name, :source_hash, :raw_json, NOW())
        ON DUPLICATE KEY UPDATE
            name = VALUES(name),
            source_hash = VALUES(source_hash),
            raw_json = VALUES(raw_json),
            synced_at = NOW()
    ")->execute([
        'status_type' => $type,
        'keycrm_status_id' => $id,
        'name' => $status['name'] ?? null,
        'source_hash' => $hash,
        'raw_json' => json_encode($status, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
}

function sync_company_label(array $company, array $keys): ?string
{
    foreach ($keys as $key) {
        $value = trim((string) ($company[$key] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }

    return null;
}

function sync_upsert_company(array $company): string
{
    $id = (int) ($company['id'] ?? 0);
    $name = sync_company_label($company, ['name', 'display_name']);
    $title = sync_company_label($company, ['title', 'full_name', 'legal_name']);
    if ($id <= 0 && $name === null && $title === null) {
        return 'unchanged';
    }
    $hash = sync_source_hash($company);
    $raw = json_encode($company, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $stmt = db()->prepare('SELECT id, raw_json FROM db_client_companies WHERE keycrm_company_id = :id ORDER BY id DESC LIMIT 1');
    $stmt->execute(['id' => $id]);
    $existing = $stmt->fetch() ?: [];
    $localId = (int) ($existing['id'] ?? 0);
    $oldRaw = (string) ($existing['raw_json'] ?? '');
    if ($oldRaw !== '' && hash('sha256', $oldRaw) === $hash) {
        return 'unchanged';
    }

    $data = [
        'keycrm_company_id' => $id > 0 ? $id : null,
        'display_name' => $name ?: $title,
        'keycrm_name' => $name,
        'keycrm_title' => $title,
        'name' => $name,
        'title' => $title,
        'manager_id' => !empty($company['manager_id']) ? (int) $company['manager_id'] : null,
        'raw_json' => $raw ?: null,
    ];

    if ($localId > 0) {
        $updateData = $data;
        unset($updateData['keycrm_company_id']);
        $updateData['id'] = $localId;
        db()->prepare("
            UPDATE db_client_companies
            SET display_name = :display_name,
                keycrm_name = :keycrm_name,
                keycrm_title = :keycrm_title,
                name = :name,
                title = :title,
                manager_id = :manager_id,
                raw_json = :raw_json,
                synced_at = NOW()
            WHERE id = :id
        ")->execute($updateData);

        return 'updated';
    }

    db()->prepare("
        INSERT INTO db_client_companies
            (keycrm_company_id, display_name, keycrm_name, keycrm_title, name, title, manager_id, raw_json, synced_at)
        VALUES
            (:keycrm_company_id, :display_name, :keycrm_name, :keycrm_title, :name, :title, :manager_id, :raw_json, NOW())
    ")->execute($data);

    return 'inserted';
}

function sync_upsert_buyer(array $buyer): string
{
    $id = (int) ($buyer['id'] ?? 0);
    $company = is_array($buyer['company'] ?? null) ? $buyer['company'] : [];
    $clientCompanyId = null;
    if ($company) {
        sync_upsert_company($company);
    }
    $companyId = (int) (($company['id'] ?? 0) ?: ($buyer['company_id'] ?? 0));
    if ($companyId > 0) {
        $stmt = db()->prepare('SELECT id FROM db_client_companies WHERE keycrm_company_id = :keycrm_company_id ORDER BY id DESC LIMIT 1');
        $stmt->execute(['keycrm_company_id' => $companyId]);
        $clientCompanyId = $stmt->fetchColumn() ?: null;
    }
    $name = sync_company_label($buyer, ['full_name', 'name']);
    $email = sync_company_label($buyer, ['email']);
    $phone = sync_company_label($buyer, ['phone']);
    if ($id <= 0 && $name === null && $email === null && $phone === null) {
        return 'unchanged';
    }
    $hash = sync_source_hash($buyer);
    $raw = json_encode($buyer, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $stmt = db()->prepare('SELECT id, raw_json FROM db_client_contacts WHERE keycrm_buyer_id = :id ORDER BY id DESC LIMIT 1');
    $stmt->execute(['id' => $id]);
    $existing = $stmt->fetch() ?: [];
    $localId = (int) ($existing['id'] ?? 0);
    $oldRaw = (string) ($existing['raw_json'] ?? '');
    if ($oldRaw !== '' && hash('sha256', $oldRaw) === $hash) {
        return 'unchanged';
    }
    $data = [
        'keycrm_buyer_id' => $id > 0 ? $id : null,
        'client_company_id' => $clientCompanyId ?: null,
        'full_name' => $name,
        'email' => $email,
        'phone' => $phone,
        'position' => sync_company_label($buyer, ['position']),
        'raw_json' => $raw ?: null,
    ];

    if ($localId > 0) {
        $updateData = $data;
        unset($updateData['keycrm_buyer_id']);
        $updateData['id'] = $localId;
        db()->prepare("
            UPDATE db_client_contacts
            SET client_company_id = :client_company_id,
                full_name = :full_name,
                email = :email,
                phone = :phone,
                position = :position,
                raw_json = :raw_json,
                synced_at = NOW()
            WHERE id = :id
        ")->execute($updateData);

        return 'updated';
    }

    db()->prepare("
        INSERT INTO db_client_contacts
            (keycrm_buyer_id, client_company_id, full_name, email, phone, position, raw_json, synced_at)
        VALUES
            (:keycrm_buyer_id, :client_company_id, :full_name, :email, :phone, :position, :raw_json, NOW())
    ")->execute($data);

    return 'inserted';
}

function sync_run_product_statuses(string $apiKey, array &$counts): void
{
    for ($page = 1; $page <= 10; $page++) {
        $response = sync_http_get('order/product-status?' . http_build_query(['limit' => 50, 'page' => $page]), $apiKey);
        $rows = sync_rows($response);
        if (!$rows) {
            break;
        }
        foreach ($rows as $row) {
            if (is_array($row)) {
                sync_upsert_status($row, 'product');
                $counts['seen']++;
                $counts['updated']++;
            }
        }
        if (count($rows) < 50) {
            break;
        }
    }
}

function sync_worker_run_once(): ?array
{
    ensure_sync_tables();
    $job = sync_claim_job();
    if (!$job) {
        return null;
    }
    try {
        $counts = sync_run_job_type((string) $job['job_type']);
        sync_finish_job($job, 'success', $counts);
        return ['job' => $job, 'status' => 'success', 'counts' => $counts];
    } catch (Throwable $e) {
        sync_finish_job($job, 'failed', ['seen' => 0, 'inserted' => 0, 'updated' => 0, 'unchanged' => 0], $e->getMessage());
        return ['job' => $job, 'status' => 'failed', 'error' => $e->getMessage()];
    }
}

function sync_active_summary(): array
{
    ensure_sync_tables();
    sync_recover_stale_jobs();
    $activeGlobal = db()->query("
        SELECT id
        FROM db_sync_jobs
        WHERE job_type = 'global_refresh'
          AND status IN ('queued','running')
        ORDER BY id DESC
        LIMIT 1
    ")->fetchColumn();
    if ($activeGlobal) {
        sync_ensure_parent_jobs((int) $activeGlobal, null);
    }
    $active = db()->query("
        SELECT *
        FROM db_sync_jobs
        WHERE status IN ('queued','running')
        ORDER BY id ASC
    ")->fetchAll();
    $last = db()->query("
        SELECT *
        FROM db_sync_jobs
        WHERE job_type = 'global_refresh'
        ORDER BY id DESC
        LIMIT 1
    ")->fetch() ?: [];
    $states = db()->query("SELECT * FROM db_sync_state ORDER BY sync_key ASC")->fetchAll();

    return [
        'active' => (bool) $active,
        'active_jobs' => $active,
        'last_global_job' => $last,
        'states' => $states,
    ];
}
