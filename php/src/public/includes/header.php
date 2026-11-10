<?php
/** @var array|null $user */
$user = current_user();
?>
<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= isset($pageTitle) ? e($pageTitle) . ' - ' : '' ?>ระบบให้คะแนนกรรมการ</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="/assets/css/style.css?v=<?= @filemtime(__DIR__ . '/../assets/css/style.css') ?>" rel="stylesheet">
</head>
<body>
<?php if ($user): ?>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4">
    <div class="container-fluid">
        <a class="navbar-brand" href="<?= $user['role'] === 'admin' ? base_url('admin/dashboard.php') : base_url('judge/dashboard.php') ?>">
            🏅 ระบบให้คะแนนกรรมการ
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMain">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navMain">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <?php if ($user['role'] === 'admin'): ?>
                    <li class="nav-item"><a class="nav-link" href="<?= base_url('admin/dashboard.php') ?>">🏠 หน้าหลัก</a></li>
                    <li class="nav-item"><a class="nav-link" href="<?= base_url('admin/divisions.php') ?>">🏆 ทักษะ</a></li>
                    <li class="nav-item"><a class="nav-link" href="<?= base_url('admin/judges.php') ?>">⚖️ กรรมการ</a></li>
                    <li class="nav-item"><a class="nav-link" href="<?= base_url('admin/skills.php') ?>">🎯 กลุ่มเกณฑ์การให้คะแนน</a></li>
                    <li class="nav-item"><a class="nav-link" href="<?= base_url('admin/teams.php') ?>">👥 ทีมผู้เข้าแข่งขัน</a></li>
                    <li class="nav-item"><a class="nav-link" href="<?= base_url('admin/summary.php') ?>">📊 สรุปคะแนน</a></li>
                    <li class="nav-item"><a class="nav-link" href="<?= base_url('admin/score_sheet.php') ?>">🖨️ ใบให้คะแนน (สำรอง)</a></li>
                    <li class="nav-item"><a class="nav-link" href="<?= base_url('admin/backup.php') ?>">🗄️ Import/Export</a></li>
                <?php else: ?>
                    <li class="nav-item"><a class="nav-link" href="<?= base_url('judge/dashboard.php') ?>">✏️ ให้คะแนน</a></li>
                    <?php if (is_head_judge($user)): ?>
                        <li class="nav-item"><a class="nav-link" href="<?= base_url('admin/summary.php') ?>">📊 ดูผลคะแนน</a></li>
                        <li class="nav-item"><a class="nav-link" href="<?= base_url('judge/assign.php') ?>">🗂️ มอบหมายทีม</a></li>
                        <li class="nav-item"><a class="nav-link" href="<?= base_url('admin/score_sheet.php') ?>">🖨️ ใบให้คะแนน (สำรอง)</a></li>
                    <?php endif; ?>
                <?php endif; ?>
                <li class="nav-item"><a class="nav-link" href="<?= base_url('help.php') ?>">❓ คู่มือการใช้งาน</a></li>
            </ul>
            <span class="navbar-text text-light me-3">
                👤 <?= e($user['full_name']) ?> (<?= e($user['role'] === 'admin' ? '⚙️ ผู้ดูแลระบบ' : position_label($user['position'] ?? 'กรรมการ')) ?>)
            </span>
            <a href="<?= base_url('logout.php') ?>" class="btn btn-outline-light btn-sm">🚪 ออกจากระบบ</a>
        </div>
    </div>
</nav>
<?php endif; ?>
<main class="container-fluid px-4 pb-5">
<?php
$flash = flash_get();
if ($flash):
?>
<script>
window.__flash = <?= json_encode($flash, JSON_UNESCAPED_UNICODE) ?>;
</script>
<?php endif; ?>
