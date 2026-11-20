<?php
require __DIR__ . '/../app/bootstrap.php';
$user = require_login();

$pdo = db();

$divisions = scoring_all_divisions($pdo);
if (empty($divisions)) {
    flash_set('error', 'ยังไม่มีทักษะในระบบ');
    redirect(base_url($user['role'] === 'admin' ? 'admin/dashboard.php' : 'judge/dashboard.php'));
}
$currentDivision = scoring_current_division($pdo, $divisions);
$currentDivisionId = (int)$currentDivision['id'];

$skillsStmt = $pdo->prepare('SELECT * FROM skills WHERE division_id = ? ORDER BY sort_order, id');
$skillsStmt->execute([$currentDivisionId]);
$skills = $skillsStmt->fetchAll();

// --- กรรมการ (ไม่ใช่ Admin) เห็นเฉพาะกลุ่มเกณฑ์ที่ตนเองรับผิดชอบเท่านั้น ห้ามเห็นกลุ่มเกณฑ์อื่นในทักษะนี้ ---
$myAssignedSkillIds = null;
if ($user['role'] !== 'admin') {
    $assignedStmt = $pdo->prepare('SELECT skill_id FROM judge_skills WHERE judge_id = ?');
    $assignedStmt->execute([(int)$user['id']]);
    $myAssignedSkillIds = array_map('intval', array_column($assignedStmt->fetchAll(), 'skill_id'));
    $skills = array_values(array_filter($skills, fn($s) => in_array((int)$s['id'], $myAssignedSkillIds, true)));
}

$skillsById = [];
foreach ($skills as $s) {
    $skillsById[(int)$s['id']] = $s;
}

// --- สร้างเอกสารสำหรับพิมพ์ (เลือกกลุ่มเกณฑ์มาจากฟอร์ม POST ปกติ หรือลิงก์ตรง ?all=1 / ?skill_ids[]=.. แบบ GET) ---
$wantsPrint = ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['skill_ids']))
    || isset($_GET['skill_ids']) || isset($_GET['all']);
if ($wantsPrint) {
    if (isset($_GET['all'])) {
        $requestedIds = array_map(fn($s) => (int)$s['id'], $skills);
    } else {
        $requestedIds = array_map('intval', (array)($_POST['skill_ids'] ?? $_GET['skill_ids'] ?? []));
    }
    $printSkills = [];
    foreach ($skills as $s) {
        if (in_array((int)$s['id'], $requestedIds, true)) {
            $printSkills[] = $s;
        }
    }

    if (empty($printSkills)) {
        flash_set('error', 'กรุณาเลือกอย่างน้อย 1 กลุ่มเกณฑ์');
        redirect(base_url('criteria_doc.php?division_id=' . $currentDivisionId));
    }

    $skillIds = array_map(fn($s) => (int)$s['id'], $printSkills);
    $placeholders = implode(',', array_fill(0, count($skillIds), '?'));

    $groupsStmt = $pdo->prepare("SELECT * FROM criteria_groups WHERE skill_id IN ({$placeholders}) ORDER BY skill_id, sort_order, id");
    $groupsStmt->execute($skillIds);
    $groupsBySkill = [];
    foreach ($groupsStmt->fetchAll() as $g) {
        $groupsBySkill[(int)$g['skill_id']][] = $g;
    }

    $critStmt = $pdo->prepare("
        SELECT c.*, cg.code AS group_code, cg.name AS group_name
        FROM criteria c
        LEFT JOIN criteria_groups cg ON cg.id = c.group_id
        WHERE c.skill_id IN ({$placeholders}) ORDER BY c.skill_id, c.sort_order, c.id
    ");
    $critStmt->execute($skillIds);
    $criteriaBySkill = [];
    foreach ($critStmt->fetchAll() as $c) {
        $criteriaBySkill[(int)$c['skill_id']][] = $c;
    }

    $grandMax = 0;
    foreach ($printSkills as $s) {
        if (!$s['is_bonus']) {
            $grandMax += (float)$s['max_score'];
        }
    }
    ?>
<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <title>เกณฑ์การแข่งขัน - <?= e($currentDivision['name']) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        @page { size: A4; margin: 16mm 14mm; }
        * { box-sizing: border-box; }
        body { font-family: 'Sarabun', sans-serif; font-size: 13px; color: #000; margin: 0; padding: 0; }
        .toolbar { position: sticky; top: 0; background: #f0f0f0; padding: 10px; text-align: center; border-bottom: 2px solid #ccc; z-index: 10; }
        .toolbar button, .toolbar a { font-family: 'Sarabun', sans-serif; font-size: 14px; padding: 8px 16px; margin: 0 4px; cursor: pointer; text-decoration: none; }
        .doc { max-width: 210mm; margin: 0 auto; padding: 8mm; }

        .cover { text-align: center; padding-top: 50mm; page-break-after: always; }
        .cover .badge { font-size: 42px; margin-bottom: 10px; }
        .cover h1 { font-size: 22px; color: #0d4a8f; margin: 0 0 6px; }
        .cover h2 { font-size: 15px; font-weight: 500; color: #444; margin: 0 0 24px; }
        .cover .purpose { max-width: 150mm; margin: 0 auto 24px; background: #f3f7fc; border: 1px solid #d7e5f5; border-radius: 10px; padding: 14px 20px; font-size: 13px; text-align: left; }
        .cover table.toc { max-width: 150mm; margin: 0 auto; width: 100%; border-collapse: collapse; font-size: 12.5px; }
        .cover table.toc th, .cover table.toc td { border: 1px solid #ccc; padding: 5px 8px; text-align: left; }
        .cover table.toc th { background: #0d4a8f; color: #fff; }
        .cover .meta { margin-top: 24px; font-size: 11.5px; color: #666; }

        h1.skill-title { font-size: 17px; color: #0d4a8f; border-bottom: 3px solid #0d4a8f; padding-bottom: 5px; margin: 0 0 4px; page-break-before: always; }
        .skill-sub { font-size: 12px; color: #555; margin: 0 0 12px; }
        .group-title { background: #eaf1fb; border-left: 4px solid #0d4a8f; padding: 5px 10px; font-weight: 700; font-size: 13px; margin: 14px 0 6px; }
        .group-desc { font-size: 12px; color: #555; margin: 0 0 6px 10px; }

        table.crit { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        table.crit th, table.crit td { border: 1px solid #999; padding: 5px 7px; vertical-align: top; font-size: 12px; }
        table.crit th { background: #eee; text-align: center; font-weight: 600; }
        .col-code { width: 9%; text-align: center; font-weight: 600; }
        .col-item { width: 27%; font-weight: 600; }
        .col-method { width: 53%; }
        .col-max { width: 11%; text-align: center; }
        tr { page-break-inside: avoid; }
        thead { display: table-header-group; }

        .skill-block.break-before { page-break-before: always; }
        .first-skill { page-break-before: auto !important; }

        @media print {
            .toolbar { display: none; }
            .doc { max-width: none; padding: 0; }
        }
    </style>
</head>
<body>
<div class="toolbar no-print">
    <button onclick="window.print()">🖨️ พิมพ์ / บันทึกเป็น PDF</button>
    <a href="<?= base_url('admin/skills.php?division_id=' . $currentDivisionId) ?>">🏆 <?= e($currentDivision['name']) ?></a>
    <span style="color:#999;">|</span>
    <a href="<?= base_url('criteria_doc.php?division_id=' . $currentDivisionId) ?>">⬅️ กลับไปเลือกใหม่</a>
</div>
<div class="doc">
    <div class="cover">
        <div class="badge">📖</div>
        <h1>เอกสารเกณฑ์การแข่งขันสำหรับกรรมการ</h1>
        <h2><?= e($currentDivision['name']) ?></h2>
        <div class="purpose">
            📌 เอกสารนี้จัดทำขึ้นให้กรรมการผู้ประเมินอ่านทำความเข้าใจ <strong>หัวข้อใหญ่ หัวข้อย่อย และวิธีตรวจ/เกณฑ์การให้คะแนน</strong>
            ของแต่ละกลุ่มเกณฑ์ ก่อนเริ่มให้คะแนนจริง เพื่อให้กรรมการทุกคนตรวจและให้คะแนนด้วยมาตรฐานเดียวกัน
        </div>
        <table class="toc">
            <tr><th>รหัส</th><th>กลุ่มเกณฑ์</th><th>คะแนนเต็ม</th></tr>
            <?php foreach ($printSkills as $s): ?>
                <tr>
                    <td><?= e($s['code']) ?></td>
                    <td><?= e($s['name']) ?><?= $s['is_bonus'] ? ' (โจทย์พิเศษ)' : '' ?></td>
                    <td style="text-align:center;"><?= fmt_score($s['max_score']) ?></td>
                </tr>
            <?php endforeach; ?>
            <tr>
                <td colspan="2" style="text-align:right;font-weight:700;">รวมคะแนนเต็ม (ไม่รวมโจทย์พิเศษ)</td>
                <td style="text-align:center;font-weight:700;"><?= fmt_score($grandMax) ?></td>
            </tr>
        </table>
        <div class="meta">พิมพ์เมื่อ <?= e(date('d/m/Y H:i')) ?> น.</div>
    </div>

    <?php foreach ($printSkills as $skillIndex => $s): $skillId = (int)$s['id'];
        $groups = $groupsBySkill[$skillId] ?? [];
        $groupsByCode = [];
        foreach ($groups as $g) { $groupsByCode[$g['code']] = $g; }
        $criteria = $criteriaBySkill[$skillId] ?? [];
    ?>
    <div class="skill-block<?= $skillIndex === 0 ? ' first-skill' : '' ?>">
        <h1 class="skill-title">🎯 <?= e($s['code'] . ' - ' . $s['name']) ?><?= $s['is_bonus'] ? ' (โจทย์พิเศษ)' : '' ?></h1>
        <p class="skill-sub">คะแนนเต็มของกลุ่มเกณฑ์นี้: <strong><?= fmt_score($s['max_score']) ?></strong> คะแนน
            <?php if (!empty($criteria)): ?> | จำนวนเกณฑ์ย่อย: <strong><?= count($criteria) ?></strong> ข้อ<?php endif; ?>
        </p>

        <?php if (empty($criteria)): ?>
            <p style="color:#888;">ยังไม่มีเกณฑ์ย่อยในกลุ่มนี้</p>
        <?php else: ?>
            <table class="crit">
                <thead>
                <tr>
                    <th class="col-code">รหัส</th>
                    <th class="col-item">รายการ</th>
                    <th class="col-method">วิธีตรวจ / เกณฑ์ได้คะแนน</th>
                    <th class="col-max">คะแนนเต็ม</th>
                </tr>
                </thead>
                <tbody>
                <?php $prevGroupCode = null; foreach ($criteria as $c):
                    $gCode = $c['group_code'] ?? null;
                    if ($gCode !== $prevGroupCode):
                        $prevGroupCode = $gCode;
                        if ($gCode !== null):
                            $g = $groupsByCode[$gCode] ?? null;
                ?>
                    <tr><td colspan="4" style="border:none;padding:0;">
                        <div class="group-title">📑 <?= e($gCode) ?> <?= e($c['group_name']) ?></div>
                        <?php if ($g && $g['description']): ?><div class="group-desc"><?= nl2br(e($g['description'])) ?></div><?php endif; ?>
                    </td></tr>
                <?php endif; endif; ?>
                    <tr>
                        <td class="col-code"><?= e($c['code']) ?></td>
                        <td class="col-item"><?= e($c['name']) ?></td>
                        <td class="col-method"><?= nl2br(e($c['description'])) ?></td>
                        <td class="col-max"><?= fmt_score($c['max_score']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>
</body>
</html>
    <?php
    exit;
}

// --- หน้าเลือกทักษะ/กลุ่มเกณฑ์ที่จะพิมพ์ ---
$pageTitle = 'เกณฑ์การให้คะแนน (สำหรับกรรมการอ่าน)';
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/division_tabs.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h3>📖 เอกสารเกณฑ์การให้คะแนน: <?= e($currentDivision['name']) ?></h3>
</div>

<div class="alert alert-info">
    ℹ️ สร้างเอกสาร PDF รวม<strong>หัวข้อใหญ่ (กลุ่มเกณฑ์) หัวข้อย่อย และวิธีตรวจ/เกณฑ์การให้คะแนน</strong>ของแต่ละข้อ
    ให้กรรมการอ่านทำความเข้าใจก่อนเริ่มให้คะแนนจริง (ไม่มีช่องกรอกคะแนน ใช้สำหรับอ่านเตรียมตัวเท่านั้น — ถ้าต้องการใบกรอกคะแนน ให้ใช้เมนู "🖨️ ใบให้คะแนน (สำรอง)")
    ติ๊กเลือกกลุ่มเกณฑ์ที่ต้องการ แล้วกด "สร้างและพิมพ์เอกสาร" ระบบจะเปิดหน้าต่างใหม่ให้พิมพ์หรือบันทึกเป็น PDF (A4, Font Saraban)
</div>

<?php if ($currentDivision['criteria_file_path']): ?>
<div class="alert alert-light border">
    📎 ไฟล์เกณฑ์การแข่งขันต้นฉบับ (เอกสารทางการที่ Admin อัปโหลดไว้):
    <a href="<?= base_url('download_criteria.php?division_id=' . $currentDivisionId) ?>"><?= e($currentDivision['criteria_file_name']) ?></a>
</div>
<?php endif; ?>

<?php if (empty($skills)): ?>
    <p class="text-muted"><?= $myAssignedSkillIds !== null ? 'ท่านยังไม่ได้รับมอบหมายกลุ่มเกณฑ์ในทักษะนี้' : 'ยังไม่มีกลุ่มเกณฑ์ในทักษะนี้' ?></p>
<?php else: ?>
<form method="post" target="_blank">
    <input type="hidden" name="division_id" value="<?= $currentDivisionId ?>">
    <div class="card">
        <div class="card-body">
            <div class="mb-2">
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="toggleAllSkills(true)">✅ เลือกทั้งหมด</button>
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="toggleAllSkills(false)">⬜ ไม่เลือกเลย</button>
            </div>
            <table class="table table-hover align-middle">
                <thead>
                <tr>
                    <th style="width:40px;"></th>
                    <th>รหัส</th>
                    <th>กลุ่มเกณฑ์</th>
                    <th>คะแนนเต็ม</th>
                    <th>จำนวนเกณฑ์ย่อย</th>
                    <th>ประเภท</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($skills as $s):
                    $cntStmt = $pdo->prepare('SELECT COUNT(*) c FROM criteria WHERE skill_id = ?');
                    $cntStmt->execute([$s['id']]);
                    $critCount = (int)$cntStmt->fetch()['c'];
                    $canEditThisSkill = can_manage_skill_criteria($user, $pdo, (int)$s['id']);
                ?>
                    <tr>
                        <td><input type="checkbox" class="form-check-input skill-checkbox" name="skill_ids[]" value="<?= (int)$s['id'] ?>" checked></td>
                        <td><?= e($s['code']) ?></td>
                        <td><?= e($s['name']) ?></td>
                        <td><?= fmt_score($s['max_score']) ?></td>
                        <td><?= $critCount ?> ข้อ</td>
                        <td><?= $s['is_bonus'] ? '<span class="badge bg-warning text-dark">🎁 โจทย์พิเศษ</span>' : '<span class="badge bg-primary">🏅 คะแนนหลัก</span>' ?></td>
                        <td>
                            <?php if ($canEditThisSkill && $user['role'] !== 'admin'): ?>
                                <a href="<?= base_url('admin/criteria.php?skill_id=' . (int)$s['id']) ?>" class="btn btn-sm btn-outline-primary">✏️ แก้ไขเกณฑ์</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <button type="submit" class="btn btn-primary mt-3">🖨️ สร้างและพิมพ์เอกสาร</button>
</form>
<script>
function toggleAllSkills(checked) {
    document.querySelectorAll('.skill-checkbox').forEach(function (cb) { cb.checked = checked; });
}
</script>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
