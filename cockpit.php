<?php

function cockpit_valid_month(?string $month): string
{
    $month = (string) $month;
    return preg_match('/^\d{4}-\d{2}$/', $month) ? $month : date('Y-m');
}

function cockpit_month_bounds(string $month): array
{
    $date = DateTimeImmutable::createFromFormat('!Y-m', cockpit_valid_month($month)) ?: new DateTimeImmutable('first day of this month');
    return [
        'start' => $date->modify('first day of this month')->setTime(0, 0, 0),
        'end' => $date->modify('last day of this month')->setTime(23, 59, 59),
    ];
}

function cockpit_not_canceled_sql(string $alias = ''): string
{
    $prefix = $alias !== '' ? rtrim($alias, '.') . '.' : '';
    return "
        LOWER(COALESCE({$prefix}status_name, '')) NOT LIKE '%cancel%'
        AND LOWER(COALESCE({$prefix}status_name, '')) NOT LIKE '%deleted%'
        AND LOWER(COALESCE({$prefix}status_name, '')) NOT LIKE '%скас%'
        AND LOWER(COALESCE({$prefix}payment_status, '')) NOT LIKE '%cancel%'
        AND LOWER(COALESCE({$prefix}payment_status, '')) NOT LIKE '%deleted%'
        AND LOWER(COALESCE({$prefix}payment_status, '')) NOT LIKE '%скас%'
    ";
}

function cockpit_has_column(string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (!array_key_exists($key, $cache)) {
        if (!function_exists('finance_columns')) {
            require_once __DIR__ . '/financial.php';
        }
        $cache[$key] = invoice_table_exists($table) && in_array($column, finance_columns($table), true);
    }

    return (bool) $cache[$key];
}

function cockpit_order_not_excluded_sql(string $alias = ''): string
{
    if (!cockpit_has_column('db_orders', 'analytics_excluded')) {
        return '1=1';
    }
    $prefix = $alias !== '' ? rtrim($alias, '.') . '.' : '';
    return "COALESCE({$prefix}analytics_excluded, 0) = 0";
}

function cockpit_order_client_not_excluded_sql(string $alias = ''): string
{
    $prefix = $alias !== '' ? rtrim($alias, '.') . '.' : '';
    $clauses = [];

    if (cockpit_has_column('db_client_companies', 'analytics_excluded')) {
        $conditions = [];
        $companyNameParts = [];
        foreach (['display_name', 'keycrm_name', 'name', 'keycrm_title', 'title'] as $column) {
            if (cockpit_has_column('db_client_companies', $column)) {
                $companyNameParts[] = "NULLIF(analytics_cc.{$column}, '')";
            }
        }

        if (cockpit_has_column('db_orders', 'company_id') && cockpit_has_column('db_client_companies', 'keycrm_company_id')) {
            $conditions[] = "analytics_cc.keycrm_company_id = {$prefix}company_id";
        }

        $nameParts = [];
        foreach (['company_name', 'client_name', 'buyer_name'] as $column) {
            if (cockpit_has_column('db_orders', $column)) {
                $nameParts[] = "NULLIF({$prefix}{$column}, '')";
            }
        }
        if ($nameParts) {
            $orderName = 'COALESCE(' . implode(', ', $nameParts) . ')';
            if ($companyNameParts) {
                $companyName = 'COALESCE(' . implode(', ', $companyNameParts) . ')';
                $conditions[] = "(analytics_cc.keycrm_company_id IS NULL AND {$companyName} = {$orderName})";
            }
        }

        if ($conditions) {
            $clauses[] = 'NOT EXISTS (
                SELECT 1
                FROM db_client_companies analytics_cc
                WHERE COALESCE(analytics_cc.analytics_excluded, 0) = 1
                  AND (' . implode(' OR ', $conditions) . ')
                LIMIT 1
            )';
        }
    }

    if (cockpit_has_column('db_client_contacts', 'analytics_excluded')) {
        $conditions = [];

        if (cockpit_has_column('db_orders', 'buyer_id') && cockpit_has_column('db_client_contacts', 'keycrm_buyer_id')) {
            $conditions[] = "analytics_ct.keycrm_buyer_id = {$prefix}buyer_id";
        }
        if (cockpit_has_column('db_orders', 'buyer_name') && cockpit_has_column('db_client_contacts', 'full_name')) {
            $conditions[] = "NULLIF(analytics_ct.full_name, '') = NULLIF({$prefix}buyer_name, '')";
        }
        if (cockpit_has_column('db_orders', 'client_name') && cockpit_has_column('db_client_contacts', 'full_name')) {
            $conditions[] = "NULLIF(analytics_ct.full_name, '') = NULLIF({$prefix}client_name, '')";
        }
        if (cockpit_has_column('db_orders', 'buyer_email') && cockpit_has_column('db_client_contacts', 'email')) {
            $conditions[] = "NULLIF(analytics_ct.email, '') = NULLIF({$prefix}buyer_email, '')";
        }
        if (cockpit_has_column('db_orders', 'buyer_phone') && cockpit_has_column('db_client_contacts', 'phone')) {
            $conditions[] = "NULLIF(analytics_ct.phone, '') = NULLIF({$prefix}buyer_phone, '')";
        }

        if ($conditions) {
            $clauses[] = 'NOT EXISTS (
                SELECT 1
                FROM db_client_contacts analytics_ct
                WHERE COALESCE(analytics_ct.analytics_excluded, 0) = 1
                  AND (' . implode(' OR ', $conditions) . ')
                LIMIT 1
            )';
        }
    }

    return $clauses ? implode(' AND ', $clauses) : '1=1';
}

function cockpit_order_excluded_sql(string $alias = ''): string
{
    if (!cockpit_has_column('db_orders', 'analytics_excluded')) {
        return '1=0';
    }
    $prefix = $alias !== '' ? rtrim($alias, '.') . '.' : '';
    return "COALESCE({$prefix}analytics_excluded, 0) = 1";
}

function cockpit_active_order_sql(string $alias = ''): string
{
    return '(' . cockpit_not_canceled_sql($alias) . ') AND ' . cockpit_order_not_excluded_sql($alias) . ' AND ' . cockpit_order_client_not_excluded_sql($alias);
}

function cockpit_client_company_not_excluded_sql(string $alias = ''): string
{
    if (!cockpit_has_column('db_client_companies', 'analytics_excluded')) {
        return '1=1';
    }
    $prefix = $alias !== '' ? rtrim($alias, '.') . '.' : '';
    return "COALESCE({$prefix}analytics_excluded, 0) = 0";
}

function cockpit_active_payment_sql(string $alias = ''): string
{
    $prefix = $alias !== '' ? rtrim($alias, '.') . '.' : '';
    return "COALESCE({$prefix}is_deleted, 0) = 0 AND {$prefix}status = 'paid'";
}

function cockpit_zero_summary(string $month): array
{
    return [
        'month' => cockpit_valid_month($month),
        'target' => 4000000.0,
        'order_count' => 0,
        'sales_fact' => 0.0,
        'sales_paid_by_order' => 0.0,
        'sales_unpaid_by_order' => 0.0,
        'cash_received' => 0.0,
        'cash_payment_count' => 0,
        'cash_from_selected_month_orders' => 0.0,
        'cash_from_previous_orders' => 0.0,
        'receivables_total' => 0.0,
        'receivables_count' => 0,
        'largest_receivable' => 0.0,
        'direct_costs' => 0.0,
        'gross_margin' => 0.0,
        'gross_margin_percent' => 0.0,
        'operating_costs' => 0.0,
        'operating_profit' => 0.0,
        'operating_profit_status' => 'needs_categorization',
        'operational_due_this_month' => 0.0,
        'operational_due_this_week' => 0.0,
        'overdue_obligations_total' => 0.0,
        'overdue_obligations_count' => 0,
        'strategic_debt_total' => 0.0,
        'current_balance' => 0.0,
        'unallocated_transactions_count' => 0,
        'cash_forecast' => 0.0,
        'sync_status' => [],
    ];
}

function cockpit_monthly_summary(string $month): array
{
    if (function_exists('ensure_analytics_exclusion_columns')) {
        ensure_analytics_exclusion_columns();
    }

    $month = cockpit_valid_month($month);
    $bounds = cockpit_month_bounds($month);
    $summary = cockpit_zero_summary($month);
    $target = active_company_target(db(), $month);
    $summary['target'] = (float) ($target['amount_uah'] ?? 4000000);
    $notCanceled = cockpit_active_order_sql('o');

    if (invoice_table_exists('db_orders')) {
        $stmt = db()->prepare("
            SELECT
                COUNT(*) AS order_count,
                COALESCE(SUM(o.total_amount_uah), 0) AS sales_fact,
                COALESCE(SUM(o.paid_amount_uah), 0) AS paid_by_order,
                COALESCE(SUM(o.unpaid_amount_uah), 0) AS unpaid_by_order,
                COALESCE(SUM(o.expenses_sum_uah), 0) AS expenses_sum,
                COALESCE(SUM(o.margin_sum_uah), 0) AS margin_sum
            FROM db_orders o
            WHERE o.order_month = :month
              AND {$notCanceled}
        ");
        $stmt->execute(['month' => $month]);
        $row = $stmt->fetch() ?: [];
        $summary['order_count'] = (int) ($row['order_count'] ?? 0);
        $summary['sales_fact'] = (float) ($row['sales_fact'] ?? 0);
        $summary['sales_paid_by_order'] = (float) ($row['paid_by_order'] ?? 0);
        $summary['sales_unpaid_by_order'] = (float) ($row['unpaid_by_order'] ?? 0);

        $marginSum = (float) ($row['margin_sum'] ?? 0);
        $expensesSum = (float) ($row['expenses_sum'] ?? 0);
        if ($marginSum > 0) {
            $summary['gross_margin'] = $marginSum;
            $summary['direct_costs'] = max($summary['sales_fact'] - $marginSum, 0);
        } else {
            $summary['direct_costs'] = $expensesSum;
            $summary['gross_margin'] = max($summary['sales_fact'] - $expensesSum, 0);
        }
        $summary['gross_margin_percent'] = $summary['sales_fact'] > 0 ? round(($summary['gross_margin'] / $summary['sales_fact']) * 100, 1) : 0.0;

        $receivables = db()->query("
            SELECT
                COALESCE(SUM(o.unpaid_amount_uah), 0) AS total_unpaid,
                COUNT(*) AS unpaid_count,
                COALESCE(MAX(o.unpaid_amount_uah), 0) AS largest_unpaid
            FROM db_orders o
            WHERE o.unpaid_amount_uah > 0
              AND {$notCanceled}
        ")->fetch() ?: [];
        $summary['receivables_total'] = (float) ($receivables['total_unpaid'] ?? 0);
        $summary['receivables_count'] = (int) ($receivables['unpaid_count'] ?? 0);
        $summary['largest_receivable'] = (float) ($receivables['largest_unpaid'] ?? 0);
    }

    if (invoice_table_exists('db_order_payments')) {
        $activePayment = cockpit_active_payment_sql('p');
        $activePaymentOrder = cockpit_active_order_sql('o');
        $payments = db()->prepare("
            SELECT
                COALESCE(SUM(p.amount), 0) AS cash_received,
                COUNT(*) AS payment_count,
                COALESCE(SUM(CASE WHEN o.order_month = :month_match THEN p.amount ELSE 0 END), 0) AS selected_month_cash,
                COALESCE(SUM(CASE WHEN o.order_month IS NULL OR o.order_month <> :month_match_2 THEN p.amount ELSE 0 END), 0) AS previous_cash
            FROM db_order_payments p
            LEFT JOIN db_orders o ON o.keycrm_id = p.keycrm_order_id
            WHERE p.payment_date >= :month_start
              AND p.payment_date <= :month_end
              AND {$activePayment}
              AND {$activePaymentOrder}
        ");
        $payments->execute([
            'month_match' => $month,
            'month_match_2' => $month,
            'month_start' => $bounds['start']->format('Y-m-d H:i:s'),
            'month_end' => $bounds['end']->format('Y-m-d H:i:s'),
        ]);
        $row = $payments->fetch() ?: [];
        $summary['cash_received'] = (float) ($row['cash_received'] ?? 0);
        $summary['cash_payment_count'] = (int) ($row['payment_count'] ?? 0);
        $summary['cash_from_selected_month_orders'] = (float) ($row['selected_month_cash'] ?? 0);
        $summary['cash_from_previous_orders'] = (float) ($row['previous_cash'] ?? 0);
    }

    if (function_exists('finance_monthly_completed_expenses')) {
        $summary['operating_costs'] = finance_monthly_completed_expenses($month);
    }

    cockpit_add_obligation_summary($summary, $bounds);
    if (function_exists('finance_total_balance')) {
        $balance = finance_total_balance();
        $summary['current_balance'] = (float) ($balance['total'] ?? 0);
        $summary['unallocated_transactions_count'] = (int) ($balance['unallocated_count'] ?? 0);
    }
    if ($summary['operating_costs'] > 0 || invoice_table_exists('db_financial_transactions')) {
        $summary['operating_profit'] = $summary['gross_margin'] - $summary['operating_costs'];
        $summary['operating_profit_status'] = $summary['operating_costs'] > 0 ? 'calculated' : 'needs_categorization';
    }
    $summary['cash_forecast'] = $summary['current_balance'] + $summary['receivables_total'] - $summary['operational_due_this_month'];
    $summary['progress_percent'] = $summary['target'] > 0 ? min(100, round(($summary['sales_fact'] / $summary['target']) * 100, 1)) : 0.0;
    $summary['remaining_to_target'] = max($summary['target'] - $summary['sales_fact'], 0);

    try {
        require_once __DIR__ . '/sync_core.php';
        $summary['sync_status'] = sync_active_summary();
    } catch (Throwable $e) {
        $summary['sync_status'] = ['error' => 'sync status unavailable'];
    }

    return $summary;
}

function cockpit_add_obligation_summary(array &$summary, array $bounds): void
{
    $today = new DateTimeImmutable('today');
    $weekStart = $today->modify('-' . ((int) $today->format('N') - 1) . ' days');
    $weekEnd = $weekStart->modify('+6 days');

    if (invoice_table_exists('db_payment_obligations')) {
        $stmt = db()->prepare("
            SELECT
                COALESCE(SUM(CASE WHEN is_strategic = 0 AND due_date BETWEEN :month_start AND :month_end THEN GREATEST(amount_uah - paid_amount_uah, 0) ELSE 0 END), 0) AS due_month,
                COALESCE(SUM(CASE WHEN is_strategic = 0 AND due_date BETWEEN :week_start AND :week_end THEN GREATEST(amount_uah - paid_amount_uah, 0) ELSE 0 END), 0) AS due_week,
                COALESCE(SUM(CASE WHEN is_strategic = 0 AND due_date < :today THEN GREATEST(amount_uah - paid_amount_uah, 0) ELSE 0 END), 0) AS overdue_total,
                COUNT(CASE WHEN is_strategic = 0 AND due_date < :today_count THEN 1 END) AS overdue_count,
                COALESCE(SUM(CASE WHEN is_strategic = 1 OR obligation_type = 'strategic_debt' THEN GREATEST(COALESCE(total_debt_amount_uah, amount_uah) - paid_amount_uah, 0) ELSE 0 END), 0) AS strategic_total,
                COUNT(*) AS active_count
            FROM db_payment_obligations
            WHERE status IN ('planned','moved','problem')
        ");
        $stmt->execute([
            'month_start' => $bounds['start']->format('Y-m-d'),
            'month_end' => $bounds['end']->format('Y-m-d'),
            'week_start' => $weekStart->format('Y-m-d'),
            'week_end' => $weekEnd->format('Y-m-d'),
            'today' => $today->format('Y-m-d'),
            'today_count' => $today->format('Y-m-d'),
        ]);
        $row = $stmt->fetch() ?: [];
        $summary['operational_due_this_month'] = (float) ($row['due_month'] ?? 0);
        $summary['operational_due_this_week'] = (float) ($row['due_week'] ?? 0);
        $summary['overdue_obligations_total'] = (float) ($row['overdue_total'] ?? 0);
        $summary['overdue_obligations_count'] = (int) ($row['overdue_count'] ?? 0);
        $summary['strategic_debt_total'] = (float) ($row['strategic_total'] ?? 0);
        if ((int) ($row['active_count'] ?? 0) > 0 || !invoice_table_exists('db_expenses')) {
            return;
        }
    }

    if (!invoice_table_exists('db_expenses')) {
        return;
    }

    $stmt = db()->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN is_strategic = 0 AND expense_type <> 'strategic_debt' AND due_date BETWEEN :month_start AND :month_end THEN GREATEST(amount_uah - paid_amount_uah, 0) ELSE 0 END), 0) AS due_month,
            COALESCE(SUM(CASE WHEN is_strategic = 0 AND expense_type <> 'strategic_debt' AND due_date BETWEEN :week_start AND :week_end THEN GREATEST(amount_uah - paid_amount_uah, 0) ELSE 0 END), 0) AS due_week,
            COALESCE(SUM(CASE WHEN is_strategic = 0 AND expense_type <> 'strategic_debt' AND due_date < :today THEN GREATEST(amount_uah - paid_amount_uah, 0) ELSE 0 END), 0) AS overdue_total,
            COUNT(CASE WHEN is_strategic = 0 AND expense_type <> 'strategic_debt' AND due_date < :today_count THEN 1 END) AS overdue_count,
            COALESCE(SUM(CASE WHEN is_strategic = 1 OR expense_type = 'strategic_debt' THEN GREATEST(COALESCE(total_debt_amount_uah, amount_uah) - paid_amount_uah, 0) ELSE 0 END), 0) AS strategic_total
        FROM db_expenses
        WHERE status = 'planned'
    ");
    $stmt->execute([
        'month_start' => $bounds['start']->format('Y-m-d'),
        'month_end' => $bounds['end']->format('Y-m-d'),
        'week_start' => $weekStart->format('Y-m-d'),
        'week_end' => $weekEnd->format('Y-m-d'),
        'today' => $today->format('Y-m-d'),
        'today_count' => $today->format('Y-m-d'),
    ]);
    $row = $stmt->fetch() ?: [];
    $summary['operational_due_this_month'] = (float) ($row['due_month'] ?? 0);
    $summary['operational_due_this_week'] = (float) ($row['due_week'] ?? 0);
    $summary['overdue_obligations_total'] = (float) ($row['overdue_total'] ?? 0);
    $summary['overdue_obligations_count'] = (int) ($row['overdue_count'] ?? 0);
    $summary['strategic_debt_total'] = (float) ($row['strategic_total'] ?? 0);
}

function cockpit_manager_summary(string $month): array
{
    if (function_exists('ensure_analytics_exclusion_columns')) {
        ensure_analytics_exclusion_columns();
    }

    if (!invoice_table_exists('db_orders')) {
        return [];
    }

    $month = cockpit_valid_month($month);
    $notCanceled = cockpit_active_order_sql('o');
    $stmt = db()->prepare("
        SELECT
            COALESCE(NULLIF(o.manager_name, ''), 'Без менеджера') AS manager_name,
            COUNT(*) AS order_count,
            COALESCE(SUM(o.total_amount_uah), 0) AS sales_fact,
            COALESCE(SUM(o.paid_amount_uah), 0) AS paid_by_order,
            COALESCE(SUM(o.unpaid_amount_uah), 0) AS unpaid_by_order
        FROM db_orders o
        WHERE o.order_month = :month
          AND {$notCanceled}
        GROUP BY COALESCE(NULLIF(o.manager_name, ''), 'Без менеджера')
        ORDER BY sales_fact DESC
    ");
    $stmt->execute(['month' => $month]);
    $rows = $stmt->fetchAll();
    $targets = active_manager_targets(db(), $month, array_map(static fn($row) => (string) $row['manager_name'], $rows));

    foreach ($rows as &$row) {
        $target = (float) (($targets[(string) $row['manager_name']]['amount_uah'] ?? 0));
        $fact = (float) ($row['sales_fact'] ?? 0);
        $row['target_amount_uah'] = $target;
        $row['remaining_to_target'] = $target > 0 ? max($target - $fact, 0) : null;
        $row['progress_percent'] = $target > 0 ? min(100, round(($fact / $target) * 100, 1)) : null;
    }
    unset($row);

    return $rows;
}

function cockpit_attention_items(string $month): array
{
    $items = [];
    $summary = cockpit_monthly_summary($month);
    if ($summary['overdue_obligations_count'] > 0) {
        $items[] = [
            'level' => 'danger',
            'title' => 'Прострочені платежі',
            'value' => $summary['overdue_obligations_count'] . ' / ' . money_uah_compact($summary['overdue_obligations_total']),
        ];
    }
    if ($summary['receivables_count'] > 0) {
        $items[] = [
            'level' => 'warning',
            'title' => 'Клієнтська заборгованість',
            'value' => $summary['receivables_count'] . ' / ' . money_uah_compact($summary['receivables_total']),
        ];
    }
    if ($summary['progress_percent'] < 80 && cockpit_valid_month($month) === date('Y-m')) {
        $items[] = [
            'level' => 'neutral',
            'title' => 'План місяця',
            'value' => $summary['progress_percent'] . '%',
        ];
    }

    return $items;
}

function cockpit_action_queue(string $month): array
{
    $month = cockpit_valid_month($month);
    $actions = [];
    $notCanceled = cockpit_active_order_sql('o');

    if (invoice_table_exists('db_orders')) {
        $stmt = db()->query("
            SELECT o.order_number, o.ordered_at, o.company_name, o.buyer_name, o.manager_name,
                   o.unpaid_amount_uah, DATEDIFF(CURDATE(), o.ordered_at) AS debt_age
            FROM db_orders o
            WHERE o.unpaid_amount_uah > 0
              AND {$notCanceled}
            ORDER BY o.unpaid_amount_uah DESC, o.ordered_at ASC
            LIMIT 5
        ");
        foreach ($stmt->fetchAll() as $row) {
            $client = (string) (($row['company_name'] ?? '') ?: (($row['buyer_name'] ?? '') ?: 'Без клієнта'));
            $actions[] = [
                'level' => (int) ($row['debt_age'] ?? 0) > 30 ? 'danger' : 'warning',
                'title' => 'Нагадати оплату',
                'meta' => '#' . (string) $row['order_number'] . ' · ' . $client . ' · ' . (string) max(0, (int) ($row['debt_age'] ?? 0)) . ' дн.',
                'value' => money_uah_compact($row['unpaid_amount_uah'] ?? 0),
                'href' => '/sales.php?' . http_build_query(['month' => $month, 'status' => 'debt']),
                'cta' => 'Продажі',
            ];
        }
    }

    if (invoice_table_exists('db_payment_obligations')) {
        $stmt = db()->query("
            SELECT title, payee_name, amount_uah, paid_amount_uah, due_date, priority
            FROM db_payment_obligations
            WHERE status IN ('planned','moved','problem')
              AND is_strategic = 0
              AND due_date IS NOT NULL
              AND due_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
            ORDER BY due_date ASC, amount_uah DESC
            LIMIT 4
        ");
        foreach ($stmt->fetchAll() as $row) {
            $left = max((float) ($row['amount_uah'] ?? 0) - (float) ($row['paid_amount_uah'] ?? 0), 0);
            $dueDate = (string) ($row['due_date'] ?? '');
            $actions[] = [
                'level' => $dueDate < date('Y-m-d') ? 'danger' : 'neutral',
                'title' => 'Оплатити зобов’язання',
                'meta' => trim((string) ($row['payee_name'] ?? '')) !== '' ? (string) $row['payee_name'] . ' · ' . $dueDate : (string) ($row['title'] ?? '') . ' · ' . $dueDate,
                'value' => money_uah_compact($left),
                'href' => '/expenses.php?month=' . urlencode($month),
                'cta' => 'Витрати',
            ];
        }
    } elseif (invoice_table_exists('db_expenses')) {
        $stmt = db()->query("
            SELECT title, category, amount_uah, paid_amount_uah, due_date
            FROM db_expenses
            WHERE status = 'planned'
              AND is_strategic = 0
              AND due_date IS NOT NULL
              AND due_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
            ORDER BY due_date ASC, amount_uah DESC
            LIMIT 4
        ");
        foreach ($stmt->fetchAll() as $row) {
            $left = max((float) ($row['amount_uah'] ?? 0) - (float) ($row['paid_amount_uah'] ?? 0), 0);
            $dueDate = (string) ($row['due_date'] ?? '');
            $actions[] = [
                'level' => $dueDate < date('Y-m-d') ? 'danger' : 'neutral',
                'title' => 'Оплатити витрату',
                'meta' => (string) ($row['title'] ?? '') . ' · ' . $dueDate,
                'value' => money_uah_compact($left),
                'href' => '/expenses.php?month=' . urlencode($month),
                'cta' => 'Витрати',
            ];
        }
    }

    if (invoice_table_exists('db_invoices')) {
        $stmt = db()->query("
            SELECT invoice_number, buyer_display_name, recipient_legal_name, total_amount_uah,
                   payment_status, payment_due_date, expected_payment_date
            FROM db_invoices
            WHERE payment_status IN ('waiting_payment','problem')
              AND COALESCE(payment_due_date, expected_payment_date) IS NOT NULL
              AND COALESCE(payment_due_date, expected_payment_date) <= DATE_ADD(CURDATE(), INTERVAL 3 DAY)
            ORDER BY COALESCE(payment_due_date, expected_payment_date) ASC, total_amount_uah DESC
            LIMIT 4
        ");
        foreach ($stmt->fetchAll() as $row) {
            $dueDate = (string) (($row['payment_due_date'] ?? '') ?: ($row['expected_payment_date'] ?? ''));
            $client = (string) (($row['recipient_legal_name'] ?? '') ?: (($row['buyer_display_name'] ?? '') ?: 'Без платника'));
            $actions[] = [
                'level' => $dueDate < date('Y-m-d') ? 'danger' : 'warning',
                'title' => 'Контроль рахунку',
                'meta' => '№ ' . (string) $row['invoice_number'] . ' · ' . $client . ' · ' . $dueDate,
                'value' => money_uah_compact($row['total_amount_uah'] ?? 0),
                'href' => '/invoices.php',
                'cta' => 'Рахунки',
            ];
        }
    }

    if (invoice_table_exists('db_financial_transactions') && function_exists('finance_has_column') && finance_has_column('db_financial_transactions', 'allocation_status')) {
        $count = (int) db()->query("
            SELECT COUNT(*)
            FROM db_financial_transactions
            WHERE allocation_status = 'needs_review'
              AND status = 'completed'
        ")->fetchColumn();
        if ($count > 0) {
            $actions[] = [
                'level' => 'neutral',
                'title' => 'Рознести операції',
                'meta' => 'Нерозподілені платежі за рахунками',
                'value' => (string) $count,
                'href' => '/payments.php?month=' . urlencode($month),
                'cta' => 'Операції',
            ];
        }
    }

    if (invoice_table_exists('db_sync_jobs')) {
        $failed = db()->query("
            SELECT job_type, error_message
            FROM db_sync_jobs
            WHERE status = 'failed'
            ORDER BY id DESC
            LIMIT 1
        ")->fetch();
        if ($failed) {
            $actions[] = [
                'level' => 'danger',
                'title' => 'Перевірити sync',
                'meta' => (string) ($failed['job_type'] ?? 'sync') . ' · ' . substr((string) ($failed['error_message'] ?? ''), 0, 90),
                'value' => 'failed',
                'href' => '/history_sync.php?month=' . urlencode($month),
                'cta' => 'Імпорт',
            ];
        }
    }

    usort($actions, static function (array $a, array $b): int {
        $rank = ['danger' => 0, 'warning' => 1, 'neutral' => 2];
        return ($rank[(string) ($a['level'] ?? 'neutral')] ?? 2) <=> ($rank[(string) ($b['level'] ?? 'neutral')] ?? 2);
    });

    return array_slice($actions, 0, 10);
}

function money_uah_compact($value): string
{
    return number_format((float) $value, 0, '.', ' ') . ' UAH';
}

function cockpit_dual_progress(float $planPercent, float $paidPercent, string $label = ''): string
{
    $planPercent = max(0, min(100, $planPercent));
    $paidPercent = max(0, min(100, $paidPercent));
    $labelHtml = $label !== '' ? '<small>' . e($label) . '</small>' : '';

    return '<div class="dual-progress">'
        . '<div class="dual-progress__track">'
        . '<span class="dual-progress__plan" style="width:' . e((string) $planPercent) . '%"></span>'
        . '<span class="dual-progress__paid" style="width:' . e((string) $paidPercent) . '%"></span>'
        . '</div>'
        . $labelHtml
        . '</div>';
}
