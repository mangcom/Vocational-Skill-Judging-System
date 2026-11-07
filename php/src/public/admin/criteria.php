<?php
require __DIR__ . '/../../app/bootstrap.php';
require_role('admin');

$pdo = db();

$skillId = (int)($_GET['skill_id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM skills WHERE id = ?');
$stmt->execute([$skillId]);
$skill = $stmt->fetch();
if (!$skill) {
    flash_set('error', 'ไม่พบทักษะที่เลือก');
    redirect(base_url('admin/skills.php'));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $code = trim($_POST['code'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        $max = (float)($_POST['max_score'] ?? 1);

        if ($code === '' || $name === '') {
            flash_set('error', 'กรุณากรอกรหัสและชื่อข้อ');
        } else {
            $maxOrder = (int)$pdo->query('SELECT COALESCE(MAX(sort_order),0) m FROM criteria WHERE skill_id = ' . (int)$skillId)->fetch()['m'];
            try {
                $pdo->prepare('INSERT INTO criteria (skill_id, code, name, description, max_score, sort_order) VALUES (?,?,?,?,?,?)')
                    ->execute([$skillId, $code, $name, $desc, $max, $maxOrder + 1]);
                flash_set('success', "เพิ่มเกณฑ์ข้อ '{$code}' สำเร็จ");
            } catch (PDOException $e) {
                flash_set('error', $e->getCode() === '23000' ? 'รหัสข้อนี้มีอยู่แล้วในทักษะนี้' : 'เกิดข้อผิดพลาด');
            }
        }
    } elseif ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        $max = (float)($_POST['max_score'] ?? 1);
        if ($id > 0 && $name !== '') {
            $pdo->prepare('UPDATE criteria SET name = ?, description = ?, max_score = ? WHERE id = ? AND skill_id = ?')
                ->execute([$name, $desc, $max, $id, $skillId]);
            flash_set('success', 'แก้ไขเกณฑ์สำเร็จ');
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare('DELETE FROM criteria WHERE id = ? AND skill_id = ?')->execute([$id, $skillId]);
        flash_set('success', 'ลบเกณฑ์สำเร็จ');
    }

    redirect(base_url('admin/criteria.php?skill_id=' . $skillId));
}

$stmt = $pdo->prepare('SELECT * FROM criteria WHERE skill_id = ? ORDER BY sort_order, id');
$stmt->execute([$skillId]);
$criteria = $stmt->fetchAll();
$sum = array_sum(array_column($criteria, 'max_score'));

$pageTitle = 'เกณฑ์ย่อย: ' . $skill['name'];
require __DIR__ . '/../includes/header.php';
?>
<a href="<?= base_url('admin/skills.php?division_id=' . (int)$skill['division_id']) ?>" class="btn btn-sm btn-link mb-2">⬅️ กลับไปหน้ากลุ่มเกณฑ์การให้คะแนน</a>
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h3 class="mb-0">📋 <?= e($skill['code'] . ' - ' . $skill['name']) ?></h3>
        <small class="text-muted">
            คะแนนเต็มกลุ่มเกณฑ์: <?= fmt_score($skill['max_score']) ?> |
            รวมเกณฑ์ย่อยปัจจุบัน:
            <span class="<?= (float)$sum !== (float)$skill['max_score'] ? 'text-danger fw-bold' : 'text-success' ?>"><?= fmt_score($sum) ?> <?= (float)$sum !== (float)$skill['max_score'] ? '⚠️' : '✅' ?></span>
        </small>
    </div>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createCriteriaModal">➕ เพิ่มเกณฑ์ย่อย</button>
</div>

<div class="card">
    <div class="card-body table-responsive">
        <table class="table table-hover align-middle">
            <thead>
            <tr>
                <th style="width:90px;">รหัส</th>
                <th>รายการ</th>
                <th>วิธีตรวจ / เกณฑ์ได้คะแนน</th>
                <th style="width:90px;">คะแนนเต็ม</th>
                <th class="text-end">จัดการ</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($criteria as $c): ?>
                <tr>
                    <td><?= e($c['code']) ?></td>
                    <td><?= e($c['name']) ?></td>
                    <td class="text-muted small"><?= e($c['description']) ?></td>
                    <td><?= fmt_score($c['max_score']) ?></td>
                    <td class="text-end">
                        <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#editCriteria<?= (int)$c['id'] ?>">✏️ แก้ไข</button>
                        <form method="post" class="d-inline" data-confirm="ลบเกณฑ์ข้อ '<?= e($c['code']) ?>' ใช่หรือไม่?">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger">🗑️ ลบ</button>
                        </form>
                    </td>
                </tr>

                <div class="modal fade" id="editCriteria<?= (int)$c['id'] ?>" tabindex="-1">
                    <div class="modal-dialog modal-lg">
                        <div class="modal-content">
                            <form method="post">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="update">
                                <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                                <div class="modal-header">
                                    <h5 class="modal-title">✏️ แก้ไขเกณฑ์ข้อ <?= e($c['code']) ?></h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    <div class="mb-3">
                                        <label class="form-label">ชื่อรายการ</label>
                                        <input type="text" name="name" class="form-control" value="<?= e($c['name']) ?>" required>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">วิธีตรวจ / เกณฑ์ได้คะแนน</label>
                                        <textarea name="description" class="form-control" rows="3"><?= e($c['description']) ?></textarea>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">คะแนนเต็ม</label>
                                        <input type="number" step="0.01" min="0" name="max_score" class="form-control" value="<?= e((string)$c['max_score']) ?>" required>
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
            <?php if (empty($criteria)): ?>
                <tr><td colspan="5" class="text-center text-muted py-4">ยังไม่มีเกณฑ์ย่อยในทักษะนี้</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="createCriteriaModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create">
                <div class="modal-header">
                    <h5 class="modal-title">➕ เพิ่มเกณฑ์ย่อย</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">รหัสข้อ (เช่น 1.1)</label>
                        <input type="text" name="code" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">ชื่อรายการ</label>
                        <input type="text" name="name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">วิธีตรวจ / เกณฑ์ได้คะแนน</label>
                        <textarea name="description" class="form-control" rows="3"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">คะแนนเต็ม</label>
                        <input type="number" step="0.01" min="0" name="max_score" class="form-control" value="1" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">✖️ ยกเลิก</button>
                    <button type="submit" class="btn btn-primary">✅ เพิ่มเกณฑ์</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
