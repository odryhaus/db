<?php

require_once __DIR__ . '/bootstrap.php';

if (is_logged_in()) {
    redirect_to('/dashboard_v2.php');
}

$error = '';
$email = '';

if (is_post()) {
    $email = post_string('email');
    $password = (string) ($_POST['password'] ?? '');

    if (csrf_is_valid() && $email !== '' && $password !== '' && attempt_login($email, $password)) {
        redirect_to('/dashboard_v2.php');
    }

    $error = 'Invalid email or password.';
}
?>
<!doctype html>
<html lang="uk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login | .BRAND DB</title>
    <link rel="stylesheet" href="<?= e(asset_path('/assets/app.css')) ?>">
</head>
<body>
    <main class="page page-narrow">
        <section class="panel">
            <p class="eyebrow">.BRAND DB</p>
            <h1>Login</h1>
            <?php if ($error !== ''): ?>
                <div class="alert"><?= e($error) ?></div>
            <?php endif; ?>
            <form method="post" action="<?= e(base_path('/login.php')) ?>" class="form">
                <?= csrf_field() ?>
                <label>
                    <span>Email</span>
                    <input type="email" name="email" value="<?= e($email) ?>" autocomplete="email" required autofocus>
                </label>
                <label>
                    <span>Password</span>
                    <input type="password" name="password" autocomplete="current-password" required>
                </label>
                <button type="submit">Sign in</button>
            </form>
        </section>
        <?= app_version_badge() ?>
    </main>
</body>
</html>
