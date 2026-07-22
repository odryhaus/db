<?php

function finance_columns(string $table): array
{
    if (!isset($GLOBALS['__finance_columns_cache']) || !is_array($GLOBALS['__finance_columns_cache'])) {
        $GLOBALS['__finance_columns_cache'] = [];
    }
    if (isset($GLOBALS['__finance_columns_cache'][$table])) {
        return $GLOBALS['__finance_columns_cache'][$table];
    }

    if (!invoice_table_exists($table)) {
        $GLOBALS['__finance_columns_cache'][$table] = [];
        return [];
    }

    $stmt = db()->prepare("
        SELECT COLUMN_NAME
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table_name
    ");
    $stmt->execute(['table_name' => $table]);
    $GLOBALS['__finance_columns_cache'][$table] = array_map('strval', array_column($stmt->fetchAll(), 'COLUMN_NAME'));
    return $GLOBALS['__finance_columns_cache'][$table];
}

function finance_refresh_columns(string $table): void
{
    if (isset($GLOBALS['__finance_columns_cache']) && is_array($GLOBALS['__finance_columns_cache'])) {
        unset($GLOBALS['__finance_columns_cache'][$table]);
    }
}

function finance_has_column(string $table, string $column): bool
{
    return in_array($column, finance_columns($table), true);
}

function finance_money($value): string
{
    return number_format((float) $value, 0, '.', ' ') . ' UAH';
}

function finance_active_payment_sql(string $alias = ''): string
{
    $prefix = $alias !== '' ? rtrim($alias, '.') . '.' : '';
    return "COALESCE({$prefix}is_deleted, 0) = 0 AND {$prefix}status = 'paid'";
}

function finance_completed_transaction_sql(string $alias = ''): string
{
    $prefix = $alias !== '' ? rtrim($alias, '.') . '.' : '';
    return "{$prefix}status = 'completed'";
}

function finance_filter_month_bounds(string $month): array
{
    $month = cockpit_valid_month($month);
    $date = DateTimeImmutable::createFromFormat('!Y-m', $month) ?: new DateTimeImmutable('first day of this month');
    return [
        'month' => $month,
        'start' => $date->modify('first day of this month')->setTime(0, 0, 0),
        'end' => $date->modify('last day of this month')->setTime(23, 59, 59),
    ];
}

function finance_account_balance_select(): string
{
    return "
        COALESCE(SUM(CASE
            WHEN t.status = 'completed' AND t.direction = 'income' THEN t.amount
            WHEN t.status = 'completed' AND t.direction = 'expense' THEN -t.amount
            ELSE 0
        END), 0)
    ";
}

function finance_total_balance(): array
{
    if (!invoice_table_exists('db_financial_accounts')) {
        return ['total' => 0.0, 'unallocated_count' => 0];
    }

    $total = 0.0;
    if (invoice_table_exists('db_financial_transactions')) {
        $total = (float) db()->query("
            SELECT " . finance_account_balance_select() . "
            FROM db_financial_accounts a
            LEFT JOIN db_financial_transactions t ON t.financial_account_id = a.id
            WHERE a.is_active = 1
              AND a.include_in_total_balance = 1
        ")->fetchColumn();
    }

    $unallocated = 0;
    if (invoice_table_exists('db_financial_transactions') && finance_has_column('db_financial_transactions', 'allocation_status')) {
        $unallocated = (int) db()->query("
            SELECT COUNT(*)
            FROM db_financial_transactions
            WHERE allocation_status = 'needs_review'
              AND status <> 'canceled'
        ")->fetchColumn();
    }

    return ['total' => $total, 'unallocated_count' => $unallocated];
}

function finance_monthly_completed_expenses(string $month): float
{
    if (!invoice_table_exists('db_financial_transactions')) {
        return 0.0;
    }

    $bounds = finance_filter_month_bounds($month);
    $stmt = db()->prepare("
        SELECT COALESCE(SUM(amount), 0)
        FROM db_financial_transactions
        WHERE direction = 'expense'
          AND status = 'completed'
          AND transaction_date >= :start_at
          AND transaction_date <= :end_at
          AND COALESCE(transaction_type, '') NOT IN ('opening_balance','manual_adjustment','transfer')
          AND COALESCE(balance_operation_type, 'normal') = 'normal'
    ");
    $stmt->execute([
        'start_at' => $bounds['start']->format('Y-m-d H:i:s'),
        'end_at' => $bounds['end']->format('Y-m-d H:i:s'),
    ]);

    return (float) ($stmt->fetchColumn() ?: 0);
}

function finance_monthly_cash_summary(string $month): array
{
    $bounds = finance_filter_month_bounds($month);
    $summary = ['income' => 0.0, 'expense' => 0.0, 'net' => 0.0];
    if (!invoice_table_exists('db_financial_transactions')) {
        return $summary;
    }

    $stmt = db()->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN direction = 'income' THEN amount ELSE 0 END), 0) AS income_total,
            COALESCE(SUM(CASE WHEN direction = 'expense' THEN amount ELSE 0 END), 0) AS expense_total
        FROM db_financial_transactions
        WHERE status = 'completed'
          AND transaction_date >= :start_at
          AND transaction_date <= :end_at
    ");
    $stmt->execute([
        'start_at' => $bounds['start']->format('Y-m-d H:i:s'),
        'end_at' => $bounds['end']->format('Y-m-d H:i:s'),
    ]);
    $row = $stmt->fetch() ?: [];
    $summary['income'] = (float) ($row['income_total'] ?? 0);
    $summary['expense'] = (float) ($row['expense_total'] ?? 0);
    $summary['net'] = $summary['income'] - $summary['expense'];

    return $summary;
}
