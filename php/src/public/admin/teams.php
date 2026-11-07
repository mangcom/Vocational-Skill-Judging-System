<?php
require __DIR__ . '/../../app/bootstrap.php';
require_role('admin');

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $divisionId = (int)($_POST['division_id'] ?? 0);
        $teamNo = (int)($_POST['team_no'] ?? 0);
        $teamName = trim($_POST['team_name'] ?? '');
        $problemSet = trim($_POST['problem_set'] ?? '');
        $notes = trim($_POST['notes'] ?? '');

        if ($divisionId <= 0 || $teamNo <= 0 || $teamName === '') {
            flash_set('error', 'กรุณากรอกหมายเลขทีมและชื่อทีม');
        } else {
            try {
                $pdo->prepare('INSERT INTO teams (division_id, team_no, team_name, problem_set, notes) VALUES (?,?,?,?,?)')
                    ->execute([$divisionId, $teamNo, $teamName, $problemSet ?: null, $notes ?: null]);
                flash_set('success', "เพิ่มทีม '{$teamName}' สำเร็จ");
            } catch (PDOException $e) {
                flash_set('error', $e->getCode() === '23000' ? 'หมายเลขทีมนี้มีอยู่แล้วในทักษะนี้' : 'เกิดข้อผิดพลาด');
            }
        }
        redirect(base_url('admin/teams.php?division_id=' . $divisionId));
    } elseif ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $divisionId = (int)($_POST['division_id'] ?? 0);
        $teamName = trim($_POST['team_name'] ?? '');
        $problemSet = trim($_POST['problem_set'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        if ($id > 0 && $teamName !== '') {
            $pdo->prepare('UPDATE teams SET team_name = ?, problem_set = ?, notes = ? WHERE id = ?')
                ->execute([$teamName, $problemSet ?: null, $notes ?: null, $id]);
            flash_set('success', 'แก้ไขข้อมูลทีมสำเร็จ');
        }
        redirect(base_url('admin/teams.php?division_id=' . $divisionId));
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $divisionId = (int)($_POST['division_id'] ?? 0);
        $pdo->prepare('DELETE FROM teams WHERE id = ?')->execute([$id]);
        flash_set('success', 'ลบทีมสำเร็จ');
        redirect(base_url('admin/teams.php?division_id=' . $divisionId));
    }

    redirect(base_url('admin/teams.php'));
}

$divisions = scoring_all_divisions($pdo);
if (empty($divisions)) {
    flash_set('error', 'ยังไม่มีทักษะในระบบ');
    redirect(base_url('admin/dashboard.php'));
}
$currentDivision = scoring_current_division($pdo, $divisions);
$currentDivisionId = (int)$currentDivision['id'];

$teamsStmt = $pdo->prepare('SELECT * FROM teams WHERE division_id = ? ORDER BY team_no');
$teamsStmt->execute([$currentDivisionId]);
$teams = $teamsStmt->fetchAll();

$pageTitle = 'จัดการทีมผู้เข้าแข่งขัน';
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/division_tabs.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h3>👥 จัดการทีมผู้เข้าแข่งขัน</h3>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createTeamModal">➕ เพิ่มทีม</button>
</div>

<div class="card">
    <div class="card-body table-responsive">
        <table class="table table-hover align-middle">
            <thead>
            <tr>
                <th style="width:80px;">ทีมที่</th>
                <th>ชื่อทีม / สถานศึกษา</th>
                <th>โจทย์ที่จับได้</th>
                <th>หมายเหตุ</th>
                <th class="text-end">จัดการ</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($teams as $t): ?>
                <tr>
                    <td><?= (int)$t['team_no'] ?></td>
                    <td><?= e($t['team_name']) ?></td>
                    <td><?= e($t['problem_set'] ?? '-') ?></td>
                    <td class="text-muted small"><?= e($t['notes'] ?? '') ?></td>
                    <td class="text-end">
                        <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#editTeam<?= (int)$t['id'] ?>">✏️ แก้ไข</button>
                        <form method="post" class="d-inline" data-confirm="ลบทีม '<?= e($t['team_name']) ?>' ใช่หรือไม่? คะแนนทั้งหมดของทีมนี้จะถูกลบ">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                            <input type="hidden" name="division_id" value="<?= $currentDivisionId ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger">🗑️ ลบ</button>
                        </form>
                    </td>
                </tr>

                <div class="modal fade" id="editTeam<?= (int)$t['id'] ?>" tabindex="-1">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <form method="post">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="update">
                                <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                                <input type="hidden" name="division_id" value="<?= $currentDivisionId ?>">
                                <div class="modal-header">
                                    <h5 class="modal-title">✏️ แก้ไขทีมที่ <?= (int)$t['team_no'] ?></h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    <div class="mb-3">
                                        <label class="form-label">ชื่อทีม / สถานศึกษา</label>
                                        <input type="text" name="team_name" class="form-control" value="<?= e($t['team_name']) ?>" required>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">โจทย์ที่จับได้</label>
                                        <input type="text" name="problem_set" class="form-control" value="<?= e($t['problem_set'] ?? '') ?>" placeholder="เช่น โจทย์ที่ 1 ColdGuard">
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">หมายเหตุ</label>
                                        <textarea name="notes" class="form-control" rows="2"><?= e($t['notes'] ?? '') ?></textarea>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">✖️ ยกเลิก</button>
                                    <button type="submit" class="btn btn-primary">💾 บันทึก</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php if (empty($teams)): ?>
                <tr><td colspan="5" class="text-center text-muted py-4">ยังไม่มีทีมในระบบ</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="createTeamModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create">
                <input type="hidden" name="division_id" value="<?= $currentDivisionId ?>">
                <div class="modal-header">
                    <h5 class="modal-title">➕ เพิ่มทีม (<?= e($currentDivision['name']) ?>)</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">ทีมที่</label>
                        <input type="number" name="team_no" class="form-control" required min="1">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">ชื่อทีม / สถานศึกษา</label>
                        <input type="text" name="team_name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">โจทย์ที่จับได้</label>
                        <input type="text" name="problem_set" class="form-control" placeholder="เช่น โจทย์ที่ 1 ColdGuard">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">หมายเหตุ</label>
                        <textarea name="notes" class="form-control" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">✖️ ยกเลิก</button>
                    <button type="submit" class="btn btn-primary">✅ เพิ่มทีม</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
