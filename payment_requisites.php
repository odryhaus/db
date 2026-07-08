<?php

require_once __DIR__ . '/bootstrap.php';
require_login();
ensure_invoice_tables();

if (!can_view_payment_requisites()) {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}

$companies = our_companies(true);
$accounts = our_company_accounts(null, true);
$companyId = (int) ($_REQUEST['company_id'] ?? ($companies[0]['id'] ?? 0));
$accountId = (int) ($_REQUEST['account_id'] ?? 0);
$orderNumber = trim((string) ($_REQUEST['order_number'] ?? ''));
$amount = trim((string) ($_REQUEST['amount'] ?? ''));
$language = (string) ($_REQUEST['language'] ?? 'uk');

$selectedCompany = $companies[0] ?? [];
foreach ($companies as $company) {
    if ((int) $company['id'] === $companyId) {
        $selectedCompany = $company;
        break;
    }
}

$companyId = (int) ($selectedCompany['id'] ?? 0);
if ($accountId <= 0 && $companyId > 0) {
    $accountId = (int) (our_default_account_id($companyId, 'UAH') ?? 0);
}

$selectedAccount = [];
foreach ($accounts as $account) {
    if ((int) $account['id'] === $accountId && (int) $account['company_id'] === $companyId) {
        $selectedAccount = $account;
        break;
    }
}
if (!$selectedAccount) {
    foreach ($accounts as $account) {
        if ((int) $account['company_id'] === $companyId) {
            $selectedAccount = $account;
            break;
        }
    }
}

$paymentText = ($selectedCompany && $selectedAccount)
    ? payment_requisites_text($selectedCompany, $selectedAccount, $orderNumber, $amount, $language)
    : '';
?>
<!doctype html>
<html lang="uk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Реквізити оплати | .BRAND DB</title>
    <link rel="stylesheet" href="<?= e(asset_path('/assets/app.css')) ?>">
</head>
<body>
    <main class="page">
        <header class="topbar">
            <div class="brand-block">
                <p class="eyebrow">Copy payment text</p>
                <h1>Реквізити оплати</h1>
            </div>
            <nav class="nav">
                <a href="<?= e(base_path('/index.php')) ?>">Дашборд</a>
                <a href="<?= e(base_path('/invoices.php')) ?>">Рахунки</a>
                <a class="active" href="<?= e(base_path('/payment_requisites.php')) ?>">Реквізити оплати</a>
                <?php if (in_array(user_role(), ['ceo', 'accountant'], true)): ?>
                    <a href="<?= e(base_path('/our_companies.php')) ?>">Наші компанії</a>
                <?php endif; ?>
                <a href="<?= e(base_path('/logout.php')) ?>">Вийти</a>
            </nav>
        </header>

        <section class="panel dashboard-section">
            <div class="section-heading padded">
                <div>
                    <span class="label">Не рахунок</span>
                    <h2>Текст для оплати карткою / реквізитами</h2>
                </div>
            </div>
            <form method="get" class="invoice-form">
                <div class="invoice-edit-grid">
                    <label>
                        <span>Замовлення №</span>
                        <input name="order_number" value="<?= e($orderNumber) ?>" placeholder="9251">
                    </label>
                    <label>
                        <span>Сума</span>
                        <input name="amount" value="<?= e($amount) ?>" placeholder="12500">
                    </label>
                    <label>
                        <span>Наша компанія</span>
                        <select name="company_id" data-company-account-source>
                            <?php foreach ($companies as $company): ?>
                                <option value="<?= e((string) $company['id']) ?>" <?= (int) $company['id'] === $companyId ? 'selected' : '' ?>>
                                    <?= e((string) $company['short_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>
                        <span>Рахунок</span>
                        <select name="account_id" data-company-account-target>
                            <?php foreach ($accounts as $account): ?>
                                <option value="<?= e((string) $account['id']) ?>" data-company-id="<?= e((string) $account['company_id']) ?>" <?= (int) $account['id'] === (int) ($selectedAccount['id'] ?? 0) ? 'selected' : '' ?>>
                                    <?= e((string) ($account['short_name'] ?? '')) ?> · <?= e(our_account_label($account)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>
                        <span>Мова</span>
                        <select name="language">
                            <option value="uk" <?= $language !== 'en' ? 'selected' : '' ?>>Українська</option>
                            <option value="en" <?= $language === 'en' ? 'selected' : '' ?>>English</option>
                        </select>
                    </label>
                </div>
                <div class="form-actions">
                    <button type="submit">Сформувати текст</button>
                </div>
            </form>
        </section>

        <section class="panel dashboard-section">
            <div class="section-heading padded">
                <div>
                    <span class="label">Copy</span>
                    <h2>Готовий текст</h2>
                </div>
            </div>
            <textarea class="copy-textarea" id="payment-text" readonly rows="10"><?= e($paymentText) ?></textarea>
            <div class="form-actions">
                <button type="button" id="copy-payment-text">Скопіювати</button>
                <?php if (in_array(user_role(), ['ceo', 'accountant'], true)): ?>
                    <a class="button button-secondary" href="<?= e(base_path('/our_companies.php')) ?>">Редагувати реквізити</a>
                <?php endif; ?>
            </div>
            <p class="muted invoice-note">Це тільки текст реквізитів для менеджера. Він не створює рахунок і не змінює KeyCRM.</p>
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
                    var visible = (option.dataset.companyId || '') === companyId;
                    option.hidden = !visible;
                    option.disabled = !visible;
                    if (visible && option.selected) selectedVisible = true;
                });
                if (!selectedVisible) {
                    var firstVisible = accountSelect.querySelector('option:not([disabled])');
                    if (firstVisible) accountSelect.value = firstVisible.value;
                }
            }

            document.querySelectorAll('[data-company-account-source]').forEach(function (select) {
                syncAccountOptions(select);
                select.addEventListener('change', function () { syncAccountOptions(select); });
            });

            var copyButton = document.getElementById('copy-payment-text');
            if (copyButton) {
                copyButton.addEventListener('click', function () {
                    var text = document.getElementById('payment-text');
                    if (!text) return;
                    text.select();
                    document.execCommand('copy');
                    copyButton.textContent = 'Скопійовано';
                    window.setTimeout(function () { copyButton.textContent = 'Скопіювати'; }, 1400);
                });
            }
        })();
    </script>
</body>
</html>
