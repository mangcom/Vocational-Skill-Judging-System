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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $teamsStmt = $pdo->prepare('SELECT id FROM teams WHERE division_id = ?');
    $teamsStmt->execute([$currentDivisionId]);
    $validTeamIds = array_map('intval', array_column($teamsStmt->fetchAll(), 'id'));

    $judgesStmt = $pdo->prepare('
        SELECT DISTINCT u.id FROM users u
        JOIN judge_skills js ON js.judge_id = u.id
        JOIN skills s ON s.id = js.skill_id
        WHERE u.role = "judge" AND s.division_id = ?
    ');
    $judgesStmt->execute([$currentDivisionId]);
    $validJudgeIds = array_map('intval', array_column($judgesStmt->fetchAll(), 'id'));

    $assign = $_POST['assign'] ?? [];

    $delStmt = $pdo->prepare('
        DELETE jta FROM judge_team_assignments jta
        JOIN teams t ON t.id = jta.team_id
        WHERE jta.judge_id = ? AND t.division_id = ?
    ');
    $insStmt = $pdo->prepare('INSERT INTO judge_team_assignments (judge_id, team_id, assigned_by) VALUES (?, ?, ?)');

    $pdo->beginTransaction();
    foreach ($validJudgeIds as $judgeId) {
        $delStmt->execute([$judgeId, $currentDivisionId]);
        $teamIds = array_map('intval', $assign[$judgeId] ?? []);
        foreach ($teamIds as $tid) {
            if (in_array($tid, $validTeamIds, true)) {
                $insStmt->execute([$judgeId, $tid, (int)$user['id']]);
            }
        }
    }
    $pdo->commit();

    flash_set('success', 'บันทึกการมอบหมายทีมสำเร็จ');
    redirect(base_url('judge/assign.php?division_id=' . $currentDivisionId));
}

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

$assignedMap = [];
if (!empty($judges)) {
    $placeholders = implode(',', array_fill(0, count($judges), '?'));
    $ids = array_column($judges, 'id');
    $rows = $pdo->prepare("SELECT judge_id, team_id FROM judge_team_assignments WHERE judge_id IN ({$placeholders})");
    $rows->execute($ids);
    foreach ($rows->fetchAll() as $r) {
        $assignedMap[(int)$r['judge_id']][] = (int)$r['team_id'];
    }
}

$pageTitle = 'มอบหมายทีมให้กรรมการ';
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/division_tabs.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h3>🗂️ มอบหมายทีมให้กรรมการ: <?= e($currentDivision['name']) ?></h3>
</div>

<div class="alert alert-info">
    ℹ️ ถ้าไม่เลือกทีมให้กรรมการคนใด (ไม่ติ๊กเลย) กรรมการคนนั้นจะให้คะแนนได้ทุกทีมในกลุ่มเกณฑ์ที่ตนรับผิดชอบตามปกติ
    ถ้าติ๊กเลือกทีมไว้ กรรมการคนนั้นจะให้คะแนนได้เฉพาะทีมที่เลือกเท่านั้น
</div>

<?php if (empty($judges)): ?>
    <p class="text-muted">ยังไม่มีกรรมการที่รับผิดชอบกลุ่มเกณฑ์ในทักษะนี้</p>
<?php elseif (empty($teams)): ?>
    <p class="text-muted">ยังไม่มีทีมในทักษะนี้</p>
<?php else: ?>
<form method="post">
    <?= csrf_field() ?>
    <div class="card">
        <div class="card-body table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                <tr>
                    <th>กรรมการ</th>
                    <?php foreach ($teams as $t): ?>
                        <th class="text-center">ทีมที่ <?= (int)$t['team_no'] ?><br><small class="text-muted"><?= e($t['team_name']) ?></small></th>
                    <?php endforeach; ?>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($judges as $j): $jAssigned = $assignedMap[(int)$j['id']] ?? []; ?>
                    <tr>
                        <td><?= e(position_label($j['position'] ?? 'กรรมการ')) ?> <?= e($j['full_name']) ?></td>
                        <?php foreach ($teams as $t): ?>
                            <td class="text-center">
                                <input type="checkbox" class="form-check-input" name="assign[<?= (int)$j['id'] ?>][]" value="<?= (int)$t['id'] ?>"
                                    <?= in_array((int)$t['id'], $jAssigned, true) ? 'checked' : '' ?>>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <button type="submit" class="btn btn-primary mt-3">💾 บันทึกการมอบหมาย</button>
</form>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
