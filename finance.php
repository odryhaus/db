<?php

function ensure_finance_tables(): void
{
    $pdo = db();

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS db_monthly_targets (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            month CHAR(7) NOT NULL,
            target_amount_uah DECIMAL(14,2) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_month (month)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS db_manager_targets (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            month CHAR(7) NOT NULL,
            manager_name VARCHAR(150) NOT NULL,
            target_amount_uah DECIMAL(14,2) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_month_manager (month, manager_name),
            KEY idx_month (month)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS db_expenses (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            category VARCHAR(120) NOT NULL DEFAULT '',
            amount_uah DECIMAL(14,2) NOT NULL DEFAULT 0,
            currency VARCHAR(10) NOT NULL DEFAULT 'UAH',
            expense_type ENUM('one_time','monthly_subscription','loan_payment','operational_debt','strategic_debt','other') NOT NULL DEFAULT 'other',
            due_date DATE NULL,
            repeat_day TINYINT NULL,
            repeat_until DATE NULL,
            total_debt_amount_uah DECIMAL(14,2) NULL,
            paid_amount_uah DECIMAL(14,2) NOT NULL DEFAULT 0,
            status ENUM('planned','paid','canceled') NOT NULL DEFAULT 'planned',
            is_strategic TINYINT(1) NOT NULL DEFAULT 0,
            note TEXT NULL,
            created_by_user_id INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_status_due (status, due_date),
            KEY idx_expense_type (expense_type),
            KEY idx_is_strategic (is_strategic)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function expense_types(): array
{
    return ['one_time', 'monthly_subscription', 'loan_payment', 'operational_debt', 'strategic_debt', 'other'];
}

function expense_statuses(): array
{
    return ['planned', 'paid', 'canceled'];
}

function can_manage_expenses(): bool
{
    return in_array(user_role(), ['ceo', 'accountant'], true);
}
