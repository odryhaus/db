<?php

require_once __DIR__ . '/bootstrap.php';

$realConfigExists = is_file(__DIR__ . '/config/config.php');
$configuredKey = (string) app_config('app.setup_key', '');
$providedKey = (string) ($_GET['key'] ?? '');
$isPlaceholderKey = $configuredKey === '' || $configuredKey === 'CHANGE_ME_LONG_RANDOM_SECRET';
$hasAccess = $realConfigExists && !$isPlaceholderKey && $providedKey !== '' && hash_equals($configuredKey, $providedKey);

$message = '';
$error = '';
$email = '';

if (!$hasAccess) {
    http_response_code(404);
} elseif (is_post()) {
    $email = post_string('email');
    $password = (string) ($_POST['password'] ?? '');

    if (!csrf_is_valid()) {
        $error = 'Setup request is invalid. Refresh the page and try again.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter a valid CEO email.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } else {
        $find = db()->prepare('SELECT id, email FROM users WHERE email = :email LIMIT 1');
        $find->execute(['email' => $email]);
        $user = $find->fetch();

        if (!$user) {
            $error = 'No user found for that email.';
        } else {
            $update = db()->prepare(
                'UPDATE users
                 SET db_password_hash = :password_hash, db_role = :role, db_active = :active
                 WHERE id = :id'
            );
            $update->execute([
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'role' => 'ceo',
                'active' => 1,
                'id' => (int) $user['id'],
            ]);

            $message = 'CEO password created. Delete setup-ceo.php from the server or disable setup_key in config now.';
            $email = '';
        }
    }
}
?>
<!doctype html>
<html lang="uk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CEO Setup | .BRAND DB</title>
    <link rel="stylesheet" href="<?= e(base_path('/assets/app.css')) ?>">
</head>
<body>
    <main class="page page-narrow">
        <section class="panel">
            <?php if (!$hasAccess): ?>
                <p class="eyebrow">.BRAND DB</p>
                <h1>Not found</h1>
                <p>This setup page is not available.</p>
            <?php else: ?>
                <p class="eyebrow">One-time setup</p>
                <h1>CEO Setup</h1>
                <div class="alert">Delete setup-ceo.php after first CEO password is created.</div>
                <?php if ($message !== ''): ?>
                    <div class="notice"><?= e($message) ?></div>
                <?php endif; ?>
                <?php if ($error !== ''): ?>
                    <div class="alert"><?= e($error) ?></div>
                <?php endif; ?>
                <form method="post" action="<?= e(base_path('/setup-ceo.php?key=' . rawurlencode($providedKey))) ?>" class="form">
                    <?= csrf_field() ?>
                    <label>
                        <span>CEO email</span>
                        <input type="email" name="email" value="<?= e($email) ?>" autocomplete="email" required autofocus>
                    </label>
                    <label>
                        <span>New password</span>
                        <input type="password" name="password" autocomplete="new-password" minlength="8" required>
                    </label>
                    <button type="submit">Create CEO password</button>
                </form>
            <?php endif; ?>
        </section>
    </main>
</body>
</html>
