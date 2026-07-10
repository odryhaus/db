<?php

require_once __DIR__ . '/bootstrap.php';
require_login();
ensure_invoice_tables();

if (!in_array(user_role(), ['ceo', 'accountant'], true)) {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}

$canEdit = can_edit_our_companies();
$message = '';
$error = '';

function company_post_bool(string $key): int
{
    return isset($_POST[$key]) ? 1 : 0;
}

if (is_post()) {
    if (!$canEdit) {
        $error = 'Only CEO/accountant can edit our companies.';
    } elseif (!csrf_is_valid()) {
        $error = 'Invalid request. Refresh the page and try again.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        try {
            if ($action === 'save_company') {
                $id = (int) ($_POST['id'] ?? 0);
                $params = [
                    'short_name' => post_string('short_name'),
                    'legal_name' => post_string('legal_name'),
                    'company_type' => post_string('company_type') ?: 'fop',
                    'tax_code' => post_string('tax_code') ?: null,
                    'tax_mode' => post_string('tax_mode') ?: 'single_tax_no_vat',
                    'single_tax_group' => post_string('single_tax_group') !== '' ? (int) post_string('single_tax_group') : null,
                    'allowed_item_type' => post_string('allowed_item_type') ?: 'products_only',
                    'address' => post_string('address') ?: null,
                    'email' => post_string('email') ?: null,
                    'phone' => post_string('phone') ?: null,
                    'website' => post_string('website') ?: null,
                    'accountant_email' => post_string('accountant_email') ?: null,
                    'accountant_phone' => post_string('accountant_phone') ?: null,
                    'signer_name' => post_string('signer_name') ?: null,
                    'signer_position' => post_string('signer_position') ?: null,
                    'is_active' => company_post_bool('is_active'),
                    'is_default' => company_post_bool('is_default'),
                    'note' => post_string('note') ?: null,
                ];

                if ($params['short_name'] === '' || $params['legal_name'] === '') {
                    throw new RuntimeException('Short name and legal name are required.');
                }
                if ((int) $params['is_default'] === 1) {
                    db()->exec('UPDATE db_our_companies SET is_default = 0');
                }

                if ($id > 0) {
                    $params['id'] = $id;
                    $stmt = db()->prepare("
                        UPDATE db_our_companies
                        SET short_name = :short_name,
                            legal_name = :legal_name,
                            edrpou = :edrpou,
                            company_type = :company_type,
                            tax_code = :tax_code,
                            tax_mode = :tax_mode,
                            single_tax_group = :single_tax_group,
                            allowed_item_type = :allowed_item_type,
                            address = :address,
                            email = :email,
                            phone = :phone,
                            website = :website,
                            accountant_email = :accountant_email,
                            accountant_phone = :accountant_phone,
                            signer_name = :signer_name,
                            signer_position = :signer_position,
                            is_active = :is_active,
                            is_default = :is_default,
                            note = :note
                        WHERE id = :id
                    ");
                    $params['edrpou'] = $params['tax_code'];
                    $stmt->execute($params);
                    $message = 'Компанію оновлено.';
                } else {
                    $stmt = db()->prepare("
                        INSERT INTO db_our_companies
                            (short_name, legal_name, edrpou, company_type, tax_code, tax_mode, single_tax_group,
                             allowed_item_type, address, email, phone, website, accountant_email, accountant_phone,
                             signer_name, signer_position, is_active, is_default, note)
                        VALUES
                            (:short_name, :legal_name, :edrpou, :company_type, :tax_code, :tax_mode, :single_tax_group,
                             :allowed_item_type, :address, :email, :phone, :website, :accountant_email, :accountant_phone,
                             :signer_name, :signer_position, :is_active, :is_default, :note)
                    ");
                    $params['edrpou'] = $params['tax_code'];
                    $stmt->execute($params);
                    $message = 'Компанію додано.';
                }
            } elseif ($action === 'save_account') {
                $id = (int) ($_POST['id'] ?? 0);
                $companyId = (int) ($_POST['company_id'] ?? 0);
                $currency = strtoupper(post_string('currency') ?: 'UAH');
                $params = [
                    'company_id' => $companyId,
                    'account_label' => post_string('account_label'),
                    'account_type' => post_string('account_type') ?: 'bank_account',
                    'currency' => $currency,
                    'iban' => post_string('iban') ?: null,
                    'bank_name' => post_string('bank_name') ?: null,
                    'bank_address' => post_string('bank_address') ?: null,
                    'swift' => post_string('swift') ?: null,
                    'recipient_name' => post_string('recipient_name') ?: null,
                    'recipient_address' => post_string('recipient_address') ?: null,
                    'tax_code' => post_string('tax_code') ?: null,
                    'language' => post_string('language') === 'en' ? 'en' : 'uk',
                    'is_default' => company_post_bool('is_default'),
                    'is_active' => company_post_bool('is_active'),
                    'payment_template' => post_string('payment_template') ?: null,
                    'note' => post_string('note') ?: null,
                ];

                if ($companyId <= 0 || $params['account_label'] === '') {
                    throw new RuntimeException('Company and account label are required.');
                }
                if ((int) $params['is_default'] === 1) {
                    $stmt = db()->prepare('UPDATE db_our_company_accounts SET is_default = 0 WHERE company_id = :company_id AND currency = :currency');
                    $stmt->execute(['company_id' => $companyId, 'currency' => $currency]);
                }

                if ($id > 0) {
                    $params['id'] = $id;
                    $stmt = db()->prepare("
                        UPDATE db_our_company_accounts
                        SET company_id = :company_id,
                            account_label = :account_label,
                            account_type = :account_type,
                            currency = :currency,
                            iban = :iban,
                            bank_name = :bank_name,
                            bank_address = :bank_address,
                            swift = :swift,
                            recipient_name = :recipient_name,
                            recipient_address = :recipient_address,
                            tax_code = :tax_code,
                            language = :language,
                            is_default = :is_default,
                            is_active = :is_active,
                            payment_template = :payment_template,
                            note = :note
                        WHERE id = :id
                    ");
                    $stmt->execute($params);
                    $message = 'Рахунок оновлено.';
                } else {
                    $stmt = db()->prepare("
                        INSERT INTO db_our_company_accounts
                            (company_id, account_label, account_type, currency, iban, bank_name, bank_address,
                             swift, recipient_name, recipient_address, tax_code, language, is_default,
                             is_active, payment_template, note)
                        VALUES
                            (:company_id, :account_label, :account_type, :currency, :iban, :bank_name, :bank_address,
                             :swift, :recipient_name, :recipient_address, :tax_code, :language, :is_default,
                             :is_active, :payment_template, :note)
                    ");
                    $stmt->execute($params);
                    $message = 'Рахунок додано.';
                }
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$companies = our_companies(false);
$accounts = our_company_accounts(null, false);
$accountsByCompany = [];
$duplicateAccountKeys = [];
$accountKeyCounts = [];
foreach ($accounts as $account) {
    $accountsByCompany[(int) $account['company_id']][] = $account;
    $normalizedIban = preg_replace('/\s+/', '', strtoupper((string) ($account['iban'] ?? '')));
    if ($normalizedIban !== '') {
        $key = implode('|', [
            (string) ($account['company_id'] ?? ''),
            strtoupper((string) ($account['currency'] ?? 'UAH')),
            $normalizedIban,
        ]);
        $accountKeyCounts[$key] = ($accountKeyCounts[$key] ?? 0) + 1;
    }
}
foreach ($accountKeyCounts as $key => $count) {
    if ($count > 1) {
        $duplicateAccountKeys[$key] = true;
    }
}
?>
<!doctype html>
<html lang="uk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Наші компанії | .BRAND DB</title>
    <link rel="stylesheet" href="<?= e(asset_path('/assets/app.css')) ?>">
</head>
<body>
    <main class="page">
        <header class="topbar">
            <div class="brand-block">
                <p class="eyebrow">Seller legal entities</p>
                <h1>Наші компанії</h1>
            </div>
            <nav class="nav">
                <a href="<?= e(base_path('/index.php')) ?>">Дашборд</a>
                <a href="<?= e(base_path('/invoices.php')) ?>">Рахунки</a>
                <a href="<?= e(base_path('/payment_requisites.php')) ?>">Реквізити оплати</a>
                <a class="active" href="<?= e(base_path('/our_companies.php')) ?>">Наші компанії</a>
                <a href="<?= e(base_path('/logout.php')) ?>">Вийти</a>
            </nav>
        </header>

        <?php if ($message !== ''): ?><div class="notice"><?= e($message) ?></div><?php endif; ?>
        <?php if ($error !== ''): ?><div class="alert"><?= e($error) ?></div><?php endif; ?>
        <?php if (!$canEdit): ?><div class="notice">Перегляд доступний бухгалтеру. Редагувати можуть CEO та бухгалтер.</div><?php endif; ?>

        <?php if ($canEdit): ?>
            <section class="panel form-section dashboard-section">
                <div class="section-heading padded">
                    <div>
                        <span class="label">Додати</span>
                        <h2>Нова наша юрособа</h2>
                    </div>
                </div>
                <form method="post" class="invoice-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save_company">
                    <div class="invoice-edit-grid">
                        <label><span>Коротка назва</span><input name="short_name" required></label>
                        <label class="wide-field"><span>Повна назва</span><input name="legal_name" required></label>
                        <label><span>Код / ІПН</span><input name="tax_code"></label>
                        <label><span>Тип</span><select name="company_type"><option value="fop">ФОП</option><option value="tov">ТОВ</option><option value="pp">ПП</option><option value="other">Інше</option></select></label>
                        <label><span>Податки</span><select name="tax_mode"><option value="single_tax_no_vat">Єдиний без ПДВ</option><option value="vat_20">ПДВ 20%</option><option value="no_vat_other">Без ПДВ інше</option></select></label>
                        <label><span>Група</span><input type="number" min="1" max="4" name="single_tax_group"></label>
                        <label><span>Позиції</span><select name="allowed_item_type"><option value="products_only">Тільки продукція</option><option value="services_allowed">Послуги дозволені</option><option value="products_and_services">Продукція і послуги</option></select></label>
                        <label><span>Активна</span><input type="checkbox" name="is_active" checked></label>
                        <label><span>Default</span><input type="checkbox" name="is_default"></label>
                    </div>
                    <div class="form-actions"><button type="submit">Додати компанію</button></div>
                </form>
            </section>
        <?php endif; ?>

        <?php foreach ($companies as $company): ?>
            <section class="panel form-section dashboard-section">
                <div class="section-heading padded">
                    <div>
                        <span class="label"><?= (int) $company['is_active'] === 1 ? 'Активна' : 'Вимкнена' ?></span>
                        <h2><?= e((string) $company['short_name']) ?></h2>
                    </div>
                    <span class="status-badge <?= (int) $company['is_default'] === 1 ? 'status-badge--success' : 'status-badge--muted' ?>"><?= (int) $company['is_default'] === 1 ? 'default' : 'seller' ?></span>
                </div>

                <form method="post" class="invoice-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save_company">
                    <input type="hidden" name="id" value="<?= e((string) $company['id']) ?>">
                    <div class="invoice-edit-grid">
                        <label><span>Коротка назва</span><input name="short_name" value="<?= e((string) $company['short_name']) ?>" <?= !$canEdit ? 'readonly' : '' ?>></label>
                        <label class="wide-field"><span>Повна назва</span><input name="legal_name" value="<?= e((string) $company['legal_name']) ?>" <?= !$canEdit ? 'readonly' : '' ?>></label>
                        <label><span>Код / ІПН</span><input name="tax_code" value="<?= e((string) (($company['tax_code'] ?? '') ?: ($company['edrpou'] ?? ''))) ?>" <?= !$canEdit ? 'readonly' : '' ?>></label>
                        <label><span>Тип</span><select name="company_type" <?= !$canEdit ? 'disabled' : '' ?>><?php foreach (['fop' => 'ФОП', 'tov' => 'ТОВ', 'pp' => 'ПП', 'other' => 'Інше'] as $value => $label): ?><option value="<?= e($value) ?>" <?= (string) ($company['company_type'] ?? 'fop') === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
                        <label><span>Податки</span><select name="tax_mode" <?= !$canEdit ? 'disabled' : '' ?>><?php foreach (['single_tax_no_vat' => 'Єдиний без ПДВ', 'vat_20' => 'ПДВ 20%', 'no_vat_other' => 'Без ПДВ інше'] as $value => $label): ?><option value="<?= e($value) ?>" <?= (string) $company['tax_mode'] === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
                        <label><span>Група</span><input type="number" min="1" max="4" name="single_tax_group" value="<?= e((string) ($company['single_tax_group'] ?? '')) ?>" <?= !$canEdit ? 'readonly' : '' ?>></label>
                        <label><span>Позиції</span><select name="allowed_item_type" <?= !$canEdit ? 'disabled' : '' ?>><?php foreach (['products_only' => 'Тільки продукція', 'services_allowed' => 'Послуги дозволені', 'products_and_services' => 'Продукція і послуги'] as $value => $label): ?><option value="<?= e($value) ?>" <?= (string) $company['allowed_item_type'] === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
                        <label><span>Email</span><input name="email" value="<?= e((string) $company['email']) ?>" <?= !$canEdit ? 'readonly' : '' ?>></label>
                        <label><span>Телефон</span><input name="phone" value="<?= e((string) $company['phone']) ?>" <?= !$canEdit ? 'readonly' : '' ?>></label>
                        <label><span>Website</span><input name="website" value="<?= e((string) ($company['website'] ?? '')) ?>" <?= !$canEdit ? 'readonly' : '' ?>></label>
                        <label><span>Бухгалтер email</span><input name="accountant_email" value="<?= e((string) $company['accountant_email']) ?>" <?= !$canEdit ? 'readonly' : '' ?>></label>
                        <label><span>Бухгалтер телефон</span><input name="accountant_phone" value="<?= e((string) $company['accountant_phone']) ?>" <?= !$canEdit ? 'readonly' : '' ?>></label>
                        <label><span>Підписант</span><input name="signer_name" value="<?= e((string) ($company['signer_name'] ?? '')) ?>" <?= !$canEdit ? 'readonly' : '' ?>></label>
                        <label><span>Посада</span><input name="signer_position" value="<?= e((string) ($company['signer_position'] ?? '')) ?>" <?= !$canEdit ? 'readonly' : '' ?>></label>
                        <label class="wide-field"><span>Адреса</span><input name="address" value="<?= e((string) $company['address']) ?>" <?= !$canEdit ? 'readonly' : '' ?>></label>
                        <label><span>Активна</span><input type="checkbox" name="is_active" <?= (int) $company['is_active'] === 1 ? 'checked' : '' ?> <?= !$canEdit ? 'disabled' : '' ?>></label>
                        <label><span>Default</span><input type="checkbox" name="is_default" <?= (int) $company['is_default'] === 1 ? 'checked' : '' ?> <?= !$canEdit ? 'disabled' : '' ?>></label>
                        <label class="wide-field"><span>Нотатка</span><input name="note" value="<?= e((string) ($company['note'] ?? '')) ?>" <?= !$canEdit ? 'readonly' : '' ?>></label>
                    </div>
                    <?php if ($canEdit): ?><div class="form-actions"><button type="submit">Зберегти компанію</button></div><?php endif; ?>
                </form>

                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Рахунок</th><th>Тип</th><th>Валюта</th><th>IBAN</th><th>Банк</th><th>SWIFT</th><th>Мова</th><th>Default</th><th>Активний</th><th>Нотатка</th><?php if ($canEdit): ?><th></th><?php endif; ?></tr></thead>
                        <tbody>
                            <?php foreach (($accountsByCompany[(int) $company['id']] ?? []) as $account): ?>
                                <tr>
                                    <form method="post">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="save_account">
                                        <input type="hidden" name="id" value="<?= e((string) $account['id']) ?>">
                                        <input type="hidden" name="company_id" value="<?= e((string) $company['id']) ?>">
                                        <?php
                                        $accountDuplicateKey = implode('|', [
                                            (string) ($account['company_id'] ?? ''),
                                            strtoupper((string) ($account['currency'] ?? 'UAH')),
                                            preg_replace('/\s+/', '', strtoupper((string) ($account['iban'] ?? ''))),
                                        ]);
                                        ?>
                                        <td>
                                            <input name="account_label" value="<?= e((string) $account['account_label']) ?>" <?= !$canEdit ? 'readonly' : '' ?>>
                                            <?php if (!empty($duplicateAccountKeys[$accountDuplicateKey])): ?>
                                                <span class="status-badge status-badge--warning">дубль IBAN</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><select name="account_type" <?= !$canEdit ? 'disabled' : '' ?>><?php foreach (['bank_account','card_requisites','privat','mono','other'] as $type): ?><option value="<?= e($type) ?>" <?= (string) $account['account_type'] === $type ? 'selected' : '' ?>><?= e($type) ?></option><?php endforeach; ?></select></td>
                                        <td><input name="currency" value="<?= e((string) $account['currency']) ?>" <?= !$canEdit ? 'readonly' : '' ?>></td>
                                        <td><input name="iban" value="<?= e((string) $account['iban']) ?>" <?= !$canEdit ? 'readonly' : '' ?>></td>
                                        <td><input name="bank_name" value="<?= e((string) $account['bank_name']) ?>" <?= !$canEdit ? 'readonly' : '' ?>></td>
                                        <td><input name="swift" value="<?= e((string) $account['swift']) ?>" <?= !$canEdit ? 'readonly' : '' ?>></td>
                                        <td><select name="language" <?= !$canEdit ? 'disabled' : '' ?>><option value="uk" <?= (string) $account['language'] !== 'en' ? 'selected' : '' ?>>uk</option><option value="en" <?= (string) $account['language'] === 'en' ? 'selected' : '' ?>>en</option></select></td>
                                        <td><input type="checkbox" name="is_default" <?= (int) $account['is_default'] === 1 ? 'checked' : '' ?> <?= !$canEdit ? 'disabled' : '' ?>></td>
                                        <td><input type="checkbox" name="is_active" <?= (int) $account['is_active'] === 1 ? 'checked' : '' ?> <?= !$canEdit ? 'disabled' : '' ?>></td>
                                        <td><input name="note" value="<?= e((string) $account['note']) ?>" <?= !$canEdit ? 'readonly' : '' ?>></td>
                                        <?php if ($canEdit): ?><td><button type="submit" class="small-button">Save</button></td><?php endif; ?>
                                        <input type="hidden" name="bank_address" value="<?= e((string) $account['bank_address']) ?>">
                                        <input type="hidden" name="recipient_name" value="<?= e((string) $account['recipient_name']) ?>">
                                        <input type="hidden" name="recipient_address" value="<?= e((string) $account['recipient_address']) ?>">
                                        <input type="hidden" name="tax_code" value="<?= e((string) $account['tax_code']) ?>">
                                        <input type="hidden" name="payment_template" value="<?= e((string) $account['payment_template']) ?>">
                                    </form>
                                </tr>
                            <?php endforeach; ?>
                            <?php if ($canEdit): ?>
                                <tr>
                                    <form method="post">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="save_account">
                                        <input type="hidden" name="company_id" value="<?= e((string) $company['id']) ?>">
                                        <td><input name="account_label" placeholder="ПриватБанк UAH"></td>
                                        <td><select name="account_type"><option value="bank_account">bank_account</option><option value="privat">privat</option><option value="mono">mono</option><option value="card_requisites">card_requisites</option><option value="other">other</option></select></td>
                                        <td><input name="currency" value="UAH"></td>
                                        <td><input name="iban"></td>
                                        <td><input name="bank_name"></td>
                                        <td><input name="swift"></td>
                                        <td><select name="language"><option value="uk">uk</option><option value="en">en</option></select></td>
                                        <td><input type="checkbox" name="is_default"></td>
                                        <td><input type="checkbox" name="is_active" checked></td>
                                        <td><input name="note"></td>
                                        <td><button type="submit" class="small-button">Add</button></td>
                                    </form>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endforeach; ?>
        <?= app_version_badge() ?>
    </main>
</body>
</html>
