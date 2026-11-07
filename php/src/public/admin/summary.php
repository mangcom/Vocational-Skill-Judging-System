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

$skills = scoring_skills_with_criteria($pdo, $currentDivisionId);
$avgMatrix = scoring_average_matrix($pdo);
$totals = scoring_team_totals($teams, $skills, $avgMatrix);

usort($totals, function ($a, $b) {
    if ($a['total'] !== $b['total']) {
        return $b['total'] <=> $a['total'];
    }
    return $b['bonus'] <=> $a['bonus'];
});

$mainSkills = array_filter($skills, fn($s) => !$s['is_bonus']);
$bonusSkills = array_filter($skills, fn($s) => $s['is_bonus']);

$pageTitle = 'สรุปคะแนน (เฉพาะผู้ดูแลระบบ)';
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/division_tabs.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h3>📊 สรุปคะแนนรวม: <?= e($currentDivision['name']) ?></h3>
    <span class="badge bg-danger">🔒 ข้อมูลภายใน - ห้ามเผยแพร่สู่สาธารณะ</span>
</div>

<div class="card">
    <div class="card-body table-responsive">
        <table class="table table-hover align-middle">
            <thead>
            <tr>
                <th>อันดับ</th>
                <th>ทีม</th>
                <?php foreach ($mainSkills as $s): ?>
                    <th class="text-center"><?= e($s['code']) ?><br><small class="text-muted">เต็ม <?= fmt_score($s['max_score']) ?></small></th>
                <?php endforeach; ?>
                <th class="text-center bg-light">รวม (100)</th>
                <?php foreach ($bonusSkills as $s): ?>
                    <th class="text-center text-warning"><?= e($s['code']) ?><br><small>พิเศษ</small></th>
                <?php endforeach; ?>
                <th class="text-center">ความครบถ้วน</th>
                <th class="text-end">รายละเอียด</th>
            </tr>
            </thead>
            <tbody>
            <?php
            $rankIcons = [1 => '🥇', 2 => '🥈', 3 => '🥉'];
            $rank = 0; foreach ($totals as $row): $rank++; $team = $row['team'];
            ?>
                <tr>
                    <td><strong><?= $rankIcons[$rank] ?? '' ?> <?= $rank ?></strong></td>
                    <td>
                        ทีมที่ <?= (int)$team['team_no'] ?> - <?= e($team['team_name']) ?>
                        <?php if ($team['problem_set']): ?><br><small class="text-muted"><?= e($team['problem_set']) ?></small><?php endif; ?>
                    </td>
                    <?php foreach ($mainSkills as $s): ?>
                        <td class="text-center"><?= fmt_score($row['per_skill'][(int)$s['id']] ?? 0) ?></td>
                    <?php endforeach; ?>
                    <td class="text-center bg-light fw-bold"><?= fmt_score($row['total']) ?></td>
                    <?php foreach ($bonusSkills as $s): ?>
                        <td class="text-center text-warning"><?= fmt_score($row['per_skill'][(int)$s['id']] ?? 0) ?></td>
                    <?php endforeach; ?>
                    <td class="text-center">
                        <?php
                        $pct = $row['total_criteria'] > 0 ? round($row['scored_criteria'] / $row['total_criteria'] * 100) : 0;
                        $barClass = $pct >= 100 ? 'bg-success' : ($pct >= 50 ? 'bg-warning' : 'bg-danger');
                        ?>
                        <div class="progress" style="height: 18px;" title="<?= $row['scored_criteria'] ?>/<?= $row['total_criteria'] ?> ข้อ">
                            <div class="progress-bar <?= $barClass ?>" style="width: <?= $pct ?>%"><?= $pct ?>%</div>
                        </div>
                    </td>
                    <td class="text-end">
                        <a href="<?= base_url('admin/summary_detail.php?team_id=' . (int)$team['id']) ?>" class="btn btn-sm btn-outline-primary">🔍 ดูรายกรรมการ</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($totals)): ?>
                <tr><td colspan="20" class="text-center text-muted py-4">ยังไม่มีทีมในระบบ</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<p class="text-muted small mt-2">
    คะแนนรวม = ผลรวมคะแนนเฉลี่ยของกรรมการทุกคนที่บันทึกในแต่ละข้อ ของทุกกลุ่มเกณฑ์หลัก (ไม่รวมโจทย์พิเศษ) &middot;
    โจทย์พิเศษแยกไว้ใช้จัดอันดับเมื่อคะแนนรวมเท่ากัน
</p>

<?php require __DIR__ . '/../includes/footer.php'; ?>
