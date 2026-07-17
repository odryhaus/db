<?php

require_once __DIR__ . '/bootstrap.php';
require_login();

$autoloadPath = __DIR__ . '/vendor/autoload.php';
if (is_file($autoloadPath)) {
    require_once $autoloadPath;
}

if (!can_manage_invoices()) {
    http_response_code(403);
    include __DIR__ . '/partials_forbidden.php';
    exit;
}

ensure_invoice_tables();

$message = '';
$error = '';
$editInvoice = null;
$editItems = [];
$editLegalEntities = [];
$editContacts = [];
$editClientCompany = null;
$companies = [];

function invoice_money($value): string
{
    return number_format((float) $value, 2, ',', ' ') . ' UAH';
}

function invoice_number_value($value): float
{
    return round(max(0, (float) str_replace([' ', ','], ['', '.'], (string) $value)), 2);
}

function invoice_quantity_value($value): float
{
    return round(max(0, (float) str_replace([' ', ','], ['', '.'], (string) $value)), 3);
}

function invoice_date_label(?string $date): string
{
    if (!$date) {
        return date('d.m.Y');
    }

    $time = strtotime($date);
    return $time ? date('d.m.Y', $time) : $date;
}

function invoice_datetime_time_label(?string $datetime): string
{
    $time = $datetime ? strtotime($datetime) : false;
    return $time ? date('H:i', $time) : '';
}

function invoice_ua_date_label(?string $date): string
{
    $time = $date ? strtotime($date) : time();
    if (!$time) {
        return invoice_date_label($date);
    }

    $months = [
        1 => 'січня',
        2 => 'лютого',
        3 => 'березня',
        4 => 'квітня',
        5 => 'травня',
        6 => 'червня',
        7 => 'липня',
        8 => 'серпня',
        9 => 'вересня',
        10 => 'жовтня',
        11 => 'листопада',
        12 => 'грудня',
    ];

    return date('j', $time) . ' ' . $months[(int) date('n', $time)] . ' ' . date('Y', $time);
}

function invoice_status_label(string $status): string
{
    $labels = [
        'draft' => 'Чернетка',
        'sent' => 'Надіслано',
        'paid' => 'Оплачено',
        'docs_sent' => 'Документи надіслано',
        'docs_closed' => 'Документи закрито',
        'canceled' => 'Скасовано',
    ];

    return $labels[$status] ?? $status;
}

function invoice_payment_status_label(string $status): string
{
    $labels = [
        'draft' => 'Чернетка',
        'waiting_payment' => 'Очікуємо оплату',
        'paid' => 'Оплачено',
        'problem' => 'Проблема',
        'canceled' => 'Скасовано',
    ];

    return $labels[$status] ?? $status;
}

function invoice_document_status_label(string $status): string
{
    $labels = [
        'not_sent' => 'Не надіслано',
        'sent' => 'Документи відправлено',
        'closed' => 'Документи закрито',
        'problem' => 'Проблема',
    ];

    return $labels[$status] ?? $status;
}

function invoice_status_badge_html(string $label, string $class): string
{
    return '<span class="status-badge ' . $class . '">' . e($label) . '</span>';
}

function invoice_payment_badge(array $invoice): string
{
    $status = (string) ($invoice['payment_status'] ?? 'draft');
    $class = 'status-badge--muted';
    if ($status === 'paid') {
        $class = 'status-badge--success';
    } elseif ($status === 'waiting_payment') {
        $class = 'status-badge--warning';
    } elseif (in_array($status, ['problem', 'canceled'], true)) {
        $class = 'status-badge--danger';
    }

    return invoice_status_badge_html(invoice_payment_status_label($status), $class);
}

function invoice_document_badge(array $invoice): string
{
    $status = (string) ($invoice['document_status'] ?? 'not_sent');
    $class = 'status-badge--muted';
    if ($status === 'closed') {
        $class = 'status-badge--success';
    } elseif ($status === 'sent') {
        $class = 'status-badge--warning';
    } elseif ($status === 'problem') {
        $class = 'status-badge--danger';
    }

    return invoice_status_badge_html(invoice_document_status_label($status), $class);
}

function invoice_docs_status_label(string $status): string
{
    $labels = [
        'not_sent' => 'Не надіслано',
        'sent' => 'Надіслано',
        'signed' => 'Підписано',
        'closed' => 'Закрито',
        'problem' => 'Проблема',
    ];

    return $labels[$status] ?? $status;
}

function invoice_status_badge(string $status): string
{
    $class = 'status-badge--muted';
    if (in_array($status, ['paid', 'docs_closed'], true)) {
        $class = 'status-badge--success';
    } elseif (in_array($status, ['sent', 'docs_sent'], true)) {
        $class = 'status-badge--warning';
    } elseif ($status === 'canceled') {
        $class = 'status-badge--danger';
    }

    return '<span class="status-badge ' . $class . '">' . e(invoice_status_label($status)) . '</span>';
}

function invoice_docs_badge(string $status): string
{
    $class = 'status-badge--muted';
    if ($status === 'closed' || $status === 'signed') {
        $class = 'status-badge--success';
    } elseif ($status === 'sent') {
        $class = 'status-badge--warning';
    } elseif ($status === 'problem') {
        $class = 'status-badge--danger';
    }

    return '<span class="status-badge ' . $class . '">' . e(invoice_docs_status_label($status)) . '</span>';
}

function invoice_workflow_label(array $invoice): string
{
    $paymentStatus = (string) ($invoice['payment_status'] ?? '');
    $documentStatus = (string) ($invoice['document_status'] ?? '');
    if ($paymentStatus !== '') {
        if ($paymentStatus === 'problem' || $documentStatus === 'problem') {
            return 'Проблема';
        }
        if ($paymentStatus === 'canceled') {
            return 'Скасовано';
        }
        if ($documentStatus === 'closed') {
            return 'Документи закрито';
        }
        if ($documentStatus === 'sent') {
            return 'Документи відправлено';
        }
        return invoice_payment_status_label($paymentStatus);
    }

    $status = (string) ($invoice['status'] ?? 'draft');
    $docsStatus = (string) ($invoice['docs_status'] ?? 'not_sent');

    if ($status === 'canceled') {
        return 'Скасовано';
    }
    if ($docsStatus === 'problem') {
        return 'Проблема';
    }
    if ($status === 'docs_closed' || $docsStatus === 'closed') {
        return 'Документи закрито';
    }
    if ($status === 'docs_sent' || $docsStatus === 'sent') {
        return 'Документи відправлено';
    }
    if ($status === 'paid') {
        return 'Оплачено';
    }
    if ($status === 'sent') {
        return 'Очікуємо оплату';
    }

    return 'Чернетка';
}

function invoice_workflow_badge(array $invoice): string
{
    $label = invoice_workflow_label($invoice);
    $class = 'status-badge--muted';
    if (in_array($label, ['Оплачено', 'Документи закрито'], true)) {
        $class = 'status-badge--success';
    } elseif (in_array($label, ['Очікуємо оплату', 'Документи відправлено'], true)) {
        $class = 'status-badge--warning';
    } elseif (in_array($label, ['Проблема', 'Скасовано'], true)) {
        $class = 'status-badge--danger';
    }

    return '<span class="status-badge ' . $class . '">' . e($label) . '</span>';
}

function invoice_expected_due_timestamp(array $invoice): ?int
{
    $dueDate = (string) (($invoice['payment_due_date'] ?? '') ?: ($invoice['expected_payment_date'] ?? ''));
    if ($dueDate !== '') {
        $time = strtotime($dueDate);
        return $time ?: null;
    }

    $baseDate = (string) (($invoice['sent_at'] ?? '') ?: (($invoice['invoice_date'] ?? '') ?: date('Y-m-d')));
    $time = strtotime($baseDate . ' +3 days');
    return $time ?: null;
}

function invoice_is_overdue(array $invoice): bool
{
    $paymentStatus = (string) ($invoice['payment_status'] ?? '');
    if ($paymentStatus !== '' && !in_array($paymentStatus, ['waiting_payment', 'problem'], true)) {
        return false;
    }
    if ($paymentStatus === '' && (string) ($invoice['status'] ?? '') !== 'sent') {
        return false;
    }

    $dueTime = invoice_expected_due_timestamp($invoice);
    return $dueTime !== null && $dueTime < strtotime('today');
}

function invoice_payment_control_label(array $invoice): string
{
    $status = (string) (($invoice['payment_status'] ?? '') ?: ($invoice['status'] ?? 'draft'));
    $docsStatus = (string) (($invoice['document_status'] ?? '') ?: ($invoice['docs_status'] ?? 'not_sent'));

    if ($docsStatus === 'problem') {
        return '<span class="status-badge status-badge--danger">потрібна дія</span>';
    }
    if ($status === 'problem' && empty($invoice['payment_due_date']) && empty($invoice['expected_payment_date'])) {
        return '<span class="status-badge status-badge--danger">потрібна дія</span>';
    }
    if ($status === 'canceled') {
        return '<span class="status-badge status-badge--muted">скасовано</span>';
    }
    if ($status === 'paid' || $status === 'docs_sent' || $status === 'docs_closed') {
        $paidAt = !empty($invoice['paid_at']) ? invoice_date_label((string) $invoice['paid_at']) : '';
        return '<span class="status-badge status-badge--success">оплачено' . ($paidAt !== '' ? ' ' . e($paidAt) : '') . '</span>';
    }
    if ($status === 'sent' || $status === 'waiting_payment' || $status === 'problem') {
        $dueTime = invoice_expected_due_timestamp($invoice);
        if ($dueTime) {
            $class = $dueTime < strtotime('today') ? 'status-badge--danger' : 'status-badge--warning';
            return '<span class="status-badge ' . $class . '">' . e(date('d.m.Y', $dueTime)) . '</span>';
        }
    }

    return '<span class="status-badge status-badge--muted">не відправлено</span>';
}

function invoice_deadline_class(array $invoice): string
{
    $status = (string) (($invoice['payment_status'] ?? '') ?: ($invoice['status'] ?? 'draft'));
    if ($status === 'paid') {
        return 'deadline-date--paid';
    }
    if ($status === 'problem') {
        return 'deadline-date--overdue';
    }
    if (invoice_is_overdue($invoice)) {
        return 'deadline-date--overdue';
    }
    if ($status === 'waiting_payment' || $status === 'sent') {
        return 'deadline-date--waiting';
    }

    return 'deadline-date--muted';
}

function invoice_get_path(array $data, array $paths)
{
    foreach ($paths as $path) {
        $current = $data;
        foreach (explode('.', $path) as $part) {
            if (!is_array($current) || !array_key_exists($part, $current)) {
                $current = null;
                break;
            }
            $current = $current[$part];
        }
        if (is_scalar($current) && $current !== '') {
            return $current;
        }
    }

    return null;
}

function invoice_safe_item_title(string $title, array $seller): string
{
    $title = trim($title);
    if ($title === '') {
        $title = 'Поліграфічна продукція';
    }

    if (($seller['allowed_item_type'] ?? 'products_only') === 'products_only') {
        $title = preg_replace('/послуг\p{L}*|сервіс\p{L}*|services?/iu', 'продукція', $title) ?: $title;
    }

    return substr($title, 0, 255);
}

function invoice_safe_file_number(string $number): string
{
    $safe = preg_replace('/[^A-Za-z0-9_-]+/', '_', trim($number));
    $safe = trim((string) $safe, '_-');
    return $safe !== '' ? $safe : 'document';
}

function invoice_pdf_available(array $invoice): bool
{
    return !empty($invoice['pdf_file_path'])
        && strtolower(pathinfo((string) $invoice['pdf_file_path'], PATHINFO_EXTENSION)) === 'pdf';
}

function invoice_print_template_available(array $invoice): bool
{
    return !empty($invoice['pdf_file_path'])
        && strtolower(pathinfo((string) $invoice['pdf_file_path'], PATHINFO_EXTENSION)) === 'html';
}

function invoice_document_type_label(string $type): string
{
    $labels = [
        'invoice' => 'Рахунок',
        'delivery_note' => 'Видаткова',
        'act' => 'Акт',
    ];

    return $labels[$type] ?? $type;
}

function invoice_document_short_label(string $type): string
{
    $labels = [
        'invoice' => 'PDF',
        'delivery_note' => 'Видаткова',
        'act' => 'Акт',
    ];

    return $labels[$type] ?? 'PDF';
}

function invoice_document_prefix_label(string $type): string
{
    $labels = [
        'invoice' => 'PDF',
        'delivery_note' => 'Накл.',
        'act' => 'Акт',
    ];

    return $labels[$type] ?? 'PDF';
}

function invoice_seller_allows_act(array $invoiceOrSeller): bool
{
    return (string) ($invoiceOrSeller['allowed_item_type'] ?? 'products_only') !== 'products_only';
}

function invoice_document_types_for_seller(array $invoiceOrSeller): array
{
    $types = [
        'invoice' => 'Рахунок',
        'delivery_note' => 'Видаткова',
    ];
    if (invoice_seller_allows_act($invoiceOrSeller)) {
        $types['act'] = 'Акт';
    }

    return $types;
}

function invoice_keycrm_fetch_buyer(int $buyerId): array
{
    $apiKey = trim((string) app_config('keycrm.api_key', ''));
    $baseUrl = rtrim((string) app_config('keycrm.base_url', 'https://openapi.keycrm.app/v1'), '/');
    if ($buyerId <= 0 || $apiKey === '' || $apiKey === 'CHANGE_ME_IN_REAL_CONFIG' || !function_exists('curl_init')) {
        return [];
    }

    $url = $baseUrl . '/buyer/' . $buyerId . '?include=company';
    $ch = curl_init($url);
    if (!$ch) {
        return [];
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Accept: application/json',
        ],
    ]);
    $response = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status < 200 || $status >= 300 || !is_string($response) || $response === '') {
        return [];
    }

    $decoded = json_decode($response, true);
    if (is_array($decoded) && is_array($decoded['data'] ?? null)) {
        return $decoded['data'];
    }
    return is_array($decoded) ? $decoded : [];
}

function invoice_order_payload(array $dbOrder): array
{
    $raw = json_decode((string) ($dbOrder['raw_json'] ?? ''), true);
    $raw = is_array($raw) ? $raw : [];
    $buyer = is_array($raw['buyer'] ?? null) ? $raw['buyer'] : [];
    if (!is_array($buyer['company'] ?? null)) {
        $fetchedBuyer = invoice_keycrm_fetch_buyer((int) (($dbOrder['buyer_id'] ?? 0) ?: ($buyer['id'] ?? 0)));
        if ($fetchedBuyer) {
            $buyer = array_replace_recursive($buyer, $fetchedBuyer);
        }
    }
    $buyerCompany = is_array($buyer['company'] ?? null) ? $buyer['company'] : [];
    $company = is_array($raw['company'] ?? null) ? $raw['company'] : $buyerCompany;
    $products = is_array($raw['products'] ?? null) ? $raw['products'] : [];

    $companyTitle = trim((string) (($company['title'] ?? '') ?: ($company['full_name'] ?? '')));
    $companyName = trim((string) (($dbOrder['company_name'] ?? '') ?: ($company['name'] ?? '')));
    $contactName = trim((string) (($dbOrder['buyer_name'] ?? '') ?: ($buyer['full_name'] ?? ($buyer['name'] ?? ($dbOrder['client_name'] ?? '')))));
    $recipientName = $companyTitle !== '' ? $companyTitle : $companyName;
    $companyId = (int) (($dbOrder['company_id'] ?? 0)
        ?: ($buyer['company_id'] ?? 0)
        ?: ($company['id'] ?? 0));
    $buyerEmail = trim((string) (($dbOrder['buyer_email'] ?? '') ?: ($buyer['email'] ?? '')));
    $buyerPhone = trim((string) (($dbOrder['buyer_phone'] ?? '') ?: ($buyer['phone'] ?? '')));
    $recipientEmail = trim((string) (($company['email'] ?? '') ?: $buyerEmail));
    $recipientPhone = trim((string) (($company['phone'] ?? '') ?: $buyerPhone));
    $recipientAddress = trim((string) (($company['address'] ?? '') ?: ($company['legal_address'] ?? '')));

    return [
        'buyer_id' => (int) (($dbOrder['buyer_id'] ?? 0) ?: ($buyer['id'] ?? 0)),
        'buyer_company_id' => $companyId,
        'buyer_display_name' => $recipientName,
        'buyer_contact_name' => $contactName,
        'recipient_legal_name' => $recipientName,
        'recipient_short_name' => $companyName !== '' ? $companyName : $recipientName,
        'recipient_edrpou' => (string) invoice_get_path(['company' => $company, 'buyer' => $buyer], ['company.edrpou', 'company.code', 'buyer.edrpou', 'buyer.code']),
        'recipient_tax_number' => (string) invoice_get_path(['company' => $company, 'buyer' => $buyer], ['company.tax_number', 'company.tax_id', 'buyer.tax_number']),
        'recipient_legal_address' => $recipientAddress,
        'recipient_email' => $recipientEmail,
        'recipient_phone' => $recipientPhone,
        'contact_name' => $contactName,
        'contact_email' => $buyerEmail,
        'contact_phone' => $buyerPhone,
        'company_name' => $companyName,
        'company_title' => $companyTitle,
        'company_raw_json' => $company ? json_encode($company, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        'buyer_raw_json' => $buyer ? json_encode($buyer, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        'manager_id' => (int) (($dbOrder['manager_id'] ?? 0) ?: invoice_get_path($raw, ['manager.id'])),
        'buyer_edrpou' => (string) invoice_get_path(['company' => $company, 'buyer' => $buyer], ['company.edrpou', 'company.code', 'buyer.edrpou', 'buyer.code']),
        'buyer_address' => $recipientAddress,
        'buyer_email' => $recipientEmail,
        'buyer_phone' => $recipientPhone,
        'total_amount_uah' => invoice_number_value($dbOrder['total_amount_uah'] ?? invoice_get_path($raw, ['grand_total', 'total'])),
        'products' => $products,
        'order_number' => (string) (($dbOrder['order_number'] ?? '') ?: ($dbOrder['keycrm_id'] ?? '')),
    ];
}

function invoice_store_client_snapshot(array $payload): array
{
    $clientCompanyId = null;
    $contactId = null;
    $keycrmCompanyId = (int) ($payload['buyer_company_id'] ?? 0);

    if ($keycrmCompanyId > 0 || (string) ($payload['company_name'] ?? '') !== '' || (string) ($payload['company_title'] ?? '') !== '') {
        if ($keycrmCompanyId > 0) {
            $stmt = db()->prepare('SELECT id FROM db_client_companies WHERE keycrm_company_id = :keycrm_company_id ORDER BY id DESC LIMIT 1');
            $stmt->execute(['keycrm_company_id' => $keycrmCompanyId]);
            $clientCompanyId = $stmt->fetchColumn() ?: null;
        }

        if ($clientCompanyId) {
            $stmt = db()->prepare("
                UPDATE db_client_companies
                SET display_name = :display_name,
                    keycrm_name = :keycrm_name,
                    keycrm_title = :keycrm_title,
                    name = :name,
                    title = :title,
                    manager_id = :manager_id,
                    raw_json = :raw_json,
                    synced_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([
                'display_name' => ($payload['company_name'] ?: $payload['company_title']) ?: null,
                'keycrm_name' => $payload['company_name'] ?: null,
                'keycrm_title' => $payload['company_title'] ?: null,
                'name' => $payload['company_name'] ?: null,
                'title' => $payload['company_title'] ?: null,
                'manager_id' => !empty($payload['manager_id']) ? (int) $payload['manager_id'] : null,
                'raw_json' => $payload['company_raw_json'] ?: null,
                'id' => (int) $clientCompanyId,
            ]);
        } else {
            $stmt = db()->prepare("
                INSERT INTO db_client_companies
                    (keycrm_company_id, display_name, keycrm_name, keycrm_title, name, title, manager_id, raw_json, synced_at)
                VALUES
                    (:keycrm_company_id, :display_name, :keycrm_name, :keycrm_title, :name, :title, :manager_id, :raw_json, NOW())
            ");
            $stmt->execute([
                'keycrm_company_id' => $keycrmCompanyId > 0 ? $keycrmCompanyId : null,
                'display_name' => ($payload['company_name'] ?: $payload['company_title']) ?: null,
                'keycrm_name' => $payload['company_name'] ?: null,
                'keycrm_title' => $payload['company_title'] ?: null,
                'name' => $payload['company_name'] ?: null,
                'title' => $payload['company_title'] ?: null,
                'manager_id' => !empty($payload['manager_id']) ? (int) $payload['manager_id'] : null,
                'raw_json' => $payload['company_raw_json'] ?: null,
            ]);
            $clientCompanyId = (int) db()->lastInsertId();
        }
    }

    $keycrmBuyerId = (int) ($payload['buyer_id'] ?? 0);
    if ($keycrmBuyerId > 0 || (string) ($payload['buyer_contact_name'] ?? '') !== '') {
        if ($keycrmBuyerId > 0) {
            $stmt = db()->prepare('SELECT id FROM db_client_contacts WHERE keycrm_buyer_id = :keycrm_buyer_id ORDER BY id DESC LIMIT 1');
            $stmt->execute(['keycrm_buyer_id' => $keycrmBuyerId]);
            $contactId = $stmt->fetchColumn() ?: null;
        }

        if ($contactId) {
            $stmt = db()->prepare("
                UPDATE db_client_contacts
                SET client_company_id = :client_company_id,
                    full_name = :full_name,
                    email = :email,
                    phone = :phone,
                    raw_json = :raw_json,
                    synced_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([
                'client_company_id' => $clientCompanyId ?: null,
                'full_name' => $payload['buyer_contact_name'] ?: null,
                'email' => ($payload['contact_email'] ?? '') ?: null,
                'phone' => ($payload['contact_phone'] ?? '') ?: null,
                'raw_json' => $payload['buyer_raw_json'] ?: null,
                'id' => (int) $contactId,
            ]);
        } else {
            $stmt = db()->prepare("
                INSERT INTO db_client_contacts
                    (keycrm_buyer_id, client_company_id, full_name, email, phone, raw_json, synced_at)
                VALUES
                    (:keycrm_buyer_id, :client_company_id, :full_name, :email, :phone, :raw_json, NOW())
            ");
            $stmt->execute([
                'keycrm_buyer_id' => $keycrmBuyerId > 0 ? $keycrmBuyerId : null,
                'client_company_id' => $clientCompanyId ?: null,
                'full_name' => $payload['buyer_contact_name'] ?: null,
                'email' => ($payload['contact_email'] ?? '') ?: null,
                'phone' => ($payload['contact_phone'] ?? '') ?: null,
                'raw_json' => $payload['buyer_raw_json'] ?: null,
            ]);
            $contactId = (int) db()->lastInsertId();
        }
    }

    return [
        'client_company_id' => $clientCompanyId ? (int) $clientCompanyId : null,
        'contact_id' => $contactId ? (int) $contactId : null,
    ];
}

function invoice_product_items(array $products, float $fallbackTotal, array $seller): array
{
    $items = [];
    $sort = 1;

    foreach ($products as $product) {
        if (!is_array($product)) {
            continue;
        }

        $quantity = invoice_quantity_value($product['product_quantity'] ?? ($product['quantity'] ?? 1));
        if ($quantity <= 0) {
            $quantity = 1;
        }
        $price = invoice_number_value($product['price_sold'] ?? ($product['price'] ?? ($product['price_uah'] ?? 0)));
        $amount = invoice_number_value($product['total'] ?? ($product['amount'] ?? ($quantity * $price)));
        if ($price <= 0 && $quantity > 0 && $amount > 0) {
            $price = round($amount / $quantity, 2);
        }
        if ($amount <= 0) {
            $amount = round($quantity * $price, 2);
        }

        $title = (string) (($product['product_name'] ?? '') ?: ($product['name'] ?? ($product['title'] ?? '')));
        $offer = is_array($product['offer'] ?? null) ? $product['offer'] : [];
        $items[] = [
            'source_product_id' => (int) ($product['id'] ?? ($product['product_id'] ?? 0)),
            'source_product_name' => $title !== '' ? $title : null,
            'source_product_sku' => (string) (($product['sku'] ?? '') ?: ($offer['sku'] ?? '')),
            'source_offer_id' => (int) (($product['offer_id'] ?? 0) ?: ($offer['id'] ?? 0)),
            'source_product_json' => json_encode($product, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'item_type' => 'product',
            'title' => invoice_safe_item_title($title, $seller),
            'unit' => substr((string) (($product['unit'] ?? '') ?: 'шт'), 0, 30),
            'quantity' => $quantity,
            'price_uah' => $price,
            'amount_uah' => $amount,
            'sort_order' => $sort++,
        ];
    }

    if (!$items) {
        $items[] = invoice_collapsed_item($fallbackTotal, $seller);
    }

    return $items;
}

function invoice_collapsed_item(float $total, array $seller, string $title = ''): array
{
    $defaultTitle = (($seller['allowed_item_type'] ?? 'products_only') === 'products_only')
        ? 'Поліграфічна продукція'
        : 'Поліграфічні роботи';

    return [
        'source_product_id' => null,
        'source_product_name' => null,
        'source_product_sku' => null,
        'source_offer_id' => null,
        'source_product_json' => null,
        'item_type' => (($seller['allowed_item_type'] ?? 'products_only') === 'products_only') ? 'product' : 'service',
        'title' => invoice_safe_item_title($title !== '' ? $title : $defaultTitle, $seller),
        'unit' => 'шт',
        'quantity' => 1,
        'price_uah' => $total,
        'amount_uah' => $total,
        'sort_order' => 1,
    ];
}

function invoice_insert_items(PDO $pdo, int $invoiceId, array $items): void
{
    $stmt = $pdo->prepare("
        INSERT INTO db_invoice_items
            (invoice_id, source_product_id, source_product_name, source_product_sku, source_offer_id, source_product_json,
             title, unit, quantity, price_uah, amount_uah, item_type, sort_order)
        VALUES
            (:invoice_id, :source_product_id, :source_product_name, :source_product_sku, :source_offer_id, :source_product_json,
             :title, :unit, :quantity, :price_uah, :amount_uah, :item_type, :sort_order)
    ");

    foreach ($items as $index => $item) {
        $stmt->execute([
            'invoice_id' => $invoiceId,
            'source_product_id' => $item['source_product_id'] ?: null,
            'source_product_name' => $item['source_product_name'] ?? null,
            'source_product_sku' => ($item['source_product_sku'] ?? '') !== '' ? $item['source_product_sku'] : null,
            'source_offer_id' => !empty($item['source_offer_id']) ? (int) $item['source_offer_id'] : null,
            'source_product_json' => $item['source_product_json'] ?? null,
            'title' => (string) $item['title'],
            'unit' => (string) ($item['unit'] ?: 'шт'),
            'quantity' => invoice_quantity_value($item['quantity'] ?? 1),
            'price_uah' => invoice_number_value($item['price_uah'] ?? 0),
            'amount_uah' => invoice_number_value($item['amount_uah'] ?? 0),
            'item_type' => in_array((string) ($item['item_type'] ?? 'product'), ['product', 'service', 'mixed', 'other'], true) ? (string) ($item['item_type'] ?? 'product') : 'product',
            'sort_order' => (int) ($item['sort_order'] ?? ($index + 1)),
        ]);
    }
}

function invoice_load(int $id): ?array
{
    $stmt = db()->prepare("
        SELECT i.*,
               c.short_name,
               c.legal_name,
               COALESCE(NULLIF(c.tax_code, ''), c.edrpou) AS edrpou,
               COALESCE(NULLIF(selected_account.iban, ''), NULLIF(default_account.iban, ''), c.iban) AS iban,
               COALESCE(NULLIF(selected_account.bank_name, ''), NULLIF(default_account.bank_name, ''), c.bank) AS bank,
               COALESCE(NULLIF(selected_account.account_label, ''), NULLIF(default_account.account_label, '')) AS account_label,
               COALESCE(NULLIF(selected_account.currency, ''), NULLIF(default_account.currency, ''), 'UAH') AS account_currency,
               COALESCE(NULLIF(selected_account.language, ''), NULLIF(default_account.language, ''), 'uk') AS account_language,
               COALESCE(NULLIF(selected_account.swift, ''), NULLIF(default_account.swift, '')) AS swift,
               COALESCE(NULLIF(selected_account.bank_address, ''), NULLIF(default_account.bank_address, '')) AS bank_address,
               COALESCE(NULLIF(selected_account.recipient_name, ''), NULLIF(default_account.recipient_name, ''), c.legal_name) AS account_recipient_name,
               COALESCE(NULLIF(selected_account.recipient_address, ''), NULLIF(default_account.recipient_address, '')) AS account_recipient_address,
               c.address, c.email, c.phone, c.website,
               c.accountant_email, c.accountant_phone, c.tax_mode, c.allowed_item_type,
               c.company_type, c.single_tax_group, c.signer_name, c.signer_position
        FROM db_invoices i
        LEFT JOIN db_our_companies c ON c.id = i.seller_company_id
        LEFT JOIN db_our_company_accounts selected_account ON selected_account.id = i.seller_account_id
        LEFT JOIN db_our_company_accounts default_account
               ON default_account.company_id = i.seller_company_id
              AND default_account.currency = 'UAH'
              AND default_account.is_default = 1
              AND default_account.is_active = 1
              AND NULLIF(TRIM(default_account.iban), '') IS NOT NULL
        WHERE i.id = :id
        LIMIT 1
    ");
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function invoice_items(int $invoiceId): array
{
    $stmt = db()->prepare('SELECT * FROM db_invoice_items WHERE invoice_id = :invoice_id ORDER BY sort_order ASC, id ASC');
    $stmt->execute(['invoice_id' => $invoiceId]);
    return $stmt->fetchAll();
}

function invoice_account_id_for_seller(int $sellerId, int $accountId, string $currency = 'UAH'): ?int
{
    if ($sellerId <= 0) {
        return null;
    }
    if ($accountId > 0) {
        $stmt = db()->prepare("
            SELECT id
            FROM db_our_company_accounts
            WHERE id = :id
              AND company_id = :company_id
              AND currency = :currency
              AND is_active = 1
              AND NULLIF(TRIM(iban), '') IS NOT NULL
            LIMIT 1
        ");
        $stmt->execute([
            'id' => $accountId,
            'company_id' => $sellerId,
            'currency' => strtoupper($currency),
        ]);
        $id = $stmt->fetchColumn();
        if ($id) {
            return (int) $id;
        }
    }

    return our_default_account_id($sellerId, $currency);
}

function invoice_legal_entities(?int $clientCompanyId): array
{
    if (!$clientCompanyId) {
        return [];
    }

    $stmt = db()->prepare("
        SELECT *
        FROM db_client_legal_entities
        WHERE client_company_id = :client_company_id
        ORDER BY is_default DESC, legal_name ASC, id DESC
    ");
    $stmt->execute(['client_company_id' => $clientCompanyId]);
    return $stmt->fetchAll();
}

function invoice_client_companies(): array
{
    return db()->query("
        SELECT *
        FROM db_client_companies
        ORDER BY COALESCE(display_name, title, name) ASC, id DESC
        LIMIT 500
    ")->fetchAll();
}

function invoice_client_company_by_id(?int $id): ?array
{
    if (!$id) {
        return null;
    }

    $stmt = db()->prepare('SELECT * FROM db_client_companies WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function invoice_client_company_label(?array $company): string
{
    if (!$company) {
        return '';
    }

    return (string) (($company['keycrm_name'] ?? '') ?: (($company['name'] ?? '') ?: (($company['display_name'] ?? '') ?: (($company['keycrm_title'] ?? '') ?: ($company['title'] ?? '')))));
}

function invoice_contacts(?int $clientCompanyId): array
{
    if (!$clientCompanyId) {
        return [];
    }

    $stmt = db()->prepare("
        SELECT *
        FROM db_client_contacts
        WHERE client_company_id = :client_company_id
        ORDER BY full_name ASC, id DESC
    ");
    $stmt->execute(['client_company_id' => $clientCompanyId]);
    return $stmt->fetchAll();
}

function invoice_default_legal_entity(?int $clientCompanyId): ?array
{
    if (!$clientCompanyId) {
        return null;
    }

    $stmt = db()->prepare("
        SELECT *
        FROM db_client_legal_entities
        WHERE client_company_id = :client_company_id
        ORDER BY is_default DESC, id DESC
        LIMIT 1
    ");
    $stmt->execute(['client_company_id' => $clientCompanyId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function invoice_legal_entity_by_id(int $id): ?array
{
    if ($id <= 0) {
        return null;
    }

    $stmt = db()->prepare('SELECT * FROM db_client_legal_entities WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function invoice_contact_by_id(int $id): ?array
{
    if ($id <= 0) {
        return null;
    }

    $stmt = db()->prepare('SELECT * FROM db_client_contacts WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function invoice_recipient_name(array $invoice): string
{
    return trim((string) (($invoice['recipient_legal_name'] ?? '') ?: ($invoice['buyer_display_name'] ?? '')));
}

function invoice_contact_name(array $invoice): string
{
    return trim((string) (($invoice['contact_name'] ?? '') ?: ($invoice['buyer_contact_name'] ?? '')));
}

function invoice_document_html(array $invoice, array $items, string $documentType): string
{
    $isDelivery = $documentType === 'delivery_note';
    $isAct = $documentType === 'act';
    $title = $isAct ? 'АКТ' : ($isDelivery ? 'ВИДАТКОВА НАКЛАДНА' : 'РАХУНОК НА ОПЛАТУ');
    $seller = (string) ($invoice['legal_name'] ?: $invoice['short_name']);
    $buyer = invoice_recipient_name($invoice);
    if ($buyer === '') {
        $buyer = 'Одержувач не підтягнувся';
    }
    $contact = invoice_contact_name($invoice);
    $total = (float) ($invoice['total_with_vat_uah'] ?: $invoice['total_amount_uah']);

    ob_start();
    ?>
<!doctype html>
<html lang="uk">
<head>
    <meta charset="utf-8">
    <title><?= e($title . ' № ' . $invoice['invoice_number']) ?></title>
    <style>
        @page { size: A4; margin: 14mm 13mm; }
        body { margin: 0; color: #111; font-family: DejaVu Sans, Arial, sans-serif; font-size: 10px; line-height: 1.32; }
        .topline { width: 100%; margin-bottom: 11px; border-bottom: 2px solid #111; }
        .topline td { border: 0; padding: 0 0 8px; vertical-align: bottom; }
        .brand { font-size: 22px; font-weight: 800; letter-spacing: .03em; }
        .muted { color: #555; }
        .tax-note { text-align: right; font-weight: 700; }
        .party-table { width: 100%; margin-bottom: 16px; border-collapse: collapse; }
        .party-table td { width: 50%; border: 0; padding: 0 10px 0 0; vertical-align: top; }
        .party-table td + td { padding: 0 0 0 10px; }
        .party-title { margin-bottom: 4px; font-size: 9px; color: #666; text-transform: uppercase; letter-spacing: .04em; }
        .party-name { margin-bottom: 4px; font-size: 11px; font-weight: 700; }
        .party-line { margin-bottom: 2px; }
        h1 { margin: 12px 0 4px; text-align: center; font-size: 16px; letter-spacing: .04em; }
        .date { text-align: center; margin-bottom: 14px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #d8dde5; padding: 5px 6px; vertical-align: top; }
        th { background: #f3f5f7; font-size: 9px; text-transform: uppercase; }
        .num { text-align: right; white-space: nowrap; }
        .center { text-align: center; }
        .total-row td { font-weight: 700; }
        .footer { margin-top: 16px; }
        .footer p { margin: 0 0 5px; }
        .signatures { width: 100%; margin-top: 38px; border-collapse: collapse; }
        .signatures td { width: 50%; border: 0; padding: 0 32px 0 0; }
        .signatures td + td { padding: 0 0 0 32px; }
        .line { border-bottom: 1px solid #111; height: 28px; margin-bottom: 6px; }
    </style>
</head>
<body>
    <table class="topline">
        <tr>
            <td>
                <div class="brand">.BRAND</div>
                <div class="muted">bws.com.ua</div>
            </td>
            <td class="tax-note">Без ПДВ. Платник єдиного податку.</td>
        </tr>
    </table>

    <table class="party-table">
        <tr>
            <td>
                <div class="party-title">Постачальник</div>
                <div class="party-name"><?= e($seller) ?></div>
                <div class="party-line">IBAN: <?= e((string) $invoice['iban']) ?></div>
                <div class="party-line">ЄДРПОУ: <?= e((string) $invoice['edrpou']) ?></div>
                <div class="party-line">Банк: <?= e((string) $invoice['bank']) ?></div>
                <div class="party-line">Адреса: <?= e((string) $invoice['address']) ?></div>
                <div class="party-line">E-mail: <?= e((string) $invoice['email']) ?></div>
                <div class="party-line">Офіс: <?= e((string) $invoice['phone']) ?></div>
                <div class="party-line">Бухгалтерія: <?= e((string) $invoice['accountant_email']) ?><?= !empty($invoice['accountant_phone']) ? ', ' . e((string) $invoice['accountant_phone']) : '' ?></div>
            </td>
            <td>
                <div class="party-title">Одержувач</div>
                <div class="party-name"><?= e($buyer) ?></div>
                <?php if ($contact !== ''): ?><div class="party-line">Контактна особа: <?= e($contact) ?></div><?php endif; ?>
                <?php if (!empty($invoice['recipient_edrpou'] ?: $invoice['buyer_edrpou'])): ?><div class="party-line">ЄДРПОУ: <?= e((string) (($invoice['recipient_edrpou'] ?? '') ?: $invoice['buyer_edrpou'])) ?></div><?php endif; ?>
                <?php if (!empty($invoice['recipient_tax_number'])): ?><div class="party-line">ІПН: <?= e((string) $invoice['recipient_tax_number']) ?></div><?php endif; ?>
                <?php if (!empty($invoice['recipient_legal_address'] ?: $invoice['buyer_address'])): ?><div class="party-line">Адреса: <?= e((string) (($invoice['recipient_legal_address'] ?? '') ?: $invoice['buyer_address'])) ?></div><?php endif; ?>
                <?php if (!empty($invoice['recipient_email'] ?: $invoice['buyer_email'])): ?><div class="party-line">E-mail: <?= e((string) (($invoice['recipient_email'] ?? '') ?: $invoice['buyer_email'])) ?></div><?php endif; ?>
                <?php if (!empty($invoice['recipient_phone'] ?: $invoice['buyer_phone'])): ?><div class="party-line">Тел.: <?= e((string) (($invoice['recipient_phone'] ?? '') ?: $invoice['buyer_phone'])) ?></div><?php endif; ?>
                <?php if ($isDelivery): ?><div class="party-line">Платник: той самий</div><div class="party-line">Умова продажу: безготівковий розрахунок</div><?php endif; ?>
            </td>
        </tr>
    </table>

    <h1><?= e($title) ?> № <?= e((string) $invoice['invoice_number']) ?></h1>
    <div class="date">від <?= e(invoice_ua_date_label((string) $invoice['invoice_date'])) ?></div>

    <table>
        <thead>
            <tr>
                <th class="center">№</th>
                <th>Найменування</th>
                <th class="center">Од. вим</th>
                <th class="num">К-сть</th>
                <th class="num">Ціна</th>
                <th class="num">Разом</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($items as $index => $item): ?>
                <tr>
                    <td class="center"><?= e((string) ($index + 1)) ?></td>
                    <td><?= e((string) $item['title']) ?></td>
                    <td class="center"><?= e((string) $item['unit']) ?></td>
                    <td class="num"><?= e(number_format((float) $item['quantity'], 3, ',', ' ')) ?></td>
                    <td class="num"><?= e(number_format((float) $item['price_uah'], 2, ',', ' ')) ?></td>
                    <td class="num"><?= e(number_format((float) $item['amount_uah'], 2, ',', ' ')) ?></td>
                </tr>
            <?php endforeach; ?>
            <tr class="total-row">
                <td colspan="4"></td>
                <td>Разом без ПДВ</td>
                <td class="num"><?= e(number_format($total, 2, ',', ' ')) ?></td>
            </tr>
        </tbody>
    </table>

    <div class="footer">
        <p><strong>Всього на суму: <?= e(number_format($total, 2, ',', ' ')) ?> грн</strong></p>
        <p>Без ПДВ. Платник єдиного податку.</p>
            <?php if (!$isDelivery && !$isAct): ?>
            <p>Рахунок дійсний протягом 3-х днів.</p>
            <p><strong>Призначення платежу:</strong> <?= e((string) $invoice['payment_purpose']) ?></p>
        <?php elseif ($isDelivery): ?>
            <p>Замовник підтверджує відсутність претензій щодо обсягу, якості та терміну відвантаження продукції.</p>
        <?php else: ?>
            <p>Замовник підтверджує відсутність претензій щодо обсягу, якості та складу позицій документа.</p>
        <?php endif; ?>
    </div>

    <table class="signatures">
        <tr>
            <td>
                <div class="line"></div>
                <div>Від постачальника</div>
            </td>
            <td>
                <div class="line"></div>
                <div>Від замовника</div>
            </td>
        </tr>
    </table>
</body>
</html>
    <?php
    return (string) ob_get_clean();
}

function invoice_generate_document(array $invoice, array $items, string $documentType): array
{
    $dir = __DIR__ . '/storage/invoices';
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create invoice storage directory.');
    }

    $safeNumber = invoice_safe_file_number((string) $invoice['invoice_number']);
    $prefix = 'INV_';
    if ($documentType === 'delivery_note') {
        $prefix = 'DN_';
    } elseif ($documentType === 'act') {
        $prefix = 'ACT_';
    }
    $base = $prefix . $safeNumber;
    $htmlPath = $dir . '/' . $base . '.html';
    $pdfPath = $dir . '/' . $base . '.pdf';
    $html = invoice_document_html($invoice, $items, $documentType);
    file_put_contents($htmlPath, $html);

    $relativeHtml = 'storage/invoices/' . basename($htmlPath);
    $relativePdf = 'storage/invoices/' . basename($pdfPath);

    if (class_exists('\\Dompdf\\Dompdf')) {
        $options = new \Dompdf\Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);
        $dompdf = new \Dompdf\Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        file_put_contents($pdfPath, $dompdf->output());
        if (is_file($pdfPath) && filesize($pdfPath) > 0) {
            return ['path' => $relativePdf, 'is_pdf' => true];
        }
    }

    $wkhtmltopdf = function_exists('shell_exec') ? trim((string) shell_exec('command -v wkhtmltopdf 2>/dev/null')) : '';
    if ($wkhtmltopdf !== '' && function_exists('shell_exec')) {
        $command = escapeshellcmd($wkhtmltopdf) . ' --quiet --encoding utf-8 '
            . escapeshellarg($htmlPath) . ' ' . escapeshellarg($pdfPath) . ' 2>&1';
        shell_exec($command);
        if (is_file($pdfPath) && filesize($pdfPath) > 0) {
            return ['path' => $relativePdf, 'is_pdf' => true];
        }
    }

    return ['path' => $relativeHtml, 'is_pdf' => false];
}

function invoice_store_document(int $invoiceId, string $documentType, string $documentDate, string $filePath, string $documentNumber): int
{
    $stmt = db()->prepare("
        INSERT INTO db_invoice_documents
            (invoice_id, document_type, document_number, document_date, file_path, created_by_user_id)
        VALUES
            (:invoice_id, :document_type, :document_number, :document_date, :file_path, :created_by_user_id)
    ");
    $stmt->execute([
        'invoice_id' => $invoiceId,
        'document_type' => $documentType,
        'document_number' => $documentNumber,
        'document_date' => $documentDate,
        'file_path' => $filePath,
        'created_by_user_id' => (int) (current_user()['id'] ?? 0),
    ]);

    return (int) db()->lastInsertId();
}

function invoice_download_file(array $invoice): void
{
    $relative = (string) ($invoice['pdf_file_path'] ?? '');
    $full = __DIR__ . '/' . ltrim($relative, '/');
    $storageRoot = realpath(__DIR__ . '/storage/invoices');
    $filePath = realpath($full);

    if ($relative === '' || !$storageRoot || !$filePath || strpos($filePath, $storageRoot) !== 0 || !is_file($filePath)) {
        http_response_code(404);
        echo 'Document file not found.';
        exit;
    }

    $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    if ($extension === 'pdf') {
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . basename($filePath) . '"');
    } elseif ($extension === 'html') {
        header('Content-Type: text/html; charset=utf-8');
        header('Content-Disposition: inline; filename="' . basename($filePath) . '"');
    } else {
        http_response_code(404);
        echo 'Document file type is not allowed.';
        exit;
    }

    header('Content-Length: ' . filesize($filePath));
    readfile($filePath);
    exit;
}

function invoice_download_path(string $relative): void
{
    $full = __DIR__ . '/' . ltrim($relative, '/');
    $storageRoot = realpath(__DIR__ . '/storage/invoices');
    $filePath = realpath($full);

    if ($relative === '' || !$storageRoot || !$filePath || strpos($filePath, $storageRoot) !== 0 || !is_file($filePath)) {
        http_response_code(404);
        echo 'Document file not found.';
        exit;
    }

    $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    if ($extension === 'pdf') {
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . basename($filePath) . '"');
    } elseif ($extension === 'html') {
        header('Content-Type: text/html; charset=utf-8');
        header('Content-Disposition: inline; filename="' . basename($filePath) . '"');
    } else {
        http_response_code(404);
        echo 'Document file type is not allowed.';
        exit;
    }

    header('Content-Length: ' . filesize($filePath));
    readfile($filePath);
    exit;
}

function invoice_download_package(int $invoiceId): void
{
    if (!class_exists('ZipArchive')) {
        http_response_code(500);
        echo 'ZIP export is not available on this server.';
        exit;
    }

    $invoice = invoice_load($invoiceId);
    if (!$invoice) {
        http_response_code(404);
        echo 'Invoice not found.';
        exit;
    }

    $stmt = db()->prepare("
        SELECT *
        FROM db_invoice_documents
        WHERE invoice_id = :invoice_id
        ORDER BY document_type ASC, id DESC
    ");
    $stmt->execute(['invoice_id' => $invoiceId]);
    $documents = $stmt->fetchAll();

    $storageRoot = realpath(__DIR__ . '/storage/invoices');
    if (!$storageRoot) {
        http_response_code(404);
        echo 'Document storage not found.';
        exit;
    }

    $files = [];
    foreach ($documents as $document) {
        $relative = (string) ($document['file_path'] ?? '');
        if ($relative === '' || strtolower(pathinfo($relative, PATHINFO_EXTENSION)) !== 'pdf') {
            continue;
        }
        $filePath = realpath(__DIR__ . '/' . ltrim($relative, '/'));
        if ($filePath && strpos($filePath, $storageRoot) === 0 && is_file($filePath)) {
            $files[(string) $document['document_type']] = $filePath;
        }
    }

    if (!$files && invoice_pdf_available($invoice)) {
        $filePath = realpath(__DIR__ . '/' . ltrim((string) $invoice['pdf_file_path'], '/'));
        if ($filePath && strpos($filePath, $storageRoot) === 0 && is_file($filePath)) {
            $files['invoice'] = $filePath;
        }
    }

    if (!$files) {
        http_response_code(404);
        echo 'No PDF documents found for this invoice.';
        exit;
    }

    $safeNumber = invoice_safe_file_number((string) $invoice['invoice_number']);
    $zipPath = tempnam(sys_get_temp_dir(), 'brand_invoice_docs_');
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::OVERWRITE) !== true) {
        http_response_code(500);
        echo 'Cannot create ZIP package.';
        exit;
    }

    foreach ($files as $type => $filePath) {
        $prefix = $type === 'delivery_note' ? 'DN' : ($type === 'act' ? 'ACT' : 'INV');
        $zip->addFile($filePath, $prefix . '_' . $safeNumber . '.pdf');
    }
    $zip->close();

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="DOCS_' . $safeNumber . '.zip"');
    header('Content-Length: ' . filesize($zipPath));
    readfile($zipPath);
    @unlink($zipPath);
    exit;
}

$companies = our_companies(true);
$companyAccounts = array_values(array_filter(
    our_company_accounts(null, true, true),
    static fn(array $account): bool => strtoupper((string) ($account['currency'] ?? 'UAH')) === 'UAH'
));
$defaultCompany = $companies[0] ?? [];

if (isset($_GET['download'])) {
    $downloadInvoice = invoice_load((int) $_GET['download']);
    if ($downloadInvoice) {
        invoice_download_file($downloadInvoice);
    }
}

if (isset($_GET['document'])) {
    $stmt = db()->prepare('SELECT * FROM db_invoice_documents WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => (int) $_GET['document']]);
    $document = $stmt->fetch();
    if ($document) {
        db()->prepare('UPDATE db_invoice_documents SET download_count = download_count + 1, last_downloaded_at = NOW() WHERE id = :id')->execute([
            'id' => (int) $document['id'],
        ]);
        invoice_download_path((string) $document['file_path']);
    }
}

if (isset($_GET['package'])) {
    invoice_download_package((int) $_GET['package']);
}

if (is_post()) {
    $action = (string) ($_POST['action'] ?? '');

    if (!csrf_is_valid()) {
        $error = 'Invalid invoice request.';
    } elseif ($action === 'create_from_order') {
        $orderId = (int) ($_POST['order_id'] ?? 0);
        $sellerId = (int) ($_POST['seller_company_id'] ?? ($defaultCompany['id'] ?? 0));
        $seller = $defaultCompany;
        foreach ($companies as $company) {
            if ((int) $company['id'] === $sellerId) {
                $seller = $company;
                break;
            }
        }
        $sellerAccountId = invoice_account_id_for_seller($sellerId, (int) ($_POST['seller_account_id'] ?? 0), 'UAH');

        $stmt = db()->prepare('SELECT * FROM db_orders WHERE keycrm_id = :keycrm_id LIMIT 1');
        $stmt->execute(['keycrm_id' => $orderId]);
        $dbOrder = $stmt->fetch();

        if (!$dbOrder) {
            $error = 'Order not found in local db_orders. Run sync first or check KeyCRM order id.';
        } else {
            $payload = invoice_order_payload($dbOrder);
            $clientSnapshot = invoice_store_client_snapshot($payload);
            $defaultLegalEntity = invoice_default_legal_entity($clientSnapshot['client_company_id'] ?? null);
            if ($defaultLegalEntity) {
                $payload['buyer_display_name'] = (string) $defaultLegalEntity['legal_name'];
                $payload['buyer_edrpou'] = (string) ($defaultLegalEntity['edrpou'] ?? '');
                $payload['buyer_address'] = (string) ($defaultLegalEntity['legal_address'] ?? '');
                $payload['buyer_email'] = (string) ($defaultLegalEntity['email'] ?? '');
                $payload['buyer_phone'] = (string) ($defaultLegalEntity['phone'] ?? '');
                $payload['recipient_legal_name'] = (string) $defaultLegalEntity['legal_name'];
                $payload['recipient_short_name'] = (string) (($defaultLegalEntity['short_name'] ?? '') ?: $defaultLegalEntity['legal_name']);
                $payload['recipient_edrpou'] = (string) ($defaultLegalEntity['edrpou'] ?? '');
                $payload['recipient_tax_number'] = (string) ($defaultLegalEntity['tax_number'] ?? '');
                $payload['recipient_legal_address'] = (string) ($defaultLegalEntity['legal_address'] ?? '');
                $payload['recipient_email'] = (string) ($defaultLegalEntity['email'] ?? '');
                $payload['recipient_phone'] = (string) ($defaultLegalEntity['phone'] ?? '');
            }
            $total = invoice_number_value($payload['total_amount_uah']);
            $number = trim((string) ($payload['order_number'] ?: $orderId));
            $purpose = 'за продукцію згідно рахунку № ' . $number . ' від ' . invoice_ua_date_label(date('Y-m-d'));
            if (trim((string) ($payload['recipient_legal_name'] ?? '')) === '') {
                $message = 'Рахунок створено, але платник не підтягнувся. Заповніть юрособу-платника в редагуванні.';
            }

            $stmt = db()->prepare("
                INSERT INTO db_invoices
                    (keycrm_order_id, invoice_number, invoice_date, document_type, seller_company_id, seller_account_id, buyer_id,
                     buyer_company_id, client_company_id, buyer_display_name, buyer_contact_name,
                     buyer_edrpou, buyer_address, buyer_email, buyer_phone,
                     recipient_legal_name, recipient_short_name, recipient_edrpou, recipient_tax_number,
                     recipient_legal_address, recipient_email, recipient_phone,
                     contact_name, contact_email, contact_phone,
                     total_amount_uah, vat_mode, vat_amount_uah, total_with_vat_uah, payment_purpose,
                     payment_status, document_status, status, sent_at, payment_due_date, expected_payment_date, created_by_user_id)
                VALUES
                    (:keycrm_order_id, :invoice_number, CURDATE(), 'invoice', :seller_company_id, :seller_account_id, :buyer_id,
                     :buyer_company_id, :client_company_id, :buyer_display_name, :buyer_contact_name,
                     :buyer_edrpou, :buyer_address, :buyer_email, :buyer_phone,
                     :recipient_legal_name, :recipient_short_name, :recipient_edrpou, :recipient_tax_number,
                     :recipient_legal_address, :recipient_email, :recipient_phone,
                     :contact_name, :contact_email, :contact_phone,
                     :total_amount_uah, 'no_vat', 0, :total_with_vat_uah, :payment_purpose,
                     'waiting_payment', 'not_sent', 'sent', NOW(), DATE_ADD(CURDATE(), INTERVAL 3 DAY), DATE_ADD(CURDATE(), INTERVAL 3 DAY), :created_by_user_id)
            ");
            $stmt->execute([
                'keycrm_order_id' => $orderId,
                'invoice_number' => $number,
                'seller_company_id' => $sellerId,
                'seller_account_id' => $sellerAccountId,
                'buyer_id' => $payload['buyer_id'] ?: null,
                'buyer_company_id' => $payload['buyer_company_id'] ?: null,
                'client_company_id' => $clientSnapshot['client_company_id'] ?: null,
                'buyer_display_name' => $payload['buyer_display_name'] ?: '',
                'buyer_contact_name' => $payload['buyer_contact_name'] ?: null,
                'buyer_edrpou' => $payload['buyer_edrpou'] ?: null,
                'buyer_address' => $payload['buyer_address'] ?: null,
                'buyer_email' => $payload['buyer_email'] ?: null,
                'buyer_phone' => $payload['buyer_phone'] ?: null,
                'recipient_legal_name' => $payload['recipient_legal_name'] ?: null,
                'recipient_short_name' => $payload['recipient_short_name'] ?: null,
                'recipient_edrpou' => $payload['recipient_edrpou'] ?: null,
                'recipient_tax_number' => $payload['recipient_tax_number'] ?: null,
                'recipient_legal_address' => $payload['recipient_legal_address'] ?: null,
                'recipient_email' => $payload['recipient_email'] ?: null,
                'recipient_phone' => $payload['recipient_phone'] ?: null,
                'contact_name' => $payload['contact_name'] ?: null,
                'contact_email' => $payload['contact_email'] ?: null,
                'contact_phone' => $payload['contact_phone'] ?: null,
                'total_amount_uah' => $total,
                'total_with_vat_uah' => $total,
                'payment_purpose' => $purpose,
                'created_by_user_id' => (int) (current_user()['id'] ?? 0),
            ]);

            $invoiceId = (int) db()->lastInsertId();
            if ($defaultLegalEntity) {
                db()->prepare('UPDATE db_invoices SET client_legal_entity_id = :client_legal_entity_id WHERE id = :id')->execute([
                    'client_legal_entity_id' => (int) $defaultLegalEntity['id'],
                    'id' => $invoiceId,
                ]);
            }
            invoice_insert_items(db(), $invoiceId, invoice_product_items($payload['products'], $total, $seller));
            redirect_to('/invoices.php?edit=' . $invoiceId);
        }
    } else {
        $invoiceId = (int) ($_POST['id'] ?? 0);
        $invoice = $invoiceId > 0 ? invoice_load($invoiceId) : null;

        if (!$invoice) {
            $error = 'Invoice not found.';
        } elseif ($action === 'delete_invoice') {
            db()->beginTransaction();
            db()->prepare('DELETE FROM db_invoice_items WHERE invoice_id = :id')->execute(['id' => $invoiceId]);
            db()->prepare('DELETE FROM db_invoice_documents WHERE invoice_id = :id')->execute(['id' => $invoiceId]);
            db()->prepare('DELETE FROM db_invoices WHERE id = :id')->execute(['id' => $invoiceId]);
            db()->commit();
            redirect_to('/invoices.php');
        } elseif ($action === 'generate_registry_document') {
            $documentType = (string) ($_POST['document_type'] ?? 'invoice');
            if (!in_array($documentType, ['invoice', 'delivery_note', 'act'], true)) {
                $error = 'Invalid document type.';
            } elseif ($documentType === 'act' && !invoice_seller_allows_act($invoice)) {
                $error = 'Акт недоступний для products-only продавця.';
            } else {
                $items = invoice_items($invoiceId);
                $documentDate = (string) (($invoice['document_due_date'] ?? '') ?: ($invoice['invoice_date'] ?? date('Y-m-d')));
                $documentInvoice = $invoice;
                if ($documentType !== 'invoice') {
                    $documentInvoice['invoice_date'] = $documentDate;
                }
                $generated = invoice_generate_document($documentInvoice, $items, $documentType);
                $documentId = invoice_store_document($invoiceId, $documentType, $documentDate, (string) $generated['path'], (string) $invoice['invoice_number']);
                $legacyDocumentType = $documentType === 'act' ? (string) $invoice['document_type'] : $documentType;
                db()->prepare("
                    UPDATE db_invoices
                    SET document_type = :document_type,
                        pdf_file_path = :pdf_file_path,
                        payment_status = CASE WHEN payment_status = 'paid' THEN payment_status ELSE 'waiting_payment' END,
                        status = CASE WHEN status = 'paid' THEN status ELSE 'sent' END,
                        sent_at = COALESCE(sent_at, NOW()),
                        payment_due_date = COALESCE(payment_due_date, DATE_ADD(CURDATE(), INTERVAL 3 DAY)),
                        expected_payment_date = COALESCE(expected_payment_date, payment_due_date, DATE_ADD(CURDATE(), INTERVAL 3 DAY))
                    WHERE id = :id
                ")->execute([
                    'document_type' => $legacyDocumentType,
                    'pdf_file_path' => $generated['path'],
                    'id' => $invoiceId,
                ]);

                if ($generated['is_pdf']) {
                    redirect_to('/invoices.php?document=' . $documentId);
                }
                $error = 'PDF не створено: збережено HTML-шаблон для діагностики.';
            }
        } elseif ($action === 'status') {
            $statusType = (string) ($_POST['status_type'] ?? 'payment');
            $statusAction = (string) ($_POST['status_action'] ?? '');
            $allowedPayment = ['draft', 'waiting_payment', 'paid', 'problem', 'canceled'];
            $allowedDocument = ['not_sent', 'sent', 'closed', 'problem'];
            if ($statusType === 'document') {
                $allowed = $allowedDocument;
            } else {
                $statusType = 'payment';
                $allowed = $allowedPayment;
            }
            if (!in_array($statusAction, $allowed, true)) {
                $error = 'Invalid invoice status.';
            } else {
                $sets = [];
                $params = ['id' => $invoiceId];
                if ($statusType === 'payment') {
                    $sets[] = 'payment_status = :payment_status';
                    $params['payment_status'] = $statusAction;
                    if ($statusAction === 'draft') {
                        $sets[] = "status = 'draft'";
                    } elseif ($statusAction === 'waiting_payment') {
                        $sets[] = "status = 'sent'";
                        $sets[] = 'sent_at = COALESCE(sent_at, NOW())';
                        $sets[] = 'payment_due_date = COALESCE(payment_due_date, DATE_ADD(CURDATE(), INTERVAL 3 DAY))';
                        $sets[] = 'expected_payment_date = COALESCE(expected_payment_date, payment_due_date, DATE_ADD(CURDATE(), INTERVAL 3 DAY))';
                    } elseif ($statusAction === 'paid') {
                        $sets[] = "status = 'paid'";
                        $sets[] = 'paid_at = COALESCE(paid_at, NOW())';
                    } elseif ($statusAction === 'problem') {
                        $sets[] = "status = 'sent'";
                    } elseif ($statusAction === 'canceled') {
                        $sets[] = "status = 'canceled'";
                    }
                } else {
                    $sets[] = 'document_status = :document_status';
                    $params['document_status'] = $statusAction;
                    if ($statusAction === 'not_sent') {
                        $sets[] = "docs_status = 'not_sent'";
                    } elseif ($statusAction === 'sent') {
                        $sets[] = "docs_status = 'sent'";
                        $sets[] = "status = CASE WHEN status = 'draft' THEN 'paid' ELSE status END";
                        $sets[] = 'docs_sent_at = COALESCE(docs_sent_at, NOW())';
                    } elseif ($statusAction === 'closed') {
                        $sets[] = "docs_status = 'closed'";
                        $sets[] = "status = 'docs_closed'";
                        $sets[] = 'docs_closed_at = COALESCE(docs_closed_at, NOW())';
                    } elseif ($statusAction === 'problem') {
                        $sets[] = "docs_status = 'problem'";
                    }
                }
                db()->prepare('UPDATE db_invoices SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($params);
                redirect_to((string) ($_POST['return_to'] ?? '') === 'registry' ? '/invoices.php' : '/invoices.php?edit=' . $invoiceId);
            }
        } elseif ($action === 'payment_due_date') {
            $expectedPaymentDate = trim((string) ($_POST['payment_due_date'] ?? ''));
            if ($expectedPaymentDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expectedPaymentDate)) {
                $error = 'Invalid expected payment date.';
            } else {
                $stmt = db()->prepare('UPDATE db_invoices SET payment_due_date = :payment_due_date, expected_payment_date = :expected_payment_date WHERE id = :id');
                $stmt->execute([
                    'payment_due_date' => $expectedPaymentDate !== '' ? $expectedPaymentDate : null,
                    'expected_payment_date' => $expectedPaymentDate !== '' ? $expectedPaymentDate : null,
                    'id' => $invoiceId,
                ]);
                redirect_to('/invoices.php');
            }
        } elseif (in_array($action, ['save_invoice', 'save_legal_entity', 'save_contact', 'set_default_legal_entity', 'collapse_one', 'collapse_manual', 'use_detailed', 'generate_invoice', 'generate_delivery', 'generate_act', 'generate_selected'], true)) {
            $sellerId = (int) ($_POST['seller_company_id'] ?? $invoice['seller_company_id']);
            $seller = $defaultCompany;
            foreach ($companies as $company) {
                if ((int) $company['id'] === $sellerId) {
                    $seller = $company;
                    break;
                }
            }
            $sellerAccountId = invoice_account_id_for_seller($sellerId, (int) ($_POST['seller_account_id'] ?? ($invoice['seller_account_id'] ?? 0)), 'UAH');

            if ($action === 'save_legal_entity') {
                $legalName = trim((string) ($_POST['recipient_legal_name'] ?? ($_POST['buyer_display_name'] ?? '')));
                $clientCompanyId = (int) ($_POST['client_company_id'] ?? ($invoice['client_company_id'] ?? 0));
                $clientLegalEntityId = (int) ($_POST['client_legal_entity_id'] ?? ($invoice['client_legal_entity_id'] ?? 0));
                $recipientShortName = trim((string) ($_POST['recipient_short_name'] ?? ''));
                $recipientEdrpou = trim((string) ($_POST['recipient_edrpou'] ?? ($_POST['buyer_edrpou'] ?? '')));
                $recipientTaxNumber = trim((string) ($_POST['recipient_tax_number'] ?? ''));
                $recipientAddress = trim((string) ($_POST['recipient_legal_address'] ?? ($_POST['buyer_address'] ?? '')));
                $recipientEmail = trim((string) ($_POST['recipient_email'] ?? ($_POST['buyer_email'] ?? ($_POST['contact_email'] ?? ''))));
                $recipientPhone = trim((string) ($_POST['recipient_phone'] ?? ($_POST['buyer_phone'] ?? ($_POST['contact_phone'] ?? ''))));
                if ($legalName === '') {
                    $error = 'Вкажіть юрособу-платника перед збереженням.';
                } else {
                    if ($clientCompanyId <= 0) {
                        $stmt = db()->prepare("
                            INSERT INTO db_client_companies
                                (keycrm_company_id, display_name, keycrm_name, keycrm_title, name, title, synced_at)
                            VALUES
                                (:keycrm_company_id, :display_name, :keycrm_name, :keycrm_title, :name, :title, NOW())
                        ");
                        $stmt->execute([
                            'keycrm_company_id' => !empty($invoice['buyer_company_id']) ? (int) $invoice['buyer_company_id'] : null,
                            'display_name' => $legalName,
                            'keycrm_name' => $legalName,
                            'keycrm_title' => $legalName,
                            'name' => $legalName,
                            'title' => $legalName,
                        ]);
                        $clientCompanyId = (int) db()->lastInsertId();
                    }

                    if ($clientLegalEntityId > 0) {
                        $stmt = db()->prepare("
                            UPDATE db_client_legal_entities
                            SET client_company_id = :client_company_id,
                                legal_name = :legal_name,
                                short_name = :short_name,
                                edrpou = :edrpou,
                                tax_number = :tax_number,
                                legal_address = :legal_address,
                                email = :email,
                                phone = :phone,
                                note = :note
                            WHERE id = :id
                        ");
                        $stmt->execute([
                            'client_company_id' => $clientCompanyId,
                            'legal_name' => $legalName,
                            'short_name' => $recipientShortName !== '' ? $recipientShortName : $legalName,
                            'edrpou' => $recipientEdrpou !== '' ? $recipientEdrpou : null,
                            'tax_number' => $recipientTaxNumber !== '' ? $recipientTaxNumber : null,
                            'legal_address' => $recipientAddress !== '' ? $recipientAddress : null,
                            'email' => $recipientEmail !== '' ? $recipientEmail : null,
                            'phone' => $recipientPhone !== '' ? $recipientPhone : null,
                            'note' => trim((string) ($_POST['note'] ?? '')) ?: null,
                            'id' => $clientLegalEntityId,
                        ]);
                        $legalEntityId = $clientLegalEntityId;
                    } else {
                        $stmt = db()->prepare("
                            INSERT INTO db_client_legal_entities
                                (client_company_id, legal_name, short_name, edrpou, tax_number, legal_address, email, phone, is_default, note)
                            VALUES
                                (:client_company_id, :legal_name, :short_name, :edrpou, :tax_number, :legal_address, :email, :phone, 0, :note)
                        ");
                        $stmt->execute([
                            'client_company_id' => $clientCompanyId,
                            'legal_name' => $legalName,
                            'short_name' => $recipientShortName !== '' ? $recipientShortName : $legalName,
                            'edrpou' => $recipientEdrpou !== '' ? $recipientEdrpou : null,
                            'tax_number' => $recipientTaxNumber !== '' ? $recipientTaxNumber : null,
                            'legal_address' => $recipientAddress !== '' ? $recipientAddress : null,
                            'email' => $recipientEmail !== '' ? $recipientEmail : null,
                            'phone' => $recipientPhone !== '' ? $recipientPhone : null,
                            'note' => trim((string) ($_POST['note'] ?? '')) ?: null,
                        ]);
                        $legalEntityId = (int) db()->lastInsertId();
                    }

                    if (!empty($_POST['set_as_default'])) {
                        db()->prepare("
                            UPDATE db_client_legal_entities
                            SET is_default = CASE WHEN id = :id THEN 1 ELSE 0 END
                            WHERE client_company_id = :client_company_id
                        ")->execute([
                            'id' => $legalEntityId,
                            'client_company_id' => $clientCompanyId,
                        ]);
                    }

                    db()->prepare("
                        UPDATE db_invoices
                        SET client_company_id = :client_company_id,
                            client_legal_entity_id = :client_legal_entity_id,
                            buyer_display_name = :buyer_display_name,
                            buyer_edrpou = :buyer_edrpou,
                            buyer_address = :buyer_address,
                            buyer_email = :buyer_email,
                            buyer_phone = :buyer_phone,
                            recipient_legal_name = :recipient_legal_name,
                            recipient_short_name = :recipient_short_name,
                            recipient_edrpou = :recipient_edrpou,
                            recipient_tax_number = :recipient_tax_number,
                            recipient_legal_address = :recipient_legal_address,
                            recipient_email = :recipient_email,
                            recipient_phone = :recipient_phone
                        WHERE id = :id
                    ")->execute([
                        'client_company_id' => $clientCompanyId,
                        'client_legal_entity_id' => $legalEntityId,
                        'buyer_display_name' => $legalName,
                        'buyer_edrpou' => $recipientEdrpou !== '' ? $recipientEdrpou : null,
                        'buyer_address' => $recipientAddress !== '' ? $recipientAddress : null,
                        'buyer_email' => $recipientEmail !== '' ? $recipientEmail : null,
                        'buyer_phone' => $recipientPhone !== '' ? $recipientPhone : null,
                        'recipient_legal_name' => $legalName,
                        'recipient_short_name' => $recipientShortName !== '' ? $recipientShortName : $legalName,
                        'recipient_edrpou' => $recipientEdrpou !== '' ? $recipientEdrpou : null,
                        'recipient_tax_number' => $recipientTaxNumber !== '' ? $recipientTaxNumber : null,
                        'recipient_legal_address' => $recipientAddress !== '' ? $recipientAddress : null,
                        'recipient_email' => $recipientEmail !== '' ? $recipientEmail : null,
                        'recipient_phone' => $recipientPhone !== '' ? $recipientPhone : null,
                        'id' => $invoiceId,
                    ]);

                    redirect_to('/invoices.php?edit=' . $invoiceId);
                }
            } elseif ($action === 'save_contact') {
                $contactName = trim((string) ($_POST['contact_name'] ?? ($_POST['buyer_contact_name'] ?? '')));
                $clientCompanyId = (int) ($_POST['client_company_id'] ?? ($invoice['client_company_id'] ?? 0));
                $clientContactId = (int) ($_POST['client_contact_id'] ?? 0);
                $contactEmail = trim((string) ($_POST['contact_email'] ?? ''));
                $contactPhone = trim((string) ($_POST['contact_phone'] ?? ''));
                if ($contactName === '') {
                    $error = 'Вкажіть контактну особу перед збереженням контакту.';
                } else {
                    if ($clientContactId > 0) {
                        $stmt = db()->prepare("
                            UPDATE db_client_contacts
                            SET client_company_id = :client_company_id,
                                full_name = :full_name,
                                email = :email,
                                phone = :phone,
                                note = COALESCE(NULLIF(note, ''), :note),
                                synced_at = NOW()
                            WHERE id = :id
                        ");
                        $stmt->execute([
                            'client_company_id' => $clientCompanyId > 0 ? $clientCompanyId : null,
                            'full_name' => $contactName,
                            'email' => $contactEmail !== '' ? $contactEmail : null,
                            'phone' => $contactPhone !== '' ? $contactPhone : null,
                            'note' => trim((string) ($_POST['note'] ?? '')) ?: null,
                            'id' => $clientContactId,
                        ]);
                    } else {
                        $stmt = db()->prepare("
                            INSERT INTO db_client_contacts
                                (keycrm_buyer_id, client_company_id, full_name, email, phone, note, synced_at)
                            VALUES
                                (:keycrm_buyer_id, :client_company_id, :full_name, :email, :phone, :note, NOW())
                        ");
                        $stmt->execute([
                            'keycrm_buyer_id' => !empty($invoice['buyer_id']) ? (int) $invoice['buyer_id'] : null,
                            'client_company_id' => $clientCompanyId > 0 ? $clientCompanyId : null,
                            'full_name' => $contactName,
                            'email' => $contactEmail !== '' ? $contactEmail : null,
                            'phone' => $contactPhone !== '' ? $contactPhone : null,
                            'note' => trim((string) ($_POST['note'] ?? '')) ?: null,
                        ]);
                        $clientContactId = (int) db()->lastInsertId();
                    }
                    db()->prepare("
                        UPDATE db_invoices
                        SET buyer_contact_name = :buyer_contact_name,
                            buyer_email = :buyer_email,
                            buyer_phone = :buyer_phone,
                            contact_name = :contact_name,
                            contact_email = :contact_email,
                            contact_phone = :contact_phone,
                            client_company_id = :client_company_id
                        WHERE id = :id
                    ")->execute([
                        'buyer_contact_name' => $contactName,
                        'buyer_email' => $contactEmail !== '' ? $contactEmail : null,
                        'buyer_phone' => $contactPhone !== '' ? $contactPhone : null,
                        'contact_name' => $contactName,
                        'contact_email' => $contactEmail !== '' ? $contactEmail : null,
                        'contact_phone' => $contactPhone !== '' ? $contactPhone : null,
                        'client_company_id' => $clientCompanyId > 0 ? $clientCompanyId : null,
                        'id' => $invoiceId,
                    ]);
                    redirect_to('/invoices.php?edit=' . $invoiceId);
                }
            } elseif ($action === 'set_default_legal_entity') {
                $clientCompanyId = (int) ($_POST['client_company_id'] ?? ($invoice['client_company_id'] ?? 0));
                $clientLegalEntityId = (int) ($_POST['client_legal_entity_id'] ?? ($invoice['client_legal_entity_id'] ?? 0));
                if ($clientCompanyId <= 0 || $clientLegalEntityId <= 0) {
                    $error = 'Спочатку виберіть юрособу клієнта.';
                } else {
                    db()->prepare("
                        UPDATE db_client_legal_entities
                        SET is_default = CASE WHEN id = :id THEN 1 ELSE 0 END
                        WHERE client_company_id = :client_company_id
                    ")->execute([
                        'id' => $clientLegalEntityId,
                        'client_company_id' => $clientCompanyId,
                    ]);
                    db()->prepare('UPDATE db_invoices SET client_legal_entity_id = :client_legal_entity_id WHERE id = :id')->execute([
                        'client_legal_entity_id' => $clientLegalEntityId,
                        'id' => $invoiceId,
                    ]);
                    redirect_to('/invoices.php?edit=' . $invoiceId);
                }
            } elseif ($action === 'use_detailed') {
                $stmt = db()->prepare('SELECT * FROM db_orders WHERE keycrm_id = :keycrm_id LIMIT 1');
                $stmt->execute(['keycrm_id' => (int) $invoice['keycrm_order_id']]);
                $dbOrder = $stmt->fetch();
                if ($dbOrder) {
                    $payload = invoice_order_payload($dbOrder);
                    db()->prepare('DELETE FROM db_invoice_items WHERE invoice_id = :id')->execute(['id' => $invoiceId]);
                    invoice_insert_items(db(), $invoiceId, invoice_product_items($payload['products'], (float) $invoice['total_amount_uah'], $seller));
                    $message = 'Detailed CRM products restored.';
                } else {
                    $error = 'Original order is not available in db_orders.';
                }
            } elseif ($action === 'collapse_one' || $action === 'collapse_manual') {
                $title = $action === 'collapse_manual' ? trim((string) ($_POST['collapse_title'] ?? '')) : '';
                db()->prepare('DELETE FROM db_invoice_items WHERE invoice_id = :id')->execute(['id' => $invoiceId]);
                invoice_insert_items(db(), $invoiceId, [invoice_collapsed_item((float) $invoice['total_amount_uah'], $seller, $title)]);
                $message = 'Invoice items collapsed.';
            } else {
                $invoiceNumber = substr(trim((string) ($_POST['invoice_number'] ?? $invoice['invoice_number'])), 0, 80);
                $invoiceDate = trim((string) ($_POST['invoice_date'] ?? $invoice['invoice_date']));
                $recipientLegalName = substr(trim((string) ($_POST['recipient_legal_name'] ?? ($_POST['buyer_display_name'] ?? ''))), 0, 255);
                $recipientShortName = substr(trim((string) ($_POST['recipient_short_name'] ?? '')), 0, 255);
                $recipientEdrpou = trim((string) ($_POST['recipient_edrpou'] ?? ($_POST['buyer_edrpou'] ?? '')));
                $recipientTaxNumber = trim((string) ($_POST['recipient_tax_number'] ?? ''));
                $recipientAddress = trim((string) ($_POST['recipient_legal_address'] ?? ($_POST['buyer_address'] ?? '')));
                $contactName = substr(trim((string) ($_POST['contact_name'] ?? ($_POST['buyer_contact_name'] ?? ''))), 0, 255);
                $contactEmail = trim((string) ($_POST['contact_email'] ?? ''));
                $contactPhone = trim((string) ($_POST['contact_phone'] ?? ''));
                $recipientEmail = trim((string) ($_POST['recipient_email'] ?? ($_POST['buyer_email'] ?? $contactEmail)));
                $recipientPhone = trim((string) ($_POST['recipient_phone'] ?? ($_POST['buyer_phone'] ?? $contactPhone)));
                $paymentPurpose = substr(trim((string) ($_POST['payment_purpose'] ?? '')), 0, 255);
                if ($paymentPurpose === '') {
                    $paymentPurpose = 'за продукцію згідно рахунку № ' . $invoiceNumber . ' від ' . invoice_ua_date_label($invoiceDate);
                }
                $note = trim((string) ($_POST['note'] ?? ''));
                $docsType = (string) ($_POST['docs_type'] ?? 'none');
                $paymentDueDate = trim((string) ($_POST['payment_due_date'] ?? ($invoice['payment_due_date'] ?? '')));
                $documentDueDate = trim((string) ($_POST['document_due_date'] ?? ($invoice['document_due_date'] ?? '')));
                $clientCompanyId = (int) ($_POST['client_company_id'] ?? ($invoice['client_company_id'] ?? 0));
                $clientLegalEntityId = (int) ($_POST['client_legal_entity_id'] ?? ($invoice['client_legal_entity_id'] ?? 0));
                if (!in_array($docsType, ['none', 'paper', 'electronic', 'both'], true)) {
                    $docsType = 'none';
                }
                $selectedLegalEntity = invoice_legal_entity_by_id($clientLegalEntityId);
                if ($selectedLegalEntity) {
                    $clientCompanyId = (int) ($selectedLegalEntity['client_company_id'] ?? $clientCompanyId);
                }
                $titles = $_POST['item_title'] ?? [];
                $units = $_POST['item_unit'] ?? [];
                $quantities = $_POST['item_quantity'] ?? [];
                $prices = $_POST['item_price_uah'] ?? [];
                $amounts = $_POST['item_amount_uah'] ?? [];
                $sourceIds = $_POST['item_source_product_id'] ?? [];
                $sourceNames = $_POST['item_source_product_name'] ?? [];
                $sourceSkus = $_POST['item_source_product_sku'] ?? [];
                $sourceOfferIds = $_POST['item_source_offer_id'] ?? [];
                $sourceJsons = $_POST['item_source_product_json'] ?? [];
                $itemTypes = $_POST['item_type'] ?? [];
                $items = [];
                $total = 0;

                foreach ((array) $titles as $index => $title) {
                    $cleanTitle = invoice_safe_item_title((string) $title, $seller);
                    if ($cleanTitle === '') {
                        continue;
                    }
                    $quantity = invoice_quantity_value($quantities[$index] ?? 1);
                    $price = invoice_number_value($prices[$index] ?? 0);
                    $amount = invoice_number_value($amounts[$index] ?? ($quantity * $price));
                    if ($amount <= 0) {
                        $amount = round($quantity * $price, 2);
                    }
                    $total += $amount;
                    $itemType = (string) ($itemTypes[$index] ?? 'product');
                    if (($seller['allowed_item_type'] ?? 'products_only') === 'products_only') {
                        $itemType = 'product';
                    }
                    $items[] = [
                        'source_product_id' => (int) ($sourceIds[$index] ?? 0),
                        'source_product_name' => trim((string) ($sourceNames[$index] ?? '')) ?: null,
                        'source_product_sku' => trim((string) ($sourceSkus[$index] ?? '')) ?: null,
                        'source_offer_id' => (int) ($sourceOfferIds[$index] ?? 0),
                        'source_product_json' => (string) ($sourceJsons[$index] ?? '') !== '' ? (string) $sourceJsons[$index] : null,
                        'item_type' => $itemType,
                        'title' => $cleanTitle,
                        'unit' => substr((string) (($units[$index] ?? '') ?: 'шт'), 0, 30),
                        'quantity' => $quantity > 0 ? $quantity : 1,
                        'price_uah' => $price,
                        'amount_uah' => $amount,
                        'sort_order' => $index + 1,
                    ];
                }

                if (!$items) {
                    $items[] = invoice_collapsed_item((float) $invoice['total_amount_uah'], $seller);
                    $total = (float) $invoice['total_amount_uah'];
                }

                if ($invoiceNumber === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $invoiceDate)) {
                    $error = 'Invoice number and date are required.';
                } elseif (($paymentDueDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $paymentDueDate)) || ($documentDueDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $documentDueDate))) {
                    $error = 'Payment/document deadline date is invalid.';
                } else {
                    db()->beginTransaction();
                    $stmt = db()->prepare("
                        UPDATE db_invoices
                        SET invoice_number = :invoice_number,
                            invoice_date = :invoice_date,
                            seller_company_id = :seller_company_id,
                            seller_account_id = :seller_account_id,
                            client_company_id = :client_company_id,
                            client_legal_entity_id = :client_legal_entity_id,
                            buyer_display_name = :buyer_display_name,
                            buyer_contact_name = :buyer_contact_name,
                            buyer_edrpou = :buyer_edrpou,
                            buyer_address = :buyer_address,
                            buyer_email = :buyer_email,
                            buyer_phone = :buyer_phone,
                            recipient_legal_name = :recipient_legal_name,
                            recipient_short_name = :recipient_short_name,
                            recipient_edrpou = :recipient_edrpou,
                            recipient_tax_number = :recipient_tax_number,
                            recipient_legal_address = :recipient_legal_address,
                            recipient_email = :recipient_email,
                            recipient_phone = :recipient_phone,
                            contact_name = :contact_name,
                            contact_email = :contact_email,
                            contact_phone = :contact_phone,
                            total_amount_uah = :total_amount_uah,
                            vat_mode = 'no_vat',
                            vat_amount_uah = 0,
                            total_with_vat_uah = :total_with_vat_uah,
                            payment_purpose = :payment_purpose,
                            payment_due_date = :payment_due_date,
                            expected_payment_date = :expected_payment_date,
                            document_due_date = :document_due_date,
                            docs_type = :docs_type,
                            note = :note
                        WHERE id = :id
                    ");
                    $stmt->execute([
                        'invoice_number' => $invoiceNumber,
                        'invoice_date' => $invoiceDate,
                        'seller_company_id' => $sellerId,
                        'seller_account_id' => $sellerAccountId > 0 ? $sellerAccountId : null,
                        'client_company_id' => $clientCompanyId > 0 ? $clientCompanyId : null,
                        'client_legal_entity_id' => $clientLegalEntityId > 0 ? $clientLegalEntityId : null,
                        'buyer_display_name' => $recipientLegalName !== '' ? $recipientLegalName : null,
                        'buyer_contact_name' => $contactName !== '' ? $contactName : null,
                        'buyer_edrpou' => $recipientEdrpou !== '' ? $recipientEdrpou : null,
                        'buyer_address' => $recipientAddress !== '' ? $recipientAddress : null,
                        'buyer_email' => $recipientEmail !== '' ? $recipientEmail : null,
                        'buyer_phone' => $recipientPhone !== '' ? $recipientPhone : null,
                        'recipient_legal_name' => $recipientLegalName !== '' ? $recipientLegalName : null,
                        'recipient_short_name' => $recipientShortName !== '' ? $recipientShortName : null,
                        'recipient_edrpou' => $recipientEdrpou !== '' ? $recipientEdrpou : null,
                        'recipient_tax_number' => $recipientTaxNumber !== '' ? $recipientTaxNumber : null,
                        'recipient_legal_address' => $recipientAddress !== '' ? $recipientAddress : null,
                        'recipient_email' => $recipientEmail !== '' ? $recipientEmail : null,
                        'recipient_phone' => $recipientPhone !== '' ? $recipientPhone : null,
                        'contact_name' => $contactName !== '' ? $contactName : null,
                        'contact_email' => $contactEmail !== '' ? $contactEmail : null,
                        'contact_phone' => $contactPhone !== '' ? $contactPhone : null,
                        'total_amount_uah' => $total,
                        'total_with_vat_uah' => $total,
                        'payment_purpose' => $paymentPurpose,
                        'payment_due_date' => $paymentDueDate !== '' ? $paymentDueDate : null,
                        'expected_payment_date' => $paymentDueDate !== '' ? $paymentDueDate : null,
                        'document_due_date' => $documentDueDate !== '' ? $documentDueDate : null,
                        'docs_type' => $docsType,
                        'note' => $note !== '' ? $note : null,
                        'id' => $invoiceId,
                    ]);
                    db()->prepare('DELETE FROM db_invoice_items WHERE invoice_id = :id')->execute(['id' => $invoiceId]);
                    invoice_insert_items(db(), $invoiceId, $items);
                    db()->commit();

                    if ($action === 'generate_invoice' || $action === 'generate_delivery' || $action === 'generate_act' || $action === 'generate_selected') {
                        $updatedInvoice = invoice_load($invoiceId);
                        $updatedItems = invoice_items($invoiceId);
                        if ($updatedInvoice) {
                            $documentType = 'invoice';
                            if ($action === 'generate_selected') {
                                $documentType = (string) ($_POST['document_type'] ?? 'invoice');
                                if (!in_array($documentType, ['invoice', 'delivery_note', 'act'], true)) {
                                    $documentType = 'invoice';
                                }
                            } elseif ($action === 'generate_delivery') {
                                $documentType = 'delivery_note';
                            } elseif ($action === 'generate_act') {
                                $documentType = 'act';
                            }
                            if ($documentType === 'act' && !invoice_seller_allows_act($updatedInvoice)) {
                                $error = 'Акт недоступний для products-only продавця.';
                            }
                            if ($error === '') {
                                $documentDate = trim((string) ($_POST['document_date'] ?? ''));
                                if ($documentDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $documentDate)) {
                                    $documentDate = (string) $updatedInvoice['invoice_date'];
                                }
                                if ($documentType !== 'invoice') {
                                    $updatedInvoice['invoice_date'] = $documentDate;
                                }
                                $generated = invoice_generate_document($updatedInvoice, $updatedItems, $documentType);
                                $documentId = invoice_store_document($invoiceId, $documentType, $documentDate, (string) $generated['path'], (string) $updatedInvoice['invoice_number']);
                                $legacyDocumentType = $documentType === 'act' ? (string) $updatedInvoice['document_type'] : $documentType;
                                $stmt = db()->prepare("
                                    UPDATE db_invoices
                                    SET document_type = :document_type,
                                        pdf_file_path = :pdf_file_path,
                                        payment_status = CASE WHEN payment_status = 'paid' THEN payment_status ELSE 'waiting_payment' END,
                                        status = CASE WHEN status = 'paid' THEN status ELSE 'sent' END,
                                        sent_at = COALESCE(sent_at, NOW()),
                                        payment_due_date = COALESCE(payment_due_date, DATE_ADD(CURDATE(), INTERVAL 3 DAY)),
                                        expected_payment_date = COALESCE(expected_payment_date, payment_due_date, DATE_ADD(CURDATE(), INTERVAL 3 DAY))
                                    WHERE id = :id
                                ");
                                $stmt->execute([
                                    'document_type' => $legacyDocumentType,
                                    'pdf_file_path' => $generated['path'],
                                    'id' => $invoiceId,
                                ]);
                                if ($generated['is_pdf']) {
                                    redirect_to('/invoices.php?document=' . $documentId);
                                }
                                $error = 'PDF не створено: на сервері не підключився Dompdf/wkhtmltopdf або PDF-рендер вимкнений. Збережено тільки HTML-шаблон для діагностики.';
                            }
                        }
                    } else {
                        $message = 'Invoice saved.';
                    }
                }
            }
        }
    }
}

$editId = (int) ($_GET['edit'] ?? $_POST['id'] ?? 0);
if ($editId > 0) {
    $editInvoice = invoice_load($editId);
    if ($editInvoice) {
        $editItems = invoice_items($editId);
        $editLegalEntities = invoice_legal_entities(!empty($editInvoice['client_company_id']) ? (int) $editInvoice['client_company_id'] : null);
        $editContacts = invoice_contacts(!empty($editInvoice['client_company_id']) ? (int) $editInvoice['client_company_id'] : null);
        $editClientCompany = invoice_client_company_by_id(!empty($editInvoice['client_company_id']) ? (int) $editInvoice['client_company_id'] : null);
    }
}

$invoices = db()->query("
    SELECT i.*, c.short_name AS seller_short_name, c.allowed_item_type
    FROM db_invoices i
    LEFT JOIN db_our_companies c ON c.id = i.seller_company_id
    ORDER BY i.invoice_date DESC, i.id DESC
    LIMIT 100
")->fetchAll();

$invoiceDocuments = [];
$invoiceIds = array_map(static fn($row) => (int) $row['id'], $invoices);
if ($invoiceIds) {
    $placeholders = implode(',', array_fill(0, count($invoiceIds), '?'));
    $stmt = db()->prepare("
        SELECT *
        FROM db_invoice_documents
        WHERE invoice_id IN ($placeholders)
        ORDER BY document_date DESC, id DESC
    ");
    $stmt->execute($invoiceIds);
    foreach ($stmt->fetchAll() as $documentRow) {
        $invoiceDocuments[(int) $documentRow['invoice_id']][] = $documentRow;
    }
}

$draftCount = 0;
$paidCount = 0;
$openTotal = 0;
$overdueCount = 0;
$overdueTotal = 0;
$docsOpenCount = 0;
foreach ($invoices as $invoiceRow) {
    $paymentStatus = (string) ($invoiceRow['payment_status'] ?? 'draft');
    $documentStatus = (string) ($invoiceRow['document_status'] ?? 'not_sent');
    if ($paymentStatus === 'draft') {
        $draftCount++;
    }
    if ($paymentStatus === 'paid') {
        $paidCount++;
    }
    if ($paymentStatus === 'waiting_payment') {
        $openTotal += (float) $invoiceRow['total_with_vat_uah'];
    }
    if (invoice_is_overdue($invoiceRow)) {
        $overdueCount++;
        $overdueTotal += (float) $invoiceRow['total_with_vat_uah'];
    }
    if ($paymentStatus === 'paid' && $documentStatus !== 'closed') {
        $docsOpenCount++;
    }
}
?>
<!doctype html>
<html lang="uk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Рахунки | .BRAND DB</title>
    <link rel="stylesheet" href="<?= e(asset_path('/assets/app.css')) ?>">
</head>
<body>
    <main class="page">
        <header class="topbar">
            <div class="brand-block">
                <p class="eyebrow">Documents</p>
                <h1>Рахунки</h1>
                <p class="muted">Редаговані рахунки і видаткові з локальних замовлень KeyCRM</p>
            </div>
            <nav class="nav">
                <a href="<?= e(base_path('/index.php')) ?>">Дашборд</a>
                <a class="active" href="<?= e(base_path('/invoices.php')) ?>">Рахунки</a>
                <a href="<?= e(base_path('/payment_requisites.php')) ?>">Реквізити оплати</a>
                <?php if (in_array(user_role(), ['ceo', 'accountant'], true)): ?>
                    <a href="<?= e(base_path('/our_companies.php')) ?>">Наші компанії</a>
                <?php endif; ?>
                <?php if (user_role() === 'ceo'): ?>
                    <a href="<?= e(base_path('/targets.php')) ?>">Плани</a>
                <?php endif; ?>
                <a href="<?= e(base_path('/expenses.php')) ?>">Витрати</a>
                <?php if (user_role() === 'ceo'): ?>
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
                <span class="label">Очікуємо оплату</span>
                <strong><?= e(invoice_money($openTotal)) ?></strong>
            </div>
            <div class="kpi-card <?= $overdueCount > 0 ? 'danger' : '' ?>">
                <span class="label">Прострочено</span>
                <strong><?= e(invoice_money($overdueTotal)) ?></strong>
                <small><?= e((string) $overdueCount) ?> рахунків після дедлайну оплати</small>
            </div>
            <div class="kpi-card progress-card">
                <span class="label">Оплачено</span>
                <strong><?= e((string) $paidCount) ?></strong>
            </div>
            <div class="kpi-card">
                <span class="label">Документи не закрито</span>
                <strong><?= e((string) $docsOpenCount) ?></strong>
            </div>
        </section>

        <details class="panel dashboard-section collapsible-expense-form invoice-create-panel" <?= ($error !== '' && !$editInvoice) ? 'open' : '' ?>>
            <summary>
                <span class="add-circle" aria-hidden="true"></span>
                <span>
                    <span class="label">Новий рахунок</span>
                    <strong>Додати рахунок з KeyCRM</strong>
                </span>
                <small>розгорнути форму</small>
            </summary>
            <form class="toolbar invoice-create-toolbar" method="post" action="<?= e(base_path('/invoices.php')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create_from_order">
                <label>
                    <span>KeyCRM order id</span>
                    <input type="number" min="1" name="order_id" required placeholder="9232">
                </label>
                <label>
                    <span>Від кого рахунок</span>
                    <select name="seller_company_id" data-company-account-source>
                        <?php foreach ($companies as $company): ?>
                            <option value="<?= e((string) $company['id']) ?>">
                                <?= e((string) $company['short_name']) ?> · <?= e((string) $company['tax_mode']) ?> · <?= e((string) $company['allowed_item_type']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    <span>Рахунок / IBAN</span>
                    <select name="seller_account_id" data-company-account-target>
                        <option value="">Default UAH</option>
                        <?php foreach ($companyAccounts as $account): ?>
                            <option value="<?= e((string) $account['id']) ?>" data-company-id="<?= e((string) $account['company_id']) ?>">
                                <?= e((string) ($account['short_name'] ?? '')) ?> · <?= e(our_account_label($account)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button type="submit">Створити з замовлення</button>
            </form>
            <p class="muted invoice-note">Рахунок створюється як редагована копія даних з `db_orders.raw_json`; KeyCRM не змінюється.</p>
        </details>

        <?php if ($editInvoice): ?>
            <section class="panel form-section dashboard-section">
                <div class="section-heading">
                    <div>
                        <span class="label">Редагування</span>
                        <h2><?= e((string) $editInvoice['invoice_number']) ?> · <?= invoice_payment_badge($editInvoice) ?> <?= invoice_document_badge($editInvoice) ?></h2>
                    </div>
                </div>

                <form method="post" action="<?= e(base_path('/invoices.php?edit=' . (int) $editInvoice['id'])) ?>" class="invoice-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= e((string) $editInvoice['id']) ?>">

                    <div class="invoice-edit-grid">
                        <div class="section-label">
                            <span class="label">Від кого</span>
                        </div>
                        <label>
                            <span>Від кого рахунок</span>
                            <select name="seller_company_id" data-company-account-source>
                                <?php foreach ($companies as $company): ?>
                                    <option value="<?= e((string) $company['id']) ?>" <?= (int) $editInvoice['seller_company_id'] === (int) $company['id'] ? 'selected' : '' ?>>
                                        <?= e((string) $company['short_name']) ?> · <?= e((string) $company['tax_mode']) ?> · <?= e((string) $company['allowed_item_type']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>
                            <span>Рахунок / IBAN</span>
                            <select name="seller_account_id" data-company-account-target>
                                <option value="">Default UAH</option>
                                <?php foreach ($companyAccounts as $account): ?>
                                    <option value="<?= e((string) $account['id']) ?>" data-company-id="<?= e((string) $account['company_id']) ?>" <?= (int) ($editInvoice['seller_account_id'] ?? 0) === (int) $account['id'] ? 'selected' : '' ?>>
                                        <?= e((string) ($account['short_name'] ?? '')) ?> · <?= e(our_account_label($account)) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <div class="wide-field seller-details">
                            <span class="label">Дані продавця</span>
                            <strong><?= e((string) ($editInvoice['legal_name'] ?: $editInvoice['short_name'])) ?></strong>
                            <small><?= e((string) $editInvoice['iban']) ?> · <?= e((string) $editInvoice['edrpou']) ?> · <?= e((string) $editInvoice['bank']) ?><?= !empty($editInvoice['account_label']) ? ' · ' . e((string) $editInvoice['account_label']) : '' ?></small>
                            <?php if (($editInvoice['tax_mode'] ?? '') === 'vat_20'): ?>
                                <small class="field-warning">ПДВ шаблон ще не реалізований.</small>
                            <?php endif; ?>
                            <?php if (($editInvoice['account_language'] ?? 'uk') === 'en' || strtoupper((string) ($editInvoice['account_currency'] ?? 'UAH')) !== 'UAH'): ?>
                                <small class="field-warning">English currency invoice template is not implemented yet.</small>
                            <?php endif; ?>
                        </div>
                        <div class="section-label">
                            <span class="label">Клієнт</span>
                        </div>
                        <label class="wide-field">
                            <span>Компанія</span>
                            <input
                                class="client-autocomplete-input"
                                data-autocomplete-type="company"
                                data-target-id="client-company-id"
                                data-target-label="client-company-label"
                                value="<?= e(invoice_client_company_label($editClientCompany)) ?>"
                                autocomplete="off"
                                placeholder="Почніть вводити назву компанії"
                            >
                            <input type="hidden" id="client-company-id" name="client_company_id" value="<?= e((string) ($editInvoice['client_company_id'] ?? '')) ?>">
                            <small id="client-company-label"><?= e(invoice_client_company_label($editClientCompany) ?: 'Локальна компанія не вибрана') ?></small>
                        </label>
                        <label class="wide-field">
                            <span>Повна назва юрособи-платника</span>
                            <input name="recipient_legal_name" value="<?= e(invoice_recipient_name($editInvoice)) ?>" placeholder="Заповніть юрособу-платника">
                            <?php if (invoice_recipient_name($editInvoice) === ''): ?>
                                <small class="field-warning">Немає платника — заповніть або виберіть юрособу</small>
                            <?php endif; ?>
                        </label>
                        <label class="wide-field">
                            <span>Пошук юрособи</span>
                            <input
                                class="client-autocomplete-input"
                                data-autocomplete-type="legal_entity"
                                data-target-id="client-legal-entity-id"
                                data-target-label="client-legal-entity-label"
                                data-fill-recipient="1"
                                autocomplete="off"
                                placeholder="Знайти збережену юрособу"
                            >
                            <input type="hidden" id="client-legal-entity-id" name="client_legal_entity_id" value="<?= e((string) ($editInvoice['client_legal_entity_id'] ?? '')) ?>">
                            <small id="client-legal-entity-label"><?= e((string) ($editInvoice['client_legal_entity_id'] ? 'Юрособа вибрана' : 'Юрособа не вибрана')) ?></small>
                        </label>
                        <label class="wide-field">
                            <span>Контактна особа</span>
                            <input name="contact_name" value="<?= e((string) (($editInvoice['contact_name'] ?? '') ?: ($editInvoice['buyer_contact_name'] ?? ''))) ?>">
                        </label>
                        <label class="wide-field">
                            <span>Пошук контакту</span>
                            <input
                                class="client-autocomplete-input"
                                data-autocomplete-type="contact"
                                data-target-id="client-contact-id"
                                data-target-label="client-contact-label"
                                data-fill-contact="1"
                                autocomplete="off"
                                placeholder="Знайти контакт"
                            >
                            <input type="hidden" id="client-contact-id" name="client_contact_id" value="">
                            <small id="client-contact-label">Контакт вручну або не вибраний</small>
                        </label>
                        <label>
                            <span>Email</span>
                            <input name="contact_email" value="<?= e((string) (($editInvoice['contact_email'] ?? '') ?: ($editInvoice['buyer_email'] ?? ''))) ?>">
                        </label>
                        <label>
                            <span>Телефон</span>
                            <input name="contact_phone" value="<?= e((string) (($editInvoice['contact_phone'] ?? '') ?: ($editInvoice['buyer_phone'] ?? ''))) ?>">
                        </label>
                        <div class="wide-field row-actions">
                            <button type="submit" name="action" value="save_contact" class="button-secondary small-button">Зберегти контакт</button>
                        </div>
                        <input type="hidden" name="recipient_short_name" value="<?= e((string) (($editInvoice['recipient_short_name'] ?? '') ?: '')) ?>">
                        <input type="hidden" name="recipient_edrpou" value="<?= e((string) (($editInvoice['recipient_edrpou'] ?? '') ?: ($editInvoice['buyer_edrpou'] ?? ''))) ?>">
                        <input type="hidden" name="recipient_tax_number" value="<?= e((string) (($editInvoice['recipient_tax_number'] ?? '') ?: '')) ?>">
                        <input type="hidden" name="recipient_legal_address" value="<?= e((string) (($editInvoice['recipient_legal_address'] ?? '') ?: ($editInvoice['buyer_address'] ?? ''))) ?>">
                        <div class="wide-field row-actions">
                            <button type="submit" name="action" value="save_legal_entity" class="button-secondary small-button">Створити / оновити юрособу</button>
                            <button type="submit" name="action" value="set_default_legal_entity" class="button-secondary small-button">Зробити default</button>
                        </div>
                        <div class="section-label">
                            <span class="label">Документ</span>
                        </div>
                        <label>
                            <span>Тип документа</span>
                            <select name="document_type">
                                <?php foreach (invoice_document_types_for_seller($editInvoice) as $value => $label): ?>
                                    <option value="<?= e($value) ?>" <?= (string) $editInvoice['document_type'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>
                            <span>Номер</span>
                            <input name="invoice_number" required value="<?= e((string) $editInvoice['invoice_number']) ?>">
                        </label>
                        <label>
                            <span>Дата рахунку</span>
                            <input type="date" name="invoice_date" required value="<?= e((string) $editInvoice['invoice_date']) ?>">
                        </label>
                        <input type="hidden" name="payment_due_date" value="<?= e((string) (($editInvoice['payment_due_date'] ?? '') ?: ($editInvoice['expected_payment_date'] ?? ''))) ?>">
                        <input type="hidden" name="document_due_date" value="<?= e((string) (($editInvoice['document_due_date'] ?? '') ?: $editInvoice['invoice_date'])) ?>">
                        <input type="hidden" name="document_date" value="<?= e((string) (($editInvoice['document_due_date'] ?? '') ?: $editInvoice['invoice_date'])) ?>">
                        <input type="hidden" name="docs_type" value="<?= e((string) (($editInvoice['docs_type'] ?? '') ?: 'none')) ?>">
                        <input type="hidden" name="payment_purpose" value="<?= e((string) $editInvoice['payment_purpose']) ?>">
                        <input type="hidden" name="note" value="<?= e((string) $editInvoice['note']) ?>">
                    </div>

                    <div class="section-heading invoice-items-heading">
                        <div>
                            <span class="label">Позиції</span>
                            <h2>Редагована копія товарів</h2>
                        </div>
                        <strong><?= e(invoice_money($editInvoice['total_with_vat_uah'] ?? $editInvoice['total_amount_uah'])) ?></strong>
                    </div>

                    <div class="table-wrap">
                        <table class="invoice-items-table" id="invoice-items-table">
                            <thead>
                                <tr>
                                    <th>Назва</th>
                                    <th>Од.</th>
                                    <th class="num">К-сть</th>
                                    <th class="num">Ціна</th>
                                    <th class="num">Разом</th>
                                    <th class="actions-cell"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($editItems as $item): ?>
                                    <tr>
                                        <td>
                                            <input type="hidden" name="item_source_product_id[]" value="<?= e((string) ($item['source_product_id'] ?? '')) ?>">
                                            <input type="hidden" name="item_source_product_name[]" value="<?= e((string) ($item['source_product_name'] ?? '')) ?>">
                                            <input type="hidden" name="item_source_product_sku[]" value="<?= e((string) ($item['source_product_sku'] ?? '')) ?>">
                                            <input type="hidden" name="item_source_offer_id[]" value="<?= e((string) ($item['source_offer_id'] ?? '')) ?>">
                                            <input type="hidden" name="item_source_product_json[]" value="<?= e((string) ($item['source_product_json'] ?? '')) ?>">
                                            <input type="hidden" name="item_type[]" value="<?= e((string) (($item['item_type'] ?? '') ?: 'product')) ?>">
                                            <input name="item_title[]" required value="<?= e((string) $item['title']) ?>">
                                        </td>
                                        <td><input class="mini-input" name="item_unit[]" value="<?= e((string) $item['unit']) ?>"></td>
                                        <td><input class="mini-input num-input" type="number" step="0.001" min="0" name="item_quantity[]" value="<?= e((string) $item['quantity']) ?>"></td>
                                        <td><input class="money-input" type="number" step="0.01" min="0" name="item_price_uah[]" value="<?= e((string) $item['price_uah']) ?>"></td>
                                        <td><input class="money-input" type="number" step="0.01" min="0" name="item_amount_uah[]" value="<?= e((string) $item['amount_uah']) ?>"></td>
                                        <td class="actions-cell"><button type="button" class="button-secondary small-button invoice-remove-row">Видалити</button></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <button type="button" class="button-secondary small-button invoice-add-row">+ Додати рядок</button>

                    <div class="invoice-actions">
                        <button type="submit" name="action" value="save_invoice">Зберегти</button>
                        <button type="submit" name="action" value="generate_selected" class="button-secondary">Сформувати PDF</button>
                        <a class="button-secondary" href="<?= e(base_path('/invoices.php')) ?>">Закрити</a>
                    </div>

                    <div class="invoice-actions invoice-actions--secondary">
                        <span class="label">Товари з CRM</span>
                        <button type="submit" name="action" value="use_detailed" class="button-secondary small-button">Детальні товари CRM</button>
                        <button type="submit" name="action" value="collapse_one" class="button-secondary small-button">Один рядок</button>
                        <label class="collapse-field">
                            <span>Назва згорнутого рядка</span>
                            <input name="collapse_title" value="Поліграфічна продукція">
                        </label>
                        <button type="submit" name="action" value="collapse_manual" class="button-secondary small-button">Згорнути з цією назвою</button>
                    </div>
                </form>
            </section>
        <?php endif; ?>

        <section class="panel table-panel">
            <div class="section-heading padded">
                <div>
                    <span class="label">Основний список для роботи</span>
                    <h2>Реєстр рахунків</h2>
                </div>
            </div>
            <div class="table-wrap table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>Номер</th>
                            <th>Платник / контакт</th>
                            <th class="num">Сума</th>
                            <th>Оплата</th>
                            <th>Дедлайн оплати</th>
                            <th>Дата</th>
                            <th>Від кого</th>
                            <th>Файли</th>
                            <th>Статус документів</th>
                            <th>Дія</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$invoices): ?>
                            <tr><td colspan="10">Рахунків ще немає.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($invoices as $invoiceRow): ?>
                            <?php
                            $documentButtons = [
                                'invoice' => '',
                                'delivery_note' => '',
                                'act' => '',
                            ];
                            if (!empty($invoiceDocuments[(int) $invoiceRow['id']])) {
                                foreach ($invoiceDocuments[(int) $invoiceRow['id']] as $documentRow) {
                                    if (strtolower(pathinfo((string) $documentRow['file_path'], PATHINFO_EXTENSION)) === 'pdf') {
                                        $documentUrl = base_path('/invoices.php?document=' . (int) $documentRow['id']);
                                        $documentType = (string) $documentRow['document_type'];
                                        if (array_key_exists($documentType, $documentButtons) && $documentButtons[$documentType] === '') {
                                            $documentButtons[$documentType] = $documentUrl;
                                        }
                                    }
                                }
                            }
                            if ($documentButtons['invoice'] === '' && invoice_pdf_available($invoiceRow)) {
                                $documentButtons['invoice'] = base_path('/invoices.php?download=' . (int) $invoiceRow['id']);
                            }
                            $recipientName = invoice_recipient_name($invoiceRow);
                            $contactName = invoice_contact_name($invoiceRow);
                            ?>
                            <tr>
                                <td class="<?= invoice_is_overdue($invoiceRow) ? 'flag-danger-cell' : '' ?>"><strong><?= e((string) $invoiceRow['invoice_number']) ?></strong></td>
                                <td class="wrap">
                                    <?php if ($recipientName !== ''): ?>
                                        <strong><?= e($recipientName) ?></strong>
                                    <?php else: ?>
                                        <span class="status-badge status-badge--warning">немає платника</span>
                                    <?php endif; ?>
                                    <?php if ($contactName !== ''): ?>
                                        <small><?= e($contactName) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td class="num"><?= e(invoice_money($invoiceRow['total_with_vat_uah'] ?? 0)) ?></td>
                                <td>
                                    <form method="post" action="<?= e(base_path('/invoices.php')) ?>" class="inline-cell-form">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="status">
                                        <input type="hidden" name="status_type" value="payment">
                                        <input type="hidden" name="id" value="<?= e((string) $invoiceRow['id']) ?>">
                                        <input type="hidden" name="return_to" value="registry">
                                        <select name="status_action" class="compact-select registry-status-select" data-invoice-number="<?= e((string) $invoiceRow['invoice_number']) ?>">
                                            <?php
                                            $registryStatus = (string) ($invoiceRow['payment_status'] ?? 'draft');
                                            if ($registryStatus === 'draft' || $registryStatus === 'canceled') {
                                                $registryStatus = 'waiting_payment';
                                            }
                                            foreach ([
                                                'waiting_payment' => 'Очікуємо оплату',
                                                'paid' => 'Оплачено',
                                                'problem' => 'Проблема',
                                            ] as $statusValue => $statusLabel):
                                            ?>
                                                <option value="<?= e($statusValue) ?>" <?= $registryStatus === $statusValue ? 'selected' : '' ?>><?= e($statusLabel) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </form>
                                </td>
                                <td>
                                    <form method="post" action="<?= e(base_path('/invoices.php')) ?>" class="inline-cell-form payment-control-form">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="payment_due_date">
                                        <input type="hidden" name="id" value="<?= e((string) $invoiceRow['id']) ?>">
                                        <input class="deadline-date <?= e(invoice_deadline_class($invoiceRow)) ?>" type="date" name="payment_due_date" value="<?= e((string) (($invoiceRow['payment_due_date'] ?? '') ?: ($invoiceRow['expected_payment_date'] ?? ''))) ?>" onchange="this.form.submit()">
                                    </form>
                                </td>
                                <td class="registry-date">
                                    <?= e(invoice_date_label((string) $invoiceRow['invoice_date'])) ?>
                                    <small>/ <?= e(invoice_datetime_time_label((string) ($invoiceRow['updated_at'] ?: $invoiceRow['created_at']))) ?></small>
                                </td>
                                <td><?= e((string) ($invoiceRow['seller_short_name'] ?: '—')) ?></td>
                                <td>
                                    <div class="invoice-doc-actions">
                                        <?php foreach (array_keys(invoice_document_types_for_seller($invoiceRow)) as $documentType): ?>
                                            <?php if ($documentButtons[$documentType] !== ''): ?>
                                                <a class="file-chip file-chip--ready" href="<?= e($documentButtons[$documentType]) ?>"><?= e(invoice_document_prefix_label($documentType)) ?></a>
                                            <?php else: ?>
                                                <form method="post" action="<?= e(base_path('/invoices.php')) ?>" class="inline-cell-form">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="generate_registry_document">
                                                    <input type="hidden" name="id" value="<?= e((string) $invoiceRow['id']) ?>">
                                                    <input type="hidden" name="document_type" value="<?= e($documentType) ?>">
                                                    <button type="submit" class="file-chip"><?= e(invoice_document_prefix_label($documentType)) ?></button>
                                                </form>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                        <?php if (array_filter($documentButtons)): ?>
                                            <a class="file-chip file-chip--package" href="<?= e(base_path('/invoices.php?package=' . (int) $invoiceRow['id'])) ?>">Пакет</a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <form method="post" action="<?= e(base_path('/invoices.php')) ?>" class="inline-cell-form">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="status">
                                        <input type="hidden" name="status_type" value="document">
                                        <input type="hidden" name="id" value="<?= e((string) $invoiceRow['id']) ?>">
                                        <input type="hidden" name="return_to" value="registry">
                                        <select name="status_action" class="compact-select" onchange="this.form.submit()">
                                            <?php $documentRegistryStatus = (string) ($invoiceRow['document_status'] ?? 'not_sent'); ?>
                                            <?php foreach ([
                                                'not_sent' => 'Не надіслано',
                                                'sent' => 'Надіслано',
                                                'closed' => 'Закрито',
                                                'problem' => 'Проблема',
                                            ] as $statusValue => $statusLabel): ?>
                                                <option value="<?= e($statusValue) ?>" <?= $documentRegistryStatus === $statusValue ? 'selected' : '' ?>><?= e($statusLabel) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </form>
                                </td>
                                <td>
                                    <div class="row-actions">
                                        <a class="button small-button" href="<?= e(base_path('/invoices.php?edit=' . (int) $invoiceRow['id'])) ?>">Редагувати</a>
                                        <form method="post" action="<?= e(base_path('/invoices.php')) ?>" class="inline-cell-form">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="delete_invoice">
                                            <input type="hidden" name="id" value="<?= e((string) $invoiceRow['id']) ?>">
                                            <button type="submit" class="button-danger small-button icon-delete-button" data-confirm="Видалити рахунок <?= e((string) $invoiceRow['invoice_number']) ?>?">×</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
        <?= app_version_badge() ?>
    </main>
    <script>
        (function () {
            function syncAccountOptions(select) {
                var form = select.closest('form');
                if (!form) return;
                var accountSelect = form.querySelector('[data-company-account-target]');
                if (!accountSelect) return;
                var companyId = select.value || '';
                var selectedVisible = false;
                accountSelect.querySelectorAll('option').forEach(function (option) {
                    var optionCompanyId = option.dataset.companyId || '';
                    var visible = option.value === '' || optionCompanyId === companyId;
                    option.hidden = !visible;
                    option.disabled = !visible;
                    if (visible && option.selected) selectedVisible = true;
                });
                if (!selectedVisible) {
                    accountSelect.value = '';
                }
            }

            document.querySelectorAll('[data-company-account-source]').forEach(function (select) {
                syncAccountOptions(select);
                select.addEventListener('change', function () {
                    syncAccountOptions(select);
                });
            });
        })();

        (function () {
            var endpoint = '<?= e(base_path('/ajax_client_search.php')) ?>';

            function debounce(fn, wait) {
                var timeout;
                return function () {
                    var args = arguments;
                    window.clearTimeout(timeout);
                    timeout = window.setTimeout(function () {
                        fn.apply(null, args);
                    }, wait);
                };
            }

            function closeResults(input) {
                var box = input.parentElement.querySelector('.autocomplete-results');
                if (box) {
                    box.remove();
                }
            }

            function renderResults(input, results) {
                closeResults(input);
                var box = document.createElement('div');
                box.className = 'autocomplete-results';
                if (!results.length) {
                    box.innerHTML = '<div class="autocomplete-empty">Нічого не знайдено</div>';
                    input.parentElement.appendChild(box);
                    return;
                }

                results.forEach(function (item) {
                    var button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'autocomplete-result';
                    button.innerHTML = '<strong></strong><small></small>';
                    button.querySelector('strong').textContent = item.label || '';
                    button.querySelector('small').textContent = item.subtitle || item.type || '';
                    button.addEventListener('click', function () {
                        var target = document.getElementById(input.dataset.targetId || '');
                        var label = document.getElementById(input.dataset.targetLabel || '');
                        if (target) {
                            target.value = item.client_company_id || item.id || '';
                            if (item.type === 'legal_entity') {
                                target.value = item.legal_entity_id || item.id || '';
                            }
                            if (item.type === 'contact') {
                                target.value = item.contact_id || item.id || '';
                            }
                        }
                        if (label) {
                            label.textContent = item.subtitle ? item.label + ' · ' + item.subtitle : item.label;
                        }
                        input.value = item.label || '';

                        if (item.client_company_id && input.dataset.autocompleteType !== 'company') {
                            var companyTarget = document.getElementById('client-company-id');
                            if (companyTarget && !companyTarget.value) {
                                companyTarget.value = item.client_company_id;
                            }
                        }
                        if (input.dataset.autocompleteType === 'company') {
                            var legalTarget = document.getElementById('client-legal-entity-id');
                            var contactTarget = document.getElementById('client-contact-id');
                            if (legalTarget) legalTarget.value = '';
                            if (contactTarget) contactTarget.value = '';
                        }
                        if (input.dataset.fillRecipient === '1') {
                            var recipient = document.querySelector('[name="recipient_legal_name"]');
                            if (recipient) recipient.value = item.label || '';
                        }
                        if (input.dataset.fillContact === '1') {
                            var contact = document.querySelector('[name="contact_name"]');
                            if (contact) contact.value = item.label || '';
                            var contactEmail = document.querySelector('[name="contact_email"]');
                            if (contactEmail && item.email) contactEmail.value = item.email || '';
                            var contactPhone = document.querySelector('[name="contact_phone"]');
                            if (contactPhone && item.phone) contactPhone.value = item.phone || '';
                        }
                        closeResults(input);
                    });
                    box.appendChild(button);
                });
                input.parentElement.appendChild(box);
            }

            var search = debounce(function (input) {
                var q = input.value.trim();
                if (q.length < 2) {
                    closeResults(input);
                    return;
                }
                var params = new URLSearchParams({
                    q: q,
                    type: input.dataset.autocompleteType || 'all'
                });
                var companyId = document.getElementById('client-company-id');
                if (companyId && companyId.value && input.dataset.autocompleteType !== 'company') {
                    params.set('client_company_id', companyId.value);
                }
                fetch(endpoint + '?' + params.toString(), { credentials: 'same-origin' })
                    .then(function (response) { return response.json(); })
                    .then(function (data) { renderResults(input, data.results || []); })
                    .catch(function () { closeResults(input); });
            }, 220);

            document.querySelectorAll('.client-autocomplete-input').forEach(function (input) {
                input.addEventListener('input', function () {
                    search(input);
                });
                input.addEventListener('blur', function () {
                    window.setTimeout(function () { closeResults(input); }, 180);
                });
            });
        })();

        (function () {
            var table = document.getElementById('invoice-items-table');
            var addButton = document.querySelector('.invoice-add-row');
            if (!table || !addButton) {
                return;
            }
            var tbody = table.querySelector('tbody');

            function rowCount() {
                return tbody.querySelectorAll('tr').length;
            }

            table.addEventListener('click', function (event) {
                var button = event.target.closest('.invoice-remove-row');
                if (!button) {
                    return;
                }
                if (rowCount() <= 1) {
                    return;
                }
                button.closest('tr').remove();
            });

            addButton.addEventListener('click', function () {
                var templateRow = tbody.querySelector('tr');
                if (!templateRow) {
                    return;
                }
                var row = templateRow.cloneNode(true);
                row.querySelectorAll('input').forEach(function (input) {
                    input.value = input.name === 'item_unit[]' ? 'шт' : '';
                });
                tbody.appendChild(row);
            });
        })();

        (function () {
            document.querySelectorAll('[data-confirm]').forEach(function (button) {
                button.addEventListener('click', function (event) {
                    if (!window.confirm(button.dataset.confirm)) {
                        event.preventDefault();
                    }
                });
            });
        })();

        (function () {
            document.querySelectorAll('.registry-status-select').forEach(function (select) {
                select.addEventListener('change', function () {
                    select.form.submit();
                });
            });
        })();
    </script>
</body>
</html>
