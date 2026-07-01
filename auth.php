<?php

function current_user(): ?array
{
    return isset($_SESSION['user']) && is_array($_SESSION['user']) ? $_SESSION['user'] : null;
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

function user_role(): string
{
    return (string) (current_user()['db_role'] ?? 'none');
}

function require_login(): void
{
    if (!is_logged_in()) {
        redirect_to('/login.php');
    }
}

function require_role(string $role): void
{
    require_login();

    if (user_role() !== $role) {
        http_response_code(403);
        include __DIR__ . '/partials_forbidden.php';
        exit;
    }
}

function attempt_login(string $email, string $password): bool
{
    $stmt = db()->prepare(
        'SELECT id, keycrm_id, email, first_name, last_name, status, db_password_hash, db_role, db_active, db_last_login_at
         FROM users
         WHERE email = :email
         LIMIT 1'
    );
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch();

    if (!$user) {
        return false;
    }

    $hash = (string) ($user['db_password_hash'] ?? '');
    $role = (string) ($user['db_role'] ?? 'none');
    $active = (int) ($user['db_active'] ?? 0);

    if ($hash === '' || $active !== 1 || !in_array($role, access_roles(), true)) {
        return false;
    }

    if (!password_verify($password, $hash)) {
        return false;
    }

    session_regenerate_id(true);

    $_SESSION['user'] = [
        'id' => (int) $user['id'],
        'keycrm_id' => $user['keycrm_id'],
        'email' => (string) $user['email'],
        'first_name' => (string) ($user['first_name'] ?? ''),
        'last_name' => (string) ($user['last_name'] ?? ''),
        'status' => (string) ($user['status'] ?? ''),
        'db_role' => $role,
        'db_active' => $active,
    ];

    $update = db()->prepare('UPDATE users SET db_last_login_at = NOW() WHERE id = :id');
    $update->execute(['id' => (int) $user['id']]);

    return true;
}

function logout_user(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', (bool) $params['secure'], (bool) $params['httponly']);
    }

    session_destroy();
}
