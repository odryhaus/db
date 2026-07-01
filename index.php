<?php

require_once __DIR__ . '/bootstrap.php';
require_login();

$user = current_user();
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
                <p class="eyebrow">Internal system</p>
                <h1>.BRAND DB</h1>
            </div>
            <nav class="nav">
                <?php if (user_role() === 'ceo'): ?>
                    <a href="<?= e(base_path('/users.php')) ?>">Users</a>
                <?php endif; ?>
                <a href="<?= e(base_path('/logout.php')) ?>">Logout</a>
            </nav>
        </header>

        <section class="panel">
            <div class="meta-grid">
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

        <section class="panel">
            <h2>Money control system foundation is ready.</h2>
            <p>Next milestone: Monthly Sales Dashboard.</p>
        </section>
    </main>
</body>
</html>
