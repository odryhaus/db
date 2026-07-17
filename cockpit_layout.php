<?php

function cockpit_nav(string $active, string $month): void
{
    $monthQuery = '?month=' . urlencode(cockpit_valid_month($month));
    $items = [
        'dashboard' => ['Cockpit', '/dashboard_v2.php' . $monthQuery],
        'client_balances' => ['Клієнти', '/client_balances.php' . $monthQuery],
        'sales' => ['Продажі', '/sales.php' . $monthQuery],
        'cash' => ['Гроші', '/cash.php' . $monthQuery],
        'invoices' => ['Документи', '/invoices.php'],
    ];
    $adminItems = [
        'receivables' => ['Дебіторка', '/receivables.php' . $monthQuery],
        'managers' => ['Менеджери', '/managers.php' . $monthQuery],
        'targets' => ['Плани', '/targets.php' . $monthQuery],
        'payments' => ['Операції', '/payments.php' . $monthQuery],
        'accounts' => ['Баланси рахунків', '/accounts.php'],
        'expenses' => ['Витрати', '/expenses.php' . $monthQuery],
        'requisites' => ['Реквізити', '/payment_requisites.php'],
    ];
    ?>
    <nav class="nav cockpit-nav">
        <span><?= e(format_user_name(current_user() ?? [])) ?> · <?= e(user_role()) ?></span>
        <?php foreach ($items as $key => [$label, $href]): ?>
            <a class="<?= $active === $key ? 'active' : '' ?>" href="<?= e(base_path($href)) ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
        <details class="nav-more" <?= array_key_exists($active, $adminItems) || in_array($active, ['our_companies','history_sync'], true) ? 'open' : '' ?>>
            <summary>Адмін</summary>
            <div>
                <?php foreach ($adminItems as $key => [$label, $href]): ?>
                    <a class="<?= $active === $key ? 'active' : '' ?>" href="<?= e(base_path($href)) ?>"><?= e($label) ?></a>
                <?php endforeach; ?>
                <?php if (in_array(user_role(), ['ceo', 'accountant'], true)): ?>
                    <a class="<?= $active === 'our_companies' ? 'active' : '' ?>" href="<?= e(base_path('/our_companies.php')) ?>">Наші компанії</a>
                <?php endif; ?>
                <?php if (user_role() === 'ceo'): ?>
                    <a class="<?= $active === 'history_sync' ? 'active' : '' ?>" href="<?= e(base_path('/history_sync.php' . $monthQuery)) ?>">Імпорт історії</a>
                    <a href="<?= e(base_path('/clients_sync.php')) ?>">Клієнти Sync</a>
                    <a href="<?= e(base_path('/payment_sync_check.php')) ?>">Payment Check</a>
                    <a href="<?= e(base_path('/users.php')) ?>">Користувачі</a>
                    <a href="<?= e(base_path('/index.php' . $monthQuery)) ?>">Старий Dashboard</a>
                <?php endif; ?>
            </div>
        </details>
        <a href="<?= e(base_path('/logout.php')) ?>">Вийти</a>
    </nav>
    <?php
}

function cockpit_page_header(string $eyebrow, string $title, string $subtitle, string $active, string $month, bool $showMonth = true): void
{
    ?>
    <header class="cockpit-topbar">
        <div>
            <p class="eyebrow"><?= e($eyebrow) ?></p>
            <h1><?= e($title) ?></h1>
            <p class="muted"><?= e($subtitle) ?></p>
        </div>
        <?php if ($showMonth): ?>
            <form class="cockpit-month-form" method="get">
                <label>
                    <span>Місяць</span>
                    <input type="month" name="month" value="<?= e(cockpit_valid_month($month)) ?>">
                </label>
                <button type="submit">Показати</button>
            </form>
        <?php endif; ?>
    </header>
    <?php cockpit_nav($active, $month); ?>
    <?php
}
