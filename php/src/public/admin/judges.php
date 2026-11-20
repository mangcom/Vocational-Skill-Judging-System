<?php
require __DIR__ . '/../../app/bootstrap.php';
require_role('admin');

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $username = trim($_POST['username'] ?? '');
        $fullName = trim($_POST['full_name'] ?? '');
        $password = (string)($_POST['password'] ?? '');
        $position = $_POST['position'] ?? 'กรรมการ';
        $skillIds = array_map('intval', $_POST['skill_ids'] ?? []);

        if (!in_array($position, judge_position_options(), true)) {
            $position = 'กรรมการ';
        }

        if ($username === '' || $fullName === '' || $password === '') {
            flash_set('error', 'กรุณากรอกข้อมูลให้ครบถ้วน');
        } else {
            try {
                $pdo->beginTransaction();
                $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role, position) VALUES (?, ?, ?, ?, ?)')
                    ->execute([$username, password_hash($password, PASSWORD_DEFAULT), $fullName, 'judge', $position]);
                $judgeId = (int)$pdo->lastInsertId();
                $ins = $pdo->prepare('INSERT INTO judge_skills (judge_id, skill_id) VALUES (?, ?)');
                foreach ($skillIds as $sid) {
                    $ins->execute([$judgeId, $sid]);
                }
                $pdo->commit();
                flash_set('success', "เพิ่มกรรมการ '{$fullName}' สำเร็จ");
            } catch (PDOException $e) {
                $pdo->rollBack();
                flash_set('error', $e->getCode() === '23000' ? 'ชื่อผู้ใช้นี้มีอยู่แล้ว' : 'เกิดข้อผิดพลาด: ' . $e->getMessage());
            }
        }
    } elseif ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $fullName = trim($_POST['full_name'] ?? '');
        $password = (string)($_POST['password'] ?? '');
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $position = $_POST['position'] ?? 'กรรมการ';
        $skillIds = array_map('intval', $_POST['skill_ids'] ?? []);

        if (!in_array($position, judge_position_options(), true)) {
            $position = 'กรรมการ';
        }

        if ($id > 0 && $fullName !== '') {
            $pdo->beginTransaction();
            if ($password !== '') {
                $pdo->prepare('UPDATE users SET full_name = ?, password_hash = ?, is_active = ?, position = ? WHERE id = ? AND role = "judge"')
                    ->execute([$fullName, password_hash($password, PASSWORD_DEFAULT), $isActive, $position, $id]);
            } else {
                $pdo->prepare('UPDATE users SET full_name = ?, is_active = ?, position = ? WHERE id = ? AND role = "judge"')
                    ->execute([$fullName, $isActive, $position, $id]);
            }
            $pdo->prepare('DELETE FROM judge_skills WHERE judge_id = ?')->execute([$id]);
            $ins = $pdo->prepare('INSERT INTO judge_skills (judge_id, skill_id) VALUES (?, ?)');
            foreach ($skillIds as $sid) {
                $ins->execute([$id, $sid]);
            }
            $pdo->commit();
            flash_set('success', 'แก้ไขข้อมูลกรรมการสำเร็จ');
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare('DELETE FROM users WHERE id = ? AND role = "judge"')->execute([$id]);
        flash_set('success', 'ลบกรรมการสำเร็จ');
    }

    redirect(base_url('admin/judges.php'));
}

$skills = $pdo->query('
    SELECT s.*, d.id AS division_id, d.name AS division_name
    FROM skills s JOIN divisions d ON d.id = s.division_id
    ORDER BY d.name, s.sort_order
')->fetchAll();
$skillsByDivision = [];
foreach ($skills as $s) {
    $key = (int)$s['division_id'];
    $skillsByDivision[$key]['name'] = $s['division_name'];
    $skillsByDivision[$key]['skills'][] = $s;
}
$judges = $pdo->query('SELECT * FROM users WHERE role = "judge" ORDER BY full_name')->fetchAll();

$judgeSkillMap = [];
foreach ($pdo->query('SELECT judge_id, skill_id FROM judge_skills')->fetchAll() as $row) {
    $judgeSkillMap[(int)$row['judge_id']][] = (int)$row['skill_id'];
}

$divisions = scoring_all_divisions($pdo);
$filterDivisionId = (int)($_GET['division_id'] ?? 0);
if ($filterDivisionId > 0) {
    $skillIdsInDivision = array_map(fn($s) => (int)$s['id'], array_filter($skills, fn($s) => (int)$s['division_id'] === $filterDivisionId));
    $judges = array_values(array_filter($judges, function ($j) use ($judgeSkillMap, $skillIdsInDivision) {
        $assignedSkillIds = $judgeSkillMap[(int)$j['id']] ?? [];
        return (bool)array_intersect($assignedSkillIds, $skillIdsInDivision);
    }));
}

// --- เรียงลำดับ: ถ้าแสดงทั้งหมด (ไม่กรองทักษะ) เรียงตามชื่อทักษะก่อน แล้วค่อยเรียงตามตำแหน่ง, ถ้ากรองแล้วเรียงตามตำแหน่งอย่างเดียว ---
$positionRank = array_flip(judge_position_options());
$skillDivisionName = [];
foreach ($skills as $s) {
    $skillDivisionName[(int)$s['id']] = $s['division_name'];
}
$judgePrimaryDivisionName = [];
foreach ($judgeSkillMap as $jId => $skillIds) {
    $names = array_filter(array_map(fn($sid) => $skillDivisionName[$sid] ?? null, $skillIds));
    sort($names);
    $judgePrimaryDivisionName[$jId] = $names ? reset($names) : null;
}
usort($judges, function ($a, $b) use ($filterDivisionId, $judgePrimaryDivisionName, $positionRank) {
    if ($filterDivisionId === 0) {
        $divA = $judgePrimaryDivisionName[(int)$a['id']] ?? null;
        $divB = $judgePrimaryDivisionName[(int)$b['id']] ?? null;
        if ($divA !== $divB) {
            return strcmp($divA ?? "\xff", $divB ?? "\xff");
        }
    }
    $posA = $positionRank[$a['position'] ?? 'กรรมการ'] ?? count($positionRank);
    $posB = $positionRank[$b['position'] ?? 'กรรมการ'] ?? count($positionRank);
    if ($posA !== $posB) {
        return $posA <=> $posB;
    }
    return strcmp($a['full_name'], $b['full_name']);
});

$pageTitle = 'จัดการกรรมการ';
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h3 class="mb-0">⚖️ จัดการกรรมการ</h3>
    <div class="d-flex align-items-center gap-2">
        <form method="get" class="d-flex align-items-center gap-2">
            <label class="form-label mb-0 text-muted small">🏆 กรองตามทักษะ:</label>
            <select name="division_id" class="form-select form-select-sm" style="min-width: 220px;" onchange="this.form.submit()">
                <option value="0" <?= $filterDivisionId === 0 ? 'selected' : '' ?>>ทุกทักษะ</option>
                <?php foreach ($divisions as $d): ?>
                    <option value="<?= (int)$d['id'] ?>" <?= $filterDivisionId === (int)$d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </form>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createModal">➕ เพิ่มกรรมการ</button>
    </div>
</div>

<div class="card">
    <div class="card-body table-responsive">
        <table class="table table-hover align-middle">
            <thead>
            <tr>
                <th>ชื่อ-สกุล</th>
                <th>ชื่อผู้ใช้</th>
                <th>ตำแหน่ง</th>
                <th>รับผิดชอบทักษะ / กลุ่มเกณฑ์การให้คะแนน</th>
                <th>สถานะ</th>
                <th class="text-end col-manage">จัดการ</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($judges as $judge): ?>
                <tr>
                    <td><?= e($judge['full_name']) ?></td>
                    <td><?= e($judge['username']) ?></td>
                    <td><span class="badge bg-light text-dark border"><?= e(position_label($judge['position'] ?? 'กรรมการ')) ?></span></td>
                    <td>
                        <?php
                        $assigned = $judgeSkillMap[(int)$judge['id']] ?? [];
                        $byDiv = [];
                        foreach ($skills as $s) {
                            if (in_array((int)$s['id'], $assigned, true)) {
                                $byDiv[(int)$s['division_id']]['name'] = $s['division_name'];
                                $byDiv[(int)$s['division_id']]['skills'][] = $s;
                            }
                        }
                        if (empty($byDiv)) {
                            echo '<span class="text-muted">ยังไม่กำหนด</span>';
                        }
                        foreach ($byDiv as $divId => $info): ?>
                            <div class="mb-1">
                                <a href="<?= base_url('admin/skills.php?division_id=' . $divId) ?>" class="small text-decoration-none fw-semibold">🏆 <?= e($info['name']) ?></a><br>
                                <?php foreach ($info['skills'] as $n): ?>
                                    <a href="<?= base_url('admin/criteria.php?skill_id=' . (int)$n['id']) ?>" class="badge bg-info text-dark text-decoration-none me-1" title="<?= e($n['name']) ?>"><?= e($n['code']) ?></a>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                    </td>
                    <td>
                        <?= $judge['is_active'] ? '<span class="badge bg-success">✅ ใช้งาน</span>' : '<span class="badge bg-secondary">🚫 ปิดใช้งาน</span>' ?>
                    </td>
                    <td class="text-end col-manage">
                        <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#editModal<?= (int)$judge['id'] ?>">✏️ แก้ไข</button>
                        <form method="post" class="d-inline" data-confirm="ลบกรรมการ '<?= e($judge['full_name']) ?>' ใช่หรือไม่? คะแนนที่ให้ไว้จะถูกลบทั้งหมด">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int)$judge['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger">🗑️ ลบ</button>
                        </form>
                    </td>
                </tr>

                <div class="modal fade" id="editModal<?= (int)$judge['id'] ?>" tabindex="-1">
                    <div class="modal-dialog modal-lg">
                        <div class="modal-content">
                            <form method="post">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="update">
                                <input type="hidden" name="id" value="<?= (int)$judge['id'] ?>">
                                <div class="modal-header">
                                    <h5 class="modal-title">✏️ แก้ไขกรรมการ: <?= e($judge['full_name']) ?></h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    <div class="mb-3">
                                        <label class="form-label">ชื่อ-สกุล</label>
                                        <input type="text" name="full_name" class="form-control" value="<?= e($judge['full_name']) ?>" required>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">ตั้งรหัสผ่านใหม่ (เว้นว่างถ้าไม่เปลี่ยน)</label>
                                        <input type="password" name="password" class="form-control">
                                    </div>
                                    <div class="mb-3 form-check">
                                        <input type="checkbox" name="is_active" class="form-check-input" id="active<?= (int)$judge['id'] ?>" <?= $judge['is_active'] ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="active<?= (int)$judge['id'] ?>">เปิดใช้งานบัญชีนี้</label>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">ตำแหน่ง</label>
                                        <select name="position" class="form-select">
                                            <?php foreach (judge_position_options() as $opt): ?>
                                                <option value="<?= e($opt) ?>" <?= ($judge['position'] ?? 'กรรมการ') === $opt ? 'selected' : '' ?>><?= e(position_label($opt)) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div class="form-text">👑 ประธานกรรมการ สามารถดูสรุปคะแนนและมอบหมายทีมให้กรรมการได้</div>
                                    </div>
                                    <div class="mb-2">
                                        <label class="form-label">รับผิดชอบทักษะ / กลุ่มเกณฑ์การให้คะแนน</label>
                                        <?php
                                        $editGroupName = 'skillgroup-edit-' . (int)$judge['id'];
                                        $editDefaultDiv = array_key_first($byDiv) ?: array_key_first($skillsByDivision);
                                        ?>
                                        <select class="form-select form-select-sm mb-2 skill-division-switch" data-target="<?= e($editGroupName) ?>" onchange="switchSkillGroup(this)">
                                            <?php foreach ($skillsByDivision as $divId => $divInfo): ?>
                                                <option value="<?= $divId ?>" <?= $divId === $editDefaultDiv ? 'selected' : '' ?>>🏆 <?= e($divInfo['name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <?php foreach ($skillsByDivision as $divId => $divInfo): ?>
                                            <div class="skill-division-group" data-group="<?= e($editGroupName) ?>" data-division="<?= $divId ?>" style="<?= $divId === $editDefaultDiv ? '' : 'display:none;' ?>">
                                                <?php foreach ($divInfo['skills'] as $s): ?>
                                                    <div class="form-check">
                                                        <input class="form-check-input" type="checkbox" name="skill_ids[]" value="<?= (int)$s['id'] ?>"
                                                            id="sk<?= (int)$judge['id'] ?>_<?= (int)$s['id'] ?>"
                                                            <?= in_array((int)$s['id'], $assigned, true) ? 'checked' : '' ?>>
                                                        <label class="form-check-label" for="sk<?= (int)$judge['id'] ?>_<?= (int)$s['id'] ?>">
                                                            <?= e($s['code'] . ' - ' . $s['name']) ?>
                                                        </label>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endforeach; ?>
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
            <?php if (empty($judges)): ?>
                <tr><td colspan="6" class="text-center text-muted py-4"><?= $filterDivisionId > 0 ? 'ไม่มีกรรมการที่รับผิดชอบทักษะนี้' : 'ยังไม่มีกรรมการในระบบ' ?></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="createModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create">
                <div class="modal-header">
                    <h5 class="modal-title">➕ เพิ่มกรรมการ</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">ชื่อ-สกุล</label>
                        <input type="text" name="full_name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">ชื่อผู้ใช้ (สำหรับ Login)</label>
                        <input type="text" name="username" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">รหัสผ่าน</label>
                        <input type="password" name="password" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">ตำแหน่ง</label>
                        <select name="position" class="form-select">
                            <?php foreach (judge_position_options() as $opt): ?>
                                <option value="<?= e($opt) ?>" <?= $opt === 'กรรมการ' ? 'selected' : '' ?>><?= e(position_label($opt)) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">👑 ประธานกรรมการ สามารถดูสรุปคะแนนและมอบหมายทีมให้กรรมการได้</div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">รับผิดชอบทักษะ / กลุ่มเกณฑ์การให้คะแนน</label>
                        <?php $createDefaultDiv = array_key_first($skillsByDivision); ?>
                        <select class="form-select form-select-sm mb-2 skill-division-switch" data-target="skillgroup-create" onchange="switchSkillGroup(this)">
                            <?php foreach ($skillsByDivision as $divId => $divInfo): ?>
                                <option value="<?= $divId ?>" <?= $divId === $createDefaultDiv ? 'selected' : '' ?>>🏆 <?= e($divInfo['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php foreach ($skillsByDivision as $divId => $divInfo): ?>
                            <div class="skill-division-group" data-group="skillgroup-create" data-division="<?= $divId ?>" style="<?= $divId === $createDefaultDiv ? '' : 'display:none;' ?>">
                                <?php foreach ($divInfo['skills'] as $s): ?>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="skill_ids[]" value="<?= (int)$s['id'] ?>" id="newsk<?= (int)$s['id'] ?>">
                                        <label class="form-check-label" for="newsk<?= (int)$s['id'] ?>"><?= e($s['code'] . ' - ' . $s['name']) ?></label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">✖️ ยกเลิก</button>
                    <button type="submit" class="btn btn-primary">✅ เพิ่มกรรมการ</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function switchSkillGroup(select) {
    var target = select.dataset.target;
    document.querySelectorAll('.skill-division-group[data-group="' + target + '"]').forEach(function (g) {
        g.style.display = (g.dataset.division === select.value) ? '' : 'none';
    });
}
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
