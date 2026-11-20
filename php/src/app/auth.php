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

function is_skill_secretary(?array $user): bool
{
    return $user && $user['role'] === 'judge' && ($user['position'] ?? '') === 'กรรมการและเลขานุการ';
}

/** ประธานกรรมการและกรรมการและเลขานุการ แก้ไขเกณฑ์การให้คะแนนได้เฉพาะกลุ่มเกณฑ์ที่ตนเองรับผิดชอบ (judge_skills) ส่วน Admin แก้ไขได้ทุกกลุ่ม */
function can_manage_skill_criteria(?array $user, PDO $pdo, int $skillId): bool
{
    if (!$user) {
        return false;
    }
    if ($user['role'] === 'admin') {
        return true;
    }
    if (!is_head_judge($user) && !is_skill_secretary($user)) {
        return false;
    }
    $stmt = $pdo->prepare('SELECT 1 FROM judge_skills WHERE judge_id = ? AND skill_id = ?');
    $stmt->execute([(int)$user['id'], $skillId]);
    return (bool)$stmt->fetchColumn();
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

/** @return array{status: 'ok'|'needs_2fa'|'invalid', user?: array} */
function attempt_login(string $username, string $password): array
{
    $stmt = db()->prepare('SELECT * FROM users WHERE username = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        return ['status' => 'invalid'];
    }

    if (!empty($user['totp_enabled'])) {
        $_SESSION['pending_2fa_user_id'] = (int)$user['id'];
        return ['status' => 'needs_2fa'];
    }

    finalize_login($user);
    return ['status' => 'ok', 'user' => $_SESSION['user']];
}

function finalize_login(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user'] = [
        'id' => (int)$user['id'],
        'username' => $user['username'],
        'full_name' => $user['full_name'],
        'role' => $user['role'],
        'position' => $user['position'] ?? null,
    ];
    unset($_SESSION['pending_2fa_user_id']);
}

/** ตรวจรหัส 2FA จากแอปยืนยันตัวตนของผู้ใช้ที่ login ด้วยรหัสผ่านถูกต้องแล้วและรอยืนยันขั้นที่สองอยู่ (pending_2fa_user_id ใน session) */
function verify_pending_2fa(string $code): bool
{
    $userId = $_SESSION['pending_2fa_user_id'] ?? null;
    if (!$userId) {
        return false;
    }
    $stmt = db()->prepare('SELECT * FROM users WHERE id = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    if (!$user || empty($user['totp_enabled']) || empty($user['totp_secret']) || !totp_verify_code($user['totp_secret'], $code)) {
        return false;
    }
    finalize_login($user);
    return true;
}

function logout(): void
{
    $_SESSION = [];
    session_destroy();
}
