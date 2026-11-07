<?php
declare(strict_types=1);

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function csrf_verify(): void
{
    $sent = $_POST['csrf_token'] ?? '';
    if (!is_string($sent) || !hash_equals($_SESSION['csrf_token'] ?? '', $sent)) {
        http_response_code(400);
        exit('Invalid CSRF token. กรุณาโหลดหน้าใหม่แล้วลองอีกครั้ง');
    }
}

function flash_set(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function flash_get(): ?array
{
    if (empty($_SESSION['flash'])) {
        return null;
    }
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}

function redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

function base_url(string $path = ''): string
{
    return '/' . ltrim($path, '/');
}

function judge_position_options(): array
{
    return ['ประธานกรรมการ', 'รองประธานกรรมการ', 'กรรมการ', 'กรรมการและเลขานุการ', 'ผู้ช่วยกรรมการและเลขานุการ'];
}

function position_label(?string $position): string
{
    $icons = [
        'ประธานกรรมการ' => '👑',
        'รองประธานกรรมการ' => '🎖️',
        'กรรมการ' => '⚖️',
        'กรรมการและเลขานุการ' => '📋',
        'ผู้ช่วยกรรมการและเลขานุการ' => '📝',
    ];
    $icon = $icons[$position] ?? '⚖️';
    return $icon . ' ' . ($position ?? 'กรรมการ');
}

function fmt_score($value): string
{
    if ($value === null) {
        return '-';
    }
    $num = (float)$value;
    if (abs($num - round($num)) < 0.001) {
        return (string)(int)round($num);
    }
    return rtrim(rtrim(sprintf('%.2f', $num), '0'), '.');
}
