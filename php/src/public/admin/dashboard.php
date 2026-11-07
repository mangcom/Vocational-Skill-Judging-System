<?php
require __DIR__ . '/../../app/bootstrap.php';
require_role('admin');

$pdo = db();

$divisionCount = (int)$pdo->query('SELECT COUNT(*) c FROM divisions')->fetch()['c'];
$judgeCount = (int)$pdo->query('SELECT COUNT(*) c FROM users WHERE role="judge"')->fetch()['c'];
$teamCount = (int)$pdo->query('SELECT COUNT(*) c FROM teams')->fetch()['c'];
$skillCount = (int)$pdo->query('SELECT COUNT(*) c FROM skills')->fetch()['c'];
$criteriaCount = (int)$pdo->query('SELECT COUNT(*) c FROM criteria')->fetch()['c'];
$scoreCount = (int)$pdo->query('SELECT COUNT(*) c FROM scores')->fetch()['c'];
$expectedScores = $judgeCount > 0 ? $criteriaCount : 0; // informational only

$unassigned = $pdo->query('
    SELECT u.full_name FROM users u
    LEFT JOIN judge_skills js ON js.judge_id = u.id
    WHERE u.role = "judge" AND js.judge_id IS NULL
')->fetchAll();

$pageTitle = 'หน้าหลักผู้ดูแลระบบ';
require __DIR__ . '/../includes/header.php';
?>
<h3 class="mb-4">📊 ภาพรวมระบบ</h3>

<div class="row g-3 mb-4">
    <div class="col-md-3">
        <a href="<?= base_url('admin/judges.php') ?>" class="card text-center p-3 stat-link-card">
            <div class="fs-1">⚖️</div>
            <div class="fs-2 fw-bold"><?= $divisionCount ?> / <?= $judgeCount ?></div>
            <div class="text-muted">ทักษะ / กรรมการ</div>
        </a>
    </div>
    <div class="col-md-3">
        <a href="<?= base_url('admin/teams.php') ?>" class="card text-center p-3 stat-link-card">
            <div class="fs-1">👥</div>
            <div class="fs-2 fw-bold"><?= $teamCount ?></div>
            <div class="text-muted">ทีมผู้เข้าแข่งขัน</div>
        </a>
    </div>
    <div class="col-md-3">
        <a href="<?= base_url('admin/skills.php') ?>" class="card text-center p-3 stat-link-card">
            <div class="fs-1">🎯</div>
            <div class="fs-2 fw-bold"><?= $divisionCount ?> / <?= $skillCount ?></div>
            <div class="text-muted">ทักษะ / กลุ่มเกณฑ์การให้คะแนน</div>
        </a>
    </div>
    <div class="col-md-3">
        <a href="<?= base_url('admin/summary.php') ?>" class="card text-center p-3 stat-link-card">
            <div class="fs-1">📝</div>
            <div class="fs-2 fw-bold"><?= $scoreCount ?></div>
            <div class="text-muted">รายการคะแนนที่บันทึกแล้ว</div>
        </a>
    </div>
</div>

<?php if (!empty($unassigned)): ?>
    <div class="alert alert-warning">
        ⚠️ <strong>กรรมการที่ยังไม่ได้กำหนดกลุ่มเกณฑ์ที่รับผิดชอบ:</strong>
        <?= e(implode(', ', array_column($unassigned, 'full_name'))) ?>
        &mdash; <a href="<?= base_url('admin/judges.php') ?>">🎯 ไปกำหนดกลุ่มเกณฑ์</a>
    </div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-md-2">
        <a href="<?= base_url('admin/divisions.php') ?>" class="btn btn-outline-secondary w-100 py-3">🏆 ทักษะ</a>
    </div>
    <div class="col-md-2">
        <a href="<?= base_url('admin/judges.php') ?>" class="btn btn-outline-primary w-100 py-3">⚖️ จัดการกรรมการ</a>
    </div>
    <div class="col-md-2">
        <a href="<?= base_url('admin/skills.php') ?>" class="btn btn-outline-primary w-100 py-3">🎯 จัดการกลุ่มเกณฑ์การให้คะแนน</a>
    </div>
    <div class="col-md-3">
        <a href="<?= base_url('admin/teams.php') ?>" class="btn btn-outline-primary w-100 py-3">👥 จัดการทีมผู้เข้าแข่งขัน</a>
    </div>
    <div class="col-md-3">
        <a href="<?= base_url('admin/summary.php') ?>" class="btn btn-primary w-100 py-3">📊 ดูสรุปคะแนน (เฉพาะ Admin)</a>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
