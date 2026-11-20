<?php
require __DIR__ . '/../../app/bootstrap.php';
require_role('admin');

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $divisionId = (int)($_POST['division_id'] ?? 0);
        $code = trim($_POST['code'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $maxScore = (float)($_POST['max_score'] ?? 0);
        $isBonus = isset($_POST['is_bonus']) ? 1 : 0;

        if ($divisionId <= 0 || $code === '' || $name === '') {
            flash_set('error', 'กรุณากรอกรหัสและชื่อกลุ่มเกณฑ์การให้คะแนน');
        } else {
            $stmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order),0) m FROM skills WHERE division_id = ?');
            $stmt->execute([$divisionId]);
            $maxOrder = (int)$stmt->fetch()['m'];
            try {
                $pdo->prepare('INSERT INTO skills (division_id, code, name, max_score, sort_order, is_bonus) VALUES (?, ?, ?, ?, ?, ?)')
                    ->execute([$divisionId, $code, $name, $maxScore, $maxOrder + 1, $isBonus]);
                flash_set('success', "เพิ่มกลุ่มเกณฑ์ '{$name}' สำเร็จ");
            } catch (PDOException $e) {
                flash_set('error', $e->getCode() === '23000' ? 'รหัสกลุ่มเกณฑ์นี้มีอยู่แล้วในทักษะนี้' : 'เกิดข้อผิดพลาด');
            }
        }
        redirect(base_url('admin/skills.php?division_id=' . $divisionId));
    } elseif ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $maxScore = (float)($_POST['max_score'] ?? 0);
        $isBonus = isset($_POST['is_bonus']) ? 1 : 0;
        $divisionId = (int)($_POST['division_id'] ?? 0);
        if ($id > 0 && $name !== '') {
            $pdo->prepare('UPDATE skills SET name = ?, max_score = ?, is_bonus = ? WHERE id = ?')
                ->execute([$name, $maxScore, $isBonus, $id]);
            flash_set('success', 'แก้ไขกลุ่มเกณฑ์สำเร็จ');
        }
        redirect(base_url('admin/skills.php?division_id=' . $divisionId));
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $divisionId = (int)($_POST['division_id'] ?? 0);
        $pdo->prepare('DELETE FROM skills WHERE id = ?')->execute([$id]);
        flash_set('success', 'ลบกลุ่มเกณฑ์สำเร็จ (เกณฑ์ย่อยและคะแนนที่เกี่ยวข้องถูกลบด้วย)');
        redirect(base_url('admin/skills.php?division_id=' . $divisionId));
    }

    redirect(base_url('admin/skills.php'));
}

$divisions = scoring_all_divisions($pdo);
if (empty($divisions)) {
    flash_set('error', 'ยังไม่มีทักษะในระบบ');
    redirect(base_url('admin/dashboard.php'));
}
$currentDivision = scoring_current_division($pdo, $divisions);
$currentDivisionId = (int)$currentDivision['id'];

$skillsStmt = $pdo->prepare('
    SELECT s.*,
        (SELECT COUNT(*) FROM criteria c WHERE c.skill_id = s.id) AS criteria_count,
        (SELECT COALESCE(SUM(max_score),0) FROM criteria c WHERE c.skill_id = s.id) AS criteria_sum
    FROM skills s WHERE s.division_id = ? ORDER BY sort_order
');
$skillsStmt->execute([$currentDivisionId]);
$skills = $skillsStmt->fetchAll();

$pageTitle = 'จัดการกลุ่มเกณฑ์การให้คะแนน';
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/division_tabs.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h3>🎯 จัดการกลุ่มเกณฑ์การให้คะแนน</h3>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createSkillModal">➕ เพิ่มกลุ่มเกณฑ์การให้คะแนน</button>
</div>

<div class="card">
    <div class="card-body table-responsive">
        <table class="table table-hover align-middle">
            <thead>
            <tr>
                <th>รหัส</th>
                <th>ชื่อกลุ่มเกณฑ์</th>
                <th>คะแนนเต็ม</th>
                <th>จำนวนเกณฑ์ย่อย</th>
                <th>ประเภท</th>
                <th class="text-end col-manage">จัดการ</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($skills as $s): ?>
                <tr>
                    <td><?= e($s['code']) ?></td>
                    <td><?= e($s['name']) ?></td>
                    <td>
                        <?= fmt_score($s['max_score']) ?>
                        <?php if ((float)$s['criteria_sum'] !== (float)$s['max_score']): ?>
                            <span class="badge bg-warning text-dark" title="รวมเกณฑ์ย่อย = <?= fmt_score($s['criteria_sum']) ?>">⚠️ รวมเกณฑ์ย่อยไม่ตรง</span>
                        <?php endif; ?>
                    </td>
                    <td><?= (int)$s['criteria_count'] ?> ข้อ</td>
                    <td><?= $s['is_bonus'] ? '<span class="badge bg-warning text-dark">🎁 โจทย์พิเศษ (ไม่รวมใน 100)</span>' : '<span class="badge bg-primary">🏅 คะแนนหลัก</span>' ?></td>
                    <td class="text-end col-manage">
                        <a href="<?= base_url('admin/criteria.php?skill_id=' . (int)$s['id']) ?>" class="btn btn-sm btn-outline-primary">📋 จัดการเกณฑ์ย่อย</a>
                        <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#editSkill<?= (int)$s['id'] ?>">✏️ แก้ไข</button>
                        <form method="post" class="d-inline" data-confirm="ลบกลุ่มเกณฑ์ '<?= e($s['name']) ?>' ใช่หรือไม่? เกณฑ์ย่อยและคะแนนทั้งหมดในกลุ่มนี้จะถูกลบ">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                            <input type="hidden" name="division_id" value="<?= $currentDivisionId ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger">🗑️ ลบ</button>
                        </form>
                    </td>
                </tr>

                <div class="modal fade" id="editSkill<?= (int)$s['id'] ?>" tabindex="-1">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <form method="post">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="update">
                                <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                                <input type="hidden" name="division_id" value="<?= $currentDivisionId ?>">
                                <div class="modal-header">
                                    <h5 class="modal-title">✏️ แก้ไขกลุ่มเกณฑ์: <?= e($s['code']) ?></h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    <div class="mb-3">
                                        <label class="form-label">ชื่อกลุ่มเกณฑ์</label>
                                        <input type="text" name="name" class="form-control" value="<?= e($s['name']) ?>" required>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">คะแนนเต็ม</label>
                                        <input type="number" step="0.01" min="0" name="max_score" class="form-control" value="<?= e((string)$s['max_score']) ?>" required>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="is_bonus" id="bonus<?= (int)$s['id'] ?>" <?= $s['is_bonus'] ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="bonus<?= (int)$s['id'] ?>">เป็นโจทย์พิเศษ (ไม่รวมในคะแนนเต็ม 100 ใช้จัดอันดับเมื่อคะแนนเท่ากัน)</label>
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
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="createSkillModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create">
                <input type="hidden" name="division_id" value="<?= $currentDivisionId ?>">
                <div class="modal-header">
                    <h5 class="modal-title">➕ เพิ่มกลุ่มเกณฑ์การให้คะแนน (ทักษะ: <?= e($currentDivision['name']) ?>)</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">รหัสกลุ่มเกณฑ์ (เช่น S6)</label>
                        <input type="text" name="code" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">ชื่อกลุ่มเกณฑ์</label>
                        <input type="text" name="name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">คะแนนเต็ม</label>
                        <input type="number" step="0.01" min="0" name="max_score" class="form-control" value="10" required>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="is_bonus" id="newbonus">
                        <label class="form-check-label" for="newbonus">เป็นโจทย์พิเศษ (ไม่รวมในคะแนนเต็ม 100)</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">✖️ ยกเลิก</button>
                    <button type="submit" class="btn btn-primary">✅ เพิ่มกลุ่มเกณฑ์</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
