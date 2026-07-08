<?php

require_once __DIR__ . '/bootstrap.php';
require_role('ceo');

$defaultOrderId = 9124;
$orderId = isset($_GET['order_id']) ? (int) $_GET['order_id'] : $defaultOrderId;
if ($orderId <= 0) {
    $orderId = $defaultOrderId;
}

$include = 'products,products.offer,status,shipping,manager,buyer';
$baseUrl = rtrim((string) app_config('keycrm.base_url', 'https://openapi.keycrm.app/v1'), '/');
$apiKey = (string) app_config('keycrm.api_key', '');
$placeholderKey = 'CHANGE_ME_IN_REAL_CONFIG';

$error = '';
$result = null;

function keycrm_debug_scalar($value): bool
{
    return is_scalar($value) || $value === null;
}

function keycrm_debug_assoc(array $value): bool
{
    if ($value === []) {
        return true;
    }

    return array_keys($value) !== range(0, count($value) - 1);
}

function keycrm_debug_get_path(array $data, array $paths): ?string
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
        if (keycrm_debug_scalar($current) && $current !== null && $current !== '') {
            return (string) $current;
        }
    }

    return null;
}

function keycrm_debug_collect_candidates(array $data, array $patterns): array
{
    $out = [];
    keycrm_debug_collect_candidates_recursive($data, $patterns, '', $out);

    return $out;
}

function keycrm_debug_collect_candidates_recursive(array $data, array $patterns, string $prefix, array &$out): void
{
    foreach ($data as $key => $value) {
        $path = $prefix === '' ? (string) $key : $prefix . '.' . (string) $key;
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, (string) $key)) {
                if (keycrm_debug_scalar($value)) {
                    $out[$path] = $value === null ? 'null' : (string) $value;
                } elseif (is_array($value) && isset($value['name']) && keycrm_debug_scalar($value['name'])) {
                    $out[$path . '.name'] = (string) $value['name'];
                }
                break;
            }
        }

        if (is_array($value)) {
            keycrm_debug_collect_candidates_recursive($value, $patterns, $path, $out);
        }
    }
}

function keycrm_debug_request(string $url, string $apiKey): array
{
    if (!function_exists('curl_init')) {
        return ['http_status' => 0, 'body' => '', 'error' => 'PHP cURL extension is not available.'];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 30,
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

    return [
        'http_status' => $status,
        'body' => $body === false ? '' : (string) $body,
        'error' => $error,
    ];
}

function keycrm_debug_extract_order($json): ?array
{
    if (!is_array($json)) {
        return null;
    }

    if (isset($json['data']) && is_array($json['data'])) {
        if (keycrm_debug_assoc($json['data'])) {
            return $json['data'];
        }
        if (isset($json['data'][0]) && is_array($json['data'][0])) {
            return $json['data'][0];
        }
    }

    return keycrm_debug_assoc($json) ? $json : null;
}

function keycrm_debug_fetch_order(int $orderId, string $baseUrl, string $apiKey, string $include): array
{
    $directEndpoint = '/order/' . $orderId . '?include=' . rawurlencode($include);
    $directUrl = $baseUrl . $directEndpoint;
    $direct = keycrm_debug_request($directUrl, $apiKey);
    $directJson = json_decode($direct['body'], true);

    if ($direct['http_status'] >= 200 && $direct['http_status'] < 300 && is_array($directJson)) {
        return [
            'endpoint' => $directEndpoint,
            'response' => $direct,
            'json' => $directJson,
            'fallback_used' => false,
        ];
    }

    $fallbackEndpoint = '/order?' . http_build_query([
        'filter' => ['order_id' => $orderId],
        'include' => $include,
    ]);
    $fallbackUrl = $baseUrl . $fallbackEndpoint;
    $fallback = keycrm_debug_request($fallbackUrl, $apiKey);
    $fallbackJson = json_decode($fallback['body'], true);

    return [
        'endpoint' => $fallbackEndpoint,
        'response' => $fallback,
        'json' => is_array($fallbackJson) ? $fallbackJson : null,
        'fallback_used' => true,
        'direct_status' => $direct['http_status'],
        'direct_error' => $direct['error'],
    ];
}

if ($apiKey === '' || $apiKey === $placeholderKey) {
    $error = 'KeyCRM API key is not configured in config/config.php.';
} else {
    $result = keycrm_debug_fetch_order($orderId, $baseUrl, $apiKey, $include);
}

$json = $result['json'] ?? null;
$response = $result['response'] ?? ['http_status' => 0, 'body' => '', 'error' => ''];
$order = is_array($json) ? keycrm_debug_extract_order($json) : null;
$topKeys = is_array($json) ? array_keys($json) : [];
$rawPretty = is_array($json)
    ? json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    : (string) ($response['body'] ?? '');

$managerId = $order ? keycrm_debug_get_path($order, ['manager.id', 'manager_id', 'managerId']) : null;
$managerName = $order ? keycrm_debug_get_path($order, ['manager.name', 'manager.full_name', 'manager.username', 'manager.email', 'manager_name', 'managerName']) : null;
$clientId = $order ? keycrm_debug_get_path($order, ['client.id', 'customer.id', 'buyer.id', 'contact.id', 'client_id', 'customer_id', 'buyer_id']) : null;
$clientName = $order ? keycrm_debug_get_path($order, ['client.name', 'client.full_name', 'customer.name', 'customer.full_name', 'buyer.name', 'buyer.full_name', 'contact.name', 'client_name', 'customer_name', 'buyer_name']) : null;
$buyerCompanyId = $order ? keycrm_debug_get_path($order, ['buyer.company_id', 'company_id', 'buyer.company.id', 'company.id']) : null;
$buyerEmail = $order ? keycrm_debug_get_path($order, ['buyer.email', 'customer.email', 'client.email', 'buyer_email']) : null;
$buyerPhone = $order ? keycrm_debug_get_path($order, ['buyer.phone', 'customer.phone', 'client.phone', 'buyer_phone']) : null;

$totalCandidates = $order ? keycrm_debug_collect_candidates($order, ['/^(grand_)?total/i', '/amount/i', '/sum/i', '/price/i', '/cost/i']) : [];
$paidCandidates = $order ? keycrm_debug_collect_candidates($order, ['/paid/i', '/payed/i']) : [];
$unpaidCandidates = $order ? keycrm_debug_collect_candidates($order, ['/unpaid/i', '/debt/i', '/balance/i', '/remaining/i']) : [];
$paymentStatusCandidates = $order ? keycrm_debug_collect_candidates($order, ['/payment.*status/i', '/status.*payment/i', '/paid_status/i']) : [];
$currencyCandidates = $order ? keycrm_debug_collect_candidates($order, ['/currency/i']) : [];
?>
<!doctype html>
<html lang="uk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>KeyCRM Order Debug | .BRAND DB</title>
    <link rel="stylesheet" href="<?= e(asset_path('/assets/app.css')) ?>">
</head>
<body>
    <main class="page">
        <header class="topbar">
            <div>
                <p class="eyebrow">CEO debug</p>
                <h1>KeyCRM Order Debug</h1>
            </div>
            <nav class="nav">
                <a href="<?= e(base_path('/index.php')) ?>">Dashboard</a>
                <a href="<?= e(base_path('/logout.php')) ?>">Logout</a>
            </nav>
        </header>

        <section class="panel">
            <form method="get" action="<?= e(base_path('/keycrm_debug_order.php')) ?>" class="inline-form">
                <label>
                    <span>Order ID</span>
                    <input type="number" name="order_id" value="<?= e((string) $orderId) ?>" min="1" required>
                </label>
                <button type="submit">Inspect order</button>
            </form>
        </section>

        <?php if ($error !== ''): ?>
            <div class="alert"><?= e($error) ?></div>
        <?php endif; ?>

        <section class="panel debug-panel">
            <h2>Request</h2>
            <dl class="debug-list">
                <dt>Requested order id</dt>
                <dd><?= e((string) $orderId) ?></dd>
                <dt>HTTP status</dt>
                <dd><?= e((string) ($response['http_status'] ?? 0)) ?></dd>
                <dt>Endpoint used</dt>
                <dd><?= e((string) ($result['endpoint'] ?? 'not called')) ?></dd>
                <dt>Fallback used</dt>
                <dd><?= !empty($result['fallback_used']) ? 'yes' : 'no' ?></dd>
                <?php if (!empty($response['error'])): ?>
                    <dt>Transport error</dt>
                    <dd><?= e((string) $response['error']) ?></dd>
                <?php endif; ?>
            </dl>
        </section>

        <section class="panel debug-panel">
            <h2>Detected Fields</h2>
            <dl class="debug-list">
                <dt>Top-level JSON keys</dt>
                <dd><?= e($topKeys ? implode(', ', $topKeys) : 'none detected') ?></dd>
                <dt>Detected order id</dt>
                <dd><?= e($order ? (keycrm_debug_get_path($order, ['id', 'order_id', 'orderId']) ?? 'not detected') : 'not detected') ?></dd>
                <dt>Detected order number</dt>
                <dd><?= e($order ? (keycrm_debug_get_path($order, ['number', 'order_number', 'orderNumber', 'source_uuid']) ?? 'not detected') : 'not detected') ?></dd>
                <dt>Detected created date</dt>
                <dd><?= e($order ? (keycrm_debug_get_path($order, ['created_at', 'createdAt', 'ordered_at', 'orderedAt']) ?? 'not detected') : 'not detected') ?></dd>
                <dt>Detected updated date</dt>
                <dd><?= e($order ? (keycrm_debug_get_path($order, ['updated_at', 'updatedAt']) ?? 'not detected') : 'not detected') ?></dd>
                <dt>Detected manager</dt>
                <dd><?= e(trim(($managerId ?? 'not detected') . ' / ' . ($managerName ?? 'not detected'))) ?></dd>
                <dt>Detected client/customer/buyer</dt>
                <dd><?= e(trim(($clientId ?? 'not detected') . ' / ' . ($clientName ?? 'not detected'))) ?></dd>
                <dt>Detected buyer company id</dt>
                <dd><?= e($buyerCompanyId ?? 'not detected') ?></dd>
                <dt>Detected buyer email</dt>
                <dd><?= e($buyerEmail ?? 'not detected') ?></dd>
                <dt>Detected buyer phone</dt>
                <dd><?= e($buyerPhone ?? 'not detected') ?></dd>
            </dl>
        </section>

        <section class="debug-grid">
            <?php
            $groups = [
                'Total amount candidates' => $totalCandidates,
                'Paid amount candidates' => $paidCandidates,
                'Unpaid amount candidates' => $unpaidCandidates,
                'Payment status candidates' => $paymentStatusCandidates,
                'Currency candidates' => $currencyCandidates,
            ];
            ?>
            <?php foreach ($groups as $title => $items): ?>
                <div class="panel debug-panel">
                    <h2><?= e($title) ?></h2>
                    <?php if (!$items): ?>
                        <p class="muted">No candidates detected.</p>
                    <?php else: ?>
                        <dl class="debug-list compact">
                            <?php foreach ($items as $path => $value): ?>
                                <dt><?= e((string) $path) ?></dt>
                                <dd><?= e((string) $value) ?></dd>
                            <?php endforeach; ?>
                        </dl>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </section>

        <section class="panel debug-panel">
            <details>
                <summary>Raw JSON</summary>
                <pre class="debug-json"><?= e($rawPretty ?: 'No response body.') ?></pre>
            </details>
        </section>
        <?= app_version_badge() ?>
    </main>
</body>
</html>
