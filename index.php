<?php

require_once __DIR__ . '/bootstrap.php';
require_login();

$user = current_user();
$monthLabel = date('F Y');
$monthlyTarget = 4000000;
$salesFact = 0;
$paid = 0;
$unpaid = 0;
$weOwe = 0;
$remaining = $monthlyTarget;
$progress = 0;

function money_uah(int $amount): string
{
    return number_format($amount, 0, '.', ' ') . ' UAH';
}
?>
<!doctype html>
<html lang="uk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>.BRAND DB</title>
    <link rel="stylesheet" href="<?= e(base_path('/assets/app.css')) ?>">
</head>
<body>
    <main class="page">
        <header class="topbar">
            <div>
                <p class="eyebrow">Money dashboard v0.1</p>
                <h1>.BRAND DB</h1>
            </div>
            <nav class="nav">
                <?php if (user_role() === 'ceo'): ?>
                    <a href="<?= e(base_path('/users.php')) ?>">Users</a>
                <?php endif; ?>
                <a href="<?= e(base_path('/logout.php')) ?>">Logout</a>
            </nav>
        </header>

        <section class="panel dashboard-hero">
            <div class="dashboard-hero-main">
                <span class="label">Current month</span>
                <strong><?= e($monthLabel) ?></strong>
                <h2>Monthly target: <?= e(money_uah($monthlyTarget)) ?></h2>
                <div class="progress-track" aria-label="Progress toward monthly target">
                    <span style="width: <?= e((string) $progress) ?>%"></span>
                </div>
                <div class="progress-row">
                    <span>Progress</span>
                    <strong><?= e((string) $progress) ?>%</strong>
                </div>
            </div>
            <div class="dashboard-user">
                <div>
                    <span class="label">Logged in as</span>
                    <strong><?= e(format_user_name($user)) ?></strong>
                    <small><?= e($user['email'] ?? '') ?></small>
                </div>
                <div>
                    <span class="label">Role</span>
                    <strong><?= e($user['db_role'] ?? 'none') ?></strong>
                </div>
            </div>
        </section>

        <section class="money-grid" aria-label="Monthly money summary">
            <div class="money-card target">
                <span class="label">Monthly target</span>
                <strong><?= e(money_uah($monthlyTarget)) ?></strong>
            </div>
            <div class="money-card">
                <span class="label">Sales fact</span>
                <strong><?= e(money_uah($salesFact)) ?></strong>
            </div>
            <div class="money-card">
                <span class="label">Paid</span>
                <strong><?= e(money_uah($paid)) ?></strong>
            </div>
            <div class="money-card">
                <span class="label">Unpaid</span>
                <strong><?= e(money_uah($unpaid)) ?></strong>
            </div>
            <div class="money-card">
                <span class="label">We owe</span>
                <strong><?= e(money_uah($weOwe)) ?></strong>
            </div>
            <div class="money-card">
                <span class="label">Remaining to target</span>
                <strong><?= e(money_uah($remaining)) ?></strong>
            </div>
        </section>

        <section class="panel action-panel">
            <div>
                <span class="label">Main next action</span>
                <h2>Connect orders data source</h2>
                <p>Values are placeholders until local order data is inspected and connected.</p>
            </div>
        </section>
    </main>
</body>
</html>
