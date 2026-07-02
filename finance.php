<?php

function ensure_finance_tables(): void
{
    $pdo = db();

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS db_sales_targets (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            target_type ENUM('company','manager') NOT NULL,
            manager_name VARCHAR(150) NULL,
            amount_uah DECIMAL(14,2) NOT NULL DEFAULT 0,
            effective_from DATE NOT NULL,
            note TEXT NULL,
            created_by_user_id INT UNSIGNED NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_target_type (target_type),
            KEY idx_manager_name (manager_name),
            KEY idx_effective_from (effective_from)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS db_expenses (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            category VARCHAR(100) NULL,
            amount_uah DECIMAL(14,2) NOT NULL DEFAULT 0,
            currency VARCHAR(10) NOT NULL DEFAULT 'UAH',
            expense_type ENUM('one_time','monthly_subscription','loan_payment','operational_debt','strategic_debt','other') NOT NULL DEFAULT 'one_time',
            due_date DATE NULL,
            repeat_day TINYINT UNSIGNED NULL,
            repeat_until DATE NULL,
            total_debt_amount_uah DECIMAL(14,2) NULL,
            paid_amount_uah DECIMAL(14,2) NOT NULL DEFAULT 0,
            status ENUM('planned','paid','canceled') NOT NULL DEFAULT 'planned',
            is_strategic TINYINT(1) NOT NULL DEFAULT 0,
            note TEXT NULL,
            created_by_user_id INT UNSIGNED NULL,
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

function month_end_date(string $month): string
{
    $date = DateTimeImmutable::createFromFormat('!Y-m', $month) ?: new DateTimeImmutable('first day of this month');
    return $date->modify('last day of this month')->format('Y-m-d');
}

function active_company_target(PDO $pdo, string $month, float $fallback = 4000000): array
{
    $stmt = $pdo->prepare("
        SELECT amount_uah, effective_from, note
        FROM db_sales_targets
        WHERE target_type = 'company'
          AND effective_from <= :month_end
        ORDER BY effective_from DESC, id DESC
        LIMIT 1
    ");
    $stmt->execute(['month_end' => month_end_date($month)]);
    $row = $stmt->fetch();

    if (!$row) {
        return [
            'amount_uah' => $fallback,
            'effective_from' => null,
            'note' => null,
            'is_fallback' => true,
        ];
    }

    return [
        'amount_uah' => (float) $row['amount_uah'],
        'effective_from' => $row['effective_from'],
        'note' => $row['note'] ?? null,
        'is_fallback' => false,
    ];
}

function active_manager_targets(PDO $pdo, string $month, array $managerNames): array
{
    $targets = [];
    $stmt = $pdo->prepare("
        SELECT amount_uah, effective_from, note
        FROM db_sales_targets
        WHERE target_type = 'manager'
          AND manager_name = :manager_name
          AND effective_from <= :month_end
        ORDER BY effective_from DESC, id DESC
        LIMIT 1
    ");
    $monthEnd = month_end_date($month);

    foreach ($managerNames as $managerName) {
        $managerName = (string) $managerName;
        $stmt->execute([
            'manager_name' => $managerName,
            'month_end' => $monthEnd,
        ]);
        $row = $stmt->fetch();
        $targets[$managerName] = $row ? [
            'amount_uah' => (float) $row['amount_uah'],
            'effective_from' => $row['effective_from'],
            'note' => $row['note'] ?? null,
            'is_fallback' => false,
        ] : [
            'amount_uah' => 0,
            'effective_from' => null,
            'note' => null,
            'is_fallback' => true,
        ];
    }

    return $targets;
}
