<?php

require_once __DIR__ . '/bootstrap.php';
require_login();

if (!can_manage_expenses()) {
    http_response_code(403);
    include __DIR__ . '/partials_forbidden.php';
    exit;
}

ensure_finance_tables();

$selectedMonth = (string) ($_GET['month'] ?? $_POST['month'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $selectedMonth)) {
    $selectedMonth = date('Y-m');
}
$monthDate = DateTimeImmutable::createFromFormat('!Y-m', $selectedMonth) ?: new DateTimeImmutable('first day of this month');
$monthStart = $monthDate->modify('first day of this month')->format('Y-m-d');
$monthEnd = $monthDate->modify('last day of this month')->format('Y-m-d');

$statusFilter = (string) ($_GET['status'] ?? 'planned');
if (!in_array($statusFilter, array_merge(expense_statuses(), ['all']), true)) {
    $statusFilter = 'planned';
}

$scopeFilter = (string) ($_GET['scope'] ?? 'all');
if (!in_array($scopeFilter, ['all', 'operational', 'strategic'], true)) {
    $scopeFilter = 'all';
}

$message = '';
$error = '';
$editExpense = null;

function expense_money($value): string
{
    return number_format((float) $value, 0, '.', ' ') . ' UAH';
}

function expense_type_label(string $type): string
{
    $labels = [
        'one_time' => 'Разовий',
        'monthly_subscription' => 'Щомісячний',
        'loan_payment' => 'Кредит',
        'operational_debt' => 'Операційний борг',
        'strategic_debt' => 'Стратегічний борг',
        'other' => 'Інше',
    ];

    return $labels[$type] ?? $type;
}

function expense_status_badge(string $status): string
{
    if ($status === 'paid') {
        return '<span class="status-badge status-badge--success">Оплачено</span>';
    }
    if ($status === 'canceled') {
        return '<span class="status-badge status-badge--muted">Скасовано</span>';
    }
    return '<span class="status-badge status-badge--warning">План</span>';
}

if (is_post()) {
    $action = (string) ($_POST['action'] ?? 'save');

    if (!csrf_is_valid()) {
        $error = 'Invalid expense request.';
    } elseif ($action === 'mark_paid') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = db()->prepare("
                UPDATE db_expenses
                SET status = 'paid',
                    paid_amount_uah = CASE WHEN paid_amount_uah > 0 THEN paid_amount_uah ELSE amount_uah END
                WHERE id = :id
            ");
            $stmt->execute(['id' => $id]);
            $message = 'Expense marked as paid.';
        }
    } else {
        $id = (int) ($_POST['id'] ?? 0);
        $title = trim((string) ($_POST['title'] ?? ''));
        $category = trim((string) ($_POST['category'] ?? ''));
        $amount = max(0, (float) ($_POST['amount_uah'] ?? 0));
        $currency = strtoupper(substr(trim((string) ($_POST['currency'] ?? 'UAH')), 0, 10));
        $expenseType = (string) ($_POST['expense_type'] ?? 'other');
        $dueDate = trim((string) ($_POST['due_date'] ?? ''));
        $repeatDay = trim((string) ($_POST['repeat_day'] ?? ''));
        $repeatUntil = trim((string) ($_POST['repeat_until'] ?? ''));
        $totalDebt = trim((string) ($_POST['total_debt_amount_uah'] ?? ''));
        $paidAmount = max(0, (float) ($_POST['paid_amount_uah'] ?? 0));
        $status = (string) ($_POST['status'] ?? 'planned');
        $isStrategic = isset($_POST['is_strategic']) ? 1 : 0;
        $note = trim((string) ($_POST['note'] ?? ''));

        if ($title === '' || !in_array($expenseType, expense_types(), true) || !in_array($status, expense_statuses(), true)) {
            $error = 'Title, type, and status are required.';
        } else {
            if ($expenseType === 'strategic_debt') {
                $isStrategic = 1;
            }

            $params = [
                'title' => $title,
                'category' => $category,
                'amount_uah' => $amount,
                'currency' => $currency !== '' ? $currency : 'UAH',
                'expense_type' => $expenseType,
                'due_date' => $dueDate !== '' ? $dueDate : null,
                'repeat_day' => $repeatDay !== '' ? max(1, min(31, (int) $repeatDay)) : null,
                'repeat_until' => $repeatUntil !== '' ? $repeatUntil : null,
                'total_debt_amount_uah' => $totalDebt !== '' ? max(0, (float) $totalDebt) : null,
                'paid_amount_uah' => $paidAmount,
                'status' => $status,
                'is_strategic' => $isStrategic,
                'note' => $note !== '' ? $note : null,
            ];

            if ($id > 0) {
                $stmt = db()->prepare("
                    UPDATE db_expenses
                    SET title = :title,
                        category = :category,
                        amount_uah = :amount_uah,
                        currency = :currency,
                        expense_type = :expense_type,
                        due_date = :due_date,
                        repeat_day = :repeat_day,
                        repeat_until = :repeat_until,
                        total_debt_amount_uah = :total_debt_amount_uah,
                        paid_amount_uah = :paid_amount_uah,
                        status = :status,
                        is_strategic = :is_strategic,
                        note = :note
                    WHERE id = :id
                ");
                $params['id'] = $id;
                $stmt->execute($params);
                $message = 'Expense updated.';
            } else {
                $stmt = db()->prepare("
                    INSERT INTO db_expenses
                        (title, category, amount_uah, currency, expense_type, due_date, repeat_day, repeat_until,
                         total_debt_amount_uah, paid_amount_uah, status, is_strategic, note, created_by_user_id)
                    VALUES
                        (:title, :category, :amount_uah, :currency, :expense_type, :due_date, :repeat_day, :repeat_until,
                         :total_debt_amount_uah, :paid_amount_uah, :status, :is_strategic, :note, :created_by_user_id)
                ");
                $params['created_by_user_id'] = (int) (current_user()['id'] ?? 0);
                $stmt->execute($params);
                $message = 'Expense added.';
            }
        }
    }
}

$editId = (int) ($_GET['edit'] ?? 0);
if ($editId > 0) {
    $stmt = db()->prepare('SELECT * FROM db_expenses WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $editId]);
    $editExpense = $stmt->fetch() ?: null;
}

$where = [];
$params = [];
if ($statusFilter !== 'all') {
    $where[] = 'status = :status_filter';
    $params['status_filter'] = $statusFilter;
}
if ($scopeFilter === 'operational') {
    $where[] = "is_strategic = 0 AND expense_type <> 'strategic_debt'";
} elseif ($scopeFilter === 'strategic') {
    $where[] = "(is_strategic = 1 OR expense_type = 'strategic_debt')";
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$expensesStmt = db()->prepare("
    SELECT *
    FROM db_expenses
    {$whereSql}
    ORDER BY
        CASE WHEN due_date IS NULL THEN 1 ELSE 0 END,
        due_date ASC,
        id DESC
    LIMIT 100
");
$expensesStmt->execute($params);
$expenses = $expensesStmt->fetchAll();

$upcomingStmt = db()->query("
    SELECT *
    FROM db_expenses
    WHERE status = 'planned'
      AND due_date IS NOT NULL
      AND due_date >= CURDATE()
    ORDER BY due_date ASC, amount_uah DESC
    LIMIT 12
");
$upcoming = $upcomingStmt->fetchAll();
$upcomingCount = count($upcoming);

$monthlyStmt = db()->prepare("
    SELECT
        COALESCE(SUM(GREATEST(amount_uah - paid_amount_uah, 0)), 0) AS planned_total,
        COUNT(*) AS planned_count
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
$monthlyStmt->execute([
    'month_start' => $monthStart,
    'month_end' => $monthEnd,
    'month_end_repeat' => $monthEnd,
    'month_start_repeat' => $monthStart,
]);
$monthlyPlanned = $monthlyStmt->fetch() ?: [];

$strategicStmt = db()->query("
    SELECT
        COALESCE(SUM(GREATEST(COALESCE(total_debt_amount_uah, amount_uah) - paid_amount_uah, 0)), 0) AS strategic_total,
        COUNT(*) AS strategic_count
    FROM db_expenses
    WHERE status <> 'canceled'
      AND (is_strategic = 1 OR expense_type = 'strategic_debt')
");
$strategicDebt = $strategicStmt->fetch() ?: [];
?>
<!doctype html>
<html lang="uk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Витрати | .BRAND DB</title>
    <link rel="stylesheet" href="<?= e(asset_path('/assets/app.css')) ?>">
</head>
<body>
    <main class="page">
        <header class="topbar">
            <div class="brand-block">
                <p class="eyebrow">Money control</p>
                <h1>Витрати</h1>
                <p class="muted">Планові платежі, операційні витрати і стратегічні борги</p>
            </div>
            <nav class="nav">
                <a href="<?= e(base_path('/index.php?month=' . urlencode($selectedMonth))) ?>">Дашборд</a>
                <?php if (user_role() === 'ceo'): ?>
                    <a href="<?= e(base_path('/targets.php?month=' . urlencode($selectedMonth))) ?>">Плани</a>
                <?php endif; ?>
                <a href="<?= e(base_path('/invoices.php')) ?>">Рахунки</a>
                <a class="active" href="<?= e(base_path('/expenses.php?month=' . urlencode($selectedMonth))) ?>">Витрати</a>
                <?php if (user_role() === 'ceo'): ?>
                    <a href="<?= e(base_path('/sync_orders.php')) ?>">Синхронізація</a>
                    <a href="<?= e(base_path('/users.php')) ?>">Користувачі</a>
                <?php endif; ?>
                <a href="<?= e(base_path('/logout.php')) ?>">Вийти</a>
            </nav>
        </header>

        <?php if ($message !== ''): ?>
            <div class="notice"><?= e($message) ?></div>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
            <div class="alert"><?= e($error) ?></div>
        <?php endif; ?>

        <section class="kpi-grid compact-kpis">
            <div class="kpi-card">
                <span class="label">План на місяць</span>
                <strong><?= e(expense_money($monthlyPlanned['planned_total'] ?? 0)) ?></strong>
                <small><?= e((string) ($monthlyPlanned['planned_count'] ?? 0)) ?> планових платежів</small>
            </div>
            <div class="kpi-card danger">
                <span class="label">Стратегічні борги</span>
                <strong><?= e(expense_money($strategicDebt['strategic_total'] ?? 0)) ?></strong>
                <small><?= e((string) ($strategicDebt['strategic_count'] ?? 0)) ?> записів</small>
            </div>
            <div class="kpi-card">
                <span class="label">Найближчі платежі</span>
                <strong><?= e((string) $upcomingCount) ?></strong>
                <small>у списку нижче</small>
            </div>
        </section>

        <section class="panel dashboard-section">
            <form class="toolbar" method="get" action="<?= e(base_path('/expenses.php')) ?>">
                <label>
                    <span>Місяць</span>
                    <input type="month" name="month" value="<?= e($selectedMonth) ?>">
                </label>
                <label>
                    <span>Статус</span>
                    <select name="status">
                        <?php foreach (array_merge(['all'], expense_statuses()) as $status): ?>
                            <option value="<?= e($status) ?>" <?= $statusFilter === $status ? 'selected' : '' ?>><?= e($status === 'all' ? 'усі' : $status) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    <span>Тип</span>
                    <select name="scope">
                        <option value="all" <?= $scopeFilter === 'all' ? 'selected' : '' ?>>усі</option>
                        <option value="operational" <?= $scopeFilter === 'operational' ? 'selected' : '' ?>>операційні</option>
                        <option value="strategic" <?= $scopeFilter === 'strategic' ? 'selected' : '' ?>>стратегічні</option>
                    </select>
                </label>
                <button type="submit">Фільтрувати</button>
            </form>
        </section>

        <section class="panel form-section dashboard-section">
            <div class="section-heading">
                <div>
                    <span class="label"><?= $editExpense ? 'Редагування' : 'Новий платіж' ?></span>
                    <h2><?= $editExpense ? e((string) $editExpense['title']) : 'Додати витрату' ?></h2>
                </div>
                <?php if ($editExpense): ?>
                    <a class="button-secondary small-button" href="<?= e(base_path('/expenses.php?month=' . urlencode($selectedMonth))) ?>">Новий запис</a>
                <?php endif; ?>
            </div>
            <form method="post" action="<?= e(base_path('/expenses.php')) ?>" class="expense-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" value="<?= e((string) ($editExpense['id'] ?? 0)) ?>">
                <input type="hidden" name="month" value="<?= e($selectedMonth) ?>">
                <label>
                    <span>Назва</span>
                    <input name="title" required value="<?= e($editExpense['title'] ?? '') ?>">
                </label>
                <label>
                    <span>Категорія</span>
                    <input name="category" value="<?= e($editExpense['category'] ?? '') ?>">
                </label>
                <label>
                    <span>Сума, UAH</span>
                    <input type="number" step="0.01" min="0" name="amount_uah" value="<?= e((string) ($editExpense['amount_uah'] ?? '')) ?>">
                </label>
                <label>
                    <span>Валюта</span>
                    <input name="currency" value="<?= e($editExpense['currency'] ?? 'UAH') ?>">
                </label>
                <label>
                    <span>Тип</span>
                    <select name="expense_type">
                        <?php foreach (expense_types() as $type): ?>
                            <option value="<?= e($type) ?>" <?= (string) ($editExpense['expense_type'] ?? 'one_time') === $type ? 'selected' : '' ?>><?= e(expense_type_label($type)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    <span>Статус</span>
                    <select name="status">
                        <?php foreach (expense_statuses() as $status): ?>
                            <option value="<?= e($status) ?>" <?= (string) ($editExpense['status'] ?? 'planned') === $status ? 'selected' : '' ?>><?= e($status) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    <span>Дата платежу</span>
                    <input type="date" name="due_date" value="<?= e($editExpense['due_date'] ?? '') ?>">
                </label>
                <label>
                    <span>День повтору</span>
                    <input type="number" min="1" max="31" name="repeat_day" value="<?= e((string) ($editExpense['repeat_day'] ?? '')) ?>">
                </label>
                <label>
                    <span>Повторювати до</span>
                    <input type="date" name="repeat_until" value="<?= e($editExpense['repeat_until'] ?? '') ?>">
                </label>
                <label>
                    <span>Загальний борг, UAH</span>
                    <input type="number" step="0.01" min="0" name="total_debt_amount_uah" value="<?= e((string) ($editExpense['total_debt_amount_uah'] ?? '')) ?>">
                </label>
                <label>
                    <span>Оплачено, UAH</span>
                    <input type="number" step="0.01" min="0" name="paid_amount_uah" value="<?= e((string) ($editExpense['paid_amount_uah'] ?? '0')) ?>">
                </label>
                <label class="checkbox-field">
                    <input type="checkbox" name="is_strategic" value="1" <?= (int) ($editExpense['is_strategic'] ?? 0) === 1 ? 'checked' : '' ?>>
                    <span>Стратегічний борг</span>
                </label>
                <label class="wide-field">
                    <span>Нотатка</span>
                    <textarea name="note" rows="3"><?= e($editExpense['note'] ?? '') ?></textarea>
                </label>
                <button type="submit">Зберегти</button>
            </form>
        </section>

        <section class="dashboard-grid lower-grid">
            <div class="panel table-panel">
                <div class="section-heading padded">
                    <div>
                        <span class="label">Найближчі дати</span>
                        <h2>Майбутні платежі</h2>
                    </div>
                </div>
                <div class="table-wrap">
                    <table class="compact-table">
                        <thead>
                            <tr>
                                <th>Дата</th>
                                <th>Назва</th>
                                <th>Тип</th>
                                <th class="num">Сума</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$upcoming): ?>
                                <tr><td colspan="4">Немає майбутніх планових платежів.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($upcoming as $expense): ?>
                                <tr>
                                    <td><?= e((string) $expense['due_date']) ?></td>
                                    <td><?= e((string) $expense['title']) ?></td>
                                    <td><span class="status-badge status-badge--muted"><?= e(expense_type_label((string) $expense['expense_type'])) ?></span></td>
                                    <td class="num"><?= e(expense_money($expense['amount_uah'] ?? 0)) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="panel">
                <div class="section-heading">
                    <div>
                        <span class="label">Поточний фільтр</span>
                        <h2><?= e($statusFilter === 'all' ? 'Усі статуси' : $statusFilter) ?></h2>
                    </div>
                </div>
                <dl class="plan-list">
                    <div>
                        <dt>Місяць</dt>
                        <dd><?= e($selectedMonth) ?></dd>
                    </div>
                    <div>
                        <dt>Тип</dt>
                        <dd><?= e($scopeFilter) ?></dd>
                    </div>
                </dl>
            </div>
        </section>

        <section class="panel table-panel">
            <div class="section-heading padded">
                <div>
                    <span class="label">Реєстр витрат</span>
                    <h2>Планові, оплачені, скасовані</h2>
                </div>
            </div>
            <div class="table-wrap table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>Назва</th>
                            <th>Категорія</th>
                            <th>Тип</th>
                            <th>Дата</th>
                            <th class="num">Сума</th>
                            <th class="num">Оплачено</th>
                            <th>Статус</th>
                            <th>Стратегічний</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$expenses): ?>
                            <tr><td colspan="9">За обраними фільтрами витрат немає.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($expenses as $expense): ?>
                            <tr>
                                <td><?= e((string) $expense['title']) ?></td>
                                <td><?= e((string) ($expense['category'] ?: '—')) ?></td>
                                <td><span class="status-badge status-badge--muted"><?= e(expense_type_label((string) $expense['expense_type'])) ?></span></td>
                                <td><?= e((string) ($expense['due_date'] ?: '—')) ?></td>
                                <td class="num"><?= e(expense_money($expense['amount_uah'] ?? 0)) ?></td>
                                <td class="num"><?= e(expense_money($expense['paid_amount_uah'] ?? 0)) ?></td>
                                <td><?= expense_status_badge((string) $expense['status']) ?></td>
                                <td><?= (int) $expense['is_strategic'] === 1 ? '<span class="status-badge status-badge--danger">Так</span>' : '<span class="status-badge status-badge--muted">Ні</span>' ?></td>
                                <td>
                                    <div class="row-actions">
                                        <a class="button-secondary small-button" href="<?= e(base_path('/expenses.php?' . http_build_query(['month' => $selectedMonth, 'status' => $statusFilter, 'scope' => $scopeFilter, 'edit' => (int) $expense['id']]))) ?>">Edit</a>
                                        <?php if ((string) $expense['status'] !== 'paid'): ?>
                                            <form method="post" action="<?= e(base_path('/expenses.php')) ?>">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="mark_paid">
                                                <input type="hidden" name="id" value="<?= e((string) $expense['id']) ?>">
                                                <input type="hidden" name="month" value="<?= e($selectedMonth) ?>">
                                                <button type="submit" class="small-button">Paid</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</body>
</html>
