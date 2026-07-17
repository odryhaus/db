<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/cockpit.php';
require_once __DIR__ . '/financial.php';
require_once __DIR__ . '/cockpit_layout.php';

require_login();

$month = cockpit_valid_month((string) ($_GET['month'] ?? date('Y-m')));
$search = trim((string) ($_GET['q'] ?? ''));
$bounds = cockpit_month_bounds($month);
$summary = cockpit_monthly_summary($month);
$rowsByKey = [];
$trend = [];
$hasOrders = invoice_table_exists('db_orders');
$hasPayments = invoice_table_exists('db_order_payments');
$orderColumns = $hasOrders ? finance_columns('db_orders') : [];
$hasClientCompanies = invoice_table_exists('db_client_companies');
$hasClientContacts = invoice_table_exists('db_client_contacts');
$clientCompanyColumns = $hasClientCompanies ? finance_columns('db_client_companies') : [];
$clientContactColumns = $hasClientContacts ? finance_columns('db_client_contacts') : [];

function client_balances_months(string $month, int $count = 6): array
{
    $date = DateTimeImmutable::createFromFormat('!Y-m', cockpit_valid_month($month)) ?: new DateTimeImmutable('first day of this month');
    $months = [];
    for ($i = $count - 1; $i >= 0; $i--) {
        $months[] = $date->modify("-{$i} months")->format('Y-m');
    }
    return $months;
}

function client_balances_coalesce(array $expressions, string $fallback): string
{
    $parts = $expressions;
    $parts[] = "'" . str_replace("'", "''", $fallback) . "'";
    return 'COALESCE(' . implode(', ', $parts) . ')';
}

function client_balances_exprs(array $orderColumns, bool $hasClientCompanies, bool $hasClientContacts, array $clientCompanyColumns, array $clientContactColumns): array
{
    $hasCompanyId = in_array('company_id', $orderColumns, true);
    $hasBuyerId = in_array('buyer_id', $orderColumns, true);
    $hasClientId = in_array('client_id', $orderColumns, true);
    $hasCompanyName = in_array('company_name', $orderColumns, true);
    $hasBuyerName = in_array('buyer_name', $orderColumns, true);
    $hasClientName = in_array('client_name', $orderColumns, true);
    $hasBuyerEmail = in_array('buyer_email', $orderColumns, true);
    $hasBuyerPhone = in_array('buyer_phone', $orderColumns, true);
    $hasOrderNumber = in_array('order_number', $orderColumns, true);
    $hasManagerName = in_array('manager_name', $orderColumns, true);

    $companyJoin = $hasClientCompanies && $hasCompanyId ? "LEFT JOIN db_client_companies cc ON cc.keycrm_company_id = o.company_id" : "";
    $contactJoin = $hasClientContacts && $hasBuyerId ? "LEFT JOIN db_client_contacts ct ON ct.keycrm_buyer_id = o.buyer_id" : "";

    $companyExpressions = [];
    if ($hasClientCompanies && $hasCompanyId) {
        foreach (['keycrm_name', 'name', 'display_name'] as $column) {
            if (in_array($column, $clientCompanyColumns, true)) {
                $companyExpressions[] = "NULLIF(cc.{$column}, '')";
            }
        }
    }
    if ($hasCompanyName) {
        $companyExpressions[] = "NULLIF(o.company_name, '')";
    }
    if ($hasClientName) {
        $companyExpressions[] = "NULLIF(o.client_name, '')";
    }
    if ($hasBuyerName) {
        $companyExpressions[] = "NULLIF(o.buyer_name, '')";
    }
    $companyName = client_balances_coalesce($companyExpressions, 'Без компанії');

    $buyerExpressions = [];
    if ($hasClientContacts && $hasBuyerId) {
        if (in_array('full_name', $clientContactColumns, true)) {
            $buyerExpressions[] = "NULLIF(ct.full_name, '')";
        }
    }
    if ($hasBuyerName) {
        $buyerExpressions[] = "NULLIF(o.buyer_name, '')";
    }
    if ($hasClientName) {
        $buyerExpressions[] = "NULLIF(o.client_name, '')";
    }
    if ($hasBuyerEmail) {
        $buyerExpressions[] = "NULLIF(o.buyer_email, '')";
    }
    $buyerName = client_balances_coalesce($buyerExpressions, 'Без покупця');

    if ($hasCompanyId) {
        $key = "CASE WHEN COALESCE(o.company_id, 0) > 0 THEN CONCAT('company:', o.company_id) ELSE CONCAT('company-name:', {$companyName}) END";
    } elseif ($hasClientId) {
        $key = "CASE WHEN COALESCE(o.client_id, 0) > 0 THEN CONCAT('client:', o.client_id) ELSE CONCAT('company-name:', {$companyName}) END";
    } else {
        $key = "CONCAT('company-name:', {$companyName})";
    }

    $searchFields = [];
    foreach ([
        $hasOrderNumber ? "o.order_number" : null,
        $hasCompanyName ? "o.company_name" : null,
        $hasBuyerName ? "o.buyer_name" : null,
        $hasClientName ? "o.client_name" : null,
        $hasBuyerEmail ? "o.buyer_email" : null,
        $hasBuyerPhone ? "o.buyer_phone" : null,
        $hasManagerName ? "o.manager_name" : null,
    ] as $field) {
        if ($field !== null) {
            $searchFields[] = $field;
        }
    }
    if ($hasClientCompanies && $hasCompanyId) {
        foreach (['keycrm_name', 'keycrm_title', 'display_name', 'name', 'title'] as $column) {
            if (in_array($column, $clientCompanyColumns, true)) {
                $searchFields[] = "cc.{$column}";
            }
        }
    }
    if ($hasClientContacts && $hasBuyerId) {
        foreach (['full_name', 'email', 'phone'] as $column) {
            if (in_array($column, $clientContactColumns, true)) {
                $searchFields[] = "ct.{$column}";
            }
        }
    }

    return [
        'key' => $key,
        'company_name' => $companyName,
        'buyer_name' => $buyerName,
        'manager_name' => $hasManagerName ? "COALESCE(NULLIF(MAX(o.manager_name), ''), 'Без менеджера')" : "'Без менеджера'",
        'joins' => trim($companyJoin . "\n" . $contactJoin),
        'search_sql' => $searchFields ? '(' . implode(' OR ', array_map(static fn($field) => "{$field} LIKE :search", $searchFields)) . ')' : '1=1',
    ];
}

function client_balances_blank_row(string $key, string $name = ''): array
{
    return [
        'client_key' => $key,
        'client_name' => $name !== '' ? $name : 'Без компанії',
        'buyers' => [],
        'buyers_count' => 0,
        'manager_name' => '',
        'order_count' => 0,
        'sales_month' => 0.0,
        'paid_by_order' => 0.0,
        'unpaid_by_order' => 0.0,
        'total_purchases' => 0.0,
        'receivable_total' => 0.0,
        'receivable_count' => 0,
        'largest_receivable' => 0.0,
        'cash_received' => 0.0,
        'cash_count' => 0,
    ];
}

function client_balances_take_row(array &$rowsByKey, array $row): void
{
    $key = (string) ($row['client_key'] ?? '');
    if ($key === '') {
        $key = 'unknown';
    }
    if (!isset($rowsByKey[$key])) {
        $rowsByKey[$key] = client_balances_blank_row($key, trim((string) ($row['client_name'] ?? '')));
    }
    if ($rowsByKey[$key]['client_name'] === 'Без компанії' && !empty($row['client_name'])) {
        $rowsByKey[$key]['client_name'] = (string) $row['client_name'];
    }
    if ($rowsByKey[$key]['manager_name'] === '' && !empty($row['manager_name'])) {
        $rowsByKey[$key]['manager_name'] = (string) $row['manager_name'];
    }
}

function client_balances_split_buyers(?string $buyers): array
{
    $buyers = trim((string) $buyers);
    if ($buyers === '') {
        return [];
    }
    $parts = array_values(array_filter(array_map('trim', explode('|||', $buyers)), static fn($item) => $item !== ''));
    return array_slice(array_values(array_unique($parts)), 0, 8);
}

$months = client_balances_months($month);

try {
    if ($hasOrders) {
        $expr = client_balances_exprs($orderColumns, $hasClientCompanies, $hasClientContacts, $clientCompanyColumns, $clientContactColumns);
        $notCanceled = cockpit_not_canceled_sql('o');
        $searchSql = $search !== '' ? ' AND ' . $expr['search_sql'] : '';
        $searchParams = $search !== '' ? ['search' => '%' . $search . '%'] : [];

        $stmt = db()->prepare("
            SELECT
                {$expr['key']} AS client_key,
                {$expr['company_name']} AS client_name,
                {$expr['manager_name']} AS manager_name,
                COUNT(*) AS order_count,
                COALESCE(SUM(o.total_amount_uah), 0) AS sales_month,
                COALESCE(SUM(o.paid_amount_uah), 0) AS paid_by_order,
                COALESCE(SUM(o.unpaid_amount_uah), 0) AS unpaid_by_order
            FROM db_orders o
            {$expr['joins']}
            WHERE o.order_month = :month
              AND {$notCanceled}
              {$searchSql}
            GROUP BY client_key, client_name
        ");
        $stmt->execute(array_merge(['month' => $month], $searchParams));
        foreach ($stmt->fetchAll() as $row) {
            $key = (string) ($row['client_key'] ?? 'unknown');
            $rowsByKey[$key] = client_balances_blank_row($key, (string) $row['client_name']);
            $rowsByKey[$key]['manager_name'] = (string) ($row['manager_name'] ?? '');
            $rowsByKey[$key]['order_count'] = (int) ($row['order_count'] ?? 0);
            $rowsByKey[$key]['sales_month'] = (float) ($row['sales_month'] ?? 0);
            $rowsByKey[$key]['paid_by_order'] = (float) ($row['paid_by_order'] ?? 0);
            $rowsByKey[$key]['unpaid_by_order'] = (float) ($row['unpaid_by_order'] ?? 0);
        }

        $stmt = db()->prepare("
            SELECT
                {$expr['key']} AS client_key,
                {$expr['company_name']} AS client_name,
                {$expr['manager_name']} AS manager_name,
                COALESCE(SUM(o.total_amount_uah), 0) AS total_purchases
            FROM db_orders o
            {$expr['joins']}
            WHERE {$notCanceled}
              {$searchSql}
            GROUP BY client_key, client_name
        ");
        $stmt->execute($searchParams);
        foreach ($stmt->fetchAll() as $row) {
            $key = (string) ($row['client_key'] ?? 'unknown');
            client_balances_take_row($rowsByKey, $row);
            $rowsByKey[$key]['total_purchases'] = (float) ($row['total_purchases'] ?? 0);
        }

        $stmt = db()->prepare("
            SELECT
                {$expr['key']} AS client_key,
                {$expr['company_name']} AS client_name,
                {$expr['manager_name']} AS manager_name,
                COUNT(*) AS receivable_count,
                COALESCE(SUM(o.unpaid_amount_uah), 0) AS receivable_total,
                COALESCE(MAX(o.unpaid_amount_uah), 0) AS largest_receivable
            FROM db_orders o
            {$expr['joins']}
            WHERE o.unpaid_amount_uah > 0
              AND {$notCanceled}
              {$searchSql}
            GROUP BY client_key, client_name
        ");
        $stmt->execute($searchParams);
        foreach ($stmt->fetchAll() as $row) {
            $key = (string) ($row['client_key'] ?? 'unknown');
            client_balances_take_row($rowsByKey, $row);
            $rowsByKey[$key]['manager_name'] = $rowsByKey[$key]['manager_name'] ?: (string) ($row['manager_name'] ?? '');
            $rowsByKey[$key]['receivable_count'] = (int) ($row['receivable_count'] ?? 0);
            $rowsByKey[$key]['receivable_total'] = (float) ($row['receivable_total'] ?? 0);
            $rowsByKey[$key]['largest_receivable'] = (float) ($row['largest_receivable'] ?? 0);
        }

        $stmt = db()->prepare("
            SELECT
                {$expr['key']} AS client_key,
                {$expr['company_name']} AS client_name,
                COUNT(DISTINCT {$expr['buyer_name']}) AS buyers_count,
                GROUP_CONCAT(DISTINCT {$expr['buyer_name']} ORDER BY {$expr['buyer_name']} SEPARATOR '|||') AS buyers
            FROM db_orders o
            {$expr['joins']}
            WHERE {$notCanceled}
              {$searchSql}
            GROUP BY client_key, client_name
        ");
        $stmt->execute($searchParams);
        foreach ($stmt->fetchAll() as $row) {
            $key = (string) ($row['client_key'] ?? 'unknown');
            client_balances_take_row($rowsByKey, $row);
            $rowsByKey[$key]['buyers'] = client_balances_split_buyers((string) ($row['buyers'] ?? ''));
            $rowsByKey[$key]['buyers_count'] = (int) ($row['buyers_count'] ?? 0);
        }

        $trendMonthPlaceholders = implode(',', array_map(static fn($idx) => ':trend_month_' . $idx, array_keys($months)));
        $trendParams = $searchParams;
        foreach ($months as $idx => $trendMonth) {
            $trendParams['trend_month_' . $idx] = $trendMonth;
        }
        $stmt = db()->prepare("
            SELECT
                {$expr['key']} AS client_key,
                {$expr['company_name']} AS client_name,
                o.order_month,
                COUNT(*) AS order_count,
                COALESCE(SUM(o.total_amount_uah), 0) AS sales_month,
                COALESCE(SUM(o.paid_amount_uah), 0) AS paid_by_order,
                COALESCE(SUM(o.unpaid_amount_uah), 0) AS unpaid_by_order
            FROM db_orders o
            {$expr['joins']}
            WHERE o.order_month IN ({$trendMonthPlaceholders})
              AND {$notCanceled}
              {$searchSql}
            GROUP BY client_key, client_name, o.order_month
        ");
        $stmt->execute($trendParams);
        foreach ($stmt->fetchAll() as $row) {
            $key = (string) ($row['client_key'] ?? 'unknown');
            client_balances_take_row($rowsByKey, $row);
            $trend[$key][(string) $row['order_month']] = [
                'sales' => (float) ($row['sales_month'] ?? 0),
                'paid' => (float) ($row['paid_by_order'] ?? 0),
                'unpaid' => (float) ($row['unpaid_by_order'] ?? 0),
                'count' => (int) ($row['order_count'] ?? 0),
            ];
        }

        if ($hasPayments) {
            $activePayment = cockpit_active_payment_sql('p');
            $stmt = db()->prepare("
                SELECT
                    {$expr['key']} AS client_key,
                    {$expr['company_name']} AS client_name,
                    {$expr['manager_name']} AS manager_name,
                    COUNT(*) AS cash_count,
                    COALESCE(SUM(p.amount), 0) AS cash_received
                FROM db_order_payments p
                INNER JOIN db_orders o ON o.keycrm_id = p.keycrm_order_id
                {$expr['joins']}
                WHERE p.payment_date >= :month_start
                  AND p.payment_date <= :month_end
                  AND {$activePayment}
                  AND {$notCanceled}
                  {$searchSql}
                GROUP BY client_key, client_name
            ");
            $stmt->execute(array_merge([
                'month_start' => $bounds['start']->format('Y-m-d H:i:s'),
                'month_end' => $bounds['end']->format('Y-m-d H:i:s'),
            ], $searchParams));
            foreach ($stmt->fetchAll() as $row) {
                $key = (string) ($row['client_key'] ?? 'unknown');
                client_balances_take_row($rowsByKey, $row);
                $rowsByKey[$key]['manager_name'] = $rowsByKey[$key]['manager_name'] ?: (string) ($row['manager_name'] ?? '');
                $rowsByKey[$key]['cash_count'] = (int) ($row['cash_count'] ?? 0);
                $rowsByKey[$key]['cash_received'] = (float) ($row['cash_received'] ?? 0);
            }
        }
    }
} catch (Throwable $e) {
    $rowsByKey = [];
}

$rows = array_values($rowsByKey);
usort($rows, static function (array $a, array $b): int {
    $purchaseCompare = (float) $b['total_purchases'] <=> (float) $a['total_purchases'];
    if ($purchaseCompare !== 0) {
        return $purchaseCompare;
    }
    return (float) $b['sales_month'] <=> (float) $a['sales_month'];
});
$rows = array_slice($rows, 0, 200);
$activeClientCount = count(array_filter($rows, static fn($row) => (float) $row['sales_month'] > 0));

?><!doctype html>
<html lang="uk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Баланси клієнтів — .BRAND DB</title>
    <link rel="stylesheet" href="<?= e(asset_path('/assets/app.css')) ?>">
</head>
<body>
<main class="app-shell cockpit-shell">
    <?php cockpit_page_header('CEO Money Cockpit', 'Баланси клієнтів', 'Компанія головна, покупці всередині. Сортування від найбільших закупок.', 'client_balances', $month); ?>

    <section class="kpi-grid">
        <div class="kpi-card"><span class="label">Факт за місяць</span><strong><?= e(finance_money($summary['sales_fact'])) ?></strong></div>
        <div class="kpi-card"><span class="label">Гроші прийшли</span><strong><?= e(finance_money($summary['cash_received'])) ?></strong><small>за датою платежу</small></div>
        <div class="kpi-card"><span class="label">Нам повинні</span><strong><?= e(finance_money($summary['receivables_total'])) ?></strong><small>усі місяці</small></div>
        <div class="kpi-card"><span class="label">Активні у місяці</span><strong><?= e((string) $activeClientCount) ?></strong><small>компаній</small></div>
    </section>

    <section class="panel dashboard-section">
        <form class="client-balance-toolbar" method="get" action="<?= e(base_path('/client_balances.php')) ?>">
            <label>
                <span>Місяць</span>
                <input type="month" name="month" value="<?= e($month) ?>">
            </label>
            <label class="client-balance-search">
                <span>Пошук</span>
                <input type="search" name="q" value="<?= e($search) ?>" placeholder="компанія, покупець, email, телефон, номер">
            </label>
            <button type="submit">Показати</button>
        </form>
    </section>

    <section class="panel dashboard-section">
        <div class="section-heading">
            <div>
                <p class="eyebrow">Top clients</p>
                <h2>Компанії за сумою закупок</h2>
            </div>
            <span class="status-badge">продажі = order_month · гроші = payment_date</span>
        </div>
        <div class="table-scroll">
            <table class="data-table client-balance-table">
                <thead>
                <tr>
                    <th class="wrap">Компанія / покупці</th>
                    <th>Менеджер</th>
                    <th class="num">Закупки всього</th>
                    <th class="num">Зам.</th>
                    <th class="num">Факт</th>
                    <th class="num">Оплачено</th>
                    <th class="num">Гроші</th>
                    <th class="num">Борг</th>
                    <th class="wrap">Місяці</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$rows): ?><tr><td colspan="9">Даних немає.</td></tr><?php endif; ?>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td class="client-cell">
                            <div class="client-stack">
                                <span class="client-stack-company"><?= e((string) $row['client_name']) ?></span>
                                <?php if ($row['buyers']): ?>
                                    <span class="client-stack-contact"><?= e(implode(' / ', $row['buyers'])) ?></span>
                                <?php endif; ?>
                                <?php if ((int) $row['buyers_count'] > count($row['buyers'])): ?>
                                    <span class="client-stack-contact">+<?= e((string) ((int) $row['buyers_count'] - count($row['buyers']))) ?> ще</span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td><?= e((string) ($row['manager_name'] ?: '—')) ?></td>
                        <td class="num"><strong><?= e(finance_money($row['total_purchases'])) ?></strong></td>
                        <td class="num"><?= e((string) $row['order_count']) ?></td>
                        <td class="num"><?= e(finance_money($row['sales_month'])) ?></td>
                        <td class="num"><?= e(finance_money($row['paid_by_order'])) ?></td>
                        <td class="num"><strong><?= e(finance_money($row['cash_received'])) ?></strong><small><?= (int) $row['cash_count'] > 0 ? e((string) $row['cash_count']) . ' пл.' : '' ?></small></td>
                        <td class="num"><strong><?= e(finance_money($row['receivable_total'])) ?></strong><small><?= (int) $row['receivable_count'] > 0 ? e((string) $row['receivable_count']) . ' борг.' : '' ?></small></td>
                        <td class="wrap">
                            <div class="client-month-strip">
                                <?php foreach ($months as $trendMonth): ?>
                                    <?php $monthData = $trend[(string) $row['client_key']][$trendMonth] ?? ['sales' => 0, 'paid' => 0, 'unpaid' => 0, 'count' => 0]; ?>
                                    <span class="client-month-cell <?= (float) $monthData['sales'] > 0 ? 'has-sales' : '' ?>">
                                        <small><?= e(substr($trendMonth, 5, 2)) ?>.<?= e(substr($trendMonth, 2, 2)) ?></small>
                                        <strong><?= e(money_uah_compact($monthData['sales'])) ?></strong>
                                        <?php if ((float) $monthData['unpaid'] > 0): ?><em>борг <?= e(money_uah_compact($monthData['unpaid'])) ?></em><?php endif; ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        </td>
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
