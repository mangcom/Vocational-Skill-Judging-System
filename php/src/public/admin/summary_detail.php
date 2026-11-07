<?php
require __DIR__ . '/../../app/bootstrap.php';
require_admin_or_head_judge();

$pdo = db();

$teamId = (int)($_GET['team_id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM teams WHERE id = ?');
$stmt->execute([$teamId]);
$team = $stmt->fetch();
if (!$team) {
    flash_set('error', 'ไม่พบทีมที่เลือก');
    redirect(base_url('admin/summary.php'));
}

$skills = scoring_skills_with_criteria($pdo, (int)$team['division_id']);
$rawMatrix = scoring_raw_matrix($pdo);
$avgMatrix = scoring_average_matrix($pdo);

$judges = $pdo->query('SELECT * FROM users WHERE role = "judge" ORDER BY full_name')->fetchAll();
$judgeSkillMap = [];
foreach ($pdo->query('SELECT judge_id, skill_id FROM judge_skills')->fetchAll() as $r) {
    $judgeSkillMap[(int)$r['skill_id']][] = (int)$r['judge_id'];
}

$pageTitle = 'รายละเอียดคะแนน: ' . $team['team_name'];
require __DIR__ . '/../includes/header.php';
?>
<a href="<?= base_url('admin/summary.php?division_id=' . (int)$team['division_id']) ?>" class="btn btn-sm btn-link mb-2">⬅️ กลับไปหน้าสรุปคะแนน</a>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h3 class="mb-0">👥 ทีมที่ <?= (int)$team['team_no'] ?> - <?= e($team['team_name']) ?></h3>
    <span class="badge bg-danger">🔒 ข้อมูลภายใน - ห้ามเผยแพร่สู่สาธารณะ</span>
</div>

<?php foreach ($skills as $skill):
    $skillId = (int)$skill['id'];
    $assignedJudges = array_filter($judges, fn($j) => in_array((int)$j['id'], $judgeSkillMap[$skillId] ?? [], true));
    $skillTotal = 0.0;
?>
<div class="card skill-section mb-3">
    <div class="card-header d-flex justify-content-between">
        <strong>🎯 <?= e($skill['code'] . ' - ' . $skill['name']) ?></strong>
        <span class="text-muted">
            ⚖️ กรรมการที่รับผิดชอบ:
            <?= $assignedJudges ? e(implode(', ', array_column($assignedJudges, 'full_name'))) : 'ยังไม่กำหนด' ?>
        </span>
    </div>
    <div class="card-body table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead>
            <tr>
                <th style="width:70px;">ข้อ</th>
                <th>รายการ</th>
                <?php foreach ($assignedJudges as $j): ?>
                    <th class="text-center"><?= e($j['full_name']) ?></th>
                <?php endforeach; ?>
                <th class="text-center bg-light">เฉลี่ย</th>
                <th class="text-center">เต็ม</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($skill['criteria'] as $c):
                $cid = (int)$c['id'];
                $avg = $avgMatrix[$teamId][$cid] ?? null;
                if ($avg !== null) {
                    $skillTotal += $avg;
                }
            ?>
                <tr>
                    <td><?= e($c['code']) ?></td>
                    <td><?= e($c['name']) ?></td>
                    <?php foreach ($assignedJudges as $j):
                        $val = $rawMatrix[$teamId][$cid][(int)$j['id']] ?? null;
                    ?>
                        <td class="text-center <?= $val === null ? 'score-pending' : '' ?>"><?= $val === null ? '-' : fmt_score($val) ?></td>
                    <?php endforeach; ?>
                    <td class="text-center bg-light fw-bold"><?= $avg === null ? '-' : fmt_score($avg) ?></td>
                    <td class="text-center text-muted"><?= fmt_score($c['max_score']) ?></td>
                </tr>
            <?php endforeach; ?>
            <tr>
                <td colspan="<?= 2 + count($assignedJudges) ?>" class="text-end fw-bold">รวมกลุ่มเกณฑ์นี้</td>
                <td class="text-center bg-light fw-bold"><?= fmt_score($skillTotal) ?></td>
                <td class="text-center text-muted"><?= fmt_score($skill['max_score']) ?></td>
            </tr>
            </tbody>
        </table>
    </div>
</div>
<?php endforeach; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
