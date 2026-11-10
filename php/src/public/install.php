<?php
require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/migration_runner.php';

$loggedInAdmin = current_user() && current_user()['role'] === 'admin';

/** ถือว่า "ติดตั้งแล้ว" ถ้ามีการตั้ง flag ไว้ หรือ (เผื่อระบบเก่าก่อนมีฟีเจอร์นี้) ฐานข้อมูลเชื่อมต่อได้และมีบัญชี admin อยู่แล้ว */
$installed = env_value('APP_INSTALLED', '') === 'true';
if (!$installed) {
    try {
        $existingAdminCount = db()->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
        if ((int)$existingAdminCount > 0) {
            $installed = true;
        }
    } catch (Throwable $e) {
        // เชื่อมต่อฐานข้อมูลไม่ได้เลย = ยังไม่เคยติดตั้ง ปล่อยให้เข้าหน้านี้ได้ตามปกติ
    }
}

if ($installed && !$loggedInAdmin) {
    redirect(base_url('login.php?installed=1'));
}

$errors = [];
$success = false;
$needsRestart = false;

$form = [
    'db_host' => env_value('DB_HOST', 'db'),
    'db_name' => env_value('DB_NAME', 'judge_scoring'),
    'db_user' => env_value('DB_USER', 'judge_app'),
    'db_password' => env_value('DB_PASSWORD', ''),
    'admin_username' => env_value('ADMIN_DEFAULT_USERNAME', 'admin'),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $form['db_host'] = trim((string)($_POST['db_host'] ?? ''));
    $form['db_name'] = trim((string)($_POST['db_name'] ?? ''));
    $form['db_user'] = trim((string)($_POST['db_user'] ?? ''));
    $form['db_password'] = (string)($_POST['db_password'] ?? '');
    $form['admin_username'] = trim((string)($_POST['admin_username'] ?? ''));
    $adminFullName = trim((string)($_POST['admin_full_name'] ?? '')) ?: 'ผู้ดูแลระบบ';
    $adminPassword = (string)($_POST['admin_password'] ?? '');
    $adminPasswordConfirm = (string)($_POST['admin_password_confirm'] ?? '');

    if ($form['db_host'] === '') {
        $errors[] = 'กรุณากรอก Database Hostname';
    }
    if ($form['db_name'] === '') {
        $errors[] = 'กรุณากรอก Database Name';
    }
    if ($form['db_user'] === '') {
        $errors[] = 'กรุณากรอก Database User';
    }
    if ($form['admin_username'] === '' || !preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $form['admin_username'])) {
        $errors[] = 'ชื่อผู้ใช้ Admin ต้องมีความยาว 3-50 ตัวอักษร ใช้ได้เฉพาะ a-z, A-Z, 0-9, _ . -';
    }
    if (strlen($adminPassword) < 8) {
        $errors[] = 'รหัสผ่าน Admin ต้องมีความยาวอย่างน้อย 8 ตัวอักษร';
    } elseif ($adminPassword !== $adminPasswordConfirm) {
        $errors[] = 'ยืนยันรหัสผ่าน Admin ไม่ตรงกัน';
    }

    $pdo = null;
    if (empty($errors)) {
        try {
            $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $form['db_host'], $form['db_name']);
            $pdo = new PDO($dsn, $form['db_user'], $form['db_password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT => 5,
            ]);
        } catch (Throwable $e) {
            $errors[] = 'เชื่อมต่อฐานข้อมูลไม่สำเร็จ: ' . $e->getMessage();
        }
    }

    if (empty($errors) && $pdo instanceof PDO) {
        try {
            run_migrations($pdo);

            $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ?');
            $stmt->execute([$form['admin_username']]);
            $existing = $stmt->fetch();
            $hash = password_hash($adminPassword, PASSWORD_DEFAULT);
            if ($existing) {
                $pdo->prepare('UPDATE users SET password_hash = ?, full_name = ?, role = ?, is_active = 1 WHERE id = ?')
                    ->execute([$hash, $adminFullName, 'admin', $existing['id']]);
            } else {
                $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role) VALUES (?, ?, ?, ?)')
                    ->execute([$form['admin_username'], $hash, $adminFullName, 'admin']);
            }

            $envWritten = env_write([
                'DB_HOST' => $form['db_host'],
                'DB_NAME' => $form['db_name'],
                'DB_USER' => $form['db_user'],
                'DB_PASSWORD' => $form['db_password'],
                'ADMIN_DEFAULT_USERNAME' => $form['admin_username'],
                'ADMIN_DEFAULT_PASSWORD' => $adminPassword,
                'APP_INSTALLED' => 'true',
            ]);

            if (!$envWritten) {
                $errors[] = 'ติดตั้งข้อมูลสำเร็จ แต่บันทึกไฟล์ .env ไม่สำเร็จ (ไม่มีสิทธิ์เขียนไฟล์) กรุณาตรวจสอบว่า docker-compose.yml mount ไฟล์ .env เข้า container ไว้แล้ว';
            } else {
                $success = true;
                $needsRestart = (
                    $form['db_host'] !== getenv('DB_HOST')
                    || $form['db_name'] !== getenv('DB_NAME')
                    || $form['db_user'] !== getenv('DB_USER')
                    || $form['db_password'] !== getenv('DB_PASSWORD')
                );
            }
        } catch (Throwable $e) {
            $errors[] = 'เกิดข้อผิดพลาดระหว่างติดตั้ง: ' . $e->getMessage();
        }
    }
}
?>
<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ติดตั้งระบบ - ระบบให้คะแนนกรรมการ</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="/assets/css/style.css?v=<?= @filemtime(__DIR__ . '/assets/css/style.css') ?>" rel="stylesheet">
</head>
<body class="py-5">
<div class="container" style="max-width: 640px;">
    <div class="text-center mb-4">
        <div style="font-size:42px;">🏅</div>
        <h3 class="mb-0">ติดตั้งระบบให้คะแนนกรรมการ</h3>
        <p class="text-muted">Vocational Skill Judging System (VSJS) — ตั้งค่าเริ่มต้นก่อนใช้งาน</p>
    </div>

    <?php if ($success): ?>
        <div class="card shadow-sm border-success">
            <div class="card-body p-4 text-center">
                <div style="font-size:40px;">✅</div>
                <h5 class="mt-2">ติดตั้งระบบสำเร็จ!</h5>
                <p class="text-muted">
                    สร้างบัญชีผู้ดูแลระบบ <b><?= e($form['admin_username']) ?></b> และบันทึกค่าการเชื่อมต่อลงไฟล์ <code>.env</code> เรียบร้อยแล้ว
                </p>
                <?php if ($needsRestart): ?>
                    <div class="alert alert-warning text-start">
                        ⚠️ ค่าการเชื่อมต่อฐานข้อมูลที่ตั้งไว้แตกต่างจากที่ container กำลังใช้งานอยู่ตอนนี้
                        กรุณารันคำสั่งต่อไปนี้เพื่อให้ container โหลดค่าล่าสุด แล้วจึงเข้าสู่ระบบ:
                        <pre class="bg-dark text-light p-2 rounded mt-2 mb-0">docker compose up -d</pre>
                    </div>
                <?php else: ?>
                    <div class="alert alert-success">พร้อมเข้าสู่ระบบได้ทันที</div>
                <?php endif; ?>
                <a href="<?= base_url('login.php') ?>" class="btn btn-primary">🔐 ไปหน้าเข้าสู่ระบบ</a>
            </div>
        </div>
    <?php else: ?>
        <div class="card shadow-sm">
            <div class="card-body p-4">
                <?php if ($installed && $loggedInAdmin): ?>
                    <div class="alert alert-info">ℹ️ ระบบติดตั้งแล้ว หน้านี้ใช้สำหรับปรับค่าการเชื่อมต่อฐานข้อมูล/บัญชี Admin ใหม่ (เฉพาะผู้ดูแลระบบที่ล็อกอินอยู่)</div>
                <?php endif; ?>

                <?php foreach ($errors as $err): ?>
                    <div class="alert alert-danger">⚠️ <?= e($err) ?></div>
                <?php endforeach; ?>

                <form method="post">
                    <?= csrf_field() ?>

                    <h6 class="text-primary mb-2">🗄️ การเชื่อมต่อฐานข้อมูล</h6>
                    <div class="mb-3">
                        <label class="form-label">Database Hostname</label>
                        <input type="text" name="db_host" class="form-control" required value="<?= e($form['db_host']) ?>">
                        <div class="form-text">ค่าเริ่มต้นอ่านจาก .env — ถ้าใช้ docker-compose ที่แนบมากับระบบ ให้คงค่าเป็น <code>db</code> ไว้</div>
                    </div>
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Database Name</label>
                            <input type="text" name="db_name" class="form-control" required value="<?= e($form['db_name']) ?>">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Database User</label>
                            <input type="text" name="db_user" class="form-control" required value="<?= e($form['db_user']) ?>">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Database Password</label>
                            <input type="password" name="db_password" class="form-control" value="<?= e($form['db_password']) ?>">
                        </div>
                    </div>

                    <hr class="my-4">

                    <h6 class="text-primary mb-2">👤 บัญชีผู้ดูแลระบบ (Admin)</h6>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Admin Username</label>
                            <input type="text" name="admin_username" class="form-control" required value="<?= e($form['admin_username']) ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">ชื่อ-นามสกุล (แสดงในระบบ)</label>
                            <input type="text" name="admin_full_name" class="form-control" value="ผู้ดูแลระบบ">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Admin Password</label>
                            <input type="password" name="admin_password" class="form-control" required minlength="8">
                            <div class="form-text">อย่างน้อย 8 ตัวอักษร</div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">ยืนยัน Admin Password</label>
                            <input type="password" name="admin_password_confirm" class="form-control" required minlength="8">
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary w-100 mt-2">🚀 ติดตั้งระบบ</button>
                </form>
            </div>
        </div>
        <p class="text-muted text-center small mt-3">
            ระบบจะสร้างตารางฐานข้อมูล (ถ้ายังไม่มี) และบันทึกค่าทั้งหมดลงไฟล์ <code>.env</code> โดยอัตโนมัติ
        </p>
    <?php endif; ?>
</div>
</body>
</html>
