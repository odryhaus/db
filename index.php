<?php

require_once __DIR__ . '/bootstrap.php';
require_login();
ensure_finance_tables();

$user = current_user();
$selectedMonth = (string) ($_GET['month'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $selectedMonth)) {
    $selectedMonth = date('Y-m');
}

$debtManager = trim((string) ($_GET['debt_manager'] ?? ''));
$debtPage = max(1, (int) ($_GET['debt_page'] ?? 1));
$debtPerPage = 25;
$debtOffset = ($debtPage - 1) * $debtPerPage;

$monthDate = DateTimeImmutable::createFromFormat('!Y-m', $selectedMonth) ?: new DateTimeImmutable('first day of this month');
$monthStart = $monthDate->modify('first day of this month')->setTime(0, 0, 0);
$monthEnd = $monthDate->modify('last day of this month')->setTime(23, 59, 59);
$today = (new DateTimeImmutable('today'))->setTime(0, 0, 0);
$monthLabel = $monthDate->format('F Y');

$monthlyTarget = 4000000;
$salesFact = 0;
$paid = 0;
$monthlyUnpaid = 0;
$receivablesTotal = 0;
$receivablesCount = 0;
$largestReceivable = 0;
$filteredReceivablesTotal = 0;
$filteredReceivablesCount = 0;
$remaining = $monthlyTarget;
$progress = 0;
$orderCount = 0;
$dailyRequiredLabel = 'місяць закрито';
$lastSyncAt = null;
$monthlyUnpaidOrders = [];
$receivableOrders = [];
$managerSummary = [];
$receivablesByManager = [];
$aging = [];
$clientDebt = [];
$expectedProgress = 0;
$paidShare = 0;
$unpaidShare = 0;
$operationalDueThisMonth = 0;
$strategicDebtTotal = 0;
$operationalDueThisWeek = 0;
$overdueTotal = 0;
$overdueCount = 0;
$dashboardError = '';
$totalDebtPages = 1;

function money_uah($amount): string
{
    return number_format((float) $amount, 0, '.', ' ') . ' UAH';
}

function dashboard_client_name(array $order): string
{
    $rawClient = dashboard_raw_client_name($order);
    $cachedClient = dashboard_cached_client_name($order);

    return (string) (
        ($order['company_name'] ?? '')
        ?: ($order['local_company_name'] ?? '')
        ?: ($order['buyer_name'] ?? '')
        ?: ($order['local_contact_name'] ?? '')
        ?: ($order['client_name'] ?? '')
        ?: $rawClient
        ?: $cachedClient
        ?: '—'
    );
}

function dashboard_raw_id(array $order, array $paths): int
{
    $raw = json_decode((string) ($order['raw_json'] ?? ''), true);
    if (!is_array($raw)) {
        return 0;
    }

    foreach ($paths as $path) {
        $current = $raw;
        foreach (explode('.', $path) as $part) {
            if (!is_array($current) || !array_key_exists($part, $current)) {
                $current = null;
                break;
            }
            $current = $current[$part];
        }
        if (is_numeric($current) && (int) $current > 0) {
            return (int) $current;
        }
    }

    return 0;
}

function dashboard_cached_client_name(array $order): string
{
    $company = dashboard_cached_company($order);
    if ($company !== '') {
        return $company;
    }

    return dashboard_cached_contact_name($order);
}

function dashboard_cached_company(array $order): string
{
    static $companies = [];

    $companyId = (int) (($order['company_id'] ?? 0) ?: dashboard_raw_id($order, ['company.id', 'company_id', 'buyer.company.id', 'buyer.company_id']));
    if ($companyId > 0) {
        if (!array_key_exists($companyId, $companies)) {
            $stmt = db()->prepare("
                SELECT display_name, keycrm_title, keycrm_name, title, name
                FROM db_client_companies
                WHERE keycrm_company_id = :keycrm_company_id
                ORDER BY id DESC
                LIMIT 1
            ");
            $stmt->execute(['keycrm_company_id' => $companyId]);
            $company = $stmt->fetch() ?: [];
            $companies[$companyId] = (string) (($company['keycrm_name'] ?? '') ?: (($company['name'] ?? '') ?: (($company['display_name'] ?? '') ?: (($company['keycrm_title'] ?? '') ?: ($company['title'] ?? '')))));
        }
        if ($companies[$companyId] !== '') {
            return $companies[$companyId];
        }
    }

    return '';
}

function dashboard_cached_legal_name(array $order): string
{
    static $companies = [];

    $companyId = (int) (($order['company_id'] ?? 0) ?: dashboard_raw_id($order, ['company.id', 'company_id', 'buyer.company.id', 'buyer.company_id']));
    if ($companyId <= 0) {
        return '';
    }
    if (!array_key_exists($companyId, $companies)) {
        $stmt = db()->prepare("
            SELECT display_name, keycrm_title, keycrm_name, title, name
            FROM db_client_companies
            WHERE keycrm_company_id = :keycrm_company_id
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute(['keycrm_company_id' => $companyId]);
        $company = $stmt->fetch() ?: [];
        $companies[$companyId] = (string) (($company['keycrm_title'] ?? '') ?: (($company['title'] ?? '') ?: (($company['display_name'] ?? '') ?: (($company['keycrm_name'] ?? '') ?: ($company['name'] ?? '')))));
    }

    return $companies[$companyId];
}

function dashboard_cached_contact_name(array $order): string
{
    static $contacts = [];

    $buyerId = (int) (($order['buyer_id'] ?? 0) ?: dashboard_raw_id($order, ['buyer.id', 'buyer_id']));
    if ($buyerId > 0) {
        if (!array_key_exists($buyerId, $contacts)) {
            $stmt = db()->prepare("
                SELECT full_name, email, phone
                FROM db_client_contacts
                WHERE keycrm_buyer_id = :keycrm_buyer_id
                ORDER BY id DESC
                LIMIT 1
            ");
            $stmt->execute(['keycrm_buyer_id' => $buyerId]);
            $contact = $stmt->fetch() ?: [];
            $contacts[$buyerId] = (string) (($contact['full_name'] ?? '') ?: (($contact['email'] ?? '') ?: ($contact['phone'] ?? '')));
        }
        if ($contacts[$buyerId] !== '') {
            return $contacts[$buyerId];
        }
    }

    return '';
}

function dashboard_raw_company_name(array $order): string
{
    $raw = json_decode((string) ($order['raw_json'] ?? ''), true);
    if (!is_array($raw)) {
        return '';
    }

    $buyer = is_array($raw['buyer'] ?? null) ? $raw['buyer'] : [];
    $buyerCompany = is_array($buyer['company'] ?? null) ? $buyer['company'] : [];
    $company = is_array($raw['company'] ?? null) ? $raw['company'] : $buyerCompany;

    foreach ([
        $company['name'] ?? null,
        $buyerCompany['name'] ?? null,
    ] as $value) {
        $value = trim((string) $value);
        if ($value !== '') {
            return $value;
        }
    }

    return '';
}

function dashboard_raw_legal_name(array $order): string
{
    $raw = json_decode((string) ($order['raw_json'] ?? ''), true);
    if (!is_array($raw)) {
        return '';
    }

    $buyer = is_array($raw['buyer'] ?? null) ? $raw['buyer'] : [];
    $buyerCompany = is_array($buyer['company'] ?? null) ? $buyer['company'] : [];
    $company = is_array($raw['company'] ?? null) ? $raw['company'] : $buyerCompany;

    foreach ([
        $company['title'] ?? null,
        $company['full_name'] ?? null,
        $buyerCompany['title'] ?? null,
        $buyerCompany['full_name'] ?? null,
    ] as $value) {
        $value = trim((string) $value);
        if ($value !== '') {
            return $value;
        }
    }

    return '';
}

function dashboard_raw_client_name(array $order): string
{
    $company = dashboard_raw_company_name($order) ?: dashboard_raw_legal_name($order);
    if ($company !== '') {
        return $company;
    }

    $raw = json_decode((string) ($order['raw_json'] ?? ''), true);
    if (!is_array($raw)) {
        return '';
    }

    $buyer = is_array($raw['buyer'] ?? null) ? $raw['buyer'] : [];
    $client = is_array($raw['client'] ?? null) ? $raw['client'] : [];

    foreach ([
        $buyer['full_name'] ?? null,
        $buyer['name'] ?? null,
        $client['full_name'] ?? null,
        $client['name'] ?? null,
    ] as $value) {
        $value = trim((string) $value);
        if ($value !== '') {
            return $value;
        }
    }

    return '';
}

function dashboard_raw_contact_value(array $order, string $field): string
{
    static $contacts = [];

    $raw = json_decode((string) ($order['raw_json'] ?? ''), true);
    $buyer = is_array($raw['buyer'] ?? null) ? $raw['buyer'] : [];
    $value = trim((string) ($buyer[$field] ?? ''));
    if ($value !== '') {
        return $value;
    }

    $buyerId = (int) (($order['buyer_id'] ?? 0) ?: dashboard_raw_id($order, ['buyer.id', 'buyer_id']));
    if ($buyerId <= 0) {
        return '';
    }
    if (!array_key_exists($buyerId, $contacts)) {
        $stmt = db()->prepare('SELECT email, phone FROM db_client_contacts WHERE keycrm_buyer_id = :keycrm_buyer_id ORDER BY id DESC LIMIT 1');
        $stmt->execute(['keycrm_buyer_id' => $buyerId]);
        $contacts[$buyerId] = $stmt->fetch() ?: [];
    }

    return trim((string) ($contacts[$buyerId][$field] ?? ''));
}

function dashboard_client_stack(array $order): string
{
    $company = trim((string) (
        ($order['company_name'] ?? '')
        ?: ($order['local_company_name'] ?? '')
        ?: dashboard_raw_company_name($order)
        ?: dashboard_cached_company($order)
        ?: dashboard_client_name($order)
    ));
    $legal = trim((string) (
        ($order['local_legal_name'] ?? '')
        ?: dashboard_raw_legal_name($order)
        ?: dashboard_cached_legal_name($order)
    ));
    $contactName = trim((string) (
        ($order['buyer_name'] ?? '')
        ?: ($order['local_contact_name'] ?? '')
        ?: dashboard_cached_contact_name($order)
    ));
    $contactEmail = trim((string) (
        ($order['buyer_email'] ?? '')
        ?: ($order['local_contact_email'] ?? '')
        ?: dashboard_raw_contact_value($order, 'email')
    ));
    $contactPhone = trim((string) (
        ($order['buyer_phone'] ?? '')
        ?: ($order['local_contact_phone'] ?? '')
        ?: dashboard_raw_contact_value($order, 'phone')
    ));
    $contactParts = array_values(array_filter([$contactName, $contactEmail, $contactPhone], static fn($value) => $value !== ''));

    $html = '<div class="client-stack">';
    $html .= '<strong class="client-stack-company">' . e($company !== '' ? $company : 'Без клієнта') . '</strong>';
    if ($legal !== '' && $legal !== $company) {
        $html .= '<span class="client-stack-legal">' . e($legal) . '</span>';
    }
    if ($contactParts) {
        $html .= '<span class="client-stack-contact">' . e(implode(' / ', $contactParts)) . '</span>';
    }
    $html .= '</div>';

    return $html;
}

function dashboard_url(array $params = []): string
{
    return base_path('/index.php') . '?' . http_build_query($params);
}

function dashboard_manager_key($managerName): string
{
    $name = trim((string) $managerName);
    return $name !== '' && $name !== 'No manager' ? $name : 'Без менеджера';
}

function dashboard_payment_badge(array $order): string
{
    $total = (float) ($order['total_amount_uah'] ?? 0);
    $unpaid = (float) ($order['unpaid_amount_uah'] ?? 0);
    $paid = $total - $unpaid;

    if ($unpaid <= 0) {
        return '<span class="status-badge status-badge--success">Оплачено</span>';
    }
    if ($paid > 0) {
        return '<span class="status-badge status-badge--warning">Частково</span>';
    }
    return '<span class="status-badge status-badge--danger">Не оплачено</span>';
}

function dashboard_progress_mini(float $progress): string
{
    $width = max(0, min(100, $progress));
    $cls = $progress >= 100 ? ' over' : '';
    return '<div class="progress-mini' . $cls . '"><span class="progress-track"><span style="width:' . e((string) $width) . '%"></span></span><span class="progress-pct">' . e((string) round($progress)) . '%</span></div>';
}

function dashboard_pace_badge(float $actual, float $expected): string
{
    $delta = (int) round($actual - $expected);

    if ($delta >= -3) {
        $class = 'status-badge--success';
        $label = $delta > 3 ? 'Випереджає на ' . $delta . ' п.п.' : 'У графіку';
    } elseif ($delta >= -15) {
        $class = 'status-badge--warning';
        $label = 'Відстає на ' . abs($delta) . ' п.п.';
    } else {
        $class = 'status-badge--danger';
        $label = 'Сильно відстає на ' . abs($delta) . ' п.п.';
    }

    return '<span class="status-badge ' . $class . '">' . e($label) . '</span>';
}

function dashboard_age_days(?string $orderedAt): ?int
{
    if (!$orderedAt) {
        return null;
    }
    $time = strtotime($orderedAt);
    if (!$time) {
        return null;
    }

    return max(0, (int) floor((time() - $time) / 86400));
}

function dashboard_age_badge(?string $orderedAt): string
{
    $days = dashboard_age_days($orderedAt);
    if ($days === null) {
        return '<span class="status-badge status-badge--muted">—</span>';
    }

    if ($days <= 7) {
        $class = 'status-badge--muted';
    } elseif ($days <= 30) {
        $class = 'status-badge--warning';
    } else {
        $class = 'status-badge--danger';
    }

    return '<span class="status-badge ' . $class . '">' . e((string) $days) . ' дн.</span>';
}

function render_client_statement(int $clientKey, string $notCanceledSql): void
{
    $stmt = db()->prepare("
        SELECT
            order_number, ordered_at, total_amount_uah, paid_amount_uah, unpaid_amount_uah,
            company_name, buyer_name, client_name, buyer_phone, buyer_email
        FROM db_orders
        WHERE COALESCE(company_id, buyer_id, client_id, 0) = :client_key
          AND unpaid_amount_uah > 0
          AND {$notCanceledSql}
        ORDER BY ordered_at ASC
    ");
    $stmt->execute(['client_key' => $clientKey]);
    $orders = $stmt->fetchAll();

    if (!$orders) {
        http_response_code(404);
        echo 'Клієнта з таким боргом не знайдено.';
        return;
    }

    $clientName = dashboard_client_name($orders[0]);
    $phone = (string) ($orders[0]['buyer_phone'] ?? '');
    $email = (string) ($orders[0]['buyer_email'] ?? '');
    $total = 0.0;
    $textLines = ['.BRAND — зведення по оплаті', 'Клієнт: ' . $clientName, 'Дата: ' . date('d.m.Y'), ''];

    foreach ($orders as $order) {
        $total += (float) $order['unpaid_amount_uah'];
        $textLines[] = sprintf(
            '№ %s від %s — борг %s',
            (string) ($order['order_number'] ?: '—'),
            $order['ordered_at'] ? date('d.m.Y', (int) strtotime((string) $order['ordered_at'])) : '—',
            money_uah($order['unpaid_amount_uah'] ?? 0)
        );
    }
    $textLines[] = '';
    $textLines[] = 'Разом до сплати: ' . money_uah($total);
    $statementText = implode("\n", $textLines);
    ?>
<!doctype html>
<html lang="uk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Зведення — <?= e($clientName) ?></title>
    <link rel="stylesheet" href="<?= e(asset_path('/assets/app.css')) ?>">
</head>
<body>
    <main class="page statement-page">
        <div class="toolbar no-print">
            <a href="<?= e(base_path('/index.php')) ?>" class="button-secondary small-button">Назад до дашборду</a>
            <button type="button" class="small-button" onclick="window.print()">Друк</button>
            <button type="button" class="button-secondary small-button" id="copy-statement-btn">Копіювати текст</button>
        </div>
        <section class="panel">
            <p class="eyebrow">.BRAND — зведення по оплаті</p>
            <h1><?= e($clientName) ?></h1>
            <p class="muted">
                Дата: <?= e(date('d.m.Y')) ?>
                <?php if ($phone !== ''): ?> · Тел.: <?= e($phone) ?><?php endif; ?>
                <?php if ($email !== ''): ?> · Email: <?= e($email) ?><?php endif; ?>
            </p>
            <div class="table-wrap">
                <table class="compact-table">
                    <thead>
                        <tr>
                            <th>№</th>
                            <th>Дата</th>
                            <th class="num">Сума</th>
                            <th class="num">Оплачено</th>
                            <th class="num">Борг</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orders as $order): ?>
                            <tr>
                                <td><?= e((string) ($order['order_number'] ?: '—')) ?></td>
                                <td><?= e($order['ordered_at'] ? date('d.m.Y', (int) strtotime((string) $order['ordered_at'])) : '—') ?></td>
                                <td class="num"><?= e(money_uah($order['total_amount_uah'] ?? 0)) ?></td>
                                <td class="num"><?= e(money_uah($order['paid_amount_uah'] ?? 0)) ?></td>
                                <td class="num"><strong><?= e(money_uah($order['unpaid_amount_uah'] ?? 0)) ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="statement-total">Разом до сплати: <strong><?= e(money_uah($total)) ?></strong></p>
        </section>
    </main>
    <script>
        document.getElementById('copy-statement-btn').addEventListener('click', function () {
            var button = this;
            var text = <?= json_encode($statementText, JSON_UNESCAPED_UNICODE) ?>;
            navigator.clipboard.writeText(text).then(function () {
                var original = button.textContent;
                button.textContent = 'Скопійовано!';
                setTimeout(function () { button.textContent = original; }, 1500);
            });
        });
    </script>
</body>
</html>
    <?php
}

$notCanceledSql = "
    LOWER(COALESCE(status_name, '')) NOT LIKE '%cancel%'
    AND LOWER(COALESCE(status_name, '')) NOT LIKE '%deleted%'
    AND LOWER(COALESCE(status_name, '')) NOT LIKE '%скас%'
    AND LOWER(COALESCE(payment_status, '')) NOT LIKE '%cancel%'
    AND LOWER(COALESCE(payment_status, '')) NOT LIKE '%deleted%'
    AND LOWER(COALESCE(payment_status, '')) NOT LIKE '%скас%'
";

if (isset($_GET['client_statement'])) {
    render_client_statement((int) $_GET['client_statement'], $notCanceledSql);
    exit;
}

try {
    $companyTarget = active_company_target(db(), $selectedMonth);
    $monthlyTarget = (float) $companyTarget['amount_uah'];

    $metrics = db()->prepare("
        SELECT
            COUNT(*) AS order_count,
            COALESCE(SUM(total_amount_uah), 0) AS sales_fact,
            COALESCE(SUM(paid_amount_uah), 0) AS paid,
            COALESCE(SUM(unpaid_amount_uah), 0) AS unpaid
        FROM db_orders
        WHERE order_month = :month
          AND {$notCanceledSql}
    ");
    $metrics->execute(['month' => $selectedMonth]);
    $row = $metrics->fetch() ?: [];

    $orderCount = (int) ($row['order_count'] ?? 0);
    $salesFact = (float) ($row['sales_fact'] ?? 0);
    $paid = (float) ($row['paid'] ?? 0);
    $monthlyUnpaid = (float) ($row['unpaid'] ?? 0);
    $remaining = max($monthlyTarget - $salesFact, 0);
    $progress = $monthlyTarget > 0 ? min(100, round(($salesFact / $monthlyTarget) * 100, 1)) : 0;

    if ($monthEnd >= $today && $remaining > 0) {
        $daysFrom = $monthStart > $today ? $monthStart : $today;
        $remainingDays = max(1, (int) $daysFrom->diff($monthEnd)->format('%a') + 1);
        $dailyRequiredLabel = money_uah($remaining / $remainingDays) . ' / день';
    } elseif ($remaining <= 0) {
        $dailyRequiredLabel = 'план виконано';
    }

    $daysInMonth = (int) $monthEnd->format('j');
    if ($monthEnd < $today) {
        $elapsedDays = $daysInMonth;
    } elseif ($monthStart > $today) {
        $elapsedDays = 0;
    } else {
        $elapsedDays = (int) $monthStart->diff($today)->format('%a') + 1;
    }
    $expectedProgress = $daysInMonth > 0 ? min(100, round(($elapsedDays / $daysInMonth) * 100, 1)) : 0;

    $paidShare = $salesFact > 0 ? min(100, round(($paid / $salesFact) * 100, 1)) : 0;
    $unpaidShare = $salesFact > 0 ? max(0, 100 - $paidShare) : 0;

    $lastSyncStmt = db()->query("SELECT finished_at FROM db_sync_runs WHERE status = 'success' ORDER BY finished_at DESC LIMIT 1");
    $lastSyncAt = $lastSyncStmt->fetchColumn() ?: null;

    $receivablesStmt = db()->query("
        SELECT
            COALESCE(SUM(unpaid_amount_uah), 0) AS total_unpaid,
            COUNT(*) AS unpaid_count,
            COALESCE(MAX(unpaid_amount_uah), 0) AS largest_unpaid
        FROM db_orders
        WHERE unpaid_amount_uah > 0
          AND {$notCanceledSql}
    ");
    $receivables = $receivablesStmt->fetch() ?: [];
    $receivablesTotal = (float) ($receivables['total_unpaid'] ?? 0);
    $receivablesCount = (int) ($receivables['unpaid_count'] ?? 0);
    $largestReceivable = (float) ($receivables['largest_unpaid'] ?? 0);

    $receivablesByManagerStmt = db()->query("
        SELECT
            COALESCE(NULLIF(manager_name, ''), 'No manager') AS manager_name,
            COALESCE(SUM(unpaid_amount_uah), 0) AS total_unpaid,
            COUNT(*) AS unpaid_count,
            COALESCE(MAX(unpaid_amount_uah), 0) AS largest_unpaid
        FROM db_orders
        WHERE unpaid_amount_uah > 0
          AND {$notCanceledSql}
        GROUP BY COALESCE(NULLIF(manager_name, ''), 'No manager')
        ORDER BY total_unpaid DESC
    ");
    $receivablesByManager = $receivablesByManagerStmt->fetchAll();

    $agingStmt = db()->query("
        SELECT
            COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), ordered_at) <= 7 THEN unpaid_amount_uah ELSE 0 END), 0) AS bucket_fresh,
            COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), ordered_at) BETWEEN 8 AND 30 THEN unpaid_amount_uah ELSE 0 END), 0) AS bucket_mid,
            COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), ordered_at) > 30 THEN unpaid_amount_uah ELSE 0 END), 0) AS bucket_old,
            COUNT(CASE WHEN DATEDIFF(CURDATE(), ordered_at) <= 7 THEN 1 END) AS count_fresh,
            COUNT(CASE WHEN DATEDIFF(CURDATE(), ordered_at) BETWEEN 8 AND 30 THEN 1 END) AS count_mid,
            COUNT(CASE WHEN DATEDIFF(CURDATE(), ordered_at) > 30 THEN 1 END) AS count_old
        FROM db_orders
        WHERE unpaid_amount_uah > 0
          AND {$notCanceledSql}
    ");
    $aging = $agingStmt->fetch() ?: [];

    $clientDebtRowsStmt = db()->query("
        SELECT
            o.company_id,
            o.buyer_id,
            o.client_id,
            o.ordered_at,
            o.unpaid_amount_uah,
            o.company_name,
            o.buyer_name,
            o.client_name,
            o.buyer_phone,
            o.buyer_email,
            o.manager_name,
            o.raw_json,
            COALESCE(NULLIF(cc.keycrm_name, ''), NULLIF(cc.name, ''), NULLIF(cc.display_name, '')) AS local_company_name,
            COALESCE(NULLIF(cc.keycrm_title, ''), NULLIF(cc.title, ''), NULLIF(cc.display_name, ''), NULLIF(cc.keycrm_name, ''), NULLIF(cc.name, '')) AS local_legal_name,
            ct.full_name AS local_contact_name,
            ct.phone AS local_contact_phone,
            ct.email AS local_contact_email
        FROM db_orders o
        LEFT JOIN db_client_companies cc ON cc.keycrm_company_id = o.company_id
        LEFT JOIN db_client_contacts ct ON ct.keycrm_buyer_id = o.buyer_id
        WHERE o.unpaid_amount_uah > 0
          AND {$notCanceledSql}
        ORDER BY o.unpaid_amount_uah DESC, o.ordered_at ASC
        LIMIT 1000
    ");
    $clientDebtMap = [];
    foreach ($clientDebtRowsStmt->fetchAll() as $debtRow) {
        $name = dashboard_client_name($debtRow);
        $companyDisplay = (string) (($debtRow['company_name'] ?? '') ?: ($debtRow['local_company_name'] ?? '') ?: dashboard_raw_company_name($debtRow) ?: dashboard_cached_company($debtRow));
        $legalDisplay = (string) (($debtRow['local_legal_name'] ?? '') ?: dashboard_raw_legal_name($debtRow) ?: dashboard_cached_legal_name($debtRow) ?: $companyDisplay);
        $contactName = (string) (($debtRow['buyer_name'] ?? '') ?: ($debtRow['local_contact_name'] ?? '') ?: dashboard_cached_contact_name($debtRow));
        $contactPhone = (string) (($debtRow['buyer_phone'] ?? '') ?: ($debtRow['local_contact_phone'] ?? '') ?: dashboard_raw_contact_value($debtRow, 'phone'));
        $contactEmail = (string) (($debtRow['buyer_email'] ?? '') ?: ($debtRow['local_contact_email'] ?? '') ?: dashboard_raw_contact_value($debtRow, 'email'));
        $keyId = (int) (($debtRow['company_id'] ?? 0) ?: ($debtRow['buyer_id'] ?? 0) ?: ($debtRow['client_id'] ?? 0));
        $key = $keyId > 0 ? 'id:' . $keyId : 'name:' . strtolower($name);
        if (!isset($clientDebtMap[$key])) {
            $clientDebtMap[$key] = [
                'client_key' => $keyId,
                'client_name' => $name !== '—' ? $name : 'Без клієнта',
                'company_display' => $companyDisplay,
                'legal_display' => $legalDisplay,
                'contact_display' => $contactName,
                'manager_name' => dashboard_manager_key($debtRow['manager_name'] ?? ''),
                'total_unpaid' => 0,
                'unpaid_count' => 0,
                'oldest_ordered_at' => $debtRow['ordered_at'] ?? null,
                'contact_phone' => $contactPhone,
                'contact_email' => $contactEmail,
            ];
        }
        if ($clientDebtMap[$key]['company_display'] === '' && $companyDisplay !== '') {
            $clientDebtMap[$key]['company_display'] = $companyDisplay;
        }
        if ($clientDebtMap[$key]['legal_display'] === '' && $legalDisplay !== '') {
            $clientDebtMap[$key]['legal_display'] = $legalDisplay;
        }
        if ($clientDebtMap[$key]['contact_display'] === '' && $contactName !== '') {
            $clientDebtMap[$key]['contact_display'] = $contactName;
        }
        if ($clientDebtMap[$key]['contact_phone'] === '' && $contactPhone !== '') {
            $clientDebtMap[$key]['contact_phone'] = $contactPhone;
        }
        if ($clientDebtMap[$key]['contact_email'] === '' && $contactEmail !== '') {
            $clientDebtMap[$key]['contact_email'] = $contactEmail;
        }
        if ($clientDebtMap[$key]['manager_name'] === 'Без менеджера' && dashboard_manager_key($debtRow['manager_name'] ?? '') !== 'Без менеджера') {
            $clientDebtMap[$key]['manager_name'] = dashboard_manager_key($debtRow['manager_name'] ?? '');
        }
        $clientDebtMap[$key]['total_unpaid'] += (float) ($debtRow['unpaid_amount_uah'] ?? 0);
        $clientDebtMap[$key]['unpaid_count']++;
        if (!empty($debtRow['ordered_at']) && (empty($clientDebtMap[$key]['oldest_ordered_at']) || strtotime((string) $debtRow['ordered_at']) < strtotime((string) $clientDebtMap[$key]['oldest_ordered_at']))) {
            $clientDebtMap[$key]['oldest_ordered_at'] = $debtRow['ordered_at'];
        }
    }
    $clientDebt = array_values($clientDebtMap);
    usort($clientDebt, static fn($a, $b) => ($b['total_unpaid'] <=> $a['total_unpaid']));
    $clientDebt = array_slice($clientDebt, 0, 50);

    $debtWhere = "unpaid_amount_uah > 0 AND {$notCanceledSql}";
    $debtParams = [];
    if ($debtManager !== '') {
        $debtWhere .= " AND COALESCE(NULLIF(manager_name, ''), 'No manager') = :debt_manager";
        $debtParams['debt_manager'] = $debtManager;
    }

    $filteredTotalsStmt = db()->prepare("
        SELECT
            COALESCE(SUM(unpaid_amount_uah), 0) AS total_unpaid,
            COUNT(*) AS unpaid_count
        FROM db_orders
        WHERE {$debtWhere}
    ");
    $filteredTotalsStmt->execute($debtParams);
    $filteredTotals = $filteredTotalsStmt->fetch() ?: [];
    $filteredReceivablesTotal = (float) ($filteredTotals['total_unpaid'] ?? 0);
    $filteredReceivablesCount = (int) ($filteredTotals['unpaid_count'] ?? 0);
    $totalDebtPages = max(1, (int) ceil($filteredReceivablesCount / $debtPerPage));
    if ($debtPage > $totalDebtPages) {
        $debtPage = $totalDebtPages;
        $debtOffset = ($debtPage - 1) * $debtPerPage;
    }

    $debtStmt = db()->prepare("
        SELECT
            o.order_number,
            o.ordered_at,
            o.company_id,
            o.buyer_id,
            o.client_id,
            o.client_name,
            o.raw_json,
            o.buyer_name,
            o.buyer_phone,
            o.buyer_email,
            o.company_name,
            COALESCE(NULLIF(cc.keycrm_name, ''), NULLIF(cc.name, ''), NULLIF(cc.display_name, '')) AS local_company_name,
            COALESCE(NULLIF(cc.keycrm_title, ''), NULLIF(cc.title, ''), NULLIF(cc.display_name, ''), NULLIF(cc.keycrm_name, ''), NULLIF(cc.name, '')) AS local_legal_name,
            ct.full_name AS local_contact_name,
            ct.phone AS local_contact_phone,
            ct.email AS local_contact_email,
            o.manager_name,
            o.total_amount_uah,
            o.paid_amount_uah,
            o.unpaid_amount_uah,
            o.payment_status,
            o.status_name
        FROM db_orders o
        LEFT JOIN db_client_companies cc ON cc.keycrm_company_id = o.company_id
        LEFT JOIN db_client_contacts ct ON ct.keycrm_buyer_id = o.buyer_id
        WHERE {$debtWhere}
        ORDER BY o.unpaid_amount_uah DESC, o.ordered_at DESC
        LIMIT :limit OFFSET :offset
    ");
    foreach ($debtParams as $key => $value) {
        $debtStmt->bindValue(':' . $key, $value);
    }
    $debtStmt->bindValue(':limit', $debtPerPage, PDO::PARAM_INT);
    $debtStmt->bindValue(':offset', $debtOffset, PDO::PARAM_INT);
    $debtStmt->execute();
    $receivableOrders = $debtStmt->fetchAll();

    $monthlyUnpaidStmt = db()->prepare("
        SELECT
            o.order_number,
            o.company_id,
            o.buyer_id,
            o.client_id,
            o.client_name,
            o.raw_json,
            o.buyer_name,
            o.buyer_phone,
            o.buyer_email,
            o.company_name,
            COALESCE(NULLIF(cc.keycrm_name, ''), NULLIF(cc.name, ''), NULLIF(cc.display_name, '')) AS local_company_name,
            COALESCE(NULLIF(cc.keycrm_title, ''), NULLIF(cc.title, ''), NULLIF(cc.display_name, ''), NULLIF(cc.keycrm_name, ''), NULLIF(cc.name, '')) AS local_legal_name,
            ct.full_name AS local_contact_name,
            ct.phone AS local_contact_phone,
            ct.email AS local_contact_email,
            o.manager_name,
            o.unpaid_amount_uah,
            o.payment_status,
            o.status_name
        FROM db_orders o
        LEFT JOIN db_client_companies cc ON cc.keycrm_company_id = o.company_id
        LEFT JOIN db_client_contacts ct ON ct.keycrm_buyer_id = o.buyer_id
        WHERE o.order_month = :month
          AND o.unpaid_amount_uah > 0
          AND {$notCanceledSql}
        ORDER BY o.unpaid_amount_uah DESC, o.ordered_at DESC
        LIMIT 10
    ");
    $monthlyUnpaidStmt->execute(['month' => $selectedMonth]);
    $monthlyUnpaidOrders = $monthlyUnpaidStmt->fetchAll();

    $managerStmt = db()->prepare("
        SELECT
            COALESCE(NULLIF(manager_name, ''), 'No manager') AS manager_name,
            COUNT(*) AS order_count,
            COALESCE(SUM(total_amount_uah), 0) AS sales_fact,
            COALESCE(SUM(paid_amount_uah), 0) AS paid,
            COALESCE(SUM(unpaid_amount_uah), 0) AS unpaid
        FROM db_orders
        WHERE order_month = :month
          AND {$notCanceledSql}
        GROUP BY COALESCE(NULLIF(manager_name, ''), 'No manager')
        ORDER BY sales_fact DESC
    ");
    $managerStmt->execute(['month' => $selectedMonth]);
    $managerSummary = $managerStmt->fetchAll();

    $managerNames = array_map(static function (array $manager): string {
        return (string) $manager['manager_name'];
    }, $managerSummary);
    $managerTargets = active_manager_targets(db(), $selectedMonth, $managerNames);

    foreach ($managerSummary as &$manager) {
        $targetData = $managerTargets[(string) $manager['manager_name']] ?? ['amount_uah' => 0, 'is_fallback' => true];
        $target = (float) ($targetData['amount_uah'] ?? 0);
        $fact = (float) ($manager['sales_fact'] ?? 0);
        $manager['target_amount_uah'] = $target;
        $manager['has_target'] = $target > 0 && empty($targetData['is_fallback']);
        $manager['target_effective_from'] = $targetData['effective_from'] ?? null;
        $manager['remaining_to_target'] = $manager['has_target'] ? max($target - $fact, 0) : null;
        $manager['progress'] = $manager['has_target'] ? min(100, round(($fact / $target) * 100, 1)) : null;
    }
    unset($manager);

    $operationalStmt = db()->prepare("
        SELECT COALESCE(SUM(GREATEST(amount_uah - paid_amount_uah, 0)), 0)
        FROM db_expenses
        WHERE status = 'planned'
          AND is_strategic = 0
          AND expense_type <> 'strategic_debt'
          AND (
            (due_date BETWEEN :month_start AND :month_end)
            OR (
                expense_type = 'monthly_subscription'
                AND repeat_day IS NOT NULL
                AND (due_date IS NULL OR due_date <= :month_end_repeat)
                AND (repeat_until IS NULL OR repeat_until >= :month_start_repeat)
            )
          )
    ");
    $operationalStmt->execute([
        'month_start' => $monthStart->format('Y-m-d'),
        'month_end' => $monthEnd->format('Y-m-d'),
        'month_end_repeat' => $monthEnd->format('Y-m-d'),
        'month_start_repeat' => $monthStart->format('Y-m-d'),
    ]);
    $operationalDueThisMonth = (float) ($operationalStmt->fetchColumn() ?: 0);

    $strategicStmt = db()->query("
        SELECT COALESCE(SUM(GREATEST(COALESCE(total_debt_amount_uah, amount_uah) - paid_amount_uah, 0)), 0)
        FROM db_expenses
        WHERE status <> 'canceled'
          AND (is_strategic = 1 OR expense_type = 'strategic_debt')
    ");
    $strategicDebtTotal = (float) ($strategicStmt->fetchColumn() ?: 0);

    $weekStart = $today->modify('-' . ((int) $today->format('N') - 1) . ' days');
    $weekEnd = $weekStart->modify('+6 days');
    $weeklyStmt = db()->prepare("
        SELECT COALESCE(SUM(GREATEST(amount_uah - paid_amount_uah, 0)), 0)
        FROM db_expenses
        WHERE status = 'planned'
          AND is_strategic = 0
          AND expense_type <> 'strategic_debt'
          AND due_date BETWEEN :week_start AND :week_end
    ");
    $weeklyStmt->execute([
        'week_start' => $weekStart->format('Y-m-d'),
        'week_end' => $weekEnd->format('Y-m-d'),
    ]);
    $operationalDueThisWeek = (float) ($weeklyStmt->fetchColumn() ?: 0);

    $overdueStmt = db()->prepare("
        SELECT COALESCE(SUM(GREATEST(amount_uah - paid_amount_uah, 0)), 0) AS overdue_total, COUNT(*) AS overdue_count
        FROM db_expenses
        WHERE status = 'planned'
          AND is_strategic = 0
          AND expense_type <> 'strategic_debt'
          AND due_date < :today
    ");
    $overdueStmt->execute(['today' => $today->format('Y-m-d')]);
    $overdueRow = $overdueStmt->fetch() ?: [];
    $overdueTotal = (float) ($overdueRow['overdue_total'] ?? 0);
    $overdueCount = (int) ($overdueRow['overdue_count'] ?? 0);
} catch (Throwable $e) {
    $dashboardError = 'Dashboard data is not available yet. Run CEO sync after production config is ready.';
}
?>
<!doctype html>
<html lang="uk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>.BRAND DB — Дашборд</title>
    <link rel="stylesheet" href="<?= e(asset_path('/assets/app.css')) ?>">
</head>
<body>
    <main class="page dashboard-page">
        <header class="dashboard-header">
            <div class="brand-block">
                <p class="eyebrow">Money dashboard</p>
                <h1>.BRAND DB</h1>
                <p class="muted">Синхронізація: <?= e($lastSyncAt ?: 'ще не було') ?></p>
            </div>
            <form class="month-picker" method="get" action="<?= e(base_path('/index.php')) ?>">
                <label>
                    <span>Місяць</span>
                    <input type="month" name="month" value="<?= e($selectedMonth) ?>">
                </label>
                <?php if ($debtManager !== ''): ?>
                    <input type="hidden" name="debt_manager" value="<?= e($debtManager) ?>">
                <?php endif; ?>
                <button type="submit" class="small-button">Показати</button>
            </form>
            <div class="header-actions">
                <span class="sync-pill"><?= e(format_user_name($user)) ?> · <?= e((string) ($user['db_role'] ?? 'none')) ?></span>
                <nav class="nav">
                    <a class="active" href="<?= e(base_path('/index.php')) ?>">Дашборд</a>
                    <?php if (user_role() === 'ceo'): ?>
                        <a href="<?= e(base_path('/targets.php?month=' . urlencode($selectedMonth))) ?>">Плани</a>
                    <?php endif; ?>
                    <a href="<?= e(base_path('/payment_requisites.php')) ?>">Реквізити оплати</a>
                    <?php if (can_manage_expenses()): ?>
                        <a href="<?= e(base_path('/invoices.php')) ?>">Рахунки</a>
                        <a href="<?= e(base_path('/expenses.php?month=' . urlencode($selectedMonth))) ?>">Витрати</a>
                    <?php endif; ?>
                    <?php if (user_role() === 'ceo'): ?>
                        <a href="<?= e(base_path('/our_companies.php')) ?>">Наші компанії</a>
                        <a href="<?= e(base_path('/sync_orders.php')) ?>">Синхронізація</a>
                        <a href="<?= e(base_path('/clients_sync.php')) ?>">Клієнти Sync</a>
                        <a href="<?= e(base_path('/users.php')) ?>">Користувачі</a>
                    <?php endif; ?>
                    <a href="<?= e(base_path('/logout.php')) ?>">Вийти</a>
                </nav>
            </div>
        </header>

        <?php if ($dashboardError !== ''): ?>
            <div class="alert"><?= e($dashboardError) ?></div>
        <?php endif; ?>

        <section class="kpi-grid dashboard-kpis" aria-label="Ключові показники">
            <div class="kpi-card target">
                <span class="label">План</span>
                <strong><?= e(money_uah($monthlyTarget)) ?></strong>
            </div>
            <div class="kpi-card">
                <span class="label">Факт</span>
                <strong><?= e(money_uah($salesFact)) ?></strong>
                <small><?= e((string) $orderCount) ?> замовлень</small>
            </div>
            <div class="kpi-card">
                <span class="label">Оплачено</span>
                <strong><?= e(money_uah($paid)) ?></strong>
            </div>
            <div class="kpi-card warn">
                <span class="label">Не оплачено за місяць</span>
                <strong><?= e(money_uah($monthlyUnpaid)) ?></strong>
            </div>
            <div class="kpi-card danger">
                <span class="label">Нам повинні всього</span>
                <strong><?= e(money_uah($receivablesTotal)) ?></strong>
                <small><?= e((string) $receivablesCount) ?> замовлень</small>
            </div>
            <div class="kpi-card">
                <span class="label">Ми повинні цього місяця</span>
                <strong><?= e(money_uah($operationalDueThisMonth)) ?></strong>
                <small>операційні</small>
            </div>
        </section>

        <section class="panel dashboard-section">
            <div class="section-heading">
                <div>
                    <span class="label"><?= e($monthLabel) ?><?= !empty($companyTarget['effective_from']) ? ' · план з ' . e((string) $companyTarget['effective_from']) : ' · fallback' ?></span>
                    <h2>Прогрес плану</h2>
                </div>
                <strong><?= e((string) $progress) ?>%</strong>
            </div>
            <div class="progress-track" aria-label="Прогрес виконання плану">
                <span style="width: <?= e((string) $progress) ?>%"></span>
            </div>

            <div class="stack-bar" aria-label="Факт: оплачено проти не оплачено">
                <span class="stack-bar-paid" style="width: <?= e((string) $paidShare) ?>%"></span>
                <span class="stack-bar-unpaid" style="width: <?= e((string) $unpaidShare) ?>%"></span>
            </div>
            <div class="stack-bar-legend">
                <span><i class="dot dot-success"></i>Оплачено <?= e((string) $paidShare) ?>%</span>
                <span><i class="dot dot-warning"></i>Не оплачено <?= e((string) $unpaidShare) ?>%</span>
            </div>

            <dl class="plan-list">
                <div>
                    <dt>Залишилось до плану</dt>
                    <dd><?= e(money_uah($remaining)) ?></dd>
                </div>
                <div>
                    <dt>Потрібно в день</dt>
                    <dd><?= e($dailyRequiredLabel) ?></dd>
                </div>
                <div>
                    <dt>Темп</dt>
                    <dd><?= dashboard_pace_badge($progress, $expectedProgress) ?></dd>
                </div>
                <div>
                    <dt>Очікувано на сьогодні</dt>
                    <dd><?= e((string) $expectedProgress) ?>%</dd>
                </div>
            </dl>
        </section>

        <section class="panel table-panel dashboard-section">
            <div class="section-heading padded">
                <div>
                    <span class="label">Менеджери</span>
                    <h2>План продажів по менеджерах</h2>
                </div>
                <?php if (user_role() === 'ceo'): ?>
                    <a class="button-secondary small-button" href="<?= e(base_path('/targets.php?month=' . urlencode($selectedMonth))) ?>">Редагувати плани</a>
                <?php endif; ?>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Менеджер</th>
                            <th class="num">План</th>
                            <th class="num">Факт</th>
                            <th>%</th>
                            <th>Темп</th>
                            <th class="num">Оплачено</th>
                            <th class="num">Борг</th>
                            <th class="num">Залишилось</th>
                            <th class="num">Замовлень</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$managerSummary): ?>
                            <tr><td colspan="9">Немає даних по менеджерах за <?= e($monthLabel) ?>.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($managerSummary as $manager): ?>
                            <tr>
                                <td><?= e(dashboard_manager_key($manager['manager_name'] ?? '')) ?></td>
                                <td class="num">
                                    <?php if (!empty($manager['has_target'])): ?>
                                        <?= e(money_uah($manager['target_amount_uah'] ?? 0)) ?>
                                    <?php else: ?>
                                        <span class="status-badge status-badge--muted">не задано</span>
                                    <?php endif; ?>
                                </td>
                                <td class="num"><?= e(money_uah($manager['sales_fact'] ?? 0)) ?></td>
                                <td><?= !empty($manager['has_target']) ? dashboard_progress_mini((float) ($manager['progress'] ?? 0)) : '—' ?></td>
                                <td><?= !empty($manager['has_target']) ? dashboard_pace_badge((float) ($manager['progress'] ?? 0), $expectedProgress) : '—' ?></td>
                                <td class="num"><?= e(money_uah($manager['paid'] ?? 0)) ?></td>
                                <td class="num"><?= e(money_uah($manager['unpaid'] ?? 0)) ?></td>
                                <td class="num"><?= !empty($manager['has_target']) ? e(money_uah($manager['remaining_to_target'] ?? 0)) : '—' ?></td>
                                <td class="num"><?= e((string) $manager['order_count']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="panel table-panel dashboard-section">
            <div class="section-heading padded">
                <div>
                    <span class="label">Усі місяці · <?= e((string) $receivablesCount) ?> замовлень · найбільше <?= e(money_uah($largestReceivable)) ?></span>
                    <h2><?= $debtManager !== '' ? 'Борги менеджера: ' . e(dashboard_manager_key($debtManager)) : 'Нам повинні' ?> — <?= e(money_uah($debtManager !== '' ? $filteredReceivablesTotal : $receivablesTotal)) ?></h2>
                    <?php if ($debtManager !== ''): ?>
                        <p class="muted">Фільтр: <?= e(dashboard_manager_key($debtManager)) ?> · <?= e(money_uah($filteredReceivablesTotal)) ?> (<?= e((string) $filteredReceivablesCount) ?>)</p>
                    <?php endif; ?>
                </div>
                <div class="pagination">
                    <?php if ($debtManager !== ''): ?>
                        <a href="<?= e(dashboard_url(['month' => $selectedMonth])) ?>">Показати всі</a>
                    <?php endif; ?>
                    <?php if ($debtPage > 1): ?>
                        <a href="<?= e(dashboard_url(['month' => $selectedMonth, 'debt_manager' => $debtManager, 'debt_page' => $debtPage - 1])) ?>">Назад</a>
                    <?php endif; ?>
                    <span><?= e((string) $debtPage) ?> / <?= e((string) $totalDebtPages) ?></span>
                    <?php if ($debtPage < $totalDebtPages): ?>
                        <a href="<?= e(dashboard_url(['month' => $selectedMonth, 'debt_manager' => $debtManager, 'debt_page' => $debtPage + 1])) ?>">Далі</a>
                    <?php endif; ?>
                </div>
            </div>

            <div class="aging-row">
                <span class="status-badge status-badge--muted">0–7 дн: <?= e(money_uah($aging['bucket_fresh'] ?? 0)) ?> (<?= e((string) ($aging['count_fresh'] ?? 0)) ?>)</span>
                <span class="status-badge status-badge--warning">8–30 дн: <?= e(money_uah($aging['bucket_mid'] ?? 0)) ?> (<?= e((string) ($aging['count_mid'] ?? 0)) ?>)</span>
                <span class="status-badge status-badge--danger">30+ дн: <?= e(money_uah($aging['bucket_old'] ?? 0)) ?> (<?= e((string) ($aging['count_old'] ?? 0)) ?>)</span>
            </div>

            <?php if ($receivablesByManager): ?>
                <div class="table-wrap">
                    <table class="compact-table">
                        <thead>
                            <tr>
                                <th>Менеджер</th>
                                <th class="num">Борг всього</th>
                                <th class="num">Замовлень</th>
                                <th class="num">Найбільше</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($receivablesByManager as $manager): ?>
                                <?php $managerName = (string) $manager['manager_name']; ?>
                                <tr>
                                    <td>
                                        <a href="<?= e(dashboard_url(['month' => $selectedMonth, 'debt_manager' => $managerName])) ?>">
                                            <?= e(dashboard_manager_key($managerName)) ?>
                                        </a>
                                    </td>
                                    <td class="num"><?= e(money_uah($manager['total_unpaid'] ?? 0)) ?></td>
                                    <td class="num"><?= e((string) $manager['unpaid_count']) ?></td>
                                    <td class="num"><?= e(money_uah($manager['largest_unpaid'] ?? 0)) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <div class="table-wrap table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>№</th>
                            <th>Дата</th>
                            <th>Термін</th>
                            <th class="client-cell">Клієнт</th>
                            <th>Менеджер</th>
                            <th class="num">Сума</th>
                            <th class="num">Оплачено</th>
                            <th class="num">Борг</th>
                            <th>Оплата</th>
                            <th>Статус</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$receivableOrders): ?>
                            <tr><td colspan="10">Несплачених замовлень не знайдено.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($receivableOrders as $order): ?>
                            <tr>
                                <td><?= e((string) ($order['order_number'] ?: '—')) ?></td>
                                <td><?= e((string) ($order['ordered_at'] ?: '—')) ?></td>
                                <td><?= dashboard_age_badge($order['ordered_at'] ?? null) ?></td>
                                <td class="client-cell"><?= dashboard_client_stack($order) ?></td>
                                <td><?= e(dashboard_manager_key($order['manager_name'] ?? '')) ?></td>
                                <td class="num"><?= e(money_uah($order['total_amount_uah'] ?? 0)) ?></td>
                                <td class="num"><?= e(money_uah($order['paid_amount_uah'] ?? 0)) ?></td>
                                <td class="num"><strong><?= e(money_uah($order['unpaid_amount_uah'] ?? 0)) ?></strong></td>
                                <td><?= dashboard_payment_badge($order) ?></td>
                                <td><span class="status-badge status-badge--muted"><?= e((string) ($order['status_name'] ?: '—')) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="panel table-panel dashboard-section">
            <div class="section-heading padded">
                <div>
                    <span class="label"><?= e($monthLabel) ?></span>
                    <h2>Топ несплачених за місяць</h2>
                </div>
            </div>
            <div class="table-wrap">
                <table class="compact-table">
                    <thead>
                        <tr>
                            <th>№</th>
                            <th class="client-cell">Клієнт</th>
                            <th>Менеджер</th>
                            <th class="num">Борг</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$monthlyUnpaidOrders): ?>
                            <tr><td colspan="4">Немає несплачених замовлень за <?= e($monthLabel) ?>.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($monthlyUnpaidOrders as $order): ?>
                            <tr>
                                <td><?= e((string) ($order['order_number'] ?: '—')) ?></td>
                                <td class="client-cell"><?= dashboard_client_stack($order) ?></td>
                                <td><?= e(dashboard_manager_key($order['manager_name'] ?? '')) ?></td>
                                <td class="num"><strong><?= e(money_uah($order['unpaid_amount_uah'] ?? 0)) ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="panel table-panel dashboard-section">
            <div class="section-heading padded">
                <div>
                    <span class="label">Усі місяці · <?= e((string) count($clientDebt)) ?> клієнтів</span>
                    <h2>Клієнти з боргом</h2>
                </div>
            </div>
            <div class="table-wrap">
                <table class="compact-table">
                    <thead>
                        <tr>
                            <th class="client-cell">Клієнт</th>
                            <th>Менеджер</th>
                            <th>Найстаріше</th>
                            <th class="num">Борг</th>
                            <th class="num">Замовлень</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$clientDebt): ?>
                            <tr><td colspan="6">Клієнтів з боргом немає.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($clientDebt as $client): ?>
                            <tr>
                                <td class="client-cell">
                                    <?= dashboard_client_stack([
                                        'company_name' => $client['company_display'] ?? '',
                                        'local_legal_name' => $client['legal_display'] ?? '',
                                        'buyer_name' => $client['contact_display'] ?? '',
                                        'buyer_email' => $client['contact_email'] ?? '',
                                        'buyer_phone' => $client['contact_phone'] ?? '',
                                        'client_name' => $client['client_name'] ?? '',
                                    ]) ?>
                                </td>
                                <td><?= e((string) ($client['manager_name'] ?? 'Без менеджера')) ?></td>
                                <td><?= dashboard_age_badge($client['oldest_ordered_at'] ?? null) ?></td>
                                <td class="num"><strong><?= e(money_uah($client['total_unpaid'] ?? 0)) ?></strong></td>
                                <td class="num"><?= e((string) $client['unpaid_count']) ?></td>
                                <td>
                                    <?php if ((int) $client['client_key'] > 0): ?>
                                        <a class="button-secondary small-button" target="_blank" href="<?= e(base_path('/index.php?client_statement=' . (int) $client['client_key'])) ?>">Зведення</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="panel dashboard-section">
            <div class="section-heading">
                <div>
                    <span class="label">Операційний тиск окремо від стратегічного</span>
                    <h2>Ми повинні</h2>
                </div>
                <?php if (can_manage_expenses()): ?>
                    <a class="button-secondary small-button" href="<?= e(base_path('/expenses.php?month=' . urlencode($selectedMonth))) ?>">Керувати витратами</a>
                <?php endif; ?>
            </div>
            <dl class="plan-list">
                <div>
                    <dt>Операційні платежі цього місяця</dt>
                    <dd><?= e(money_uah($operationalDueThisMonth)) ?></dd>
                </div>
                <div>
                    <dt>Платежі цього тижня</dt>
                    <dd><?= e(money_uah($operationalDueThisWeek)) ?></dd>
                </div>
                <div>
                    <dt>Прострочені платежі</dt>
                    <dd><?= e(money_uah($overdueTotal)) ?><?php if ($overdueCount > 0): ?> <small>· <?= e((string) $overdueCount) ?></small><?php endif; ?></dd>
                </div>
                <div>
                    <dt>Стратегічні борги</dt>
                    <dd><?= e(money_uah($strategicDebtTotal)) ?></dd>
                </div>
            </dl>
        </section>
        <?= app_version_badge() ?>
    </main>
</body>
</html>
