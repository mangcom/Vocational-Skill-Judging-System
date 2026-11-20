<?php
require __DIR__ . '/../app/bootstrap.php';
$authUser = require_login();

$pdo = db();
$userId = (int)$authUser['id'];

$avatarDir = __DIR__ . '/assets/uploads/avatars';
$allowedAvatarExt = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
$maxAvatarBytes = 2 * 1024 * 1024; // 2MB

function fetch_current_user(PDO $pdo, int $id): array
{
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch();
}

$me = fetch_current_user($pdo, $userId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        $fullName = trim($_POST['full_name'] ?? '');
        if ($fullName === '') {
            flash_set('error', 'กรุณากรอกชื่อ-สกุล');
        } else {
            $avatarPath = $me['avatar_path'];
            $uploadError = null;
            if (!empty($_FILES['avatar']['name'])) {
                $file = $_FILES['avatar'];
                if ($file['error'] !== UPLOAD_ERR_OK) {
                    $uploadError = 'อัปโหลดรูปไม่สำเร็จ (error code ' . $file['error'] . ')';
                } elseif ($file['size'] > $maxAvatarBytes) {
                    $uploadError = 'ไฟล์รูปใหญ่เกิน 2MB';
                } else {
                    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                    if (!in_array($ext, $allowedAvatarExt, true)) {
                        $uploadError = 'รองรับเฉพาะไฟล์รูปภาพ ' . implode(', ', $allowedAvatarExt);
                    } else {
                        if (!is_dir($avatarDir)) {
                            mkdir($avatarDir, 0775, true);
                        }
                        $newName = 'u' . $userId . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
                        if (move_uploaded_file($file['tmp_name'], $avatarDir . '/' . $newName)) {
                            if ($avatarPath && is_file($avatarDir . '/' . $avatarPath)) {
                                @unlink($avatarDir . '/' . $avatarPath);
                            }
                            $avatarPath = $newName;
                        } else {
                            $uploadError = 'บันทึกไฟล์รูปไม่สำเร็จ';
                        }
                    }
                }
            }
            if ($uploadError !== null) {
                flash_set('error', $uploadError);
            } else {
                $pdo->prepare('UPDATE users SET full_name = ?, avatar_path = ? WHERE id = ?')
                    ->execute([$fullName, $avatarPath, $userId]);
                $_SESSION['user']['full_name'] = $fullName;
                flash_set('success', 'บันทึกข้อมูลส่วนบุคคลสำเร็จ');
            }
        }
    } elseif ($action === 'remove_avatar') {
        if ($me['avatar_path'] && is_file($avatarDir . '/' . $me['avatar_path'])) {
            @unlink($avatarDir . '/' . $me['avatar_path']);
        }
        $pdo->prepare('UPDATE users SET avatar_path = NULL WHERE id = ?')->execute([$userId]);
        flash_set('success', 'ลบรูปโปรไฟล์แล้ว');
    } elseif ($action === 'change_password') {
        $current = (string)($_POST['current_password'] ?? '');
        $new = (string)($_POST['new_password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');

        if (!password_verify($current, $me['password_hash'])) {
            flash_set('error', 'รหัสผ่านปัจจุบันไม่ถูกต้อง');
        } elseif (strlen($new) < 6) {
            flash_set('error', 'รหัสผ่านใหม่ต้องมีอย่างน้อย 6 ตัวอักษร');
        } elseif ($new !== $confirm) {
            flash_set('error', 'ยืนยันรหัสผ่านใหม่ไม่ตรงกัน');
        } else {
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($new, PASSWORD_DEFAULT), $userId]);
            flash_set('success', 'เปลี่ยนรหัสผ่านสำเร็จ');
        }
    } elseif ($action === 'enable_2fa_init') {
        $secret = totp_generate_secret();
        $_SESSION['totp_setup_secret'] = $secret;
        flash_set('success', '📷 สแกน QR Code ด้วยแอปยืนยันตัวตน แล้วกรอกรหัส 6 หลักเพื่อยืนยัน');
    } elseif ($action === 'enable_2fa_confirm') {
        $secret = $_SESSION['totp_setup_secret'] ?? null;
        $code = trim($_POST['code'] ?? '');
        if (!$secret) {
            flash_set('error', 'กรุณากดเริ่มตั้งค่า 2FA ใหม่อีกครั้ง');
        } elseif (!totp_verify_code($secret, $code)) {
            flash_set('error', 'รหัสยืนยันไม่ถูกต้อง กรุณาลองใหม่');
        } else {
            $pdo->prepare('UPDATE users SET totp_secret = ?, totp_enabled = 1 WHERE id = ?')->execute([$secret, $userId]);
            unset($_SESSION['totp_setup_secret']);
            flash_set('success', '✅ เปิดใช้งานการยืนยันตัวตน 2 ขั้นตอนสำเร็จ');
        }
    } elseif ($action === 'enable_2fa_cancel') {
        unset($_SESSION['totp_setup_secret']);
    } elseif ($action === 'disable_2fa') {
        $current = (string)($_POST['current_password'] ?? '');
        if (!password_verify($current, $me['password_hash'])) {
            flash_set('error', 'รหัสผ่านไม่ถูกต้อง');
        } else {
            $pdo->prepare('UPDATE users SET totp_secret = NULL, totp_enabled = 0 WHERE id = ?')->execute([$userId]);
            flash_set('success', 'ปิดใช้งานการยืนยันตัวตน 2 ขั้นตอนแล้ว');
        }
    }

    redirect(base_url('profile.php'));
}

$me = fetch_current_user($pdo, $userId);
$setupSecret = $_SESSION['totp_setup_secret'] ?? null;
$totpUri = $setupSecret ? totp_provisioning_uri($setupSecret, $me['username'], 'ระบบให้คะแนนกรรมการ') : null;

$pageTitle = 'โปรไฟล์ของฉัน';
require __DIR__ . '/includes/header.php';
?>
<h3 class="mb-3">👤 โปรไฟล์ของฉัน</h3>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header">📝 ข้อมูลส่วนบุคคล</div>
            <div class="card-body">
                <form method="post" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update_profile">
                    <div class="d-flex align-items-center gap-3 mb-3">
                        <?php if ($me['avatar_path']): ?>
                            <img src="<?= base_url('assets/uploads/avatars/' . rawurlencode($me['avatar_path'])) ?>" alt="avatar" class="rounded-circle" style="width:72px;height:72px;object-fit:cover;">
                        <?php else: ?>
                            <div class="rounded-circle bg-secondary-subtle d-flex align-items-center justify-content-center" style="width:72px;height:72px;font-size:28px;">👤</div>
                        <?php endif; ?>
                        <div class="flex-grow-1">
                            <label class="form-label mb-1">รูปโปรไฟล์ (jpg, png, gif, webp — ไม่เกิน 2MB)</label>
                            <input type="file" name="avatar" accept="image/*" class="form-control form-control-sm">
                        </div>
                    </div>
                    <?php if ($me['avatar_path']): ?>
                        <div class="mb-3">
                            <button type="submit" formaction="<?= base_url('profile.php') ?>" name="action" value="remove_avatar" class="btn btn-sm btn-outline-danger">🗑️ ลบรูปโปรไฟล์</button>
                        </div>
                    <?php endif; ?>
                    <div class="mb-3">
                        <label class="form-label">ชื่อ-สกุล</label>
                        <input type="text" name="full_name" class="form-control" value="<?= e($me['full_name']) ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">ชื่อผู้ใช้</label>
                        <input type="text" class="form-control" value="<?= e($me['username']) ?>" disabled>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">บทบาท / ตำแหน่ง</label>
                        <input type="text" class="form-control" value="<?= e($me['role'] === 'admin' ? 'ผู้ดูแลระบบ' : position_label($me['position'] ?? 'กรรมการ')) ?>" disabled>
                    </div>
                    <button type="submit" class="btn btn-primary">💾 บันทึกข้อมูล</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header">🔑 เปลี่ยนรหัสผ่าน</div>
            <div class="card-body">
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="change_password">
                    <div class="mb-3">
                        <label class="form-label">รหัสผ่านปัจจุบัน</label>
                        <input type="password" name="current_password" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">รหัสผ่านใหม่</label>
                        <input type="password" name="new_password" class="form-control" minlength="6" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">ยืนยันรหัสผ่านใหม่</label>
                        <input type="password" name="confirm_password" class="form-control" minlength="6" required>
                    </div>
                    <button type="submit" class="btn btn-primary">🔐 เปลี่ยนรหัสผ่าน</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="card">
            <div class="card-header">🛡️ ยืนยันตัวตน 2 ขั้นตอน (2FA)</div>
            <div class="card-body">
                <?php if ($me['totp_enabled']): ?>
                    <p class="text-success">✅ เปิดใช้งานอยู่ — ทุกครั้งที่เข้าสู่ระบบจะต้องกรอกรหัส 6 หลักจากแอปยืนยันตัวตนเพิ่มเติม</p>
                    <form method="post" class="row g-2 align-items-end" style="max-width:420px;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="disable_2fa">
                        <div class="col-8">
                            <label class="form-label">กรอกรหัสผ่านเพื่อปิดใช้งาน</label>
                            <input type="password" name="current_password" class="form-control" required>
                        </div>
                        <div class="col-4">
                            <button type="submit" class="btn btn-outline-danger w-100">🚫 ปิดใช้งาน</button>
                        </div>
                    </form>
                <?php elseif ($setupSecret): ?>
                    <p class="text-muted">สแกน QR Code ด้วยแอปยืนยันตัวตน (เช่น Google Authenticator, Microsoft Authenticator) หรือกรอกรหัสลับด้วยตนเอง แล้วกรอกรหัส 6 หลักที่แอปแสดงเพื่อยืนยัน</p>
                    <div class="row g-3 align-items-start">
                        <div class="col-auto">
                            <canvas id="totpQr" width="200" height="200" class="border rounded"></canvas>
                        </div>
                        <div class="col">
                            <p class="mb-1 small text-muted">รหัสลับ (กรอกเองถ้าสแกนไม่ได้):</p>
                            <code class="d-block mb-3 fs-6"><?= e($setupSecret) ?></code>
                            <form method="post" class="row g-2 align-items-end" style="max-width:380px;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="enable_2fa_confirm">
                                <div class="col-7">
                                    <label class="form-label">รหัส 6 หลักจากแอป</label>
                                    <input type="text" name="code" class="form-control" inputmode="numeric" maxlength="6" required autofocus>
                                </div>
                                <div class="col-5 d-flex gap-2">
                                    <button type="submit" class="btn btn-primary flex-grow-1">✅ ยืนยัน</button>
                                </div>
                            </form>
                            <form method="post" class="mt-2">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="enable_2fa_cancel">
                                <button type="submit" class="btn btn-sm btn-link px-0">ยกเลิก</button>
                            </form>
                        </div>
                    </div>
                    <script src="https://cdn.jsdelivr.net/npm/qrcode@1.5.3/build/qrcode.min.js"></script>
                    <script>
                    QRCode.toCanvas(document.getElementById('totpQr'), <?= json_encode($totpUri) ?>, { width: 200 });
                    </script>
                <?php else: ?>
                    <p class="text-muted">ยังไม่ได้เปิดใช้งาน — เพิ่มความปลอดภัยให้บัญชีด้วยรหัสยืนยัน 6 หลักจากแอปยืนยันตัวตนทุกครั้งที่เข้าสู่ระบบ (ไม่บังคับ)</p>
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="enable_2fa_init">
                        <button type="submit" class="btn btn-primary">🛡️ เปิดใช้งาน 2FA</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
