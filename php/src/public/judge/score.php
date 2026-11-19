<?php
require __DIR__ . '/../../app/bootstrap.php';
$user = require_role('judge');

$pdo = db();
$judgeId = (int)$user['id'];

$teamId = (int)($_GET['team_id'] ?? $_POST['team_id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM teams WHERE id = ?');
$stmt->execute([$teamId]);
$team = $stmt->fetch();
if (!$team) {
    flash_set('error', 'ไม่พบทีมที่เลือก');
    redirect(base_url('judge/dashboard.php'));
}

$mySkillsStmt = $pdo->prepare('
    SELECT s.* FROM skills s
    JOIN judge_skills js ON js.skill_id = s.id
    WHERE js.judge_id = ? AND s.division_id = ?
    ORDER BY s.sort_order
');
$mySkillsStmt->execute([$judgeId, (int)$team['division_id']]);
$mySkills = $mySkillsStmt->fetchAll();

if (empty($mySkills)) {
    flash_set('error', 'ท่านยังไม่ได้รับมอบหมายกลุ่มเกณฑ์ในทักษะของทีมนี้ กรุณาติดต่อผู้ดูแลระบบ');
    redirect(base_url('judge/dashboard.php'));
}

$allowedTeamIds = scoring_judge_allowed_teams($pdo, $judgeId, (int)$team['division_id']);
if ($allowedTeamIds !== null && !in_array($teamId, $allowedTeamIds, true)) {
    flash_set('error', 'ท่านไม่ได้รับมอบหมายให้ให้คะแนนทีมนี้ กรุณาติดต่อประธานกรรมการ');
    redirect(base_url('judge/dashboard.php'));
}

$skillIds = array_map('intval', array_column($mySkills, 'id'));
$placeholders = implode(',', array_fill(0, count($skillIds), '?'));
$critStmt = $pdo->prepare("
    SELECT c.*, cg.code AS group_code, cg.name AS group_name
    FROM criteria c
    LEFT JOIN criteria_groups cg ON cg.id = c.group_id
    WHERE c.skill_id IN ({$placeholders}) ORDER BY c.skill_id, c.sort_order, c.id
");
$critStmt->execute($skillIds);
$criteria = $critStmt->fetchAll();

$criteriaBySkill = [];
foreach ($criteria as $c) {
    $criteriaBySkill[(int)$c['skill_id']][] = $c;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $validCriteriaIds = array_column($criteria, 'id');
    $maxById = array_column($criteria, 'max_score', 'id');

    $upsert = $pdo->prepare('
        INSERT INTO scores (judge_id, team_id, criteria_id, score, note)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE score = VALUES(score), note = VALUES(note)
    ');

    $errors = [];
    $pdo->beginTransaction();
    foreach ($_POST['score'] ?? [] as $criteriaId => $rawScore) {
        $criteriaId = (int)$criteriaId;
        if (!in_array($criteriaId, $validCriteriaIds, true)) {
            continue;
        }
        if ($rawScore === '' || $rawScore === null) {
            continue;
        }
        $score = (float)$rawScore;
        $max = (float)$maxById[$criteriaId];
        if ($score < 0 || $score > $max) {
            $errors[] = "ข้อ criteria #{$criteriaId} ค่าต้องอยู่ระหว่าง 0 ถึง {$max}";
            continue;
        }
        $note = trim((string)($_POST['note'][$criteriaId] ?? ''));
        $upsert->execute([$judgeId, $teamId, $criteriaId, $score, $note !== '' ? $note : null]);
    }

    if ($errors) {
        $pdo->rollBack();
        flash_set('error', 'พบข้อมูลไม่ถูกต้อง: ' . implode(', ', $errors));
    } else {
        $pdo->commit();
        flash_set('success', 'บันทึกคะแนนทีมที่ ' . (int)$team['team_no'] . ' สำเร็จ');
    }

    redirect(base_url('judge/score.php?team_id=' . $teamId));
}

$existingStmt = $pdo->prepare('SELECT criteria_id, score, note FROM scores WHERE judge_id = ? AND team_id = ?');
$existingStmt->execute([$judgeId, $teamId]);
$existing = [];
foreach ($existingStmt->fetchAll() as $row) {
    $existing[(int)$row['criteria_id']] = $row;
}

$grandMax = array_sum(array_column($criteria, 'max_score'));

$pageTitle = 'ให้คะแนนทีมที่ ' . (int)$team['team_no'];
require __DIR__ . '/../includes/header.php';
?>
<div id="liveScoreTotal" class="card shadow-sm">
    <div class="card-body py-2 px-3 text-center">
        <div class="small text-muted mb-0">🧮 คะแนนรวมขณะนี้</div>
        <div class="fs-4 fw-bold"><span id="liveScoreTotalValue">0</span> / <span id="liveScoreTotalMax"><?= fmt_score($grandMax) ?></span></div>
    </div>
</div>

<a href="<?= base_url('judge/dashboard.php') ?>" class="btn btn-sm btn-link mb-2">⬅️ กลับไปเลือกทีม</a>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h3 class="mb-0">👥 ทีมที่ <?= (int)$team['team_no'] ?> - <?= e($team['team_name']) ?></h3>
    <?php if ($team['problem_set']): ?><span class="badge bg-secondary"><?= e($team['problem_set']) ?></span><?php endif; ?>
</div>

<form method="post" id="scoreForm">
    <?= csrf_field() ?>
    <input type="hidden" name="team_id" value="<?= $teamId ?>">

    <?php foreach ($mySkills as $skill): $skillId = (int)$skill['id']; ?>
        <div class="card skill-section mb-3">
            <div class="card-header"><strong>🎯 <?= e($skill['code'] . ' - ' . $skill['name']) ?></strong> <span class="text-muted">(คะแนนเต็ม <?= fmt_score($skill['max_score']) ?>)</span></div>
            <div class="card-body table-responsive">
                <table class="table align-middle">
                    <thead>
                    <tr>
                        <th style="width:80px;">ข้อ</th>
                        <th>รายการ / วิธีตรวจ</th>
                        <th style="width:110px;">คะแนนเต็ม</th>
                        <th style="width:120px;">✏️ ให้คะแนน</th>
                        <th>📝 บันทึกเพิ่มเติม (ถ้ามี)</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php $prevGroupCode = null; foreach ($criteriaBySkill[$skillId] ?? [] as $c):
                        $cid = (int)$c['id'];
                        $prev = $existing[$cid] ?? null;
                        if (($c['group_code'] ?? null) !== $prevGroupCode && !empty($c['group_code'])):
                            $prevGroupCode = $c['group_code'];
                    ?>
                        <tr class="table-light">
                            <td colspan="5">
                                <strong><?= e($c['group_code']) ?> <?= e($c['group_name']) ?></strong>
                            </td>
                        </tr>
                    <?php elseif (empty($c['group_code'])): $prevGroupCode = null; endif; ?>
                        <tr>
                            <td><?= e($c['code']) ?></td>
                            <td>
                                <div class="fw-semibold"><?= e($c['name']) ?></div>
                                <div class="text-muted small"><?= e($c['description']) ?></div>
                            </td>
                            <td><?= fmt_score($c['max_score']) ?></td>
                            <td>
                                <input type="number" class="form-control table-score-input score-input" name="score[<?= $cid ?>]"
                                       min="0" max="<?= e((string)$c['max_score']) ?>" step="0.01"
                                       value="<?= $prev ? e((string)$prev['score']) : '' ?>">
                            </td>
                            <td>
                                <input type="text" class="form-control" name="note[<?= $cid ?>]" maxlength="500"
                                       value="<?= $prev ? e((string)$prev['note']) : '' ?>">
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endforeach; ?>

    <button type="submit" class="btn btn-primary btn-lg">💾 บันทึกคะแนน</button>
</form>

<script>
(function () {
    function fmt(n) {
        n = Math.round(n * 100) / 100;
        return (n % 1 === 0) ? String(n) : String(n).replace(/0+$/, '').replace(/\.$/, '');
    }

    function recalc() {
        var total = 0;
        document.querySelectorAll('.score-input').forEach(function (el) {
            var max = parseFloat(el.getAttribute('max'));
            var val = parseFloat(el.value);
            if (isNaN(val)) {
                return;
            }
            if (val < 0) {
                val = 0;
                el.value = '0';
            }
            if (!isNaN(max) && val > max) {
                val = max;
                el.value = fmt(max);
            }
            total += val;
        });
        var totalEl = document.getElementById('liveScoreTotalValue');
        if (totalEl) {
            totalEl.textContent = fmt(total);
        }
    }

    document.addEventListener('input', function (e) {
        if (e.target.classList && e.target.classList.contains('score-input')) {
            recalc();
        }
    });

    document.addEventListener('DOMContentLoaded', recalc);
    recalc();
})();
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
