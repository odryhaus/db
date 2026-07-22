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

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS db_payment_obligations (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            payee_name VARCHAR(255) NULL,
            amount_uah DECIMAL(14,2) NOT NULL DEFAULT 0,
            currency VARCHAR(10) DEFAULT 'UAH',
            due_date DATE NULL,
            status ENUM('planned','paid','moved','canceled','problem') NOT NULL DEFAULT 'planned',
            priority ENUM('low','normal','high','critical') NOT NULL DEFAULT 'normal',
            obligation_type ENUM('order_contractor','supplier','rent','salary','tax','subscription','loan_payment','operational_debt','strategic_debt','other') NOT NULL DEFAULT 'other',
            category VARCHAR(100) NULL,
            keycrm_order_id INT UNSIGNED NULL,
            order_number VARCHAR(50) NULL,
            invoice_id INT UNSIGNED NULL,
            is_recurring TINYINT(1) NOT NULL DEFAULT 0,
            recurrence_type ENUM('none','monthly','weekly','custom') NOT NULL DEFAULT 'none',
            repeat_day TINYINT UNSIGNED NULL,
            repeat_until DATE NULL,
            is_strategic TINYINT(1) NOT NULL DEFAULT 0,
            total_debt_amount_uah DECIMAL(14,2) NULL,
            paid_amount_uah DECIMAL(14,2) NOT NULL DEFAULT 0,
            note TEXT NULL,
            created_by_user_id INT UNSIGNED NULL,
            paid_at DATETIME NULL,
            moved_from_due_date DATE NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_due_date (due_date),
            KEY idx_status (status),
            KEY idx_obligation_type (obligation_type),
            KEY idx_is_strategic (is_strategic),
            KEY idx_keycrm_order_id (keycrm_order_id),
            KEY idx_invoice_id (invoice_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS db_monthly_costs (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            month CHAR(7) NOT NULL,
            cost_type ENUM('direct','operating','financial','tax','other') NOT NULL DEFAULT 'operating',
            category VARCHAR(120) NULL,
            title VARCHAR(255) NOT NULL,
            amount_uah DECIMAL(14,2) NOT NULL DEFAULT 0,
            source ENUM('manual','payment_obligation','keycrm_expense','import') NOT NULL DEFAULT 'manual',
            source_id INT UNSIGNED NULL,
            is_locked TINYINT(1) NOT NULL DEFAULT 0,
            note TEXT NULL,
            created_by_user_id INT UNSIGNED NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_month (month),
            KEY idx_cost_type (cost_type),
            KEY idx_source (source, source_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    ensure_sync_tables();
}

function ensure_sync_tables(): void
{
    $pdo = db();

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS db_sync_state (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            sync_key VARCHAR(100) NOT NULL UNIQUE,
            last_successful_sync_at DATETIME NULL,
            last_attempt_at DATETIME NULL,
            status ENUM('idle','queued','running','success','partial','failed') NOT NULL DEFAULT 'idle',
            error_message TEXT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS db_sync_jobs (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            parent_job_id INT UNSIGNED NULL,
            job_type VARCHAR(80) NOT NULL,
            status ENUM('queued','running','success','partial','failed') NOT NULL DEFAULT 'queued',
            started_at DATETIME NULL,
            finished_at DATETIME NULL,
            records_seen INT UNSIGNED NOT NULL DEFAULT 0,
            records_inserted INT UNSIGNED NOT NULL DEFAULT 0,
            records_updated INT UNSIGNED NOT NULL DEFAULT 0,
            records_unchanged INT UNSIGNED NOT NULL DEFAULT 0,
            error_message TEXT NULL,
            created_by_user_id INT UNSIGNED NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_parent_job_id (parent_job_id),
            KEY idx_job_type_status (job_type, status),
            KEY idx_status_created (status, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS db_order_payments (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            keycrm_payment_id VARCHAR(80) NOT NULL,
            keycrm_order_id INT UNSIGNED NOT NULL,
            order_number VARCHAR(80) NULL,
            payment_method_id INT UNSIGNED NULL,
            payment_method_name VARCHAR(190) NULL,
            seller_company_id INT UNSIGNED NULL,
            seller_account_id INT UNSIGNED NULL,
            amount DECIMAL(14,2) NOT NULL DEFAULT 0,
            currency VARCHAR(10) NOT NULL DEFAULT 'UAH',
            status VARCHAR(50) NULL,
            payment_date DATETIME NULL,
            source_created_at DATETIME NULL,
            source_updated_at DATETIME NULL,
            source_hash CHAR(64) NULL,
            raw_json LONGTEXT NULL,
            synced_at DATETIME NULL,
            is_deleted TINYINT(1) NOT NULL DEFAULT 0,
            deleted_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_keycrm_payment_id (keycrm_payment_id),
            KEY idx_keycrm_order_id (keycrm_order_id),
            KEY idx_order_number (order_number),
            KEY idx_payment_date (payment_date),
            KEY idx_status (status),
            KEY idx_payment_method_id (payment_method_id),
            KEY idx_seller_company_id (seller_company_id),
            KEY idx_seller_account_id (seller_account_id),
            KEY idx_source_updated_at (source_updated_at),
            KEY idx_is_deleted (is_deleted)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS db_order_expenses (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            keycrm_expense_id VARCHAR(80) NOT NULL,
            keycrm_order_id INT UNSIGNED NOT NULL,
            expense_type_id INT UNSIGNED NULL,
            expense_type_name VARCHAR(190) NULL,
            amount DECIMAL(14,2) NOT NULL DEFAULT 0,
            currency VARCHAR(10) NOT NULL DEFAULT 'UAH',
            status VARCHAR(50) NULL,
            payment_date DATETIME NULL,
            source_updated_at DATETIME NULL,
            source_hash CHAR(64) NULL,
            raw_json LONGTEXT NULL,
            synced_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_keycrm_expense_id (keycrm_expense_id),
            KEY idx_keycrm_order_id (keycrm_order_id),
            KEY idx_payment_date (payment_date),
            KEY idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS db_keycrm_statuses (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            status_type ENUM('order','product') NOT NULL,
            keycrm_status_id INT UNSIGNED NOT NULL,
            name VARCHAR(190) NULL,
            source_hash CHAR(64) NULL,
            raw_json LONGTEXT NULL,
            synced_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_status_type_keycrm (status_type, keycrm_status_id),
            KEY idx_status_type (status_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    invoice_add_column_if_missing('db_sync_state', 'last_source_updated_at', 'DATETIME NULL');
    invoice_add_column_if_missing('db_sync_state', 'last_cursor', 'VARCHAR(190) NULL');
    invoice_add_column_if_missing('db_sync_state', 'last_page', 'INT UNSIGNED NULL');
    invoice_modify_column_if_exists('db_sync_state', 'status', "ENUM('idle','queued','running','success','partial','failed') NOT NULL DEFAULT 'idle'");
    invoice_add_column_if_missing('db_sync_jobs', 'updated_at', 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
    invoice_add_column_if_missing('db_order_payments', 'order_number', 'VARCHAR(80) NULL');
    invoice_add_column_if_missing('db_order_payments', 'seller_company_id', 'INT UNSIGNED NULL');
    invoice_add_column_if_missing('db_order_payments', 'seller_account_id', 'INT UNSIGNED NULL');
    invoice_add_column_if_missing('db_order_payments', 'source_created_at', 'DATETIME NULL');
    invoice_add_column_if_missing('db_order_payments', 'is_deleted', 'TINYINT(1) NOT NULL DEFAULT 0');
    invoice_add_column_if_missing('db_order_payments', 'deleted_at', 'DATETIME NULL');
    invoice_add_index_if_missing('db_order_payments', 'idx_order_number', 'order_number');
    invoice_add_index_if_missing('db_order_payments', 'idx_payment_method_id', 'payment_method_id');
    invoice_add_index_if_missing('db_order_payments', 'idx_seller_company_id', 'seller_company_id');
    invoice_add_index_if_missing('db_order_payments', 'idx_seller_account_id', 'seller_account_id');
    invoice_add_index_if_missing('db_order_payments', 'idx_source_updated_at', 'source_updated_at');
    invoice_add_index_if_missing('db_order_payments', 'idx_is_deleted', 'is_deleted');
    if (invoice_table_exists('db_orders')) {
        invoice_add_column_if_missing('db_orders', 'source_hash', 'CHAR(64) NULL');
        invoice_add_index_if_missing('db_orders', 'idx_source_updated_at', 'source_updated_at');
        invoice_add_index_if_missing('db_orders', 'idx_source_hash', 'source_hash');
    }
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
    invoice_modify_column_if_exists('db_our_companies', 'tax_mode', "ENUM('single_tax_no_vat','vat_20','no_vat_other') NOT NULL DEFAULT 'single_tax_no_vat'");
    invoice_modify_column_if_exists('db_our_companies', 'allowed_item_type', "ENUM('products_only','services_allowed','products_and_services') NOT NULL DEFAULT 'products_only'");
    invoice_modify_column_if_exists('db_our_companies', 'address', 'TEXT NULL');
    invoice_modify_column_if_exists('db_our_companies', 'email', 'VARCHAR(190) NULL');
    invoice_modify_column_if_exists('db_our_companies', 'phone', 'VARCHAR(190) NULL');
    invoice_modify_column_if_exists('db_our_companies', 'accountant_email', 'VARCHAR(190) NULL');
    invoice_modify_column_if_exists('db_our_companies', 'accountant_phone', 'VARCHAR(190) NULL');
    invoice_add_column_if_missing('db_our_companies', 'company_type', "ENUM('fop','tov','pp','other') NOT NULL DEFAULT 'fop'");
    invoice_add_column_if_missing('db_our_companies', 'tax_code', 'VARCHAR(50) NULL');
    invoice_add_column_if_missing('db_our_companies', 'single_tax_group', 'TINYINT NULL');
    invoice_add_column_if_missing('db_our_companies', 'website', 'VARCHAR(190) NULL');
    invoice_add_column_if_missing('db_our_companies', 'signer_name', 'VARCHAR(150) NULL');
    invoice_add_column_if_missing('db_our_companies', 'signer_position', 'VARCHAR(100) NULL');
    invoice_add_column_if_missing('db_our_companies', 'is_default', 'TINYINT(1) NOT NULL DEFAULT 0');
    invoice_add_column_if_missing('db_our_companies', 'note', 'TEXT NULL');
    invoice_add_index_if_missing('db_our_companies', 'idx_tax_code', 'tax_code');
    invoice_add_index_if_missing('db_our_companies', 'idx_is_default', 'is_default');

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS db_our_company_accounts (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            company_id INT UNSIGNED NOT NULL,
            account_label VARCHAR(150) NOT NULL,
            account_type ENUM('bank_account','card_requisites','privat','mono','other') NOT NULL DEFAULT 'bank_account',
            currency VARCHAR(10) NOT NULL DEFAULT 'UAH',
            iban VARCHAR(80) NULL,
            bank_name VARCHAR(255) NULL,
            bank_address TEXT NULL,
            swift VARCHAR(50) NULL,
            recipient_name VARCHAR(255) NULL,
            recipient_address TEXT NULL,
            tax_code VARCHAR(50) NULL,
            language ENUM('uk','en') NOT NULL DEFAULT 'uk',
            is_default TINYINT(1) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            payment_template TEXT NULL,
            note TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_company_id (company_id),
            KEY idx_currency (currency),
            KEY idx_account_type (account_type),
            KEY idx_is_default (is_default),
            KEY idx_is_active (is_active)
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
    invoice_add_column_if_missing('db_invoices', 'recipient_legal_name', 'VARCHAR(255) NULL');
    invoice_add_column_if_missing('db_invoices', 'recipient_short_name', 'VARCHAR(255) NULL');
    invoice_add_column_if_missing('db_invoices', 'recipient_edrpou', 'VARCHAR(50) NULL');
    invoice_add_column_if_missing('db_invoices', 'recipient_tax_number', 'VARCHAR(50) NULL');
    invoice_add_column_if_missing('db_invoices', 'recipient_legal_address', 'TEXT NULL');
    invoice_add_column_if_missing('db_invoices', 'recipient_email', 'VARCHAR(190) NULL');
    invoice_add_column_if_missing('db_invoices', 'recipient_phone', 'VARCHAR(80) NULL');
    invoice_add_column_if_missing('db_invoices', 'contact_name', 'VARCHAR(255) NULL');
    invoice_add_column_if_missing('db_invoices', 'contact_email', 'VARCHAR(190) NULL');
    invoice_add_column_if_missing('db_invoices', 'contact_phone', 'VARCHAR(80) NULL');
    invoice_add_column_if_missing('db_invoices', 'seller_account_id', 'INT UNSIGNED NULL');
    invoice_modify_column_if_exists('db_invoices', 'document_type', "ENUM('invoice','delivery_note','act') NOT NULL DEFAULT 'invoice'");

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
    invoice_add_column_if_missing('db_invoice_documents', 'document_number', 'VARCHAR(80) NULL');
    invoice_add_column_if_missing('db_invoice_documents', 'download_count', 'INT UNSIGNED NOT NULL DEFAULT 0');
    invoice_add_column_if_missing('db_invoice_documents', 'last_downloaded_at', 'DATETIME NULL');

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
    invoice_add_column_if_missing('db_invoice_items', 'source_product_name', 'VARCHAR(255) NULL');
    invoice_add_column_if_missing('db_invoice_items', 'source_product_sku', 'VARCHAR(120) NULL');
    invoice_add_column_if_missing('db_invoice_items', 'source_offer_id', 'INT UNSIGNED NULL');
    invoice_add_column_if_missing('db_invoice_items', 'source_product_json', 'LONGTEXT NULL');

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
    invoice_add_column_if_missing('db_client_companies', 'assigned_manager_user_id', 'INT UNSIGNED NULL');
    invoice_add_column_if_missing('db_client_companies', 'assigned_manager_keycrm_id', 'INT UNSIGNED NULL');
    invoice_add_column_if_missing('db_client_companies', 'assigned_manager_name', 'VARCHAR(150) NULL');
    invoice_add_column_if_missing('db_client_companies', 'manager_assignment_note', 'TEXT NULL');
    invoice_add_column_if_missing('db_client_companies', 'keycrm_manager_id', 'INT UNSIGNED NULL');
    invoice_add_column_if_missing('db_client_companies', 'keycrm_manager_name', 'VARCHAR(150) NULL');
    invoice_add_index_if_missing('db_client_companies', 'idx_display_name', 'display_name(191)');
    invoice_add_index_if_missing('db_client_companies', 'idx_keycrm_name', 'keycrm_name(191)');
    invoice_add_index_if_missing('db_client_companies', 'idx_keycrm_title', 'keycrm_title(191)');
    invoice_add_index_if_missing('db_client_companies', 'idx_manager_id', 'manager_id');

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
    invoice_add_column_if_missing('db_client_contacts', 'assigned_manager_user_id', 'INT UNSIGNED NULL');
    invoice_add_column_if_missing('db_client_contacts', 'assigned_manager_keycrm_id', 'INT UNSIGNED NULL');
    invoice_add_column_if_missing('db_client_contacts', 'assigned_manager_name', 'VARCHAR(150) NULL');
    invoice_add_column_if_missing('db_client_contacts', 'inherits_company_manager', 'TINYINT(1) NOT NULL DEFAULT 1');
    invoice_add_column_if_missing('db_client_contacts', 'manager_assignment_note', 'TEXT NULL');
    invoice_add_column_if_missing('db_client_contacts', 'keycrm_manager_id', 'INT UNSIGNED NULL');
    invoice_add_column_if_missing('db_client_contacts', 'keycrm_manager_name', 'VARCHAR(150) NULL');
    invoice_add_index_if_missing('db_client_contacts', 'idx_full_name', 'full_name(191)');
    invoice_add_index_if_missing('db_client_contacts', 'idx_email', 'email');
    invoice_add_index_if_missing('db_client_contacts', 'idx_phone', 'phone');

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
    invoice_add_index_if_missing('db_client_legal_entities', 'idx_legal_name', 'legal_name(191)');
    invoice_add_index_if_missing('db_client_legal_entities', 'idx_short_name', 'short_name(191)');
    invoice_add_index_if_missing('db_client_legal_entities', 'idx_edrpou', 'edrpou');

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS db_sync_state (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            sync_key VARCHAR(100) NOT NULL UNIQUE,
            last_successful_sync_at DATETIME NULL,
            last_attempt_at DATETIME NULL,
            status ENUM('idle','running','success','failed') NOT NULL DEFAULT 'idle',
            error_message TEXT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS db_client_sync_runs (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            sync_type VARCHAR(80) NOT NULL,
            started_at DATETIME NOT NULL,
            finished_at DATETIME NULL,
            status ENUM('running','success','failed') NOT NULL DEFAULT 'running',
            records_seen INT UNSIGNED NOT NULL DEFAULT 0,
            companies_upserted INT UNSIGNED NOT NULL DEFAULT 0,
            contacts_upserted INT UNSIGNED NOT NULL DEFAULT 0,
            error_message TEXT NULL,
            created_by_user_id INT UNSIGNED NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_sync_type (sync_type),
            KEY idx_started_at (started_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        INSERT IGNORE INTO db_sync_state (sync_key, status)
        VALUES ('keycrm_companies', 'idle'), ('keycrm_buyers', 'idle')
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
            SELECT 1
            FROM db_our_companies
            WHERE short_name = 'FOP Darchenko A.B.'
               OR edrpou = '3032919108'
               OR tax_code = '3032919108'
            LIMIT 1
        )
    ");
    $stmt->execute();

    seed_our_companies_and_accounts();
}

function ensure_analytics_exclusion_columns(): void
{
    if (invoice_table_exists('db_orders')) {
        invoice_add_column_if_missing('db_orders', 'analytics_excluded', 'TINYINT(1) NOT NULL DEFAULT 0');
        invoice_add_column_if_missing('db_orders', 'analytics_excluded_at', 'DATETIME NULL');
        invoice_add_column_if_missing('db_orders', 'analytics_excluded_by_user_id', 'INT UNSIGNED NULL');
        invoice_add_column_if_missing('db_orders', 'analytics_exclusion_note', 'TEXT NULL');
        invoice_add_index_if_missing('db_orders', 'idx_analytics_excluded', 'analytics_excluded');
    }

    if (invoice_table_exists('db_client_companies')) {
        invoice_add_column_if_missing('db_client_companies', 'keycrm_manager_id', 'INT UNSIGNED NULL');
        invoice_add_column_if_missing('db_client_companies', 'keycrm_manager_name', 'VARCHAR(150) NULL');
        invoice_add_column_if_missing('db_client_companies', 'analytics_excluded', 'TINYINT(1) NOT NULL DEFAULT 0');
        invoice_add_column_if_missing('db_client_companies', 'analytics_excluded_at', 'DATETIME NULL');
        invoice_add_column_if_missing('db_client_companies', 'analytics_excluded_by_user_id', 'INT UNSIGNED NULL');
        invoice_add_column_if_missing('db_client_companies', 'analytics_exclusion_note', 'TEXT NULL');
        invoice_add_index_if_missing('db_client_companies', 'idx_client_analytics_excluded', 'analytics_excluded');
    }

    if (invoice_table_exists('db_client_contacts')) {
        invoice_add_column_if_missing('db_client_contacts', 'analytics_excluded', 'TINYINT(1) NOT NULL DEFAULT 0');
        invoice_add_column_if_missing('db_client_contacts', 'analytics_excluded_at', 'DATETIME NULL');
        invoice_add_column_if_missing('db_client_contacts', 'analytics_excluded_by_user_id', 'INT UNSIGNED NULL');
        invoice_add_column_if_missing('db_client_contacts', 'analytics_exclusion_note', 'TEXT NULL');
        invoice_add_index_if_missing('db_client_contacts', 'idx_contact_analytics_excluded', 'analytics_excluded');
    }
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
        if (function_exists('finance_refresh_columns')) {
            finance_refresh_columns($table);
        }
    }
}

function invoice_table_exists(string $table): bool
{
    $stmt = db()->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table_name
    ");
    $stmt->execute(['table_name' => $table]);

    return (int) $stmt->fetchColumn() > 0;
}

function invoice_modify_column_if_exists(string $table, string $column, string $definition): void
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

    if ((int) $stmt->fetchColumn() > 0) {
        try {
            db()->exec("ALTER TABLE {$table} MODIFY COLUMN {$column} {$definition}");
            if (function_exists('finance_refresh_columns')) {
                finance_refresh_columns($table);
            }
        } catch (PDOException $e) {
            error_log("Could not modify column {$column} on {$table}: " . $e->getMessage());
        }
    }
}

function invoice_add_index_if_missing(string $table, string $index, string $columns): void
{
    $stmt = db()->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table_name
          AND INDEX_NAME = :index_name
    ");
    $stmt->execute([
        'table_name' => $table,
        'index_name' => $index,
    ]);

    if ((int) $stmt->fetchColumn() === 0) {
        try {
            db()->exec("ALTER TABLE {$table} ADD INDEX {$index} ({$columns})");
        } catch (PDOException $e) {
            error_log("Could not add index {$index} on {$table}: " . $e->getMessage());
        }
    }
}

function seed_our_companies_and_accounts(): void
{
    $shared = [
        'address' => 'вул. Машинобудівна, 50А, м. Київ, 03067',
        'email' => 'sales@bws.com.ua',
        'phone' => '(044) 390-72-80, (093) 390-72-80, (097) 390-72-80, (073) 390-72-80',
        'website' => 'bws.com.ua',
        'accountant_email' => 'b@bws.com.ua',
        'accountant_phone' => '(044) 390-72-80, (093) 644-62-61',
    ];

    $companies = [
        [
            'short_name' => 'ФОП Дарченко А.Б.',
            'legal_name' => 'ФОП Дарченко Алла Борисівна',
            'tax_code' => '3032919108',
            'company_type' => 'fop',
            'tax_mode' => 'single_tax_no_vat',
            'single_tax_group' => 2,
            'allowed_item_type' => 'products_only',
            'signer_name' => 'Дарченко А.Б.',
            'signer_position' => 'директор',
            'is_default' => 1,
            'accounts' => [
                ['account_label' => 'ПриватБанк UAH', 'account_type' => 'privat', 'currency' => 'UAH', 'iban' => 'UA873052990000026008015017458', 'bank_name' => 'АТ КБ «ПриватБанк»', 'language' => 'uk', 'is_default' => 1],
                ['account_label' => 'ПриватБанк EUR', 'currency' => 'EUR', 'iban' => 'UA773052990000026006025031307', 'bank_name' => 'JSC CB "PRIVATBANK"', 'bank_address' => '1D HRUSHEVSKOHO STR., KYIV, 01001, UKRAINE', 'swift' => 'PBANUA2X', 'recipient_name' => 'Alla Darchenko, PE (Private Entrepreneur)', 'recipient_address' => '4A Yakuba Kolasa, app. 51, Kyiv, 03087, UA', 'language' => 'en', 'is_default' => 1],
                ['account_label' => 'ПриватБанк USD', 'currency' => 'USD', 'iban' => 'UA413052990000026005025024229', 'bank_name' => 'JSC CB "PRIVATBANK"', 'bank_address' => '1D HRUSHEVSKOHO STR., KYIV, 01001, UKRAINE', 'swift' => 'PBANUA2X', 'recipient_name' => 'Alla Darchenko, PE (Private Entrepreneur)', 'recipient_address' => '4A Yakuba Kolasa, app. 51, Kyiv, 03087, UA', 'language' => 'en', 'is_default' => 1],
                ['account_label' => 'Mono UAH', 'account_type' => 'mono', 'currency' => 'UAH', 'iban' => 'UA983220010000026009340111547', 'bank_name' => 'Monobank', 'language' => 'uk', 'payment_template' => "ФОП Дарченко Алла Борисівна\nIBAN: UA983220010000026009340111547\nЄДРПОУ: 3032919108", 'note' => 'Копіювання реквізитів менеджером'],
            ],
        ],
        [
            'short_name' => 'ФОП Скибало А.О.',
            'legal_name' => 'ФОП Скибало Анна Олександрівна',
            'tax_code' => '2817112883',
            'company_type' => 'fop',
            'tax_mode' => 'single_tax_no_vat',
            'single_tax_group' => 2,
            'allowed_item_type' => 'products_only',
            'signer_name' => 'Скибало А.О.',
            'signer_position' => 'директор',
            'accounts' => [
                ['account_label' => 'ПриватБанк UAH', 'account_type' => 'privat', 'currency' => 'UAH', 'iban' => 'UA393052990000026007035001520', 'bank_name' => 'АТ КБ "ПРИВАТБАНК"', 'language' => 'uk', 'is_default' => 1],
                ['account_label' => 'ПриватБанк EUR', 'currency' => 'EUR', 'iban' => 'UA823052990000026008035023604', 'bank_name' => 'JSC CB "PRIVATBANK"', 'bank_address' => '1D HRUSHEVSKOHO STR., KYIV, 01001, UKRAINE', 'swift' => 'PBANUA2X', 'recipient_name' => 'Skybalo Anna, PE (Private Entrepreneur)', 'recipient_address' => '3 Kolektorna St., app. 38, Kyiv, 01001, UA', 'language' => 'en', 'is_default' => 1],
            ],
        ],
        [
            'short_name' => 'ФОП Дарченко К.М.',
            'legal_name' => 'ФОП Дарченко К.М.',
            'tax_code' => '2046517748',
            'company_type' => 'fop',
            'tax_mode' => 'single_tax_no_vat',
            'single_tax_group' => 2,
            'allowed_item_type' => 'products_only',
            'signer_name' => 'Дарченко К.М.',
            'signer_position' => 'директор',
            'accounts' => [
                ['account_label' => 'ПриватБанк UAH', 'account_type' => 'privat', 'currency' => 'UAH', 'iban' => 'UA843052990000026003005006797', 'bank_name' => 'АТ КБ "ПРИВАТБАНК"', 'language' => 'uk', 'is_default' => 1],
            ],
        ],
        [
            'short_name' => 'ФОП Кубар Т.О.',
            'legal_name' => 'ФОП Кубар Тетяна Олександрівна',
            'tax_code' => '3580408149',
            'company_type' => 'fop',
            'tax_mode' => 'single_tax_no_vat',
            'single_tax_group' => 2,
            'allowed_item_type' => 'products_only',
            'signer_name' => 'Кубар Т.О.',
            'signer_position' => 'директор',
            'accounts' => [
                ['account_label' => 'ПриватБанк UAH', 'account_type' => 'privat', 'currency' => 'UAH', 'iban' => 'UA813052990000026005045041981', 'bank_name' => 'АТ КБ «ПриватБанк»', 'language' => 'uk', 'is_default' => 1],
                ['account_label' => 'ПриватБанк EUR', 'currency' => 'EUR', 'iban' => 'UA523052990000026009015035514', 'bank_name' => 'JSC CB "PRIVATBANK"', 'bank_address' => '1D HRUSHEVSKOHO STR., KYIV, 01001, UKRAINE', 'swift' => 'PBANUA2X', 'recipient_name' => 'Tetyana Kubar, PE (Private Entrepreneur)', 'recipient_address' => '3 Kolektorna St., app. 38, Kyiv, 01001, UA', 'language' => 'en', 'is_default' => 1],
                ['account_label' => 'ПриватБанк USD', 'currency' => 'USD', 'bank_name' => 'JSC CB "PRIVATBANK"', 'swift' => 'PBANUA2X', 'recipient_name' => 'Tetyana Kubar, PE (Private Entrepreneur)', 'recipient_address' => '3 Kolektorna St., app. 38, Kyiv, 01001, UA', 'language' => 'en', 'note' => 'USD IBAN missing, fill later'],
            ],
        ],
        [
            'short_name' => 'ФОП Скибало О.Г.',
            'legal_name' => 'ФОП Скибало О.Г.',
            'tax_code' => '1977900801',
            'company_type' => 'fop',
            'tax_mode' => 'single_tax_no_vat',
            'single_tax_group' => 3,
            'allowed_item_type' => 'services_allowed',
            'signer_name' => 'Скибало О.Г.',
            'signer_position' => 'директор',
            'accounts' => [
                ['account_label' => 'ПриватБанк UAH', 'account_type' => 'privat', 'currency' => 'UAH', 'iban' => 'UA573052990000026000005008549', 'bank_name' => 'АТ КБ "ПРИВАТБАНК"', 'language' => 'uk', 'is_default' => 1],
            ],
        ],
        [
            'short_name' => 'ПП "ВД МЕДIА МIСТ"',
            'legal_name' => 'ПП "ВД МЕДIА МIСТ"',
            'tax_code' => '37210573',
            'company_type' => 'pp',
            'tax_mode' => 'vat_20',
            'allowed_item_type' => 'products_and_services',
            'signer_name' => 'Скибало А.О.',
            'signer_position' => 'директор',
            'accounts' => [
                ['account_label' => 'ПриватБанк UAH', 'account_type' => 'privat', 'currency' => 'UAH', 'iban' => 'UA973052990000026002035019852', 'bank_name' => 'АТ КБ "ПРИВАТБАНК"', 'language' => 'uk', 'is_default' => 1],
            ],
        ],
        [
            'short_name' => 'ТОВ "ШИОНА"',
            'legal_name' => 'ТОВ "ШИОНА"',
            'tax_code' => '32344400',
            'company_type' => 'tov',
            'tax_mode' => 'vat_20',
            'allowed_item_type' => 'products_and_services',
            'signer_name' => 'Дарченко А.Б.',
            'signer_position' => 'директор',
            'accounts' => [
                ['account_label' => 'ПриватБанк UAH', 'account_type' => 'privat', 'currency' => 'UAH', 'iban' => 'UA833052990000026007025005945', 'bank_name' => 'АТ КБ «ПриватБанк»', 'language' => 'uk', 'is_default' => 1],
                ['account_label' => 'ПриватБанк USD', 'currency' => 'USD', 'iban' => 'UA613052990000026007035016838', 'bank_name' => 'JSC CB "PRIVATBANK"', 'bank_address' => '1D HRUSHEVSKOHO STR., KYIV, 01001, UKRAINE', 'swift' => 'PBANUA2X', 'recipient_name' => 'SHEONA LLC', 'recipient_address' => '50A, Mashynobudivna Str., Kyiv, 03067, UA', 'language' => 'en', 'is_default' => 1],
            ],
        ],
        [
            'short_name' => 'ТОВ "ДРУКАРНЯ .БРЕНД"',
            'legal_name' => 'ТОВ "ДРУКАРНЯ .БРЕНД"',
            'tax_code' => '45567881',
            'company_type' => 'tov',
            'tax_mode' => 'vat_20',
            'allowed_item_type' => 'products_and_services',
            'accounts' => [
                ['account_label' => 'Укрсиббанк UAH', 'account_type' => 'bank_account', 'currency' => 'UAH', 'iban' => 'UA083510050000026008879243549', 'bank_name' => 'АТ "УКРСИББАНК"', 'language' => 'uk', 'is_default' => 1],
            ],
        ],
    ];

    foreach ($companies as $company) {
        $companyId = seed_our_company(array_merge($shared, $company));
        foreach ($company['accounts'] as $account) {
            seed_our_company_account($companyId, array_merge([
                'recipient_name' => $company['legal_name'],
                'tax_code' => $company['tax_code'],
            ], $account));
        }
    }
}

function seed_our_company(array $company): int
{
    $stmt = db()->prepare("
        SELECT id
        FROM db_our_companies
        WHERE tax_code = :tax_code
           OR edrpou = :edrpou_search
           OR legal_name = :legal_name
           OR short_name = :short_name
        ORDER BY id ASC
        LIMIT 1
    ");
    $stmt->execute([
        'tax_code' => $company['tax_code'],
        'edrpou_search' => $company['tax_code'],
        'legal_name' => $company['legal_name'],
        'short_name' => $company['short_name'],
    ]);
    $id = (int) ($stmt->fetchColumn() ?: 0);

    if ($id > 0) {
        $stmt = db()->prepare("
            UPDATE db_our_companies
            SET short_name = CASE WHEN tax_code IS NULL OR tax_code = '' THEN :short_name ELSE short_name END,
                legal_name = CASE WHEN tax_code IS NULL OR tax_code = '' THEN :legal_name ELSE legal_name END,
                edrpou = COALESCE(NULLIF(edrpou, ''), :edrpou),
                tax_code = COALESCE(NULLIF(tax_code, ''), :tax_code),
                company_type = CASE WHEN tax_code IS NULL OR tax_code = '' THEN :company_type ELSE company_type END,
                tax_mode = CASE WHEN tax_code IS NULL OR tax_code = '' THEN :tax_mode ELSE tax_mode END,
                single_tax_group = COALESCE(single_tax_group, :single_tax_group),
                allowed_item_type = CASE WHEN tax_code IS NULL OR tax_code = '' THEN :allowed_item_type ELSE allowed_item_type END,
                address = COALESCE(NULLIF(address, ''), :address),
                email = COALESCE(NULLIF(email, ''), :email),
                phone = COALESCE(NULLIF(phone, ''), :phone),
                website = COALESCE(NULLIF(website, ''), :website),
                accountant_email = COALESCE(NULLIF(accountant_email, ''), :accountant_email),
                accountant_phone = COALESCE(NULLIF(accountant_phone, ''), :accountant_phone),
                signer_name = COALESCE(NULLIF(signer_name, ''), :signer_name),
                signer_position = COALESCE(NULLIF(signer_position, ''), :signer_position),
                is_default = GREATEST(is_default, :is_default)
            WHERE id = :id
        ");
        $stmt->execute([
            'short_name' => $company['short_name'],
            'legal_name' => $company['legal_name'],
            'edrpou' => $company['tax_code'],
            'tax_code' => $company['tax_code'],
            'company_type' => $company['company_type'],
            'tax_mode' => $company['tax_mode'],
            'single_tax_group' => $company['single_tax_group'] ?? null,
            'allowed_item_type' => $company['allowed_item_type'],
            'address' => $company['address'],
            'email' => $company['email'],
            'phone' => $company['phone'],
            'website' => $company['website'],
            'accountant_email' => $company['accountant_email'],
            'accountant_phone' => $company['accountant_phone'],
            'signer_name' => $company['signer_name'] ?? null,
            'signer_position' => $company['signer_position'] ?? null,
            'is_default' => (int) ($company['is_default'] ?? 0),
            'id' => $id,
        ]);

        return $id;
    }

    if (invoice_table_exists('db_client_contacts')) {
        invoice_add_column_if_missing('db_client_contacts', 'keycrm_manager_id', 'INT UNSIGNED NULL');
        invoice_add_column_if_missing('db_client_contacts', 'keycrm_manager_name', 'VARCHAR(150) NULL');
    }

    $stmt = db()->prepare("
        INSERT INTO db_our_companies
            (short_name, legal_name, edrpou, tax_code, company_type, tax_mode, single_tax_group,
             allowed_item_type, address, email, phone, website, accountant_email, accountant_phone,
             signer_name, signer_position, is_active, is_default)
        VALUES
            (:short_name, :legal_name, :edrpou, :tax_code, :company_type, :tax_mode, :single_tax_group,
             :allowed_item_type, :address, :email, :phone, :website, :accountant_email, :accountant_phone,
             :signer_name, :signer_position, 1, :is_default)
    ");
    $stmt->execute([
        'short_name' => $company['short_name'],
        'legal_name' => $company['legal_name'],
        'edrpou' => $company['tax_code'],
        'tax_code' => $company['tax_code'],
        'company_type' => $company['company_type'],
        'tax_mode' => $company['tax_mode'],
        'single_tax_group' => $company['single_tax_group'] ?? null,
        'allowed_item_type' => $company['allowed_item_type'],
        'address' => $company['address'],
        'email' => $company['email'],
        'phone' => $company['phone'],
        'website' => $company['website'],
        'accountant_email' => $company['accountant_email'],
        'accountant_phone' => $company['accountant_phone'],
        'signer_name' => $company['signer_name'] ?? null,
        'signer_position' => $company['signer_position'] ?? null,
        'is_default' => (int) ($company['is_default'] ?? 0),
    ]);

    return (int) db()->lastInsertId();
}

function seed_our_company_account(int $companyId, array $account): void
{
    $stmt = db()->prepare("
        SELECT id
        FROM db_our_company_accounts
        WHERE company_id = :company_id
          AND currency = :currency
          AND (iban <=> :iban)
        ORDER BY is_default DESC,
                 CASE WHEN account_label LIKE CONCAT(:currency_prefix, '%') THEN 1 ELSE 0 END,
                 id ASC
        LIMIT 1
    ");
    $stmt->execute([
        'company_id' => $companyId,
        'currency' => $account['currency'],
        'currency_prefix' => $account['currency'],
        'iban' => $account['iban'] ?? null,
    ]);
    $existingId = (int) ($stmt->fetchColumn() ?: 0);
    if ($existingId > 0) {
        $stmt = db()->prepare("
            UPDATE db_our_company_accounts
            SET account_label = CASE WHEN account_label LIKE CONCAT(:currency_prefix, '%') THEN :account_label ELSE account_label END,
                account_type = COALESCE(NULLIF(account_type, ''), :account_type),
                bank_name = COALESCE(NULLIF(bank_name, ''), :bank_name),
                bank_address = COALESCE(NULLIF(bank_address, ''), :bank_address),
                swift = COALESCE(NULLIF(swift, ''), :swift),
                recipient_name = COALESCE(NULLIF(recipient_name, ''), :recipient_name),
                recipient_address = COALESCE(NULLIF(recipient_address, ''), :recipient_address),
                tax_code = COALESCE(NULLIF(tax_code, ''), :tax_code),
                language = COALESCE(NULLIF(language, ''), :language),
                is_default = GREATEST(is_default, :is_default),
                payment_template = COALESCE(NULLIF(payment_template, ''), :payment_template),
                note = COALESCE(NULLIF(note, ''), :note)
            WHERE id = :id
        ");
        $stmt->execute([
            'currency_prefix' => $account['currency'],
            'account_label' => $account['account_label'],
            'account_type' => $account['account_type'] ?? 'bank_account',
            'bank_name' => $account['bank_name'] ?? null,
            'bank_address' => $account['bank_address'] ?? null,
            'swift' => $account['swift'] ?? null,
            'recipient_name' => $account['recipient_name'] ?? null,
            'recipient_address' => $account['recipient_address'] ?? null,
            'tax_code' => $account['tax_code'] ?? null,
            'language' => $account['language'] ?? 'uk',
            'is_default' => (int) ($account['is_default'] ?? 0),
            'payment_template' => $account['payment_template'] ?? null,
            'note' => $account['note'] ?? null,
            'id' => $existingId,
        ]);
        return;
    }

    $stmt = db()->prepare("
        INSERT INTO db_our_company_accounts
            (company_id, account_label, account_type, currency, iban, bank_name, bank_address, swift,
             recipient_name, recipient_address, tax_code, language, is_default, is_active, payment_template, note)
        VALUES
            (:company_id, :account_label, :account_type, :currency, :iban, :bank_name, :bank_address, :swift,
             :recipient_name, :recipient_address, :tax_code, :language, :is_default, 1, :payment_template, :note)
    ");
    $stmt->execute([
        'company_id' => $companyId,
        'account_label' => $account['account_label'],
        'account_type' => $account['account_type'] ?? 'bank_account',
        'currency' => $account['currency'],
        'iban' => ($account['iban'] ?? '') !== '' ? $account['iban'] : null,
        'bank_name' => $account['bank_name'] ?? null,
        'bank_address' => $account['bank_address'] ?? null,
        'swift' => $account['swift'] ?? null,
        'recipient_name' => $account['recipient_name'] ?? null,
        'recipient_address' => $account['recipient_address'] ?? null,
        'tax_code' => $account['tax_code'] ?? null,
        'language' => $account['language'] ?? 'uk',
        'is_default' => (int) ($account['is_default'] ?? 0),
        'payment_template' => $account['payment_template'] ?? null,
        'note' => $account['note'] ?? null,
    ]);
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

function can_edit_our_companies(): bool
{
    return in_array(user_role(), ['ceo', 'accountant'], true);
}

function can_view_payment_requisites(): bool
{
    return in_array(user_role(), ['ceo', 'accountant'], true);
}

function our_companies(bool $activeOnly = true): array
{
    $sql = 'SELECT * FROM db_our_companies';
    if ($activeOnly) {
        $sql .= ' WHERE is_active = 1';
    }
    $sql .= ' ORDER BY is_default DESC, is_active DESC, short_name ASC, id ASC';

    $companies = db()->query($sql)->fetchAll();
    if (!$activeOnly) {
        return $companies;
    }

    $unique = [];
    foreach ($companies as $company) {
        $key = our_company_identity_key($company);
        if (!isset($unique[$key]) || our_company_priority($company) > our_company_priority($unique[$key])) {
            $unique[$key] = $company;
        }
    }

    return array_values($unique);
}

function our_company_identity_key(array $company): string
{
    $taxCode = trim((string) (($company['tax_code'] ?? '') ?: ($company['edrpou'] ?? '')));
    if ($taxCode !== '') {
        return 'tax:' . preg_replace('/\D+/', '', $taxCode);
    }

    return 'id:' . (string) ($company['id'] ?? '');
}

function our_company_priority(array $company): int
{
    $shortName = (string) ($company['short_name'] ?? '');
    $priority = ((int) ($company['is_default'] ?? 0)) * 1000;
    $priority += ((int) ($company['is_active'] ?? 0)) * 100;
    if (trim((string) (($company['tax_code'] ?? '') ?: ($company['edrpou'] ?? ''))) !== '') {
        $priority += 50;
    }
    if (preg_match('/[А-Яа-яІіЇїЄєҐґ]/u', $shortName)) {
        $priority += 25;
    }
    $priority -= (int) ($company['id'] ?? 0);

    return $priority;
}

function our_company_accounts(?int $companyId = null, bool $activeOnly = true, bool $requireIban = false): array
{
    $sql = 'SELECT a.*, c.short_name, c.legal_name, c.tax_code AS company_tax_code, c.edrpou
            FROM db_our_company_accounts a
            LEFT JOIN db_our_companies c ON c.id = a.company_id
            WHERE 1=1';
    $params = [];
    if ($companyId) {
        $sql .= ' AND a.company_id = :company_id';
        $params['company_id'] = $companyId;
    }
    if ($activeOnly) {
        $sql .= ' AND a.is_active = 1';
    }
    if ($requireIban) {
        $sql .= " AND NULLIF(TRIM(a.iban), '') IS NOT NULL";
    }
    $sql .= ' ORDER BY c.is_default DESC, c.short_name ASC, a.currency ASC, a.is_default DESC, a.account_label ASC';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $accounts = $stmt->fetchAll();
    if (!$requireIban) {
        return $accounts;
    }

    $unique = [];
    foreach ($accounts as $account) {
        $key = implode('|', [
            (string) ($account['company_id'] ?? ''),
            strtoupper((string) ($account['currency'] ?? 'UAH')),
            preg_replace('/\s+/', '', strtoupper((string) ($account['iban'] ?? ''))),
        ]);
        if (!isset($unique[$key]) || our_account_priority($account) > our_account_priority($unique[$key])) {
            $unique[$key] = $account;
        }
    }

    return array_values($unique);
}

function our_default_account_id(int $companyId, string $currency = 'UAH'): ?int
{
    $stmt = db()->prepare("
        SELECT id
        FROM db_our_company_accounts
        WHERE company_id = :company_id
          AND currency = :currency
          AND is_active = 1
          AND NULLIF(TRIM(iban), '') IS NOT NULL
        ORDER BY is_default DESC,
                 CASE WHEN account_label LIKE CONCAT(:currency_prefix, '%') THEN 1 ELSE 0 END,
                 id ASC
        LIMIT 1
    ");
    $stmt->execute([
        'company_id' => $companyId,
        'currency' => strtoupper($currency),
        'currency_prefix' => strtoupper($currency),
    ]);
    $id = $stmt->fetchColumn();
    return $id ? (int) $id : null;
}

function our_account_priority(array $account): int
{
    $currency = strtoupper((string) ($account['currency'] ?? ''));
    $label = strtoupper(trim((string) ($account['account_label'] ?? '')));
    $priority = ((int) ($account['is_default'] ?? 0)) * 1000;
    if ($currency !== '' && substr($label, 0, strlen($currency)) === $currency) {
        $priority -= 100;
    }
    $priority -= (int) ($account['id'] ?? 0);

    return $priority;
}

function our_account_label(array $account): string
{
    $currency = strtoupper((string) ($account['currency'] ?? 'UAH'));
    $label = trim((string) ($account['account_label'] ?? ''));
    if ($label !== '' && substr(strtoupper($label), 0, strlen($currency . ' ')) === $currency . ' ') {
        $label = trim(substr($label, strlen($currency)));
    }
    $parts = [
        $label !== '' ? $label : $currency,
        (string) (($account['iban'] ?? '') ?: ($account['bank_name'] ?? '')),
    ];
    return trim(implode(' · ', array_filter($parts, static fn($value) => trim($value) !== '')));
}

function payment_requisites_text(array $company, array $account, string $orderNumber, string $amount, string $language = 'uk'): string
{
    $currency = strtoupper((string) ($account['currency'] ?? 'UAH'));
    $language = $language === 'en' ? 'en' : 'uk';
    $amount = trim($amount);
    $orderNumber = trim($orderNumber);

    if ($language === 'en' || $currency !== 'UAH') {
        return implode("\n", array_filter([
            'Recipient: ' . (($account['recipient_name'] ?? '') ?: ($company['legal_name'] ?? '')),
            !empty($account['iban']) ? 'IBAN: ' . $account['iban'] : '',
            !empty($account['bank_name']) ? 'Bank: ' . $account['bank_name'] : '',
            !empty($account['swift']) ? 'SWIFT: ' . $account['swift'] : '',
            !empty($account['bank_address']) ? 'Bank address: ' . $account['bank_address'] : '',
            !empty($account['recipient_address']) ? 'Address: ' . $account['recipient_address'] : '',
            $orderNumber !== '' ? 'Payment for order № ' . $orderNumber : '',
            $amount !== '' ? 'Amount: ' . $amount . ' ' . $currency : '',
        ], static fn($line) => trim($line) !== ''));
    }

    $template = trim((string) ($account['payment_template'] ?? ''));
    $base = $template !== '' ? $template : implode("\n", array_filter([
        (string) ($company['legal_name'] ?? ''),
        !empty($account['bank_name']) ? 'Банк: ' . $account['bank_name'] : '',
        !empty($account['iban']) ? 'IBAN: ' . $account['iban'] : '',
        !empty($account['tax_code'] ?: ($company['tax_code'] ?? $company['edrpou'] ?? '')) ? 'ЄДРПОУ: ' . (($account['tax_code'] ?? '') ?: (($company['tax_code'] ?? '') ?: ($company['edrpou'] ?? ''))) : '',
    ], static fn($line) => trim($line) !== ''));

    return $base . "\n" . implode("\n", array_filter([
        $orderNumber !== '' ? 'Оплата за замовлення № ' . $orderNumber : '',
        $amount !== '' ? 'Сума: ' . $amount . ' грн' : '',
    ], static fn($line) => trim($line) !== ''));
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
