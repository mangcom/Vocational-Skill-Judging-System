<?php
require __DIR__ . '/../app/bootstrap.php';

if (current_user()) {
    redirect(base_url('index.php'));
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $username = trim($_POST['username'] ?? '');
    $password = (string)($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $error = 'กรุณากรอกชื่อผู้ใช้และรหัสผ่าน';
    } else {
        $result = attempt_login($username, $password);
        if ($result['status'] === 'needs_2fa') {
            redirect(base_url('verify_2fa.php'));
        } elseif ($result['status'] === 'ok') {
            $user = $result['user'];
            redirect($user['role'] === 'admin' ? base_url('admin/dashboard.php') : base_url('judge/dashboard.php'));
        } else {
            $error = 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง';
        }
    }
}

$pageTitle = 'เข้าสู่ระบบ';
?>
<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>เข้าสู่ระบบ - ระบบให้คะแนนกรรมการ</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="/assets/css/style.css?v=<?= @filemtime(__DIR__ . '/assets/css/style.css') ?>" rel="stylesheet">
</head>
<body class="d-flex align-items-center" style="min-height:100vh;">
<div class="container" style="max-width: 420px;">
    <div class="card shadow-sm">
        <div class="card-body p-4">
            <h4 class="card-title text-center mb-3">🏅 ระบบให้คะแนนกรรมการ</h4>
            <p class="text-center text-muted mb-4">เข้าสู่ระบบเพื่อให้คะแนนหรือจัดการข้อมูล</p>
            <?php if ($error): ?>
                <div class="alert alert-danger">⚠️ <?= e($error) ?></div>
            <?php elseif (isset($_GET['installed'])): ?>
                <div class="alert alert-info">ℹ️ ระบบติดตั้งเรียบร้อยแล้ว กรุณาเข้าสู่ระบบ</div>
            <?php endif; ?>
            <form method="post">
                <?= csrf_field() ?>
                <div class="mb-3">
                    <label class="form-label">👤 ชื่อผู้ใช้</label>
                    <input type="text" name="username" class="form-control" required autofocus value="<?= e($_POST['username'] ?? '') ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label">🔑 รหัสผ่าน</label>
                    <input type="password" name="password" class="form-control" required>
                </div>
                <button type="submit" class="btn btn-primary w-100">🔐 เข้าสู่ระบบ</button>
            </form>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
