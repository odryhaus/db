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

$showAll = isset($_GET['all']);

$where = $showAll ? '' : 'WHERE db_active = 1';
$stmt = db()->query(
    "SELECT id, first_name, last_name, email, db_role, db_active, db_last_login_at
     FROM users
     {$where}
     ORDER BY COALESCE(last_name, ''), COALESCE(first_name, ''), email"
);
$users = $stmt->fetchAll();
?>
<!doctype html>
<html lang="uk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Користувачі | .BRAND DB</title>
    <link rel="stylesheet" href="<?= e(base_path('/assets/app.css')) ?>">
</head>
<body>
    <main class="page">
        <header class="topbar">
            <div class="brand-block">
                <p class="eyebrow">CEO доступ</p>
                <h1>Користувачі</h1>
            </div>
            <nav class="nav">
                <a href="<?= e(base_path('/index.php')) ?>">Дашборд</a>
                <a href="<?= e(base_path('/targets.php')) ?>">Плани</a>
                <a href="<?= e(base_path('/expenses.php')) ?>">Витрати</a>
                <a href="<?= e(base_path('/sync_orders.php')) ?>">Синхронізація</a>
                <a class="active" href="<?= e(base_path('/users.php')) ?>">Користувачі</a>
                <a href="<?= e(base_path('/logout.php')) ?>">Вийти</a>
            </nav>
        </header>

        <?php if ($message !== ''): ?>
            <div class="notice"><?= e($message) ?></div>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
            <div class="alert"><?= e($error) ?></div>
        <?php endif; ?>

        <section class="panel">
            <div class="toolbar">
                <label class="form-control">
                    <span>Пошук</span>
                    <input type="search" id="user-search" placeholder="Ім'я або email">
                </label>
                <label class="form-control">
                    <span>Роль</span>
                    <select id="user-role-filter">
                        <option value="">Усі ролі</option>
                        <?php foreach (valid_roles() as $role): ?>
                            <option value="<?= e($role) ?>"><?= e($role) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <a class="button-secondary small-button" href="<?= e(base_path('/users.php' . ($showAll ? '' : '?all=1'))) ?>">
                    <?= $showAll ? 'Тільки активні' : 'Показати всіх' ?>
                </a>
            </div>
        </section>

        <section class="panel table-panel">
            <div class="table-wrap">
                <table id="users-table">
                    <thead>
                        <tr>
                            <th>Ім'я</th>
                            <th>Email</th>
                            <th>Роль</th>
                            <th class="center">Активний</th>
                            <th>Останній вхід</th>
                            <th>Новий пароль</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $row): ?>
                            <?php
                                $formId = 'user-form-' . (int) $row['id'];
                                $fullName = trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? ''));
                            ?>
                            <tr data-name="<?= e(mb_strtolower($fullName)) ?>" data-email="<?= e(mb_strtolower((string) ($row['email'] ?? ''))) ?>" data-role="<?= e((string) ($row['db_role'] ?? 'none')) ?>">
                                <td>
                                    <?= e($fullName !== '' ? $fullName : '—') ?>
                                    <input form="<?= e($formId) ?>" type="hidden" name="user_id" value="<?= e((string) $row['id']) ?>">
                                </td>
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
                                <td><?= e($row['db_last_login_at'] ?? '—') ?></td>
                                <td>
                                    <input form="<?= e($formId) ?>" type="password" name="new_password" autocomplete="new-password" placeholder="Не змінювати">
                                </td>
                                <td>
                                    <form id="<?= e($formId) ?>" method="post" action="<?= e(base_path('/users.php')) ?>">
                                        <?= csrf_field() ?>
                                    </form>
                                    <button form="<?= e($formId) ?>" type="submit" class="small-button">Зберегти</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$users): ?>
                            <tr><td colspan="7">Користувачів не знайдено.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
    <script>
        (function () {
            var search = document.getElementById('user-search');
            var roleFilter = document.getElementById('user-role-filter');
            var rows = document.querySelectorAll('#users-table tbody tr[data-name]');

            function applyFilters() {
                var query = search.value.trim().toLowerCase();
                var role = roleFilter.value;
                rows.forEach(function (row) {
                    var matchesQuery = !query || row.dataset.name.indexOf(query) !== -1 || row.dataset.email.indexOf(query) !== -1;
                    var matchesRole = !role || row.dataset.role === role;
                    row.style.display = matchesQuery && matchesRole ? '' : 'none';
                });
            }

            if (search && roleFilter) {
                search.addEventListener('input', applyFilters);
                roleFilter.addEventListener('change', applyFilters);
            }
        })();
    </script>
</body>
</html>
