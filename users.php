<?php

require_once __DIR__ . '/bootstrap.php';
require_role('ceo');

$message = '';
$error = '';

if (is_post()) {
    $userId = (int) ($_POST['user_id'] ?? 0);
    $role = post_string('db_role');
    $active = isset($_POST['db_active']) ? 1 : 0;
    $newPassword = (string) ($_POST['new_password'] ?? '');

    if (!csrf_is_valid() || $userId <= 0 || !in_array($role, valid_roles(), true)) {
        $error = 'Invalid user update.';
    } else {
        if ($newPassword !== '') {
            $hash = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = db()->prepare(
                'UPDATE users
                 SET db_role = :role, db_active = :active, db_password_hash = :hash
                 WHERE id = :id'
            );
            $stmt->execute([
                'role' => $role,
                'active' => $active,
                'hash' => $hash,
                'id' => $userId,
            ]);
        } else {
            $stmt = db()->prepare(
                'UPDATE users
                 SET db_role = :role, db_active = :active
                 WHERE id = :id'
            );
            $stmt->execute([
                'role' => $role,
                'active' => $active,
                'id' => $userId,
            ]);
        }

        $message = 'User access updated.';
    }
}

$stmt = db()->query(
    'SELECT id, first_name, last_name, email, db_role, db_active, db_last_login_at
     FROM users
     ORDER BY COALESCE(last_name, \'\'), COALESCE(first_name, \'\'), email'
);
$users = $stmt->fetchAll();
?>
<!doctype html>
<html lang="uk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>User Access | .BRAND DB</title>
    <link rel="stylesheet" href="<?= e(base_path('/assets/app.css')) ?>">
</head>
<body>
    <main class="page">
        <header class="topbar">
            <div>
                <p class="eyebrow">CEO access</p>
                <h1>User Access</h1>
            </div>
            <nav class="nav">
                <a href="<?= e(base_path('/index.php')) ?>">Dashboard</a>
                <a href="<?= e(base_path('/sync_orders.php')) ?>">Sync Orders</a>
                <a href="<?= e(base_path('/logout.php')) ?>">Logout</a>
            </nav>
        </header>

        <?php if ($message !== ''): ?>
            <div class="notice"><?= e($message) ?></div>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
            <div class="alert"><?= e($error) ?></div>
        <?php endif; ?>

        <section class="panel table-panel">
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>First name</th>
                            <th>Last name</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Active</th>
                            <th>Last login</th>
                            <th>New password</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $row): ?>
                            <?php $formId = 'user-form-' . (int) $row['id']; ?>
                            <tr>
                                <td>
                                    <?= e((string) $row['id']) ?>
                                    <input form="<?= e($formId) ?>" type="hidden" name="user_id" value="<?= e((string) $row['id']) ?>">
                                </td>
                                <td><?= e($row['first_name'] ?? '') ?></td>
                                <td><?= e($row['last_name'] ?? '') ?></td>
                                <td><?= e($row['email'] ?? '') ?></td>
                                <td>
                                    <select form="<?= e($formId) ?>" name="db_role">
                                        <?php foreach (valid_roles() as $role): ?>
                                            <option value="<?= e($role) ?>" <?= (string) ($row['db_role'] ?? 'none') === $role ? 'selected' : '' ?>>
                                                <?= e($role) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td class="center">
                                    <input form="<?= e($formId) ?>" type="checkbox" name="db_active" value="1" <?= (int) ($row['db_active'] ?? 0) === 1 ? 'checked' : '' ?>>
                                </td>
                                <td><?= e($row['db_last_login_at'] ?? '') ?></td>
                                <td>
                                    <input form="<?= e($formId) ?>" type="password" name="new_password" autocomplete="new-password" placeholder="Leave unchanged">
                                </td>
                                <td>
                                    <form id="<?= e($formId) ?>" method="post" action="<?= e(base_path('/users.php')) ?>">
                                        <?= csrf_field() ?>
                                    </form>
                                    <button form="<?= e($formId) ?>" type="submit" class="small-button">Save</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</body>
</html>
