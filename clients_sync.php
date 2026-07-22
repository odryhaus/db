<?php

require_once __DIR__ . '/bootstrap.php';
require_role('ceo');
ensure_invoice_tables();

$error = '';
$summary = null;
$perPage = 50;
$deltaPages = (int) app_config('keycrm.client_sync_delta_pages', 20);
$initialPages = (int) app_config('keycrm.client_sync_initial_pages', 200);

function client_sync_request(string $endpoint, string $apiKey): array
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

function client_sync_rows(array $response): array
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

function client_sync_compact_date($value): ?string
{
    $time = $value ? strtotime((string) $value) : false;
    return $time ? date('Y-m-d H:i:s', $time) : null;
}

function client_sync_value(array $data, array $keys): ?string
{
    foreach ($keys as $key) {
        if (isset($data[$key]) && is_scalar($data[$key]) && trim((string) $data[$key]) !== '') {
            return trim((string) $data[$key]);
        }
    }

    return null;
}

function client_sync_manager_id(array $data): ?int
{
    if (isset($data['manager']) && is_array($data['manager']) && !empty($data['manager']['id'])) {
        return (int) $data['manager']['id'];
    }
    if (!empty($data['manager_id'])) {
        return (int) $data['manager_id'];
    }

    return null;
}

function client_sync_manager_name(array $data): ?string
{
    $manager = isset($data['manager']) && is_array($data['manager']) ? $data['manager'] : [];
    return client_sync_value($manager, ['full_name', 'name', 'username']);
}

function client_sync_upsert_company(PDO $pdo, array $company): ?int
{
    $keycrmId = (int) ($company['id'] ?? 0);
    $name = client_sync_value($company, ['name', 'display_name']);
    $title = client_sync_value($company, ['title', 'full_name', 'legal_name']);
    $displayName = $title ?: $name;
    $managerId = client_sync_manager_id($company);
    $managerName = client_sync_manager_name($company);
    $rawJson = json_encode($company, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($keycrmId <= 0 && $displayName === null) {
        return null;
    }

    $localId = null;
    if ($keycrmId > 0) {
        $stmt = $pdo->prepare('SELECT id FROM db_client_companies WHERE keycrm_company_id = :keycrm_company_id ORDER BY id DESC LIMIT 1');
        $stmt->execute(['keycrm_company_id' => $keycrmId]);
        $localId = $stmt->fetchColumn() ?: null;
    }

    if ($localId) {
        $stmt = $pdo->prepare("
            UPDATE db_client_companies
            SET display_name = :display_name,
                keycrm_name = :keycrm_name,
                keycrm_title = :keycrm_title,
                name = :name,
                title = :title,
                manager_id = :manager_id,
                keycrm_manager_id = :keycrm_manager_id,
                keycrm_manager_name = :keycrm_manager_name,
                raw_json = :raw_json,
                synced_at = NOW()
            WHERE id = :id
        ");
        $stmt->execute([
            'display_name' => $displayName,
            'keycrm_name' => $name,
            'keycrm_title' => $title,
            'name' => $name,
            'title' => $title,
            'manager_id' => $managerId,
            'keycrm_manager_id' => $managerId,
            'keycrm_manager_name' => $managerName,
            'raw_json' => $rawJson ?: null,
            'id' => (int) $localId,
        ]);

        return (int) $localId;
    }

    $stmt = $pdo->prepare("
        INSERT INTO db_client_companies
            (keycrm_company_id, display_name, keycrm_name, keycrm_title, name, title, manager_id, keycrm_manager_id, keycrm_manager_name, raw_json, synced_at)
        VALUES
            (:keycrm_company_id, :display_name, :keycrm_name, :keycrm_title, :name, :title, :manager_id, :keycrm_manager_id, :keycrm_manager_name, :raw_json, NOW())
    ");
    $stmt->execute([
        'keycrm_company_id' => $keycrmId > 0 ? $keycrmId : null,
        'display_name' => $displayName,
        'keycrm_name' => $name,
        'keycrm_title' => $title,
        'name' => $name,
        'title' => $title,
        'manager_id' => $managerId,
        'keycrm_manager_id' => $managerId,
        'keycrm_manager_name' => $managerName,
        'raw_json' => $rawJson ?: null,
    ]);

    return (int) $pdo->lastInsertId();
}

function client_sync_upsert_buyer(PDO $pdo, array $buyer): ?int
{
    $keycrmId = (int) ($buyer['id'] ?? 0);
    $company = is_array($buyer['company'] ?? null) ? $buyer['company'] : [];
    $clientCompanyId = null;
    if ($company) {
        $clientCompanyId = client_sync_upsert_company($pdo, $company);
    } elseif (!empty($buyer['company_id'])) {
        $stmt = $pdo->prepare('SELECT id FROM db_client_companies WHERE keycrm_company_id = :keycrm_company_id ORDER BY id DESC LIMIT 1');
        $stmt->execute(['keycrm_company_id' => (int) $buyer['company_id']]);
        $clientCompanyId = $stmt->fetchColumn() ?: null;
    }

    $fullName = client_sync_value($buyer, ['full_name', 'name']);
    $email = client_sync_value($buyer, ['email']);
    $phone = client_sync_value($buyer, ['phone']);
    $position = client_sync_value($buyer, ['position']);
    $managerId = client_sync_manager_id($buyer);
    $managerName = client_sync_manager_name($buyer);
    $rawJson = json_encode($buyer, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($keycrmId <= 0 && $fullName === null && $email === null && $phone === null) {
        return null;
    }

    $localId = null;
    if ($keycrmId > 0) {
        $stmt = $pdo->prepare('SELECT id FROM db_client_contacts WHERE keycrm_buyer_id = :keycrm_buyer_id ORDER BY id DESC LIMIT 1');
        $stmt->execute(['keycrm_buyer_id' => $keycrmId]);
        $localId = $stmt->fetchColumn() ?: null;
    }

    if ($localId) {
        $stmt = $pdo->prepare("
            UPDATE db_client_contacts
            SET client_company_id = :client_company_id,
                full_name = :full_name,
                email = :email,
                phone = :phone,
                position = :position,
                keycrm_manager_id = :keycrm_manager_id,
                keycrm_manager_name = :keycrm_manager_name,
                raw_json = :raw_json,
                synced_at = NOW()
            WHERE id = :id
        ");
        $stmt->execute([
            'client_company_id' => $clientCompanyId ?: null,
            'full_name' => $fullName,
            'email' => $email,
            'phone' => $phone,
            'position' => $position,
            'keycrm_manager_id' => $managerId,
            'keycrm_manager_name' => $managerName,
            'raw_json' => $rawJson ?: null,
            'id' => (int) $localId,
        ]);

        return (int) $localId;
    }

    $stmt = $pdo->prepare("
        INSERT INTO db_client_contacts
            (keycrm_buyer_id, client_company_id, full_name, email, phone, position, keycrm_manager_id, keycrm_manager_name, raw_json, synced_at)
        VALUES
            (:keycrm_buyer_id, :client_company_id, :full_name, :email, :phone, :position, :keycrm_manager_id, :keycrm_manager_name, :raw_json, NOW())
    ");
    $stmt->execute([
        'keycrm_buyer_id' => $keycrmId > 0 ? $keycrmId : null,
        'client_company_id' => $clientCompanyId ?: null,
        'full_name' => $fullName,
        'email' => $email,
        'phone' => $phone,
        'position' => $position,
        'keycrm_manager_id' => $managerId,
        'keycrm_manager_name' => $managerName,
        'raw_json' => $rawJson ?: null,
    ]);

    return (int) $pdo->lastInsertId();
}

function client_sync_state(PDO $pdo, string $key, string $status, ?string $error = null, bool $success = false): void
{
    $stmt = $pdo->prepare("
        INSERT INTO db_sync_state (sync_key, last_attempt_at, last_successful_sync_at, status, error_message)
        VALUES (:sync_key, NOW(), " . ($success ? 'NOW()' : 'NULL') . ", :status, :error_message)
        ON DUPLICATE KEY UPDATE
            last_attempt_at = NOW(),
            last_successful_sync_at = " . ($success ? 'NOW()' : 'last_successful_sync_at') . ",
            status = VALUES(status),
            error_message = VALUES(error_message)
    ");
    $stmt->execute([
        'sync_key' => $key,
        'status' => $status,
        'error_message' => $error,
    ]);
}

function client_sync_create_run(PDO $pdo, string $syncType): int
{
    $stmt = $pdo->prepare("
        INSERT INTO db_client_sync_runs (sync_type, started_at, status, created_by_user_id)
        VALUES (:sync_type, NOW(), 'running', :created_by_user_id)
    ");
    $stmt->execute([
        'sync_type' => $syncType,
        'created_by_user_id' => (int) (current_user()['id'] ?? 0),
    ]);

    return (int) $pdo->lastInsertId();
}

function client_sync_finish_run(PDO $pdo, int $runId, string $status, int $seen, int $companies, int $contacts, ?string $error): void
{
    $stmt = $pdo->prepare("
        UPDATE db_client_sync_runs
        SET finished_at = NOW(),
            status = :status,
            records_seen = :records_seen,
            companies_upserted = :companies_upserted,
            contacts_upserted = :contacts_upserted,
            error_message = :error_message
        WHERE id = :id
    ");
    $stmt->execute([
        'status' => $status,
        'records_seen' => $seen,
        'companies_upserted' => $companies,
        'contacts_upserted' => $contacts,
        'error_message' => $error,
        'id' => $runId,
    ]);
}

function client_sync_run(string $type, bool $initial, int $perPage, int $maxPages): array
{
    $apiKey = trim((string) app_config('keycrm.api_key', ''));
    if ($apiKey === '' || $apiKey === 'CHANGE_ME_IN_REAL_CONFIG') {
        throw new RuntimeException('KeyCRM API key is not configured in config/config.php.');
    }

    $pdo = db();
    $syncKey = $type === 'buyers' ? 'keycrm_buyers' : 'keycrm_companies';
    $syncType = ($initial ? 'initial_' : 'delta_') . $type;
    $runId = client_sync_create_run($pdo, $syncType);
    client_sync_state($pdo, $syncKey, 'running');
    $seen = 0;
    $companies = 0;
    $contacts = 0;

    try {
        $lastSync = null;
        if (!$initial) {
            $stmt = $pdo->prepare('SELECT last_successful_sync_at FROM db_sync_state WHERE sync_key = :sync_key LIMIT 1');
            $stmt->execute(['sync_key' => $syncKey]);
            $lastSync = client_sync_compact_date($stmt->fetchColumn());
        }

        for ($page = 1; $page <= $maxPages; $page++) {
            $params = [
                'limit' => $perPage,
                'page' => $page,
            ];
            if ($type === 'buyers') {
                $params['include'] = 'company';
            }
            if ($lastSync) {
                $params['updated_after'] = $lastSync;
                $params['filter[updated_after]'] = $lastSync;
            }

            $endpoint = ($type === 'buyers' ? 'buyer' : 'companies') . '?' . http_build_query($params);
            $response = client_sync_request($endpoint, $apiKey);
            if ($response['status'] < 200 || $response['status'] >= 300) {
                throw new RuntimeException('KeyCRM request failed with HTTP ' . $response['status']);
            }

            $rows = client_sync_rows($response);
            if (!$rows) {
                break;
            }

            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $seen++;
                if ($type === 'buyers') {
                    $contactId = client_sync_upsert_buyer($pdo, $row);
                    if ($contactId) {
                        $contacts++;
                    }
                    if (!empty($row['company']) && is_array($row['company'])) {
                        $companies++;
                    }
                } else {
                    $companyId = client_sync_upsert_company($pdo, $row);
                    if ($companyId) {
                        $companies++;
                    }
                }
            }

            if (count($rows) < $perPage) {
                break;
            }
        }

        client_sync_finish_run($pdo, $runId, 'success', $seen, $companies, $contacts, null);
        client_sync_state($pdo, $syncKey, 'success', null, true);

        return [
            'sync_type' => $syncType,
            'records_seen' => $seen,
            'companies_upserted' => $companies,
            'contacts_upserted' => $contacts,
        ];
    } catch (Throwable $e) {
        client_sync_finish_run($pdo, $runId, 'failed', $seen, $companies, $contacts, $e->getMessage());
        client_sync_state($pdo, $syncKey, 'failed', $e->getMessage());
        throw $e;
    }
}

if (is_post()) {
    if (!csrf_is_valid()) {
        $error = 'Invalid sync request. Refresh the page and try again.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        try {
            if ($action === 'sync_companies_delta') {
                $summary = client_sync_run('companies', false, $perPage, $deltaPages);
            } elseif ($action === 'sync_buyers_delta') {
                $summary = client_sync_run('buyers', false, $perPage, $deltaPages);
            } elseif ($action === 'sync_companies_initial') {
                $summary = client_sync_run('companies', true, $perPage, $initialPages);
            } elseif ($action === 'sync_buyers_initial') {
                $summary = client_sync_run('buyers', true, $perPage, $initialPages);
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$states = db()->query("SELECT * FROM db_sync_state WHERE sync_key IN ('keycrm_companies', 'keycrm_buyers') ORDER BY sync_key ASC")->fetchAll();
$runs = db()->query('SELECT * FROM db_client_sync_runs ORDER BY started_at DESC LIMIT 12')->fetchAll();
?>
<!doctype html>
<html lang="uk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Клієнти Sync | .BRAND DB</title>
    <link rel="stylesheet" href="<?= e(asset_path('/assets/app.css')) ?>">
</head>
<body>
    <main class="page">
        <header class="topbar">
            <div class="brand-block">
                <p class="eyebrow">Тільки CEO</p>
                <h1>Синхронізація клієнтів</h1>
            </div>
            <nav class="nav">
                <a href="<?= e(base_path('/index.php')) ?>">Дашборд</a>
                <a href="<?= e(base_path('/invoices.php')) ?>">Рахунки</a>
                <a href="<?= e(base_path('/sync_orders.php')) ?>">Замовлення Sync</a>
                <a class="active" href="<?= e(base_path('/clients_sync.php')) ?>">Клієнти Sync</a>
                <a href="<?= e(base_path('/logout.php')) ?>">Вийти</a>
            </nav>
        </header>

        <?php if ($error !== ''): ?>
            <div class="alert"><?= e($error) ?></div>
        <?php endif; ?>
        <?php if ($summary): ?>
            <div class="notice">
                Sync <?= e((string) $summary['sync_type']) ?>:
                records <?= e((string) $summary['records_seen']) ?>,
                companies <?= e((string) $summary['companies_upserted']) ?>,
                contacts <?= e((string) $summary['contacts_upserted']) ?>.
            </div>
        <?php endif; ?>

        <section class="panel dashboard-section">
            <div class="section-heading">
                <div>
                    <span class="label">Локальний кеш</span>
                    <h2>Компанії та контакти KeyCRM</h2>
                </div>
            </div>
            <div class="toolbar">
                <form method="post">
                    <?= csrf_field() ?>
                    <button type="submit" name="action" value="sync_companies_delta">Sync companies changes</button>
                </form>
                <form method="post">
                    <?= csrf_field() ?>
                    <button type="submit" name="action" value="sync_buyers_delta">Sync buyers changes</button>
                </form>
                <form method="post">
                    <?= csrf_field() ?>
                    <button type="submit" name="action" value="sync_companies_initial" class="button-secondary" data-confirm="Запустити initial import companies?">Initial import companies</button>
                </form>
                <form method="post">
                    <?= csrf_field() ?>
                    <button type="submit" name="action" value="sync_buyers_initial" class="button-secondary" data-confirm="Запустити initial import buyers?">Initial import buyers</button>
                </form>
            </div>
        </section>

        <section class="panel table-panel">
            <div class="section-heading padded">
                <div>
                    <span class="label">Стан</span>
                    <h2>Остання синхронізація</h2>
                </div>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Ключ</th>
                            <th>Статус</th>
                            <th>Остання спроба</th>
                            <th>Успішно</th>
                            <th>Помилка</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($states as $state): ?>
                            <tr>
                                <td><?= e((string) $state['sync_key']) ?></td>
                                <td><?= e((string) $state['status']) ?></td>
                                <td><?= e((string) ($state['last_attempt_at'] ?? '—')) ?></td>
                                <td><?= e((string) ($state['last_successful_sync_at'] ?? '—')) ?></td>
                                <td><?= e((string) ($state['error_message'] ?? '')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="panel table-panel">
            <div class="section-heading padded">
                <div>
                    <span class="label">Лог</span>
                    <h2>Останні запуски</h2>
                </div>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Тип</th>
                            <th>Старт</th>
                            <th>Фініш</th>
                            <th>Статус</th>
                            <th class="num">Records</th>
                            <th class="num">Companies</th>
                            <th class="num">Contacts</th>
                            <th>Помилка</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($runs as $run): ?>
                            <tr>
                                <td><?= e((string) $run['sync_type']) ?></td>
                                <td><?= e((string) $run['started_at']) ?></td>
                                <td><?= e((string) ($run['finished_at'] ?? '—')) ?></td>
                                <td><?= e((string) $run['status']) ?></td>
                                <td class="num"><?= e((string) $run['records_seen']) ?></td>
                                <td class="num"><?= e((string) $run['companies_upserted']) ?></td>
                                <td class="num"><?= e((string) $run['contacts_upserted']) ?></td>
                                <td><?= e((string) ($run['error_message'] ?? '')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
        <?= app_version_badge() ?>
    </main>
    <script>
        document.querySelectorAll('[data-confirm]').forEach(function (button) {
            button.addEventListener('click', function (event) {
                if (!window.confirm(button.dataset.confirm)) {
                    event.preventDefault();
                }
            });
        });
    </script>
</body>
</html>
