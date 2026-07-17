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
    $month = cockpit_valid_month($month);
    $bounds = cockpit_month_bounds($month);
    $summary = cockpit_zero_summary($month);
    $target = active_company_target(db(), $month);
    $summary['target'] = (float) ($target['amount_uah'] ?? 4000000);
    $notCanceled = cockpit_not_canceled_sql('o');

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
    if (!invoice_table_exists('db_orders')) {
        return [];
    }

    $month = cockpit_valid_month($month);
    $notCanceled = cockpit_not_canceled_sql('o');
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
