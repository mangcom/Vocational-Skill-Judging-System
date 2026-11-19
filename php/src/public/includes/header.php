<?php
/** @var array|null $user */
$user = current_user();

$navItems = [];
if ($user) {
    if ($user['role'] === 'admin') {
        $navItems = [
            ['icon' => '🏠', 'label' => 'หน้าหลัก', 'href' => 'admin/dashboard.php'],
            ['icon' => '🏆', 'label' => 'ทักษะ', 'href' => 'admin/divisions.php'],
            ['icon' => '⚖️', 'label' => 'กรรมการ', 'href' => 'admin/judges.php'],
            ['icon' => '🎯', 'label' => 'กลุ่มเกณฑ์การให้คะแนน', 'href' => 'admin/skills.php'],
            ['icon' => '👥', 'label' => 'ทีมผู้เข้าแข่งขัน', 'href' => 'admin/teams.php'],
            ['icon' => '📊', 'label' => 'สรุปคะแนน', 'href' => 'admin/summary.php'],
            ['icon' => '📖', 'label' => 'เกณฑ์การแข่งขัน', 'href' => 'criteria_doc.php'],
            ['icon' => '🖨️', 'label' => 'ใบให้คะแนน', 'href' => 'admin/score_sheet.php'],
            ['icon' => '🗄️', 'label' => 'Import/Export', 'href' => 'admin/backup.php'],
        ];
    } else {
        $navItems = [
            ['icon' => '✏️', 'label' => 'ให้คะแนน', 'href' => 'judge/dashboard.php'],
            ['icon' => '📖', 'label' => 'เกณฑ์การแข่งขัน', 'href' => 'criteria_doc.php'],
        ];
        if (is_head_judge($user)) {
            $navItems[] = ['icon' => '📊', 'label' => 'ดูผลคะแนน', 'href' => 'admin/summary.php'];
            $navItems[] = ['icon' => '🗂️', 'label' => 'มอบหมายทีม', 'href' => 'judge/assign.php'];
            $navItems[] = ['icon' => '🖨️', 'label' => 'ใบให้คะแนน', 'href' => 'admin/score_sheet.php'];
        }
    }
    $navItems[] = ['icon' => '❓', 'label' => 'คู่มือการใช้งาน', 'href' => 'help.php'];
}

$currentPath = rtrim((string)parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/');
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
    <?php if ($user): ?>
    <script>
    (function () {
        try {
            if (localStorage.getItem('sidebarCollapsed') === '1') {
                document.documentElement.classList.add('sidebar-collapsed');
            }
        } catch (e) {}
    })();
    </script>
    <?php endif; ?>
</head>
<body>
<?php if ($user): ?>
<header class="app-topbar">
    <div class="topbar-left">
        <button type="button" id="sidebarToggle" class="sidebar-toggle-btn" aria-label="เปิด/ปิดเมนู" title="เปิด/ปิดเมนู">☰</button>
        <a class="brand" href="<?= $user['role'] === 'admin' ? base_url('admin/dashboard.php') : base_url('judge/dashboard.php') ?>">
            🏅 <span class="d-none d-sm-inline">ระบบให้คะแนนกรรมการ</span>
        </a>
    </div>
    <div class="topbar-right">
        <span class="topbar-user d-none d-md-inline">
            👤 <?= e($user['full_name']) ?> (<?= e($user['role'] === 'admin' ? '⚙️ ผู้ดูแลระบบ' : position_label($user['position'] ?? 'กรรมการ')) ?>)
        </span>
        <span class="topbar-user d-inline d-md-none" title="<?= e($user['full_name']) ?>">👤 <?= e($user['full_name']) ?></span>
        <a href="<?= base_url('logout.php') ?>" class="btn btn-outline-light btn-sm">
            🚪 <span class="d-none d-sm-inline">ออกจากระบบ</span>
        </a>
    </div>
</header>
<div class="app-shell">
    <aside id="appSidebar" class="app-sidebar">
        <nav>
            <ul class="nav flex-column">
                <?php foreach ($navItems as $item):
                    $itemPath = rtrim(base_url($item['href']), '/');
                    $isActive = $currentPath === $itemPath;
                ?>
                    <li class="nav-item">
                        <a class="nav-link<?= $isActive ? ' active' : '' ?>" href="<?= base_url($item['href']) ?>" title="<?= e($item['label']) ?>">
                            <span class="nav-icon"><?= $item['icon'] ?></span>
                            <span class="nav-label"><?= e($item['label']) ?></span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </nav>
    </aside>
    <main class="app-content">
<?php else: ?>
<main class="container-fluid px-4 pb-5">
<?php endif; ?>
<?php
$flash = flash_get();
if ($flash):
?>
<script>
window.__flash = <?= json_encode($flash, JSON_UNESCAPED_UNICODE) ?>;
</script>
<?php endif; ?>
