<?php
declare(strict_types=1);

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function require_login(): array
{
    $user = current_user();
    if (!$user) {
        redirect(base_url('login.php'));
    }
    return $user;
}

function require_role($role): array
{
    $user = require_login();
    if (!in_array($user['role'], (array)$role, true)) {
        http_response_code(403);
        exit('ไม่มีสิทธิ์เข้าถึงหน้านี้');
    }
    return $user;
}

function is_head_judge(?array $user): bool
{
    return $user && $user['role'] === 'judge' && ($user['position'] ?? '') === 'ประธานกรรมการ';
}

function require_admin_or_head_judge(): array
{
    $user = require_login();
    if ($user['role'] !== 'admin' && !is_head_judge($user)) {
        http_response_code(403);
        exit('ไม่มีสิทธิ์เข้าถึงหน้านี้');
    }
    return $user;
}

function attempt_login(string $username, string $password): ?array
{
    $stmt = db()->prepare('SELECT * FROM users WHERE username = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        return null;
    }

    session_regenerate_id(true);
    $_SESSION['user'] = [
        'id' => (int)$user['id'],
        'username' => $user['username'],
        'full_name' => $user['full_name'],
        'role' => $user['role'],
        'position' => $user['position'] ?? null,
    ];

    return $_SESSION['user'];
}

function logout(): void
{
    $_SESSION = [];
    session_destroy();
}
