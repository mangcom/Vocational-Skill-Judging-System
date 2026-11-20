<?php
require __DIR__ . '/../app/bootstrap.php';

if (current_user()) {
    redirect(base_url('index.php'));
}
if (empty($_SESSION['pending_2fa_user_id'])) {
    redirect(base_url('login.php'));
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if (isset($_POST['cancel'])) {
        unset($_SESSION['pending_2fa_user_id']);
        redirect(base_url('login.php'));
    }
    $code = trim($_POST['code'] ?? '');
    if (verify_pending_2fa($code)) {
        $user = current_user();
        redirect($user['role'] === 'admin' ? base_url('admin/dashboard.php') : base_url('judge/dashboard.php'));
    }
    $error = 'รหัสยืนยันไม่ถูกต้องหรือหมดอายุ กรุณาลองใหม่';
}

$pageTitle = 'ยืนยันตัวตน 2 ขั้นตอน';
?>
<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ยืนยันตัวตน 2 ขั้นตอน - ระบบให้คะแนนกรรมการ</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="/assets/css/style.css?v=<?= @filemtime(__DIR__ . '/assets/css/style.css') ?>" rel="stylesheet">
</head>
<body class="d-flex align-items-center" style="min-height:100vh;">
<div class="container" style="max-width: 420px;">
    <div class="card shadow-sm">
        <div class="card-body p-4">
            <h4 class="card-title text-center mb-3">🔒 ยืนยันตัวตน 2 ขั้นตอน</h4>
            <p class="text-center text-muted mb-4">กรอกรหัส 6 หลักจากแอปยืนยันตัวตนของท่าน (เช่น Google Authenticator)</p>
            <?php if ($error): ?>
                <div class="alert alert-danger">⚠️ <?= e($error) ?></div>
            <?php endif; ?>
            <form method="post">
                <?= csrf_field() ?>
                <div class="mb-3">
                    <label class="form-label">🔢 รหัสยืนยัน 6 หลัก</label>
                    <input type="text" name="code" class="form-control text-center fs-3" style="letter-spacing: .3em;" inputmode="numeric" pattern="[0-9]*" maxlength="6" required autofocus>
                </div>
                <button type="submit" class="btn btn-primary w-100 mb-2">✅ ยืนยัน</button>
                <button type="submit" name="cancel" value="1" class="btn btn-link w-100">⬅️ กลับไปหน้าเข้าสู่ระบบ</button>
            </form>
        </div>
    </div>
</div>
</body>
</html>
