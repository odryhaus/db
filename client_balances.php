<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/cockpit.php';
require_once __DIR__ . '/financial.php';
require_once __DIR__ . '/cockpit_layout.php';

require_login();

$month = cockpit_valid_month((string) ($_GET['month'] ?? date('Y-m')));
$group = (string) ($_GET['group'] ?? 'company');
$group = $group === 'buyer' ? 'buyer' : 'company';
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

function client_balances_months(string $month, int $count = 6): array
{
    $date = DateTimeImmutable::createFromFormat('!Y-m', cockpit_valid_month($month)) ?: new DateTimeImmutable('first day of this month');
    $months = [];
    for ($i = $count - 1; $i >= 0; $i--) {
        $months[] = $date->modify("-{$i} months")->format('Y-m');
    }
    return $months;
}

function client_balances_exprs(string $group, array $orderColumns, bool $hasClientCompanies, bool $hasClientContacts): array
{
    $hasCompanyId = in_array('company_id', $orderColumns, true);
    $hasBuyerId = in_array('buyer_id', $orderColumns, true);
    $hasClientId = in_array('client_id', $orderColumns, true);
    $companyJoin = $hasClientCompanies && $hasCompanyId ? "LEFT JOIN db_client_companies cc ON cc.keycrm_company_id = o.company_id" : "";
    $contactJoin = $hasClientContacts && $hasBuyerId ? "LEFT JOIN db_client_contacts ct ON ct.keycrm_buyer_id = o.buyer_id" : "";
    $companyLocal = $hasClientCompanies && $hasCompanyId
        ? "NULLIF(COALESCE(cc.keycrm_name, cc.name, cc.display_name), '')"
        : "NULL";
    $contactLocal = $hasClientContacts && $hasBuyerId
        ? "NULLIF(ct.full_name, '')"
        : "NULL";

    $companyName = "COALESCE({$companyLocal}, NULLIF(o.company_name, ''), NULLIF(o.client_name, ''), NULLIF(o.buyer_name, ''), 'Без компанії')";
    $buyerName = "COALESCE({$contactLocal}, NULLIF(o.buyer_name, ''), NULLIF(o.client_name, ''), NULLIF(o.company_name, ''), 'Без покупця')";

    if ($group === 'buyer') {
        if ($hasBuyerId) {
            $key = "CASE WHEN COALESCE(o.buyer_id, 0) > 0 THEN CONCAT('buyer:', o.buyer_id) ELSE CONCAT('buyer-name:', {$buyerName}) END";
        } elseif ($hasClientId) {
            $key = "CASE WHEN COALESCE(o.client_id, 0) > 0 THEN CONCAT('client:', o.client_id) ELSE CONCAT('buyer-name:', {$buyerName}) END";
        } else {
            $key = "CONCAT('buyer-name:', {$buyerName})";
        }
        $name = $buyerName;
        $subname = $companyName;
    } else {
        if ($hasCompanyId) {
            $key = "CASE WHEN COALESCE(o.company_id, 0) > 0 THEN CONCAT('company:', o.company_id) ELSE CONCAT('company-name:', {$companyName}) END";
        } else {
            $key = "CONCAT('company-name:', {$companyName})";
        }
        $name = $companyName;
        $subname = $buyerName;
    }

    $searchFields = [
        "o.order_number",
        "o.company_name",
        "o.buyer_name",
        "o.client_name",
        "o.manager_name",
    ];
    if ($hasClientCompanies && $hasCompanyId) {
        $searchFields[] = "cc.keycrm_name";
        $searchFields[] = "cc.display_name";
        $searchFields[] = "cc.name";
    }
    if ($hasClientContacts && $hasBuyerId) {
        $searchFields[] = "ct.full_name";
        $searchFields[] = "ct.email";
        $searchFields[] = "ct.phone";
    }

    return [
        'key' => $key,
        'name' => $name,
        'subname' => $subname,
        'joins' => trim($companyJoin . "\n" . $contactJoin),
        'search_sql' => '(' . implode(' OR ', array_map(static fn($field) => "{$field} LIKE :search", $searchFields)) . ')',
    ];
}

function client_balances_blank_row(string $key, string $name = '', string $subname = ''): array
{
    return [
        'client_key' => $key,
        'client_name' => $name !== '' ? $name : 'Без назви',
        'client_subname' => $subname,
        'manager_name' => '',
        'order_count' => 0,
        'sales_month' => 0.0,
        'paid_by_order' => 0.0,
        'unpaid_by_order' => 0.0,
        'receivable_total' => 0.0,
        'receivable_count' => 0,
        'largest_receivable' => 0.0,
        'cash_received' => 0.0,
        'cash_count' => 0,
    ];
}

function client_balances_take_row(array &$rowsByKey, array $row): array
{
    $key = (string) ($row['client_key'] ?? '');
    if ($key === '') {
        $key = 'unknown';
    }
    if (!isset($rowsByKey[$key])) {
        $rowsByKey[$key] = client_balances_blank_row(
            $key,
            trim((string) ($row['client_name'] ?? '')),
            trim((string) ($row['client_subname'] ?? ''))
        );
    }
    if ($rowsByKey[$key]['client_name'] === 'Без назви' && !empty($row['client_name'])) {
        $rowsByKey[$key]['client_name'] = (string) $row['client_name'];
    }
    if ($rowsByKey[$key]['client_subname'] === '' && !empty($row['client_subname'])) {
        $rowsByKey[$key]['client_subname'] = (string) $row['client_subname'];
    }
    if ($rowsByKey[$key]['manager_name'] === '' && !empty($row['manager_name'])) {
        $rowsByKey[$key]['manager_name'] = (string) $row['manager_name'];
    }
    return $rowsByKey[$key];
}

$months = client_balances_months($month);

try {
    if ($hasOrders) {
        $expr = client_balances_exprs($group, $orderColumns, $hasClientCompanies, $hasClientContacts);
        $notCanceled = cockpit_not_canceled_sql('o');
        $searchSql = $search !== '' ? ' AND ' . $expr['search_sql'] : '';
        $searchParams = $search !== '' ? ['search' => '%' . $search . '%'] : [];

        $stmt = db()->prepare("
            SELECT
                {$expr['key']} AS client_key,
                {$expr['name']} AS client_name,
                {$expr['subname']} AS client_subname,
                COALESCE(NULLIF(MAX(o.manager_name), ''), 'Без менеджера') AS manager_name,
                COUNT(*) AS order_count,
                COALESCE(SUM(o.total_amount_uah), 0) AS sales_month,
                COALESCE(SUM(o.paid_amount_uah), 0) AS paid_by_order,
                COALESCE(SUM(o.unpaid_amount_uah), 0) AS unpaid_by_order
            FROM db_orders o
            {$expr['joins']}
            WHERE o.order_month = :month
              AND {$notCanceled}
              {$searchSql}
            GROUP BY client_key, client_name, client_subname
        ");
        $stmt->execute(array_merge(['month' => $month], $searchParams));
        foreach ($stmt->fetchAll() as $row) {
            $key = (string) ($row['client_key'] ?? 'unknown');
            $rowsByKey[$key] = client_balances_blank_row($key, (string) $row['client_name'], (string) $row['client_subname']);
            $rowsByKey[$key]['manager_name'] = (string) ($row['manager_name'] ?? '');
            $rowsByKey[$key]['order_count'] = (int) ($row['order_count'] ?? 0);
            $rowsByKey[$key]['sales_month'] = (float) ($row['sales_month'] ?? 0);
            $rowsByKey[$key]['paid_by_order'] = (float) ($row['paid_by_order'] ?? 0);
            $rowsByKey[$key]['unpaid_by_order'] = (float) ($row['unpaid_by_order'] ?? 0);
        }

        $stmt = db()->prepare("
            SELECT
                {$expr['key']} AS client_key,
                {$expr['name']} AS client_name,
                {$expr['subname']} AS client_subname,
                COALESCE(NULLIF(MAX(o.manager_name), ''), 'Без менеджера') AS manager_name,
                COUNT(*) AS receivable_count,
                COALESCE(SUM(o.unpaid_amount_uah), 0) AS receivable_total,
                COALESCE(MAX(o.unpaid_amount_uah), 0) AS largest_receivable
            FROM db_orders o
            {$expr['joins']}
            WHERE o.unpaid_amount_uah > 0
              AND {$notCanceled}
              {$searchSql}
            GROUP BY client_key, client_name, client_subname
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

        $trendMonthPlaceholders = implode(',', array_map(static fn($idx) => ':trend_month_' . $idx, array_keys($months)));
        $trendParams = $searchParams;
        foreach ($months as $idx => $trendMonth) {
            $trendParams['trend_month_' . $idx] = $trendMonth;
        }
        $stmt = db()->prepare("
            SELECT
                {$expr['key']} AS client_key,
                {$expr['name']} AS client_name,
                {$expr['subname']} AS client_subname,
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
            GROUP BY client_key, client_name, client_subname, o.order_month
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
                    {$expr['name']} AS client_name,
                    {$expr['subname']} AS client_subname,
                    COALESCE(NULLIF(MAX(o.manager_name), ''), 'Без менеджера') AS manager_name,
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
                GROUP BY client_key, client_name, client_subname
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
    $receivableCompare = (float) $b['receivable_total'] <=> (float) $a['receivable_total'];
    if ($receivableCompare !== 0) {
        return $receivableCompare;
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
    <?php cockpit_page_header('CEO Money Cockpit', 'Баланси клієнтів', 'Компанії або покупці: продажі за місяць, гроші за місяць і поточний борг.', 'client_balances', $month); ?>

    <section class="kpi-grid">
        <div class="kpi-card"><span class="label">Факт за місяць</span><strong><?= e(finance_money($summary['sales_fact'])) ?></strong></div>
        <div class="kpi-card"><span class="label">Гроші прийшли</span><strong><?= e(finance_money($summary['cash_received'])) ?></strong><small>за датою платежу</small></div>
        <div class="kpi-card"><span class="label">Нам повинні</span><strong><?= e(finance_money($summary['receivables_total'])) ?></strong><small>усі місяці</small></div>
        <div class="kpi-card"><span class="label">Активні у місяці</span><strong><?= e((string) $activeClientCount) ?></strong><small><?= $group === 'buyer' ? 'покупців' : 'компаній' ?></small></div>
    </section>

    <section class="panel dashboard-section">
        <form class="client-balance-toolbar" method="get" action="<?= e(base_path('/client_balances.php')) ?>">
            <input type="hidden" name="group" value="<?= e($group) ?>">
            <label>
                <span>Місяць</span>
                <input type="month" name="month" value="<?= e($month) ?>">
            </label>
            <label class="client-balance-search">
                <span>Пошук</span>
                <input type="search" name="q" value="<?= e($search) ?>" placeholder="компанія, покупець, номер">
            </label>
            <button type="submit">Показати</button>
        </form>
        <div class="segmented-scroll client-balance-group-switch">
            <a class="<?= $group === 'company' ? 'active' : '' ?>" href="<?= e(base_path('/client_balances.php?' . http_build_query(['month' => $month, 'group' => 'company', 'q' => $search]))) ?>">Компанії</a>
            <a class="<?= $group === 'buyer' ? 'active' : '' ?>" href="<?= e(base_path('/client_balances.php?' . http_build_query(['month' => $month, 'group' => 'buyer', 'q' => $search]))) ?>">Покупці</a>
        </div>
    </section>

    <section class="panel dashboard-section">
        <div class="section-heading">
            <div>
                <p class="eyebrow">Баланс</p>
                <h2><?= $group === 'buyer' ? 'По покупцях' : 'По компаніях' ?></h2>
            </div>
            <span class="status-badge">продажі = order_month · гроші = payment_date</span>
        </div>
        <div class="table-scroll">
            <table class="data-table client-balance-table">
                <thead>
                <tr>
                    <th class="wrap"><?= $group === 'buyer' ? 'Покупець' : 'Компанія' ?></th>
                    <th>Менеджер</th>
                    <th class="num">Зам.</th>
                    <th class="num">Факт</th>
                    <th class="num">Оплачено</th>
                    <th class="num">Гроші</th>
                    <th class="num">Борг</th>
                    <th class="wrap">Місяці</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$rows): ?><tr><td colspan="8">Даних немає.</td></tr><?php endif; ?>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td class="client-cell">
                            <div class="client-stack">
                                <span class="client-stack-company"><?= e((string) $row['client_name']) ?></span>
                                <?php if ($row['client_subname'] !== '' && $row['client_subname'] !== $row['client_name']): ?>
                                    <span class="client-stack-contact"><?= e((string) $row['client_subname']) ?></span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td><?= e((string) ($row['manager_name'] ?: '—')) ?></td>
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
