<?php
declare(strict_types=1);

/**
 * seedpassword — รีเซ็ตรหัสผ่านบัญชี Admin (หรือกรรมการ) โดยตรงผ่านฐานข้อมูล กรณีจำรหัสผ่านเดิมไม่ได้
 * ใช้งานเฉพาะผ่าน CLI เท่านั้น (เรียกผ่าน docker compose exec)
 *
 * วิธีใช้:
 *   docker compose exec app php app/seed_password.php <รหัสผ่านใหม่> [username]
 *   (ไม่ระบุ username = รีเซ็ตให้บัญชี admin ตัวแรกที่พบในระบบ)
 *
 * ตัวอย่าง:
 *   docker compose exec app php app/seed_password.php "MyNewPass@2569"
 *   docker compose exec app php app/seed_password.php "MyNewPass@2569" admin
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('seedpassword ใช้งานได้เฉพาะผ่าน command line เท่านั้น');
}

require __DIR__ . '/db.php';

$newPassword = $argv[1] ?? null;
$username = $argv[2] ?? null;

if ($newPassword === null || trim($newPassword) === '') {
    fwrite(STDERR, "วิธีใช้: docker compose exec app php app/seed_password.php <รหัสผ่านใหม่> [username]\n");
    fwrite(STDERR, "        ไม่ระบุ username = รีเซ็ตให้บัญชี Admin ตัวแรกที่พบในระบบ\n");
    exit(1);
}
if (strlen($newPassword) < 6) {
    fwrite(STDERR, "รหัสผ่านสั้นเกินไป กรุณาตั้งอย่างน้อย 6 ตัวอักษร\n");
    exit(1);
}

$pdo = db();

if ($username !== null) {
    $stmt = $pdo->prepare('SELECT id, username, full_name, role FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
} else {
    $stmt = $pdo->query("SELECT id, username, full_name, role FROM users WHERE role = 'admin' ORDER BY id LIMIT 1");
}
$user = $stmt->fetch();

if (!$user) {
    fwrite(STDERR, $username !== null
        ? "ไม่พบผู้ใช้ที่มีชื่อผู้ใช้ '{$username}' ในระบบ\n"
        : "ไม่พบบัญชี Admin ในระบบ ลองระบุ username ที่ต้องการรีเซ็ตแทน\n");
    exit(1);
}

$hash = password_hash($newPassword, PASSWORD_DEFAULT);
$pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$hash, (int)$user['id']]);

echo "✅ ตั้งรหัสผ่านใหม่สำเร็จ สำหรับผู้ใช้ '{$user['username']}' ({$user['full_name']}, role={$user['role']})\n";
echo "   เข้าสู่ระบบด้วย username: {$user['username']} และรหัสผ่านใหม่ที่เพิ่งตั้งได้ทันที\n";
