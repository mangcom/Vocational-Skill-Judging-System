<?php
require __DIR__ . '/../../app/bootstrap.php';
$user = require_admin_or_head_judge();

$pdo = db();

$divisions = scoring_all_divisions($pdo);
if (empty($divisions)) {
    flash_set('error', 'ยังไม่มีทักษะในระบบ');
    redirect(base_url($user['role'] === 'admin' ? 'admin/dashboard.php' : 'judge/dashboard.php'));
}
$currentDivision = scoring_current_division($pdo, $divisions);
$currentDivisionId = (int)$currentDivision['id'];

$teamsStmt = $pdo->prepare('SELECT * FROM teams WHERE division_id = ? ORDER BY team_no');
$teamsStmt->execute([$currentDivisionId]);
$teams = $teamsStmt->fetchAll();

$judgesStmt = $pdo->prepare('
    SELECT DISTINCT u.id, u.full_name, u.position FROM users u
    JOIN judge_skills js ON js.judge_id = u.id
    JOIN skills s ON s.id = js.skill_id
    WHERE u.role = "judge" AND s.division_id = ?
    ORDER BY u.full_name
');
$judgesStmt->execute([$currentDivisionId]);
$judges = $judgesStmt->fetchAll();

$teamsById = [];
foreach ($teams as $t) {
    $teamsById[(int)$t['id']] = $t;
}
$judgesById = [];
foreach ($judges as $j) {
    $judgesById[(int)$j['id']] = $j;
}

$includeBonus = isset($_REQUEST['include_bonus']);
$pageBreakPerSkill = isset($_REQUEST['page_break_skill']);

// --- รวบรวมคู่ (ทีม, กรรมการ) ที่ขอสร้างใบประเมิน: รองรับทั้งเลือกหลายคู่ (POST จาก matrix) และลิงก์เดี่ยวแบบเดิม (GET) ---
$requestedPairs = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pairs'])) {
    foreach ((array)$_POST['pairs'] as $pair) {
        $parts = explode('_', (string)$pair);
        if (count($parts) === 2) {
            $requestedPairs[] = [(int)$parts[0], (int)$parts[1]];
        }
    }
} elseif ((int)($_GET['team_id'] ?? 0) > 0 && (int)($_GET['judge_id'] ?? 0) > 0) {
    $requestedPairs[] = [(int)$_GET['team_id'], (int)$_GET['judge_id']];
}

$sheets = [];
foreach ($requestedPairs as [$pTeamId, $pJudgeId]) {
    if (!isset($teamsById[$pTeamId]) || !isset($judgesById[$pJudgeId])) {
        continue;
    }
    $team = $teamsById[$pTeamId];
    $judge = $judgesById[$pJudgeId];

    $skillsStmt = $pdo->prepare('
        SELECT s.* FROM skills s
        JOIN judge_skills js ON js.skill_id = s.id
        WHERE js.judge_id = ? AND s.division_id = ?
        ORDER BY s.sort_order
    ');
    $skillsStmt->execute([$pJudgeId, $currentDivisionId]);
    $mySkills = $skillsStmt->fetchAll();

    $skillIds = array_map('intval', array_column($mySkills, 'id'));
    $criteriaBySkill = [];
    if (!empty($skillIds)) {
        $placeholders = implode(',', array_fill(0, count($skillIds), '?'));
        $critStmt = $pdo->prepare("SELECT * FROM criteria WHERE skill_id IN ({$placeholders}) ORDER BY skill_id, sort_order, id");
        $critStmt->execute($skillIds);
        foreach ($critStmt->fetchAll() as $c) {
            $criteriaBySkill[(int)$c['skill_id']][] = $c;
        }
    }

    $skillsWithCriteria = [];
    foreach ($mySkills as $s) {
        $s['criteria'] = $criteriaBySkill[(int)$s['id']] ?? [];
        $skillsWithCriteria[] = $s;
    }

    $printSkills = array_values(array_filter($skillsWithCriteria, fn($s) => $includeBonus || !$s['is_bonus']));
    $grandMax = 0;
    foreach ($printSkills as $s) {
        $grandMax += (float)$s['max_score'];
    }

    $sheets[] = [
        'team' => $team,
        'judge' => $judge,
        'skills' => $printSkills,
        'grand_max' => $grandMax,
    ];
}

if (!empty($sheets)) {
    // --- หน้าใบประเมินคะแนนสำหรับพิมพ์/Export PDF (A4, Font Saraban) ---
    ?>
<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <title>ใบประเมินคะแนน - <?= e($currentDivision['name']) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        @page { size: A4; margin: 14mm 12mm; }
        * { box-sizing: border-box; }
        body { font-family: 'Sarabun', sans-serif; font-size: 12px; color: #000; margin: 0; padding: 0; }
        .toolbar { position: sticky; top: 0; background: #f0f0f0; padding: 10px; text-align: center; border-bottom: 2px solid #ccc; }
        .toolbar button, .toolbar a { font-family: 'Sarabun', sans-serif; font-size: 14px; padding: 8px 16px; margin: 0 4px; cursor: pointer; }
        .sheet { max-width: 210mm; margin: 0 auto; padding: 8mm; }
        h1 { font-size: 18px; text-align: center; margin: 0; }
        .meta { font-size: 13px; margin-bottom: 10px; line-height: 1.6; }
        .meta b { display: inline-block; min-width: 90px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
        th, td { border: 1px solid #000; padding: 3px 5px; vertical-align: top; }
        th { background: #eee; text-align: center; font-weight: 600; }
        .skill-head { background: #ddd; font-weight: 700; font-size: 13px; padding: 5px; border: 1px solid #000; }
        .col-code { width: 8%; text-align: center; }
        .col-item { width: 32%; }
        .col-method { width: 32%; }
        .col-max { width: 8%; text-align: center; }
        .col-score { width: 10%; text-align: center; }
        .col-note { width: 10%; }
        .skill-total td { font-weight: 700; background: #f5f5f5; }
        .grand-total-cell { border: none; padding-top: 14px; font-size: 14px; font-weight: 700; text-align: right; }
        .signature-cell { border: none; }
        .signature { display: flex; justify-content: flex-end; }
        .signature .box { text-align: center; width: 260px; }
        .signature .line { margin-top: 40px; border-top: 1px dotted #000; padding-top: 4px; }
        .page-header-row { position: relative; text-align: center; }
        .page-no { position: absolute; right: 0; top: 2px; font-size: 12px; color: #333; white-space: nowrap; font-weight: normal; }
        .score-table thead th { background: none; }
        .page-head-cell { border: none !important; padding: 0 0 6px 0 !important; text-align: left !important; }
        tr { page-break-inside: avoid; }
        thead { display: table-header-group; }
        .skill-block.break-before { page-break-before: always; }
        .sheet-block.break-before { page-break-before: always; }
        @media print {
            .toolbar { display: none; }
            .sheet { max-width: none; padding: 0; }
        }
    </style>
</head>
<body>
<div class="toolbar no-print">
    <button onclick="window.print()">🖨️ พิมพ์ / บันทึกเป็น PDF (<?= count($sheets) ?> ชุด)</button>
    <a href="<?= base_url('admin/score_sheet.php?division_id=' . $currentDivisionId) ?>">⬅️ กลับไปเลือกใหม่</a>
</div>
<div class="sheet">
    <?php foreach ($sheets as $sheetIndex => $sheetData):
        $team = $sheetData['team'];
        $judge = $sheetData['judge'];
        $printSkills = $sheetData['skills'];
        $grandMax = $sheetData['grand_max'];
        $totalPages = count($printSkills);
    ?>
        <div class="sheet-block<?= $sheetIndex > 0 ? ' break-before' : '' ?>">
            <?php if ($totalPages === 0): ?>
                <h1>📋 ใบประเมินคะแนน</h1>
                <p style="text-align:center;">ไม่มีกลุ่มเกณฑ์ให้พิมพ์สำหรับ ทีมที่ <?= (int)$team['team_no'] ?> / <?= e($judge['full_name']) ?></p>
            <?php endif; ?>
            <?php foreach ($printSkills as $pageIndex => $skill): $skillTotal = 0.0; $pageNo = $pageIndex + 1; ?>
                <div class="skill-block<?= ($pageBreakPerSkill && $pageIndex > 0) ? ' break-before' : '' ?>">
                    <table class="score-table">
                        <thead>
                        <tr>
                            <th colspan="6" class="page-head-cell">
                                <div class="page-header-row">
                                    <h1>📋 ใบประเมินคะแนน</h1>
                                    <div class="page-no">หน้าที่ <?= $pageNo ?> / <?= $totalPages ?></div>
                                </div>
                                <div class="meta">
                                    <div><b>ทักษะ:</b> <?= e($currentDivision['name']) ?></div>
                                    <div><b>ทีมที่:</b> <?= (int)$team['team_no'] ?> - <?= e($team['team_name']) ?><?= $team['problem_set'] ? ' (' . e($team['problem_set']) . ')' : '' ?></div>
                                    <div><b>กรรมการ:</b> <?= e($judge['full_name']) ?></div>
                                </div>
                                <div class="skill-head">🎯 <?= e($skill['code'] . ' - ' . $skill['name']) ?> (คะแนนเต็ม <?= fmt_score($skill['max_score']) ?>)</div>
                            </th>
                        </tr>
                        <tr>
                            <th class="col-code">รหัส</th>
                            <th class="col-item">รายการ</th>
                            <th class="col-method">วิธีการให้คะแนน</th>
                            <th class="col-max">คะแนนเต็ม</th>
                            <th class="col-score">คะแนนที่ให้</th>
                            <th class="col-note">หมายเหตุ</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($skill['criteria'] as $c): $skillTotal += (float)$c['max_score']; ?>
                            <tr>
                                <td class="col-code"><?= e($c['code']) ?></td>
                                <td class="col-item"><?= e($c['name']) ?></td>
                                <td class="col-method"><?= e($c['description']) ?></td>
                                <td class="col-max"><?= fmt_score($c['max_score']) ?></td>
                                <td class="col-score">&nbsp;</td>
                                <td class="col-note">&nbsp;</td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($skill['criteria'])): ?>
                            <tr><td colspan="6" style="text-align:center;color:#666;">ไม่มีเกณฑ์ย่อยในกลุ่มนี้</td></tr>
                        <?php endif; ?>
                        <tr class="skill-total">
                            <td colspan="3" style="text-align:right;">รวมกลุ่มเกณฑ์นี้</td>
                            <td class="col-max"><?= fmt_score($skillTotal) ?></td>
                            <td class="col-score">&nbsp;</td>
                            <td class="col-note"></td>
                        </tr>
                        <?php if ($pageNo === $totalPages): ?>
                            <tr>
                                <td colspan="6" class="grand-total-cell">รวมคะแนนทั้งหมด (เต็ม <?= fmt_score($grandMax) ?>) = ____________________</td>
                            </tr>
                            <tr>
                                <td colspan="6" class="signature-cell">
                                    <div class="signature">
                                        <div class="box">
                                            <div class="line">ลงชื่อกรรมการผู้ประเมิน<br>( <?= e($judge['full_name']) ?> )<br>วันที่ ........./........./.........</div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
</div>
</body>
</html>
    <?php
    exit;
}

// --- หน้าเลือกทีม/กรรมการ เพื่อสร้างใบประเมินคะแนน ---
$pageTitle = 'พิมพ์ใบประเมินคะแนน';
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/division_tabs.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h3>🖨️ พิมพ์ใบประเมินคะแนน: <?= e($currentDivision['name']) ?></h3>
</div>

<div class="alert alert-info">
    ℹ️ ใบประเมินคะแนนนี้ใช้เป็นแบบฟอร์มกระดาษสำรอง กรณีระบบคอมพิวเตอร์ใช้งานไม่ได้ และใช้เป็นหลักฐานการให้คะแนนของกรรมการแต่ละคน
    ติ๊กเลือกคู่ "ทีม × กรรมการ" ที่ต้องการ (เลือกได้หลายคู่) แล้วกด "สร้างและพิมพ์ใบประเมิน" ระบบจะรวมทุกคู่ไว้ในเอกสารเดียว เปิดหน้าต่างใหม่ให้พิมพ์หรือบันทึกเป็น PDF ครั้งเดียว (A4, Font Saraban)
</div>

<?php if (empty($teams) || empty($judges)): ?>
    <p class="text-muted"><?= empty($teams) ? 'ยังไม่มีทีมในทักษะนี้' : 'ยังไม่มีกรรมการที่รับผิดชอบกลุ่มเกณฑ์ในทักษะนี้' ?></p>
<?php else: ?>
<form method="post" target="_blank">
    <input type="hidden" name="division_id" value="<?= $currentDivisionId ?>">

    <div class="card mb-3">
        <div class="card-body">
            <div class="d-flex flex-wrap gap-4">
                <div class="form-check">
                    <input type="checkbox" class="form-check-input" name="include_bonus" value="1" id="includeBonus">
                    <label class="form-check-label" for="includeBonus">➕ พิมพ์โจทย์พิเศษเพิ่มด้วย (นอกเหนือจากเกณฑ์หลักตามปกติ)</label>
                </div>
                <div class="form-check">
                    <input type="checkbox" class="form-check-input" name="page_break_skill" value="1" id="pageBreakSkill">
                    <label class="form-check-label" for="pageBreakSkill">📄 แบ่งหน้าใหม่ทุกกลุ่มเกณฑ์ (ไม่ติ๊ก = พิมพ์ต่อเนื่องในหน้าเดียวกันเพื่อประหยัดกระดาษ)</label>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-body table-responsive">
            <div class="mb-2">
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="toggleAllPairs(true)">✅ เลือกทั้งหมด</button>
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="toggleAllPairs(false)">⬜ ไม่เลือกเลย</button>
            </div>
            <table class="table table-hover align-middle">
                <thead>
                <tr>
                    <th>กรรมการ \ ทีม</th>
                    <?php foreach ($teams as $t): ?>
                        <th class="text-center">ทีมที่ <?= (int)$t['team_no'] ?><br><small class="text-muted"><?= e($t['team_name']) ?></small></th>
                    <?php endforeach; ?>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($judges as $j): ?>
                    <tr>
                        <td><?= e(position_label($j['position'] ?? 'กรรมการ')) ?> <?= e($j['full_name']) ?></td>
                        <?php foreach ($teams as $t): ?>
                            <td class="text-center">
                                <input type="checkbox" class="form-check-input pair-checkbox" name="pairs[]" value="<?= (int)$t['id'] ?>_<?= (int)$j['id'] ?>">
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <button type="submit" class="btn btn-primary mt-3">🖨️ สร้างและพิมพ์ใบประเมิน</button>
</form>
<script>
function toggleAllPairs(checked) {
    document.querySelectorAll('.pair-checkbox').forEach(function (cb) { cb.checked = checked; });
}
</script>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
