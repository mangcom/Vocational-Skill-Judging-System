<?php
require __DIR__ . '/../../app/bootstrap.php';
$user = require_role('judge');

$pdo = db();
$judgeId = (int)$user['id'];

$mySkillsStmt = $pdo->prepare('
    SELECT s.*, d.id AS division_id, d.name AS division_name, d.sort_order AS division_sort
    FROM skills s
    JOIN judge_skills js ON js.skill_id = s.id
    JOIN divisions d ON d.id = s.division_id
    WHERE js.judge_id = ?
    ORDER BY d.sort_order, s.sort_order
');
$mySkillsStmt->execute([$judgeId]);
$mySkills = $mySkillsStmt->fetchAll();

$skillsByDivision = [];
foreach ($mySkills as $s) {
    $skillsByDivision[(int)$s['division_id']]['name'] = $s['division_name'];
    $skillsByDivision[(int)$s['division_id']]['skills'][] = $s;
}

$pageTitle = 'ให้คะแนน';
require __DIR__ . '/../includes/header.php';
?>
<h3 class="mb-3">👋 สวัสดี คุณ<?= e($user['full_name']) ?></h3>

<?php if (empty($skillsByDivision)): ?>
    <div class="alert alert-warning">⚠️ ยังไม่มีการกำหนดกลุ่มเกณฑ์ที่ท่านรับผิดชอบ กรุณาติดต่อผู้ดูแลระบบ</div>
<?php else: ?>
    <?php foreach ($skillsByDivision as $divisionId => $divInfo):
        $divSkills = $divInfo['skills'];
        $skillIds = array_map('intval', array_column($divSkills, 'id'));

        $criteriaCount = 0;
        if (!empty($skillIds)) {
            $placeholders = implode(',', array_fill(0, count($skillIds), '?'));
            $stmt = $pdo->prepare("SELECT COUNT(*) c FROM criteria WHERE skill_id IN ({$placeholders})");
            $stmt->execute($skillIds);
            $criteriaCount = (int)$stmt->fetch()['c'];
        }

        $teamsStmt = $pdo->prepare('SELECT * FROM teams WHERE division_id = ? ORDER BY team_no');
        $teamsStmt->execute([$divisionId]);
        $teams = $teamsStmt->fetchAll();

        $allowedTeamIds = scoring_judge_allowed_teams($pdo, $judgeId, $divisionId);
        if ($allowedTeamIds !== null) {
            $teams = array_values(array_filter($teams, fn($t) => in_array((int)$t['id'], $allowedTeamIds, true)));
        }

        $scoredPerTeam = [];
        if ($criteriaCount > 0 && !empty($skillIds)) {
            $placeholders = implode(',', array_fill(0, count($skillIds), '?'));
            $stmt = $pdo->prepare("
                SELECT sc.team_id, COUNT(*) cnt
                FROM scores sc
                JOIN criteria c ON c.id = sc.criteria_id
                WHERE sc.judge_id = ? AND c.skill_id IN ({$placeholders})
                GROUP BY sc.team_id
            ");
            $stmt->execute(array_merge([$judgeId], $skillIds));
            foreach ($stmt->fetchAll() as $r) {
                $scoredPerTeam[(int)$r['team_id']] = (int)$r['cnt'];
            }
        }
    ?>
    <div class="skill-section mb-4">
        <h5>🏆 <?= e($divInfo['name']) ?></h5>
        <p class="text-muted">🎯 กลุ่มเกณฑ์ที่ท่านรับผิดชอบ:
            <?php foreach ($divSkills as $s): ?>
                <span class="badge bg-info text-dark me-1"><?= e($s['code'] . ' ' . $s['name']) ?></span>
            <?php endforeach; ?>
        </p>

        <div class="row g-3">
            <?php foreach ($teams as $t): $cnt = $scoredPerTeam[(int)$t['id']] ?? 0; $pct = $criteriaCount > 0 ? round($cnt / $criteriaCount * 100) : 0; ?>
                <div class="col-md-4">
                    <div class="card p-3 h-100">
                        <h5>👥 ทีมที่ <?= (int)$t['team_no'] ?></h5>
                        <div class="text-muted mb-2"><?= e($t['team_name']) ?></div>
                        <div class="progress mb-2" style="height: 18px;">
                            <div class="progress-bar <?= $pct >= 100 ? 'bg-success' : 'bg-primary' ?>" style="width: <?= $pct ?>%"><?= $pct >= 100 ? '✅ ' : '' ?><?= $pct ?>%</div>
                        </div>
                        <a href="<?= base_url('judge/score.php?team_id=' . (int)$t['id']) ?>" class="btn btn-primary mt-auto">✏️ ให้คะแนนทีมนี้</a>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php if (empty($teams)): ?>
                <p class="text-muted">ℹ️ ยังไม่มีทีมในทักษะนี้</p>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
