<?php

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function app_config(?string $key = null, $default = null)
{
    $config = $GLOBALS['app_config'] ?? [];

    if ($key === null) {
        return $config;
    }

    $current = $config;
    foreach (explode('.', $key) as $part) {
        if (!is_array($current) || !array_key_exists($part, $current)) {
            return $default;
        }
        $current = $current[$part];
    }

    return $current;
}

function base_path(string $path = ''): string
{
    $base = rtrim((string) app_config('app.base_path', ''), '/');
    $path = '/' . ltrim($path, '/');

    if ($path === '/') {
        return $base !== '' ? $base . '/' : '/';
    }

    return $base . $path;
}

function asset_path(string $path): string
{
    $url = base_path($path);
    $file = __DIR__ . '/' . ltrim($path, '/');
    if (is_file($file)) {
        $separator = str_contains($url, '?') ? '&' : '?';
        $url .= $separator . 'v=' . filemtime($file);
    }

    return $url;
}

function app_version(): string
{
    static $version = null;
    if ($version !== null) {
        return $version;
    }

    $versionPath = __DIR__ . '/VERSION';
    $version = is_file($versionPath) ? trim((string) file_get_contents($versionPath)) : '';
    return $version !== '' ? $version : 'local';
}

function app_version_badge(): string
{
    return '<div class="version-badge">version ' . e(app_version()) . '</div>';
}

function redirect_to(string $path): void
{
    header('Location: ' . base_path($path));
    exit;
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function post_string(string $key): string
{
    return trim((string) ($_POST[$key] ?? ''));
}

function format_user_name(array $user): string
{
    $name = trim((string) ($user['first_name'] ?? '') . ' ' . (string) ($user['last_name'] ?? ''));
    return $name !== '' ? $name : (string) ($user['email'] ?? '');
}

function valid_roles(): array
{
    return ['none', 'manager', 'accountant', 'ceo'];
}

function access_roles(): array
{
    return ['manager', 'accountant', 'ceo'];
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return (string) $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function csrf_is_valid(): bool
{
    $token = (string) ($_POST['csrf_token'] ?? '');
    return $token !== '' && hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $token);
}
