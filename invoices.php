<?php

require_once __DIR__ . '/bootstrap.php';
require_login();

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

function invoice_order_payload(array $dbOrder): array
{
    $raw = json_decode((string) ($dbOrder['raw_json'] ?? ''), true);
    $raw = is_array($raw) ? $raw : [];
    $buyer = is_array($raw['buyer'] ?? null) ? $raw['buyer'] : [];
    $company = is_array($raw['company'] ?? null) ? $raw['company'] : [];
    $products = is_array($raw['products'] ?? null) ? $raw['products'] : [];

    $companyTitle = trim((string) (($company['title'] ?? '') ?: ''));
    $companyName = trim((string) (($dbOrder['company_name'] ?? '') ?: ($company['name'] ?? '')));
    $contactName = trim((string) (($dbOrder['buyer_name'] ?? '') ?: ($buyer['full_name'] ?? ($buyer['name'] ?? ($dbOrder['client_name'] ?? '')))));
    $recipientName = $companyTitle !== '' ? $companyTitle : $companyName;
    if ($recipientName === '') {
        $recipientName = $contactName;
    }

    return [
        'buyer_id' => (int) (($dbOrder['buyer_id'] ?? 0) ?: ($buyer['id'] ?? 0)),
        'buyer_company_id' => (int) (($dbOrder['company_id'] ?? 0) ?: ($company['id'] ?? 0)),
        'buyer_display_name' => $recipientName,
        'buyer_contact_name' => $contactName,
        'company_name' => $companyName,
        'company_title' => $companyTitle,
        'company_raw_json' => $company ? json_encode($company, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        'buyer_raw_json' => $buyer ? json_encode($buyer, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        'manager_id' => (int) (($dbOrder['manager_id'] ?? 0) ?: invoice_get_path($raw, ['manager.id'])),
        'buyer_edrpou' => (string) invoice_get_path($raw, ['company.edrpou', 'company.code', 'buyer.edrpou', 'buyer.code']),
        'buyer_address' => (string) invoice_get_path($raw, ['company.address', 'company.legal_address', 'buyer.address']),
        'buyer_email' => (string) (($dbOrder['buyer_email'] ?? '') ?: invoice_get_path($raw, ['buyer.email', 'company.email'])),
        'buyer_phone' => (string) (($dbOrder['buyer_phone'] ?? '') ?: invoice_get_path($raw, ['buyer.phone', 'company.phone'])),
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
                SET name = :name,
                    title = :title,
                    manager_id = :manager_id,
                    raw_json = :raw_json,
                    synced_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([
                'name' => $payload['company_name'] ?: null,
                'title' => $payload['company_title'] ?: null,
                'manager_id' => !empty($payload['manager_id']) ? (int) $payload['manager_id'] : null,
                'raw_json' => $payload['company_raw_json'] ?: null,
                'id' => (int) $clientCompanyId,
            ]);
        } else {
            $stmt = db()->prepare("
                INSERT INTO db_client_companies
                    (keycrm_company_id, name, title, manager_id, raw_json, synced_at)
                VALUES
                    (:keycrm_company_id, :name, :title, :manager_id, :raw_json, NOW())
            ");
            $stmt->execute([
                'keycrm_company_id' => $keycrmCompanyId > 0 ? $keycrmCompanyId : null,
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
                'email' => $payload['buyer_email'] ?: null,
                'phone' => $payload['buyer_phone'] ?: null,
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
                'email' => $payload['buyer_email'] ?: null,
                'phone' => $payload['buyer_phone'] ?: null,
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
        $items[] = [
            'source_product_id' => (int) ($product['id'] ?? ($product['product_id'] ?? 0)),
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
            (invoice_id, source_product_id, title, unit, quantity, price_uah, amount_uah, sort_order)
        VALUES
            (:invoice_id, :source_product_id, :title, :unit, :quantity, :price_uah, :amount_uah, :sort_order)
    ");

    foreach ($items as $index => $item) {
        $stmt->execute([
            'invoice_id' => $invoiceId,
            'source_product_id' => $item['source_product_id'] ?: null,
            'title' => (string) $item['title'],
            'unit' => (string) ($item['unit'] ?: 'шт'),
            'quantity' => invoice_quantity_value($item['quantity'] ?? 1),
            'price_uah' => invoice_number_value($item['price_uah'] ?? 0),
            'amount_uah' => invoice_number_value($item['amount_uah'] ?? 0),
            'sort_order' => (int) ($item['sort_order'] ?? ($index + 1)),
        ]);
    }
}

function invoice_load(int $id): ?array
{
    $stmt = db()->prepare("
        SELECT i.*, c.short_name, c.legal_name, c.edrpou, c.iban, c.bank, c.address, c.email, c.phone,
               c.accountant_email, c.accountant_phone, c.tax_mode, c.allowed_item_type
        FROM db_invoices i
        LEFT JOIN db_our_companies c ON c.id = i.seller_company_id
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

function invoice_document_html(array $invoice, array $items, string $documentType): string
{
    $isDelivery = $documentType === 'delivery_note';
    $title = $isDelivery ? 'ВИДАТКОВА НАКЛАДНА' : 'РАХУНОК НА ОПЛАТУ';
    $seller = (string) ($invoice['legal_name'] ?: $invoice['short_name']);
    $buyer = (string) ($invoice['buyer_display_name'] ?: 'Одержувач не підтягнувся');
    $contact = (string) ($invoice['buyer_contact_name'] ?? '');
    $total = (float) ($invoice['total_with_vat_uah'] ?: $invoice['total_amount_uah']);

    ob_start();
    ?>
<!doctype html>
<html lang="uk">
<head>
    <meta charset="utf-8">
    <title><?= e($title . ' № ' . $invoice['invoice_number']) ?></title>
    <style>
        @page { size: A4; margin: 16mm; }
        body { margin: 0; color: #111; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif; font-size: 11px; line-height: 1.35; }
        .doc-header { display: flex; justify-content: space-between; gap: 24px; border-bottom: 2px solid #111; padding-bottom: 14px; margin-bottom: 18px; }
        .brand { font-size: 20px; font-weight: 800; letter-spacing: .03em; }
        .muted { color: #555; }
        .details { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; margin-bottom: 20px; }
        .box { border: 1px solid #ddd; padding: 10px; min-height: 86px; }
        h1 { margin: 18px 0 4px; text-align: center; font-size: 16px; letter-spacing: .04em; }
        .date { text-align: center; margin-bottom: 18px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #ddd; padding: 6px; vertical-align: top; }
        th { background: #f4f4f4; font-size: 10px; text-transform: uppercase; }
        .num { text-align: right; white-space: nowrap; }
        .center { text-align: center; }
        .total-row td { font-weight: 700; }
        .footer { margin-top: 20px; }
        .signatures { display: grid; grid-template-columns: 1fr 1fr; gap: 48px; margin-top: 48px; }
        .line { border-bottom: 1px solid #111; height: 28px; margin-bottom: 6px; }
    </style>
</head>
<body>
    <div class="doc-header">
        <div>
            <div class="brand">.BRAND</div>
            <div class="muted">bws.com.ua</div>
        </div>
        <div class="muted">Без ПДВ. Платник єдиного податку.</div>
    </div>

    <div class="details">
        <div class="box">
            <strong>Постачальник: <?= e($seller) ?></strong><br>
            рахунок IBAN: <?= e((string) $invoice['iban']) ?><br>
            ЄДРПОУ: <?= e((string) $invoice['edrpou']) ?><br>
            банк: <?= e((string) $invoice['bank']) ?><br>
            адреса друкарні: <?= e((string) $invoice['address']) ?><br>
            e-mail: <?= e((string) $invoice['email']) ?><br>
            офіс: <?= e((string) $invoice['phone']) ?><br>
            бухгалтерія: <?= e((string) $invoice['accountant_email']) ?>, <?= e((string) $invoice['accountant_phone']) ?>
        </div>
        <div class="box">
            <strong>Одержувач: <?= e($buyer) ?></strong><br>
            <?php if ($contact !== ''): ?>Контактна особа: <?= e($contact) ?><br><?php endif; ?>
            <?php if (!empty($invoice['buyer_edrpou'])): ?>ЄДРПОУ: <?= e((string) $invoice['buyer_edrpou']) ?><br><?php endif; ?>
            <?php if (!empty($invoice['buyer_address'])): ?>Адреса: <?= e((string) $invoice['buyer_address']) ?><br><?php endif; ?>
            <?php if (!empty($invoice['buyer_email'])): ?>E-mail: <?= e((string) $invoice['buyer_email']) ?><br><?php endif; ?>
            <?php if (!empty($invoice['buyer_phone'])): ?>Тел.: <?= e((string) $invoice['buyer_phone']) ?><br><?php endif; ?>
            <?php if ($isDelivery): ?>Платник: той самий<br>Умова продажу: Безготівковий розрахунок<?php endif; ?>
        </div>
    </div>

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
        <?php if (!$isDelivery): ?>
            <p>Рахунок дійсний протягом 3-х днів.</p>
            <p><strong>Призначення платежу:</strong> <?= e((string) $invoice['payment_purpose']) ?></p>
        <?php else: ?>
            <p>Замовник підтверджує відсутність претензій щодо обсягу, якості та терміну відвантаження продукції.</p>
        <?php endif; ?>
    </div>

    <div class="signatures">
        <div>
            <div class="line"></div>
            <div>Від постачальника</div>
        </div>
        <div>
            <div class="line"></div>
            <div>Від замовника</div>
        </div>
    </div>
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
    $base = ($documentType === 'delivery_note' ? 'DN_' : 'INV_') . $safeNumber;
    $htmlPath = $dir . '/' . $base . '.html';
    $pdfPath = $dir . '/' . $base . '.pdf';
    $html = invoice_document_html($invoice, $items, $documentType);
    file_put_contents($htmlPath, $html);

    $relativeHtml = 'storage/invoices/' . basename($htmlPath);
    $relativePdf = 'storage/invoices/' . basename($pdfPath);

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

    if (strtolower(pathinfo($filePath, PATHINFO_EXTENSION)) !== 'pdf') {
        http_response_code(404);
        echo 'PDF file not found. Generate PDF first.';
        exit;
    }

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . basename($filePath) . '"');
    header('Content-Length: ' . filesize($filePath));
    readfile($filePath);
    exit;
}

$companies = db()->query('SELECT * FROM db_our_companies WHERE is_active = 1 ORDER BY id ASC')->fetchAll();
$defaultCompany = $companies[0] ?? [];

if (isset($_GET['download'])) {
    $downloadInvoice = invoice_load((int) $_GET['download']);
    if ($downloadInvoice) {
        invoice_download_file($downloadInvoice);
    }
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

        $stmt = db()->prepare('SELECT * FROM db_orders WHERE keycrm_id = :keycrm_id LIMIT 1');
        $stmt->execute(['keycrm_id' => $orderId]);
        $dbOrder = $stmt->fetch();

        if (!$dbOrder) {
            $error = 'Order not found in local db_orders. Run sync first or check KeyCRM order id.';
        } else {
            $payload = invoice_order_payload($dbOrder);
            $clientSnapshot = invoice_store_client_snapshot($payload);
            $total = invoice_number_value($payload['total_amount_uah']);
            $number = trim((string) ($payload['order_number'] ?: $orderId));
            $purpose = 'за продукцію згідно рахунку № ' . $number . ' від ' . invoice_ua_date_label(date('Y-m-d'));

            $stmt = db()->prepare("
                INSERT INTO db_invoices
                    (keycrm_order_id, invoice_number, invoice_date, document_type, seller_company_id, buyer_id,
                     buyer_company_id, client_company_id, buyer_display_name, buyer_contact_name,
                     buyer_edrpou, buyer_address, buyer_email, buyer_phone,
                     total_amount_uah, vat_mode, vat_amount_uah, total_with_vat_uah, payment_purpose, created_by_user_id)
                VALUES
                    (:keycrm_order_id, :invoice_number, CURDATE(), 'invoice', :seller_company_id, :buyer_id,
                     :buyer_company_id, :client_company_id, :buyer_display_name, :buyer_contact_name,
                     :buyer_edrpou, :buyer_address, :buyer_email, :buyer_phone,
                     :total_amount_uah, 'no_vat', 0, :total_with_vat_uah, :payment_purpose, :created_by_user_id)
            ");
            $stmt->execute([
                'keycrm_order_id' => $orderId,
                'invoice_number' => $number,
                'seller_company_id' => $sellerId,
                'buyer_id' => $payload['buyer_id'] ?: null,
                'buyer_company_id' => $payload['buyer_company_id'] ?: null,
                'client_company_id' => $clientSnapshot['client_company_id'] ?: null,
                'buyer_display_name' => $payload['buyer_display_name'] ?: '',
                'buyer_contact_name' => $payload['buyer_contact_name'] ?: null,
                'buyer_edrpou' => $payload['buyer_edrpou'] ?: null,
                'buyer_address' => $payload['buyer_address'] ?: null,
                'buyer_email' => $payload['buyer_email'] ?: null,
                'buyer_phone' => $payload['buyer_phone'] ?: null,
                'total_amount_uah' => $total,
                'total_with_vat_uah' => $total,
                'payment_purpose' => $purpose,
                'created_by_user_id' => (int) (current_user()['id'] ?? 0),
            ]);

            $invoiceId = (int) db()->lastInsertId();
            invoice_insert_items(db(), $invoiceId, invoice_product_items($payload['products'], $total, $seller));
            redirect_to('/invoices.php?edit=' . $invoiceId);
        }
    } else {
        $invoiceId = (int) ($_POST['id'] ?? 0);
        $invoice = $invoiceId > 0 ? invoice_load($invoiceId) : null;

        if (!$invoice) {
            $error = 'Invoice not found.';
        } elseif ($action === 'status') {
            $statusAction = (string) ($_POST['status_action'] ?? '');
            $allowed = ['sent', 'paid', 'docs_sent', 'docs_closed', 'problem', 'canceled'];
            if (!in_array($statusAction, $allowed, true)) {
                $error = 'Invalid invoice status.';
            } else {
                $sets = [];
                $params = ['id' => $invoiceId];
                if ($statusAction === 'sent') {
                    $sets[] = "status = 'sent'";
                    $sets[] = 'sent_at = NOW()';
                } elseif ($statusAction === 'paid') {
                    $sets[] = "status = 'paid'";
                    $sets[] = 'paid_at = NOW()';
                } elseif ($statusAction === 'docs_sent') {
                    $sets[] = "status = 'docs_sent'";
                    $sets[] = "docs_status = 'sent'";
                    $sets[] = 'docs_sent_at = NOW()';
                } elseif ($statusAction === 'docs_closed') {
                    $sets[] = "status = 'docs_closed'";
                    $sets[] = "docs_status = 'closed'";
                    $sets[] = 'docs_closed_at = NOW()';
                } elseif ($statusAction === 'problem') {
                    $sets[] = "docs_status = 'problem'";
                } elseif ($statusAction === 'canceled') {
                    $sets[] = "status = 'canceled'";
                }
                db()->prepare('UPDATE db_invoices SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($params);
                redirect_to('/invoices.php?edit=' . $invoiceId);
            }
        } elseif (in_array($action, ['save_invoice', 'save_legal_entity', 'collapse_one', 'collapse_manual', 'use_detailed', 'generate_invoice', 'generate_delivery'], true)) {
            $sellerId = (int) ($_POST['seller_company_id'] ?? $invoice['seller_company_id']);
            $seller = $defaultCompany;
            foreach ($companies as $company) {
                if ((int) $company['id'] === $sellerId) {
                    $seller = $company;
                    break;
                }
            }

            if ($action === 'save_legal_entity') {
                $legalName = trim((string) ($_POST['buyer_display_name'] ?? ''));
                $clientCompanyId = (int) ($_POST['client_company_id'] ?? ($invoice['client_company_id'] ?? 0));
                if ($legalName === '') {
                    $error = 'Одержувач не підтягнувся. Вкажіть повну назву компанії перед збереженням юрособи.';
                } else {
                    if ($clientCompanyId <= 0) {
                        $stmt = db()->prepare("
                            INSERT INTO db_client_companies
                                (keycrm_company_id, name, title, synced_at)
                            VALUES
                                (:keycrm_company_id, :name, :title, NOW())
                        ");
                        $stmt->execute([
                            'keycrm_company_id' => !empty($invoice['buyer_company_id']) ? (int) $invoice['buyer_company_id'] : null,
                            'name' => $legalName,
                            'title' => $legalName,
                        ]);
                        $clientCompanyId = (int) db()->lastInsertId();
                    }

                    $stmt = db()->prepare("
                        INSERT INTO db_client_legal_entities
                            (client_company_id, legal_name, short_name, edrpou, legal_address, email, phone, is_default, note)
                        VALUES
                            (:client_company_id, :legal_name, :short_name, :edrpou, :legal_address, :email, :phone, 1, :note)
                    ");
                    $stmt->execute([
                        'client_company_id' => $clientCompanyId,
                        'legal_name' => $legalName,
                        'short_name' => $legalName,
                        'edrpou' => trim((string) ($_POST['buyer_edrpou'] ?? '')) ?: null,
                        'legal_address' => trim((string) ($_POST['buyer_address'] ?? '')) ?: null,
                        'email' => trim((string) ($_POST['buyer_email'] ?? '')) ?: null,
                        'phone' => trim((string) ($_POST['buyer_phone'] ?? '')) ?: null,
                        'note' => trim((string) ($_POST['note'] ?? '')) ?: null,
                    ]);
                    $legalEntityId = (int) db()->lastInsertId();

                    db()->prepare("
                        UPDATE db_client_legal_entities
                        SET is_default = CASE WHEN id = :id THEN 1 ELSE 0 END
                        WHERE client_company_id = :client_company_id
                    ")->execute([
                        'id' => $legalEntityId,
                        'client_company_id' => $clientCompanyId,
                    ]);

                    db()->prepare("
                        UPDATE db_invoices
                        SET client_company_id = :client_company_id,
                            client_legal_entity_id = :client_legal_entity_id,
                            buyer_display_name = :buyer_display_name,
                            buyer_edrpou = :buyer_edrpou,
                            buyer_address = :buyer_address,
                            buyer_email = :buyer_email,
                            buyer_phone = :buyer_phone
                        WHERE id = :id
                    ")->execute([
                        'client_company_id' => $clientCompanyId,
                        'client_legal_entity_id' => $legalEntityId,
                        'buyer_display_name' => $legalName,
                        'buyer_edrpou' => trim((string) ($_POST['buyer_edrpou'] ?? '')) ?: null,
                        'buyer_address' => trim((string) ($_POST['buyer_address'] ?? '')) ?: null,
                        'buyer_email' => trim((string) ($_POST['buyer_email'] ?? '')) ?: null,
                        'buyer_phone' => trim((string) ($_POST['buyer_phone'] ?? '')) ?: null,
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
                $buyerName = substr(trim((string) ($_POST['buyer_display_name'] ?? '')), 0, 255);
                $paymentPurpose = substr(trim((string) ($_POST['payment_purpose'] ?? '')), 0, 255);
                if ($paymentPurpose === '') {
                    $paymentPurpose = 'за продукцію згідно рахунку № ' . $invoiceNumber . ' від ' . invoice_ua_date_label($invoiceDate);
                }
                $note = trim((string) ($_POST['note'] ?? ''));
                $docsType = (string) ($_POST['docs_type'] ?? 'none');
                $clientCompanyId = (int) ($_POST['client_company_id'] ?? ($invoice['client_company_id'] ?? 0));
                $clientLegalEntityId = (int) ($_POST['client_legal_entity_id'] ?? ($invoice['client_legal_entity_id'] ?? 0));
                if (!in_array($docsType, ['none', 'paper', 'electronic', 'both'], true)) {
                    $docsType = 'none';
                }

                $titles = $_POST['item_title'] ?? [];
                $units = $_POST['item_unit'] ?? [];
                $quantities = $_POST['item_quantity'] ?? [];
                $prices = $_POST['item_price_uah'] ?? [];
                $amounts = $_POST['item_amount_uah'] ?? [];
                $sourceIds = $_POST['item_source_product_id'] ?? [];
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
                    $items[] = [
                        'source_product_id' => (int) ($sourceIds[$index] ?? 0),
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
                } else {
                    db()->beginTransaction();
                    $stmt = db()->prepare("
                        UPDATE db_invoices
                        SET invoice_number = :invoice_number,
                            invoice_date = :invoice_date,
                            seller_company_id = :seller_company_id,
                            client_company_id = :client_company_id,
                            client_legal_entity_id = :client_legal_entity_id,
                            buyer_display_name = :buyer_display_name,
                            buyer_contact_name = :buyer_contact_name,
                            buyer_edrpou = :buyer_edrpou,
                            buyer_address = :buyer_address,
                            buyer_email = :buyer_email,
                            buyer_phone = :buyer_phone,
                            total_amount_uah = :total_amount_uah,
                            vat_mode = 'no_vat',
                            vat_amount_uah = 0,
                            total_with_vat_uah = :total_with_vat_uah,
                            payment_purpose = :payment_purpose,
                            docs_type = :docs_type,
                            note = :note
                        WHERE id = :id
                    ");
                    $stmt->execute([
                        'invoice_number' => $invoiceNumber,
                        'invoice_date' => $invoiceDate,
                        'seller_company_id' => $sellerId,
                        'client_company_id' => $clientCompanyId > 0 ? $clientCompanyId : null,
                        'client_legal_entity_id' => $clientLegalEntityId > 0 ? $clientLegalEntityId : null,
                        'buyer_display_name' => $buyerName,
                        'buyer_contact_name' => trim((string) ($_POST['buyer_contact_name'] ?? '')) ?: null,
                        'buyer_edrpou' => trim((string) ($_POST['buyer_edrpou'] ?? '')) ?: null,
                        'buyer_address' => trim((string) ($_POST['buyer_address'] ?? '')) ?: null,
                        'buyer_email' => trim((string) ($_POST['buyer_email'] ?? '')) ?: null,
                        'buyer_phone' => trim((string) ($_POST['buyer_phone'] ?? '')) ?: null,
                        'total_amount_uah' => $total,
                        'total_with_vat_uah' => $total,
                        'payment_purpose' => $paymentPurpose,
                        'docs_type' => $docsType,
                        'note' => $note !== '' ? $note : null,
                        'id' => $invoiceId,
                    ]);
                    db()->prepare('DELETE FROM db_invoice_items WHERE invoice_id = :id')->execute(['id' => $invoiceId]);
                    invoice_insert_items(db(), $invoiceId, $items);
                    db()->commit();

                    if ($action === 'generate_invoice' || $action === 'generate_delivery') {
                        $updatedInvoice = invoice_load($invoiceId);
                        $updatedItems = invoice_items($invoiceId);
                        if ($updatedInvoice) {
                            $documentType = $action === 'generate_delivery' ? 'delivery_note' : 'invoice';
                            $generated = invoice_generate_document($updatedInvoice, $updatedItems, $documentType);
                            $stmt = db()->prepare('UPDATE db_invoices SET document_type = :document_type, pdf_file_path = :pdf_file_path WHERE id = :id');
                            $stmt->execute([
                                'document_type' => $documentType,
                                'pdf_file_path' => $generated['path'],
                                'id' => $invoiceId,
                            ]);
                            $message = $generated['is_pdf'] ? 'PDF saved.' : 'Printable HTML saved. Install wkhtmltopdf on server for automatic PDF files.';
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
    }
}

$invoices = db()->query("
    SELECT i.*, c.short_name AS seller_short_name
    FROM db_invoices i
    LEFT JOIN db_our_companies c ON c.id = i.seller_company_id
    ORDER BY i.invoice_date DESC, i.id DESC
    LIMIT 100
")->fetchAll();

$draftCount = 0;
$paidCount = 0;
$openTotal = 0;
foreach ($invoices as $invoiceRow) {
    if ((string) $invoiceRow['status'] === 'draft') {
        $draftCount++;
    }
    if ((string) $invoiceRow['status'] === 'paid') {
        $paidCount++;
    }
    if (!in_array((string) $invoiceRow['status'], ['paid', 'canceled'], true)) {
        $openTotal += (float) $invoiceRow['total_with_vat_uah'];
    }
}
?>
<!doctype html>
<html lang="uk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Рахунки | .BRAND DB</title>
    <link rel="stylesheet" href="<?= e(base_path('/assets/app.css')) ?>">
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
                <?php if (user_role() === 'ceo'): ?>
                    <a href="<?= e(base_path('/targets.php')) ?>">Плани</a>
                <?php endif; ?>
                <a href="<?= e(base_path('/expenses.php')) ?>">Витрати</a>
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
                <span class="label">Відкрито</span>
                <strong><?= e(invoice_money($openTotal)) ?></strong>
                <small>не оплачено і не скасовано</small>
            </div>
            <div class="kpi-card">
                <span class="label">Чернетки</span>
                <strong><?= e((string) $draftCount) ?></strong>
            </div>
            <div class="kpi-card progress-card">
                <span class="label">Оплачено</span>
                <strong><?= e((string) $paidCount) ?></strong>
            </div>
        </section>

        <section class="panel dashboard-section">
            <form class="toolbar" method="post" action="<?= e(base_path('/invoices.php')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create_from_order">
                <label>
                    <span>KeyCRM order id</span>
                    <input type="number" min="1" name="order_id" required placeholder="9232">
                </label>
                <label>
                    <span>Постачальник</span>
                    <select name="seller_company_id">
                        <?php foreach ($companies as $company): ?>
                            <option value="<?= e((string) $company['id']) ?>"><?= e((string) $company['short_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button type="submit">Створити з замовлення</button>
            </form>
            <p class="muted invoice-note">Рахунок створюється як редагована копія даних з `db_orders.raw_json`; KeyCRM не змінюється.</p>
        </section>

        <?php if ($editInvoice): ?>
            <section class="panel form-section dashboard-section">
                <div class="section-heading">
                    <div>
                        <span class="label">Редагування</span>
                        <h2><?= e((string) $editInvoice['invoice_number']) ?> · <?= invoice_status_badge((string) $editInvoice['status']) ?> <?= invoice_docs_badge((string) $editInvoice['docs_status']) ?></h2>
                    </div>
                    <div class="row-actions">
                        <?php if (invoice_pdf_available($editInvoice)): ?>
                            <a class="button-secondary small-button" href="<?= e(base_path('/invoices.php?download=' . (int) $editInvoice['id'])) ?>">PDF</a>
                        <?php endif; ?>
                        <a class="button-secondary small-button" href="<?= e(base_path('/invoices.php')) ?>">Закрити</a>
                    </div>
                </div>

                <form method="post" action="<?= e(base_path('/invoices.php?edit=' . (int) $editInvoice['id'])) ?>" class="invoice-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= e((string) $editInvoice['id']) ?>">
                    <input type="hidden" name="client_company_id" value="<?= e((string) ($editInvoice['client_company_id'] ?? '')) ?>">

                    <div class="invoice-edit-grid">
                        <label>
                            <span>Номер</span>
                            <input name="invoice_number" required value="<?= e((string) $editInvoice['invoice_number']) ?>">
                        </label>
                        <label>
                            <span>Дата</span>
                            <input type="date" name="invoice_date" required value="<?= e((string) $editInvoice['invoice_date']) ?>">
                        </label>
                        <label>
                            <span>Постачальник</span>
                            <select name="seller_company_id">
                                <?php foreach ($companies as $company): ?>
                                    <option value="<?= e((string) $company['id']) ?>" <?= (int) $editInvoice['seller_company_id'] === (int) $company['id'] ? 'selected' : '' ?>>
                                        <?= e((string) $company['short_name']) ?> · <?= e((string) $company['allowed_item_type']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>
                            <span>Тип документів</span>
                            <select name="docs_type">
                                <?php foreach (['none' => 'немає', 'paper' => 'паперові', 'electronic' => 'електронні', 'both' => 'обидва'] as $value => $label): ?>
                                    <option value="<?= e($value) ?>" <?= (string) $editInvoice['docs_type'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <?php if ($editLegalEntities): ?>
                            <label class="wide-field">
                                <span>Юрособа для рахунку</span>
                                <select name="client_legal_entity_id" id="legal-entity-select">
                                    <option value="">Поточні поля вручну</option>
                                    <?php foreach ($editLegalEntities as $entity): ?>
                                        <option
                                            value="<?= e((string) $entity['id']) ?>"
                                            <?= (int) ($editInvoice['client_legal_entity_id'] ?? 0) === (int) $entity['id'] ? 'selected' : '' ?>
                                            data-legal-name="<?= e((string) $entity['legal_name']) ?>"
                                            data-edrpou="<?= e((string) ($entity['edrpou'] ?? '')) ?>"
                                            data-address="<?= e((string) ($entity['legal_address'] ?? '')) ?>"
                                            data-email="<?= e((string) ($entity['email'] ?? '')) ?>"
                                            data-phone="<?= e((string) ($entity['phone'] ?? '')) ?>"
                                        >
                                            <?= e((string) $entity['legal_name']) ?><?= !empty($entity['edrpou']) ? ' · ' . e((string) $entity['edrpou']) : '' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                        <?php else: ?>
                            <input type="hidden" name="client_legal_entity_id" value="<?= e((string) ($editInvoice['client_legal_entity_id'] ?? '')) ?>">
                        <?php endif; ?>
                        <div class="wide-field">
                            <span class="label">Компанія / платник</span>
                        </div>
                        <label class="wide-field">
                            <span>Повна назва компанії</span>
                            <input name="buyer_display_name" value="<?= e((string) $editInvoice['buyer_display_name']) ?>" placeholder="Одержувач не підтягнувся">
                            <?php if (trim((string) ($editInvoice['buyer_display_name'] ?? '')) === ''): ?>
                                <small class="field-warning">Одержувач не підтягнувся</small>
                            <?php endif; ?>
                        </label>
                        <label class="wide-field">
                            <span>Контактна особа</span>
                            <input name="buyer_contact_name" value="<?= e((string) ($editInvoice['buyer_contact_name'] ?? '')) ?>">
                        </label>
                        <label>
                            <span>ЄДРПОУ покупця</span>
                            <input name="buyer_edrpou" value="<?= e((string) $editInvoice['buyer_edrpou']) ?>">
                        </label>
                        <label>
                            <span>Email покупця</span>
                            <input name="buyer_email" value="<?= e((string) $editInvoice['buyer_email']) ?>">
                        </label>
                        <label>
                            <span>Телефон покупця</span>
                            <input name="buyer_phone" value="<?= e((string) $editInvoice['buyer_phone']) ?>">
                        </label>
                        <label class="wide-field">
                            <span>Адреса покупця</span>
                            <input name="buyer_address" value="<?= e((string) $editInvoice['buyer_address']) ?>">
                        </label>
                        <div class="wide-field row-actions">
                            <button type="submit" name="action" value="save_legal_entity" class="button-secondary">Зберегти як юрособу клієнта</button>
                        </div>
                        <label class="wide-field">
                            <span>Призначення платежу</span>
                            <input name="payment_purpose" value="<?= e((string) $editInvoice['payment_purpose']) ?>">
                        </label>
                        <label class="wide-field">
                            <span>Нотатка</span>
                            <textarea name="note" rows="2"><?= e((string) $editInvoice['note']) ?></textarea>
                        </label>
                    </div>

                    <div class="section-heading invoice-items-heading">
                        <div>
                            <span class="label">Позиції</span>
                            <h2>Редагована копія товарів</h2>
                        </div>
                        <strong><?= e(invoice_money($editInvoice['total_with_vat_uah'] ?? $editInvoice['total_amount_uah'])) ?></strong>
                    </div>

                    <div class="table-wrap">
                        <table class="invoice-items-table">
                            <thead>
                                <tr>
                                    <th>Назва</th>
                                    <th>Од.</th>
                                    <th class="num">К-сть</th>
                                    <th class="num">Ціна</th>
                                    <th class="num">Разом</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($editItems as $item): ?>
                                    <tr>
                                        <td>
                                            <input type="hidden" name="item_source_product_id[]" value="<?= e((string) ($item['source_product_id'] ?? '')) ?>">
                                            <input name="item_title[]" value="<?= e((string) $item['title']) ?>">
                                        </td>
                                        <td><input class="mini-input" name="item_unit[]" value="<?= e((string) $item['unit']) ?>"></td>
                                        <td><input class="mini-input num-input" type="number" step="0.001" min="0" name="item_quantity[]" value="<?= e((string) $item['quantity']) ?>"></td>
                                        <td><input class="money-input" type="number" step="0.01" min="0" name="item_price_uah[]" value="<?= e((string) $item['price_uah']) ?>"></td>
                                        <td><input class="money-input" type="number" step="0.01" min="0" name="item_amount_uah[]" value="<?= e((string) $item['amount_uah']) ?>"></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="invoice-actions">
                        <button type="submit" name="action" value="save_invoice">Зберегти</button>
                        <button type="submit" name="action" value="generate_invoice">Зберегти і сформувати рахунок</button>
                        <button type="submit" name="action" value="generate_delivery" class="button-secondary">Зберегти і сформувати видаткову</button>
                        <button type="submit" name="action" value="use_detailed" class="button-secondary">Use detailed CRM products</button>
                        <button type="submit" name="action" value="collapse_one" class="button-secondary">Collapse to one product line</button>
                        <label class="collapse-field">
                            <span>Manual collapse title</span>
                            <input name="collapse_title" value="Поліграфічна продукція">
                        </label>
                        <button type="submit" name="action" value="collapse_manual" class="button-secondary">Collapse selected product type manually</button>
                    </div>
                </form>

                <div class="invoice-status-actions">
                    <?php foreach (['sent' => 'Sent to client', 'paid' => 'Paid', 'docs_sent' => 'Docs sent', 'docs_closed' => 'Docs closed', 'problem' => 'Problem', 'canceled' => 'Cancel'] as $statusAction => $label): ?>
                        <form method="post" action="<?= e(base_path('/invoices.php?edit=' . (int) $editInvoice['id'])) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="status">
                            <input type="hidden" name="id" value="<?= e((string) $editInvoice['id']) ?>">
                            <input type="hidden" name="status_action" value="<?= e($statusAction) ?>">
                            <button type="submit" class="<?= $statusAction === 'canceled' ? '' : 'button-secondary' ?> small-button"><?= e($label) ?></button>
                        </form>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <section class="panel table-panel">
            <div class="section-heading padded">
                <div>
                    <span class="label">Останні 100</span>
                    <h2>Реєстр рахунків</h2>
                </div>
            </div>
            <div class="table-wrap table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>Номер</th>
                            <th>PDF</th>
                            <th>Дата</th>
                            <th>KeyCRM</th>
                            <th>Одержувач / контакт</th>
                            <th>Постачальник</th>
                            <th class="num">Сума</th>
                            <th>Статус</th>
                            <th>Документи</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$invoices): ?>
                            <tr><td colspan="10">Рахунків ще немає.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($invoices as $invoiceRow): ?>
                            <tr>
                                <td><?= e((string) $invoiceRow['invoice_number']) ?></td>
                                <td>
                                    <?php if (invoice_pdf_available($invoiceRow)): ?>
                                        <span class="status-badge status-badge--success"><?= e(((string) $invoiceRow['document_type'] === 'delivery_note' ? 'DN_' : 'INV_') . (string) $invoiceRow['invoice_number']) ?></span>
                                    <?php else: ?>
                                        <span class="status-badge status-badge--muted">немає</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= e((string) $invoiceRow['invoice_date']) ?></td>
                                <td><?= e((string) ($invoiceRow['keycrm_order_id'] ?: '—')) ?></td>
                                <td class="wrap">
                                    <?= e((string) ($invoiceRow['buyer_display_name'] ?: '—')) ?>
                                    <?php if (!empty($invoiceRow['buyer_contact_name'])): ?>
                                        <small><?= e((string) $invoiceRow['buyer_contact_name']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?= e((string) ($invoiceRow['seller_short_name'] ?: '—')) ?></td>
                                <td class="num"><?= e(invoice_money($invoiceRow['total_with_vat_uah'] ?? 0)) ?></td>
                                <td><?= invoice_status_badge((string) $invoiceRow['status']) ?></td>
                                <td><?= invoice_docs_badge((string) $invoiceRow['docs_status']) ?></td>
                                <td>
                                    <div class="row-actions">
                                        <a class="button-secondary small-button" href="<?= e(base_path('/invoices.php?edit=' . (int) $invoiceRow['id'])) ?>">Edit</a>
                                        <?php if (invoice_pdf_available($invoiceRow)): ?>
                                            <a class="button-secondary small-button" href="<?= e(base_path('/invoices.php?download=' . (int) $invoiceRow['id'])) ?>">PDF</a>
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
    <script>
        (function () {
            var select = document.getElementById('legal-entity-select');
            if (!select) {
                return;
            }

            var fields = {
                legalName: document.querySelector('[name="buyer_display_name"]'),
                edrpou: document.querySelector('[name="buyer_edrpou"]'),
                address: document.querySelector('[name="buyer_address"]'),
                email: document.querySelector('[name="buyer_email"]'),
                phone: document.querySelector('[name="buyer_phone"]')
            };

            select.addEventListener('change', function () {
                var option = select.options[select.selectedIndex];
                if (!option || !option.value) {
                    return;
                }
                if (fields.legalName) fields.legalName.value = option.dataset.legalName || '';
                if (fields.edrpou) fields.edrpou.value = option.dataset.edrpou || '';
                if (fields.address) fields.address.value = option.dataset.address || '';
                if (fields.email) fields.email.value = option.dataset.email || '';
                if (fields.phone) fields.phone.value = option.dataset.phone || '';
            });
        })();
    </script>
</body>
</html>
