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

function ensure_invoice_tables(): void
{
    $pdo = db();

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS db_our_companies (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            short_name VARCHAR(100) NOT NULL,
            legal_name VARCHAR(255) NOT NULL,
            edrpou VARCHAR(50) NULL,
            iban VARCHAR(64) NULL,
            bank VARCHAR(255) NULL,
            address VARCHAR(255) NULL,
            email VARCHAR(150) NULL,
            phone VARCHAR(120) NULL,
            accountant_email VARCHAR(150) NULL,
            accountant_phone VARCHAR(120) NULL,
            tax_mode ENUM('single_tax_no_vat','vat_20') NOT NULL DEFAULT 'single_tax_no_vat',
            allowed_item_type ENUM('products_only','services_allowed') NOT NULL DEFAULT 'products_only',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_is_active (is_active),
            KEY idx_short_name (short_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS db_invoices (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            keycrm_order_id INT UNSIGNED NULL,
            invoice_number VARCHAR(80) NOT NULL,
            invoice_date DATE NOT NULL,
            document_type ENUM('invoice','delivery_note') NOT NULL DEFAULT 'invoice',
            seller_company_id INT UNSIGNED NULL,
            buyer_id INT UNSIGNED NULL,
            buyer_company_id INT UNSIGNED NULL,
            buyer_display_name VARCHAR(255) NULL,
            buyer_edrpou VARCHAR(50) NULL,
            buyer_address VARCHAR(255) NULL,
            buyer_email VARCHAR(150) NULL,
            buyer_phone VARCHAR(120) NULL,
            total_amount_uah DECIMAL(14,2) NOT NULL DEFAULT 0,
            vat_mode ENUM('no_vat','vat_20') NOT NULL DEFAULT 'no_vat',
            vat_amount_uah DECIMAL(14,2) NOT NULL DEFAULT 0,
            total_with_vat_uah DECIMAL(14,2) NOT NULL DEFAULT 0,
            payment_purpose VARCHAR(255) NULL,
            payment_status ENUM('draft','waiting_payment','paid','problem','canceled') NOT NULL DEFAULT 'draft',
            document_status ENUM('not_sent','sent','closed','problem') NOT NULL DEFAULT 'not_sent',
            payment_due_date DATE NULL,
            document_due_date DATE NULL,
            expected_payment_date DATE NULL,
            status ENUM('draft','sent','paid','docs_sent','docs_closed','canceled') NOT NULL DEFAULT 'draft',
            sent_at DATETIME NULL,
            paid_at DATETIME NULL,
            docs_type ENUM('none','paper','electronic','both') NOT NULL DEFAULT 'none',
            docs_status ENUM('not_sent','sent','signed','closed','problem') NOT NULL DEFAULT 'not_sent',
            docs_sent_at DATETIME NULL,
            docs_closed_at DATETIME NULL,
            pdf_file_path VARCHAR(255) NULL,
            keycrm_file_id VARCHAR(80) NULL,
            note TEXT NULL,
            created_by_user_id INT UNSIGNED NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_keycrm_order_id (keycrm_order_id),
            KEY idx_invoice_date (invoice_date),
            KEY idx_status (status),
            KEY idx_docs_status (docs_status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    invoice_add_column_if_missing('db_invoices', 'client_company_id', 'INT UNSIGNED NULL');
    invoice_add_column_if_missing('db_invoices', 'client_legal_entity_id', 'INT UNSIGNED NULL');
    invoice_add_column_if_missing('db_invoices', 'buyer_contact_name', 'VARCHAR(255) NULL');
    invoice_add_column_if_missing('db_invoices', 'expected_payment_date', 'DATE NULL');
    invoice_add_column_if_missing('db_invoices', 'payment_status', "ENUM('draft','waiting_payment','paid','problem','canceled') NOT NULL DEFAULT 'draft'");
    invoice_add_column_if_missing('db_invoices', 'document_status', "ENUM('not_sent','sent','closed','problem') NOT NULL DEFAULT 'not_sent'");
    invoice_add_column_if_missing('db_invoices', 'payment_due_date', 'DATE NULL');
    invoice_add_column_if_missing('db_invoices', 'document_due_date', 'DATE NULL');

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS db_invoice_documents (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            invoice_id INT UNSIGNED NOT NULL,
            document_type ENUM('invoice','delivery_note','act') NOT NULL,
            document_date DATE NOT NULL,
            file_path VARCHAR(255) NOT NULL,
            status ENUM('draft','generated','sent','signed','closed','canceled','problem') NOT NULL DEFAULT 'generated',
            sent_at DATETIME NULL,
            signed_at DATETIME NULL,
            closed_at DATETIME NULL,
            keycrm_file_id VARCHAR(80) NULL,
            note TEXT NULL,
            created_by_user_id INT UNSIGNED NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_invoice_id (invoice_id),
            KEY idx_document_type (document_type),
            KEY idx_document_date (document_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    invoice_add_column_if_missing('db_invoice_documents', 'status', "ENUM('draft','generated','sent','signed','closed','canceled','problem') NOT NULL DEFAULT 'generated'");
    invoice_add_column_if_missing('db_invoice_documents', 'sent_at', 'DATETIME NULL');
    invoice_add_column_if_missing('db_invoice_documents', 'signed_at', 'DATETIME NULL');
    invoice_add_column_if_missing('db_invoice_documents', 'closed_at', 'DATETIME NULL');
    invoice_add_column_if_missing('db_invoice_documents', 'keycrm_file_id', 'VARCHAR(80) NULL');
    invoice_add_column_if_missing('db_invoice_documents', 'note', 'TEXT NULL');

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS db_invoice_items (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            invoice_id INT UNSIGNED NOT NULL,
            source_product_id INT UNSIGNED NULL,
            title VARCHAR(255) NOT NULL,
            unit VARCHAR(30) NOT NULL DEFAULT 'шт',
            quantity DECIMAL(12,3) NOT NULL DEFAULT 1,
            price_uah DECIMAL(14,2) NOT NULL DEFAULT 0,
            amount_uah DECIMAL(14,2) NOT NULL DEFAULT 0,
            item_type ENUM('product','service','mixed','other') NOT NULL DEFAULT 'product',
            sort_order INT UNSIGNED NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_invoice_id (invoice_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    invoice_add_column_if_missing('db_invoice_items', 'item_type', "ENUM('product','service','mixed','other') NOT NULL DEFAULT 'product'");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS db_client_companies (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            keycrm_company_id INT UNSIGNED NULL,
            display_name VARCHAR(255) NULL,
            keycrm_name VARCHAR(255) NULL,
            keycrm_title VARCHAR(255) NULL,
            name VARCHAR(255) NULL,
            title VARCHAR(255) NULL,
            manager_id INT UNSIGNED NULL,
            note TEXT NULL,
            raw_json LONGTEXT NULL,
            synced_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_keycrm_company_id (keycrm_company_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    invoice_add_column_if_missing('db_client_companies', 'display_name', 'VARCHAR(255) NULL');
    invoice_add_column_if_missing('db_client_companies', 'keycrm_name', 'VARCHAR(255) NULL');
    invoice_add_column_if_missing('db_client_companies', 'keycrm_title', 'VARCHAR(255) NULL');
    invoice_add_column_if_missing('db_client_companies', 'note', 'TEXT NULL');

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS db_client_contacts (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            keycrm_buyer_id INT UNSIGNED NULL,
            client_company_id INT UNSIGNED NULL,
            full_name VARCHAR(255) NULL,
            email VARCHAR(190) NULL,
            phone VARCHAR(80) NULL,
            position VARCHAR(120) NULL,
            note TEXT NULL,
            raw_json LONGTEXT NULL,
            synced_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_keycrm_buyer_id (keycrm_buyer_id),
            KEY idx_client_company_id (client_company_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    invoice_add_column_if_missing('db_client_contacts', 'note', 'TEXT NULL');

    $pdo->exec("
        UPDATE db_invoices
        SET payment_status = CASE
                WHEN status = 'sent' THEN 'waiting_payment'
                WHEN status = 'paid' THEN 'paid'
                WHEN status IN ('docs_sent','docs_closed') THEN 'paid'
                WHEN status = 'canceled' THEN 'canceled'
                WHEN docs_status = 'problem' THEN 'problem'
                ELSE payment_status
            END,
            document_status = CASE
                WHEN docs_status = 'sent' THEN 'sent'
                WHEN docs_status IN ('signed','closed') OR status = 'docs_closed' THEN 'closed'
                WHEN docs_status = 'problem' THEN 'problem'
                ELSE document_status
            END,
            payment_due_date = COALESCE(payment_due_date, expected_payment_date)
        WHERE payment_status = 'draft'
           OR document_status = 'not_sent'
           OR payment_due_date IS NULL
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS db_client_legal_entities (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            client_company_id INT UNSIGNED NULL,
            legal_name VARCHAR(255) NOT NULL,
            short_name VARCHAR(255) NULL,
            edrpou VARCHAR(20) NULL,
            tax_number VARCHAR(50) NULL,
            iban VARCHAR(80) NULL,
            bank VARCHAR(255) NULL,
            legal_address TEXT NULL,
            email VARCHAR(190) NULL,
            phone VARCHAR(80) NULL,
            is_default TINYINT(1) NOT NULL DEFAULT 0,
            note TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_client_company_id (client_company_id),
            KEY idx_is_default (is_default)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $stmt = $pdo->prepare("
        INSERT INTO db_our_companies
            (short_name, legal_name, edrpou, iban, bank, address, email, phone, accountant_email,
             accountant_phone, tax_mode, allowed_item_type, is_active)
        SELECT
            'FOP Darchenko A.B.',
            'ФОП \"Дарченко А.Б.\"',
            '3032919108',
            'UA873052990000026008015017458',
            'АТ КБ \"ПРИВАТБАНК\"',
            'вул. Машинобудівна, 50А, м. Київ, 03067',
            'sales@bws.com.ua',
            '(044) 390-72-80, (093) 390-72-80, (097) 390-72-80, (073) 390-72-80',
            'b@bws.com.ua',
            '(044) 390-72-80, (093) 644-62-61',
            'single_tax_no_vat',
            'products_only',
            1
        WHERE NOT EXISTS (
            SELECT 1 FROM db_our_companies WHERE short_name = 'FOP Darchenko A.B.' LIMIT 1
        )
    ");
    $stmt->execute();
}

function invoice_add_column_if_missing(string $table, string $column, string $definition): void
{
    $stmt = db()->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table_name
          AND COLUMN_NAME = :column_name
    ");
    $stmt->execute([
        'table_name' => $table,
        'column_name' => $column,
    ]);

    if ((int) $stmt->fetchColumn() === 0) {
        db()->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
    }
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

function can_manage_invoices(): bool
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
