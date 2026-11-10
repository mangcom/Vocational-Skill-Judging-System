<?php
declare(strict_types=1);

/** ที่อยู่ไฟล์ .env ของโปรเจกต์ (mount เข้ามาที่ /var/www/html/.env ผ่าน docker-compose.yml) */
function env_file_path(): string
{
    return __DIR__ . '/../.env';
}

function env_quote(string $value): string
{
    if ($value === '') {
        return '""';
    }
    if (preg_match('/^[A-Za-z0-9_@.\-\/:]+$/', $value)) {
        return $value;
    }
    return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
}

function env_unquote(string $value): string
{
    $value = trim($value);
    $len = strlen($value);
    if ($len >= 2 && $value[0] === '"' && $value[$len - 1] === '"') {
        return str_replace(['\\"', '\\\\'], ['"', '\\'], substr($value, 1, -1));
    }
    if ($len >= 2 && $value[0] === "'" && $value[$len - 1] === "'") {
        return substr($value, 1, -1);
    }
    return $value;
}

/** อ่านค่าปัจจุบันทั้งหมดจากไฟล์ .env (ไม่ใช่ getenv() ซึ่งเป็นค่าที่ container เริ่มต้นไว้ตอน start เท่านั้น) */
function env_read(): array
{
    $path = env_file_path();
    if (!is_file($path)) {
        return [];
    }
    $values = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#') || !str_contains($trimmed, '=')) {
            continue;
        }
        [$key, $val] = explode('=', $trimmed, 2);
        $values[trim($key)] = env_unquote($val);
    }
    return $values;
}

/** อ่านค่าหนึ่งค่า: เอาจากไฟล์ .env ก่อน (ค่าล่าสุด) ถ้าไม่มีค่อย fallback เป็น getenv() (ค่าตอน container start) แล้วค่อย default */
function env_value(string $key, string $default = ''): string
{
    static $cache = null;
    if ($cache === null) {
        $cache = env_read();
    }
    if (isset($cache[$key]) && $cache[$key] !== '') {
        return $cache[$key];
    }
    $fromEnv = getenv($key);
    return $fromEnv !== false && $fromEnv !== '' ? $fromEnv : $default;
}

/** อัปเดต/เพิ่มค่าใน .env โดยคงบรรทัดอื่น ๆ (รวมคอมเมนต์) ไว้ตามเดิม */
function env_write(array $updates): bool
{
    $path = env_file_path();
    $lines = is_file($path) ? file($path, FILE_IGNORE_NEW_LINES) : [];
    $seen = [];

    foreach ($lines as $i => $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#') || !str_contains($trimmed, '=')) {
            continue;
        }
        [$key] = explode('=', $trimmed, 2);
        $key = trim($key);
        if (array_key_exists($key, $updates)) {
            $lines[$i] = $key . '=' . env_quote((string)$updates[$key]);
            $seen[$key] = true;
        }
    }
    foreach ($updates as $key => $val) {
        if (!isset($seen[$key])) {
            $lines[] = $key . '=' . env_quote((string)$val);
        }
    }

    if (is_file($path) && !is_writable($path)) {
        return false;
    }
    // เขียนทับไฟล์ตรง ๆ ด้วย LOCK_EX (ไม่ใช้ tmp-file + rename เพราะ .env ถูก mount เข้า container
    // แบบ single-file bind mount ซึ่ง rename() ทับ mount point แบบนี้จะได้ "Device or resource busy")
    return file_put_contents($path, implode("\n", $lines) . "\n", LOCK_EX) !== false;
}
