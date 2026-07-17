<?php

function cockpit_nav(string $active, string $month): void
{
    $monthQuery = '?month=' . urlencode(cockpit_valid_month($month));
    $items = [
        'dashboard' => ['Dashboard v2', '/dashboard_v2.php' . $monthQuery],
        'sales' => ['Продажі', '/sales.php' . $monthQuery],
        'cash' => ['Гроші', '/cash.php' . $monthQuery],
        'receivables' => ['Дебіторка', '/receivables.php' . $monthQuery],
        'managers' => ['Менеджери', '/managers.php' . $monthQuery],
        'payments' => ['Операції', '/payments.php' . $monthQuery],
        'accounts' => ['Рахунки', '/accounts.php'],
    ];
    ?>
    <nav class="nav cockpit-nav">
        <span><?= e(format_user_name(current_user() ?? [])) ?> · <?= e(user_role()) ?></span>
        <?php foreach ($items as $key => [$label, $href]): ?>
            <a class="<?= $active === $key ? 'active' : '' ?>" href="<?= e(base_path($href)) ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
        <?php if (user_role() === 'ceo'): ?>
            <a href="<?= e(base_path('/users.php')) ?>">Користувачі</a>
        <?php endif; ?>
        <a href="<?= e(base_path('/index.php' . $monthQuery)) ?>">Старий Dashboard</a>
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
