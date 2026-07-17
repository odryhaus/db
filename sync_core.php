<?php

function sync_job_types(): array
{
    return ['orders', 'unpaid_orders', 'payments', 'companies', 'buyers', 'order_expenses', 'statuses'];
}

function sync_table_columns(string $table): array
{
    static $cache = [];
    if (isset($cache[$table])) {
        return $cache[$table];
    }
    if (!invoice_table_exists($table)) {
        $cache[$table] = [];
        return [];
    }

    $stmt = db()->prepare("
        SELECT COLUMN_NAME
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table_name
    ");
    $stmt->execute(['table_name' => $table]);
    $cache[$table] = array_map('strval', array_column($stmt->fetchAll(), 'COLUMN_NAME'));
    return $cache[$table];
}

function sync_has_column(string $table, string $column): bool
{
    return in_array($column, sync_table_columns($table), true);
}

function sync_insert_update_by_columns(string $table, array $data, array $keys): void
{
    $columns = sync_table_columns($table);
    if (!$columns) {
        return;
    }
    $data = array_intersect_key($data, array_flip($columns));
    foreach ($keys as $key) {
        if (!array_key_exists($key, $data)) {
            return;
        }
    }
    if (!$data) {
        return;
    }

    $where = [];
    $params = [];
    foreach ($keys as $key) {
        $where[] = "{$key} = :where_{$key}";
        $params["where_{$key}"] = $data[$key];
    }

    $stmt = db()->prepare("SELECT id FROM {$table} WHERE " . implode(' AND ', $where) . ' LIMIT 1');
    $stmt->execute($params);
    $id = $stmt->fetchColumn();

    if ($id) {
        $updates = [];
        $updateData = ['id' => (int) $id];
        foreach ($data as $column => $value) {
            if ($column === 'id' || in_array($column, $keys, true)) {
                continue;
            }
            $updates[] = "{$column} = :{$column}";
            $updateData[$column] = $value;
        }
        if ($updates) {
            db()->prepare("UPDATE {$table} SET " . implode(', ', $updates) . " WHERE id = :id")->execute($updateData);
        }
        return;
    }

    $insertColumns = array_keys($data);
    $placeholders = array_map(static fn($column) => ':' . $column, $insertColumns);
    db()->prepare("INSERT INTO {$table} (" . implode(', ', $insertColumns) . ') VALUES (' . implode(', ', $placeholders) . ')')->execute($data);
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

function sync_json($value): ?string
{
    if ($value === null || $value === '') {
        return null;
    }
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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

function sync_payment_method_name(array $payment): ?string
{
    $method = $payment['payment_method'] ?? null;
    if (is_array($method)) {
        foreach (['name', 'title', 'label'] as $key) {
            $value = trim((string) ($method[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }
    }

    $methodValue = $payment['payment_method'] ?? '';
    $method = is_scalar($methodValue) ? (string) $methodValue : '';
    if ($method === '') {
        $method = (string) ($payment['payment_method_name'] ?? '');
    }
    return trim($method) !== '' ? trim($method) : null;
}

function sync_payment_method_id(array $payment): ?int
{
    if (isset($payment['payment_method_id']) && (int) $payment['payment_method_id'] > 0) {
        return (int) $payment['payment_method_id'];
    }
    if (is_array($payment['payment_method'] ?? null) && (int) ($payment['payment_method']['id'] ?? 0) > 0) {
        return (int) $payment['payment_method']['id'];
    }

    return null;
}

function sync_upsert_payment(array $payment, int $orderId, ?string $orderNumber = null): string
{
    $id = (string) (($payment['id'] ?? '') ?: (($payment['uuid'] ?? '') ?: hash('sha1', $orderId . json_encode($payment))));
    $hash = sync_source_hash($payment);
    $stmt = db()->prepare('SELECT source_hash FROM db_order_payments WHERE keycrm_payment_id = :id LIMIT 1');
    $stmt->execute(['id' => $id]);
    $oldHash = (string) ($stmt->fetchColumn() ?: '');
    if ($oldHash === $hash) {
        sync_payment_to_financial_transaction($id);
        return 'unchanged';
    }
    $isDeleted = !empty($payment['is_deleted']) || !empty($payment['deleted_at']);
    $stmt = db()->prepare("
        INSERT INTO db_order_payments
            (keycrm_payment_id, keycrm_order_id, order_number, payment_method_id, payment_method_name,
             seller_company_id, seller_account_id, amount, currency, status, payment_date, source_created_at,
             source_updated_at, source_hash, raw_json, synced_at, is_deleted, deleted_at)
        VALUES
            (:keycrm_payment_id, :keycrm_order_id, :order_number, :payment_method_id, :payment_method_name,
             :seller_company_id, :seller_account_id, :amount, :currency, :status, :payment_date, :source_created_at,
             :source_updated_at, :source_hash, :raw_json, NOW(), :is_deleted, :deleted_at)
        ON DUPLICATE KEY UPDATE
            keycrm_order_id = VALUES(keycrm_order_id),
            order_number = VALUES(order_number),
            payment_method_id = VALUES(payment_method_id),
            payment_method_name = VALUES(payment_method_name),
            seller_company_id = VALUES(seller_company_id),
            seller_account_id = VALUES(seller_account_id),
            amount = VALUES(amount),
            currency = VALUES(currency),
            status = VALUES(status),
            payment_date = VALUES(payment_date),
            source_created_at = VALUES(source_created_at),
            source_updated_at = VALUES(source_updated_at),
            source_hash = VALUES(source_hash),
            raw_json = VALUES(raw_json),
            is_deleted = VALUES(is_deleted),
            deleted_at = VALUES(deleted_at),
            synced_at = NOW()
    ");
    $stmt->execute([
        'keycrm_payment_id' => $id,
        'keycrm_order_id' => $orderId,
        'order_number' => $orderNumber,
        'payment_method_id' => sync_payment_method_id($payment),
        'payment_method_name' => sync_payment_method_name($payment),
        'seller_company_id' => $payment['seller_company_id'] ?? null,
        'seller_account_id' => $payment['seller_account_id'] ?? null,
        'amount' => round((float) ($payment['amount'] ?? 0), 2),
        'currency' => (string) (($payment['actual_currency'] ?? '') ?: (($payment['currency'] ?? '') ?: 'UAH')),
        'status' => $payment['status'] ?? null,
        'payment_date' => sync_datetime($payment['payment_date'] ?? null),
        'source_created_at' => sync_datetime($payment['created_at'] ?? null),
        'source_updated_at' => sync_datetime($payment['updated_at'] ?? null),
        'source_hash' => $hash,
        'raw_json' => json_encode($payment, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'is_deleted' => $isDeleted ? 1 : 0,
        'deleted_at' => sync_datetime($payment['deleted_at'] ?? null),
    ]);

    sync_recalculate_order_payment_totals($orderId);
    sync_payment_to_financial_transaction($id);

    return $oldHash === '' ? 'inserted' : 'updated';
}

function sync_payment_counts_as_paid(?string $status): bool
{
    return trim((string) $status) === 'paid';
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
          AND is_deleted = 0
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

function sync_product_id(array $product, int $orderId): string
{
    foreach (['id', 'order_product_id', 'order_product_uuid', 'uuid'] as $key) {
        $value = trim((string) ($product[$key] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }

    return hash('sha1', $orderId . '|' . json_encode($product, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function sync_product_properties_text($properties): ?string
{
    if (!is_array($properties) || !$properties) {
        return null;
    }

    $lines = [];
    foreach ($properties as $property) {
        if (!is_array($property)) {
            continue;
        }
        $name = trim((string) ($property['name'] ?? ''));
        $value = trim((string) ($property['value'] ?? ''));
        if ($name !== '' || $value !== '') {
            $lines[] = trim($name . ': ' . $value, ': ');
        }
    }

    return $lines ? implode('; ', $lines) : null;
}

function sync_upsert_order_item(array $product, int $orderId, ?string $orderNumber = null): string
{
    if (!invoice_table_exists('db_order_items')) {
        return 'unchanged';
    }

    $id = sync_product_id($product, $orderId);
    $hash = sync_source_hash($product);
    $oldHash = '';
    if (sync_has_column('db_order_items', 'source_hash')) {
        $stmt = db()->prepare('SELECT source_hash FROM db_order_items WHERE keycrm_order_product_id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $oldHash = (string) ($stmt->fetchColumn() ?: '');
    }
    $insert = $oldHash === '';
    if ($oldHash === $hash) {
        db()->prepare("
            UPDATE db_order_items
            SET synced_at = NOW(),
                is_deleted = 0,
                deleted_at = NULL
            WHERE keycrm_order_product_id = :id
        ")->execute(['id' => $id]);
        return 'unchanged';
    }

    $quantity = round((float) ($product['quantity'] ?? 0), 3);
    $salePrice = round((float) (($product['price_sold'] ?? null) ?? (($product['sale_price'] ?? null) ?? ($product['price'] ?? 0))), 2);
    $total = round((float) (($product['total_amount'] ?? null) ?? ($salePrice * $quantity)), 2);
    $data = [
        'keycrm_order_product_id' => $id,
        'keycrm_order_id' => $orderId,
        'order_number' => $orderNumber,
        'name' => $product['name'] ?? null,
        'properties_json' => sync_json($product['properties'] ?? null),
        'properties_text' => sync_product_properties_text($product['properties'] ?? null),
        'comment' => $product['comment'] ?? null,
        'quantity' => $quantity,
        'unit' => ($product['unit_type'] ?? null) ?: ($product['unit'] ?? null),
        'purchase_price' => round((float) (($product['purchased_price'] ?? null) ?? ($product['purchase_price'] ?? 0)), 2),
        'product_price' => round((float) ($product['price'] ?? 0), 2),
        'discount_amount' => round((float) (($product['discount_amount'] ?? null) ?? ($product['total_discount'] ?? 0)), 2),
        'discount_percent' => round((float) ($product['discount_percent'] ?? 0), 2),
        'sale_price' => $salePrice,
        'total_amount' => $total,
        'product_status_id' => $product['product_status_id'] ?? null,
        'source_created_at' => sync_datetime($product['created_at'] ?? null),
        'source_updated_at' => sync_datetime($product['updated_at'] ?? null),
        'source_hash' => $hash,
        'raw_json' => sync_json($product),
        'synced_at' => date('Y-m-d H:i:s'),
        'is_deleted' => 0,
        'deleted_at' => null,
    ];

    sync_insert_update_by_columns('db_order_items', $data, ['keycrm_order_product_id']);
    return $insert ? 'inserted' : 'updated';
}

function sync_mark_missing_order_items_deleted(int $orderId, array $seenIds): void
{
    if (!invoice_table_exists('db_order_items') || !sync_has_column('db_order_items', 'is_deleted')) {
        return;
    }

    if (!$seenIds) {
        db()->prepare("
            UPDATE db_order_items
            SET is_deleted = 1,
                deleted_at = COALESCE(deleted_at, NOW())
            WHERE keycrm_order_id = :order_id
              AND is_deleted = 0
        ")->execute(['order_id' => $orderId]);
        return;
    }

    $placeholders = [];
    $params = ['order_id' => $orderId];
    foreach (array_values($seenIds) as $index => $id) {
        $key = 'id_' . $index;
        $placeholders[] = ':' . $key;
        $params[$key] = $id;
    }
    db()->prepare("
        UPDATE db_order_items
        SET is_deleted = 1,
            deleted_at = COALESCE(deleted_at, NOW())
        WHERE keycrm_order_id = :order_id
          AND is_deleted = 0
          AND keycrm_order_product_id NOT IN (" . implode(',', $placeholders) . ")
    ")->execute($params);
}

function sync_payment_account_match(?int $paymentMethodId): array
{
    if (!$paymentMethodId || !invoice_table_exists('db_keycrm_payment_method_accounts') || !invoice_table_exists('db_financial_accounts')) {
        return ['financial_account_id' => null, 'allocation_status' => 'needs_review'];
    }

    $stmt = db()->prepare("
        SELECT m.financial_account_id
        FROM db_keycrm_payment_method_accounts m
        INNER JOIN db_financial_accounts a ON a.id = m.financial_account_id
        WHERE m.keycrm_payment_method_id = :method_id
          AND m.is_active = 1
          AND a.is_active = 1
        LIMIT 1
    ");
    $stmt->execute(['method_id' => $paymentMethodId]);
    $accountId = (int) ($stmt->fetchColumn() ?: 0);

    return [
        'financial_account_id' => $accountId > 0 ? $accountId : null,
        'allocation_status' => $accountId > 0 ? 'allocated' : 'needs_review',
    ];
}

function sync_payment_to_financial_transaction(string $keycrmPaymentId): void
{
    if (!invoice_table_exists('db_financial_transactions') || !invoice_table_exists('db_order_payments')) {
        return;
    }

    $sellerSelect = sync_has_column('db_orders', 'seller_company_id') ? 'o.seller_company_id' : 'NULL AS seller_company_id';
    $stmt = db()->prepare("
        SELECT p.*, {$sellerSelect}
        FROM db_order_payments p
        LEFT JOIN db_orders o ON o.keycrm_id = p.keycrm_order_id
        WHERE p.keycrm_payment_id = :payment_id
        LIMIT 1
    ");
    $stmt->execute(['payment_id' => $keycrmPaymentId]);
    $payment = $stmt->fetch();
    if (!$payment) {
        return;
    }

    $isActivePaid = (int) ($payment['is_deleted'] ?? 0) === 0 && sync_payment_counts_as_paid($payment['status'] ?? null);
    $match = sync_payment_account_match((int) ($payment['payment_method_id'] ?? 0));
    $data = [
        'direction' => 'income',
        'transaction_type' => 'client_payment',
        'transaction_date' => $payment['payment_date'] ?: null,
        'amount' => round((float) ($payment['amount'] ?? 0), 2),
        'currency' => ($payment['currency'] ?? '') ?: 'UAH',
        'seller_company_id' => $payment['seller_company_id'] ?? null,
        'financial_account_id' => $match['financial_account_id'],
        'keycrm_order_id' => $payment['keycrm_order_id'] ?? null,
        'order_number' => $payment['order_number'] ?? null,
        'order_payment_id' => $payment['id'] ?? null,
        'source_type' => 'keycrm_payment',
        'source_id' => $keycrmPaymentId,
        'balance_operation_type' => 'normal',
        'status' => $isActivePaid ? 'completed' : 'canceled',
        'allocation_status' => $match['allocation_status'],
        'counterparty_name' => null,
        'payment_purpose' => $payment['payment_method_name'] ?? null,
        'raw_json' => $payment['raw_json'] ?? null,
        'synced_at' => date('Y-m-d H:i:s'),
    ];

    sync_insert_update_by_columns('db_financial_transactions', $data, ['source_type', 'source_id']);
}

function sync_mark_missing_order_payments_deleted(int $orderId, array $seenIds): void
{
    if (!invoice_table_exists('db_order_payments') || !sync_has_column('db_order_payments', 'is_deleted')) {
        return;
    }

    $rows = [];
    if (!$seenIds) {
        $stmt = db()->prepare("
            SELECT keycrm_payment_id
            FROM db_order_payments
            WHERE keycrm_order_id = :order_id
              AND is_deleted = 0
        ");
        $stmt->execute(['order_id' => $orderId]);
        $rows = $stmt->fetchAll();
        db()->prepare("
            UPDATE db_order_payments
            SET is_deleted = 1,
                deleted_at = COALESCE(deleted_at, NOW()),
                synced_at = NOW()
            WHERE keycrm_order_id = :order_id
              AND is_deleted = 0
        ")->execute(['order_id' => $orderId]);
    } else {
        $placeholders = [];
        $params = ['order_id' => $orderId];
        foreach (array_values($seenIds) as $index => $id) {
            $key = 'id_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $id;
        }
        $stmt = db()->prepare("
            SELECT keycrm_payment_id
            FROM db_order_payments
            WHERE keycrm_order_id = :order_id
              AND is_deleted = 0
              AND keycrm_payment_id NOT IN (" . implode(',', $placeholders) . ")
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        db()->prepare("
            UPDATE db_order_payments
            SET is_deleted = 1,
                deleted_at = COALESCE(deleted_at, NOW()),
                synced_at = NOW()
            WHERE keycrm_order_id = :order_id
              AND is_deleted = 0
              AND keycrm_payment_id NOT IN (" . implode(',', $placeholders) . ")
        ")->execute($params);
    }

    foreach ($rows as $row) {
        sync_payment_to_financial_transaction((string) ($row['keycrm_payment_id'] ?? ''));
    }
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
                    $orderNumber = (string) (($row['number'] ?? '') ?: (($row['source_uuid'] ?? '') ?: $orderId));
                    $seenPaymentIds = [];
                    foreach ((array) ($row['payments'] ?? []) as $payment) {
                        if (is_array($payment) && $orderId > 0) {
                            $seenPaymentIds[] = (string) (($payment['id'] ?? '') ?: (($payment['uuid'] ?? '') ?: hash('sha1', $orderId . json_encode($payment))));
                            $counts[sync_upsert_payment($payment, $orderId, $orderNumber)]++;
                        }
                    }
                    if ($orderId > 0) {
                        sync_mark_missing_order_payments_deleted($orderId, $seenPaymentIds);
                        sync_recalculate_order_payment_totals($orderId);
                    }
                }
                if ($jobType === 'orders') {
                    $orderNumber = (string) (($row['number'] ?? '') ?: (($row['source_uuid'] ?? '') ?: $orderId));
                    $seenItemIds = [];
                    foreach ((array) ($row['products'] ?? []) as $product) {
                        if (is_array($product) && $orderId > 0) {
                            $seenItemIds[] = sync_product_id($product, $orderId);
                            $counts[sync_upsert_order_item($product, $orderId, $orderNumber)]++;
                        }
                    }
                    if ($orderId > 0) {
                        sync_mark_missing_order_items_deleted($orderId, $seenItemIds);
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
                $orderNumber = (string) (($order['number'] ?? '') ?: (($order['source_uuid'] ?? '') ?: $orderId));
                $seenPaymentIds[] = (string) (($payment['id'] ?? '') ?: (($payment['uuid'] ?? '') ?: hash('sha1', $orderId . json_encode($payment))));
                $counts[sync_upsert_payment($payment, $orderId, $orderNumber)]++;
            }
        }
        sync_mark_missing_order_payments_deleted($orderId, $seenPaymentIds ?? []);
        sync_recalculate_order_payment_totals($orderId);

        $seenItemIds = [];
        foreach ((array) ($order['products'] ?? []) as $product) {
            if (is_array($product)) {
                $orderNumber = (string) (($order['number'] ?? '') ?: (($order['source_uuid'] ?? '') ?: $orderId));
                $seenItemIds[] = sync_product_id($product, $orderId);
                $counts[sync_upsert_order_item($product, $orderId, $orderNumber)]++;
            }
        }
        sync_mark_missing_order_items_deleted($orderId, $seenItemIds);

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
    if (!invoice_table_exists('db_sync_jobs')) {
        return null;
    }
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
    if (!invoice_table_exists('db_sync_jobs')) {
        return ['active' => false, 'jobs' => [], 'last_global_job' => null];
    }
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
