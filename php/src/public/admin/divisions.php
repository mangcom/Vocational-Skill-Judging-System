<?php
require __DIR__ . '/../../app/bootstrap.php';
require_role('admin');

$pdo = db();

$uploadDir = __DIR__ . '/../../storage/uploads/criteria';
$allowedExt = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png'];
$maxUploadBytes = 10 * 1024 * 1024; // 10MB

function handle_criteria_upload(array $file, string $uploadDir, array $allowedExt, int $maxBytes): array
{
    if ($file['error'] === UPLOAD_ERR_NO_FILE) {
        return [null, null, null];
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('อัปโหลดไฟล์ไม่สำเร็จ (error code ' . $file['error'] . ')');
    }
    if ($file['size'] > $maxBytes) {
        throw new RuntimeException('ไฟล์ใหญ่เกิน 10MB');
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExt, true)) {
        throw new RuntimeException('รองรับเฉพาะไฟล์ ' . implode(', ', $allowedExt));
    }
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0775, true);
    }
    $storedName = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $uploadDir . '/' . $storedName)) {
        throw new RuntimeException('บันทึกไฟล์ไม่สำเร็จ');
    }
    return [$storedName, $file['name'], date('Y-m-d H:i:s')];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $code = trim($_POST['code'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');

        if ($code === '' || $name === '') {
            flash_set('error', 'กรุณากรอกรหัสและชื่อทักษะ');
        } else {
            $maxOrder = (int)$pdo->query('SELECT COALESCE(MAX(sort_order),0) m FROM divisions')->fetch()['m'];
            try {
                [$storedName, $origName, $uploadedAt] = handle_criteria_upload($_FILES['criteria_file'] ?? ['error' => UPLOAD_ERR_NO_FILE], $uploadDir, $allowedExt, $maxUploadBytes);
                $pdo->prepare('INSERT INTO divisions (code, name, sort_order, description, criteria_file_path, criteria_file_name, criteria_file_uploaded_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$code, $name, $maxOrder + 1, $description ?: null, $storedName, $origName, $uploadedAt]);
                flash_set('success', "เพิ่มทักษะ '{$name}' สำเร็จ");
            } catch (RuntimeException $e) {
                flash_set('error', $e->getMessage());
            } catch (PDOException $e) {
                flash_set('error', $e->getCode() === '23000' ? 'รหัสนี้มีอยู่แล้ว' : 'เกิดข้อผิดพลาด');
            }
        }
    } elseif ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        if ($id > 0 && $name !== '') {
            try {
                [$storedName, $origName, $uploadedAt] = handle_criteria_upload($_FILES['criteria_file'] ?? ['error' => UPLOAD_ERR_NO_FILE], $uploadDir, $allowedExt, $maxUploadBytes);
                if ($storedName !== null) {
                    $pdo->prepare('UPDATE divisions SET name = ?, description = ?, criteria_file_path = ?, criteria_file_name = ?, criteria_file_uploaded_at = ? WHERE id = ?')
                        ->execute([$name, $description ?: null, $storedName, $origName, $uploadedAt, $id]);
                } else {
                    $pdo->prepare('UPDATE divisions SET name = ?, description = ? WHERE id = ?')
                        ->execute([$name, $description ?: null, $id]);
                }
                flash_set('success', 'แก้ไขข้อมูลทักษะสำเร็จ');
            } catch (RuntimeException $e) {
                flash_set('error', $e->getMessage());
            }
        }
    } elseif ($action === 'remove_file') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare('UPDATE divisions SET criteria_file_path = NULL, criteria_file_name = NULL, criteria_file_uploaded_at = NULL WHERE id = ?')->execute([$id]);
        flash_set('success', 'ลบไฟล์เกณฑ์การแข่งขันสำเร็จ');
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare('DELETE FROM divisions WHERE id = ?')->execute([$id]);
        flash_set('success', 'ลบทักษะสำเร็จ (กลุ่มเกณฑ์ ทีม และคะแนนที่เกี่ยวข้องถูกลบด้วย)');
    }

    redirect(base_url('admin/divisions.php'));
}

$divisions = $pdo->query('
    SELECT d.*,
        (SELECT COUNT(*) FROM skills s WHERE s.division_id = d.id) AS skill_count,
        (SELECT COUNT(*) FROM teams t WHERE t.division_id = d.id) AS team_count,
        (SELECT COUNT(DISTINCT js.judge_id) FROM judge_skills js JOIN skills s2 ON s2.id = js.skill_id WHERE s2.division_id = d.id) AS judge_count
    FROM divisions d ORDER BY d.name
')->fetchAll();

$pageTitle = 'ทักษะ';
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h3>🏆 ทักษะ</h3>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createDivisionModal">➕ เพิ่มทักษะ</button>
</div>
<p class="text-muted">แต่ละทักษะมีทีม กรรมการที่รับผิดชอบ กลุ่มเกณฑ์การให้คะแนน และสรุปคะแนนแยกจากกันโดยสิ้นเชิง</p>

<div class="row g-3">
    <?php foreach ($divisions as $d): ?>
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <h5 class="mb-1"><?= e($d['code']) ?> — <?= e($d['name']) ?></h5>
                            <?php if ($d['description']): ?>
                                <p class="text-muted small mb-2"><?= nl2br(e($d['description'])) ?></p>
                            <?php endif; ?>
                            <div class="mb-2">
                                <a href="<?= base_url('admin/skills.php?division_id=' . (int)$d['id']) ?>" class="badge bg-info text-dark text-decoration-none me-1">🎯 <?= (int)$d['skill_count'] ?> กลุ่มเกณฑ์การให้คะแนน</a>
                                <a href="<?= base_url('admin/teams.php?division_id=' . (int)$d['id']) ?>" class="badge bg-primary text-decoration-none me-1">👥 <?= (int)$d['team_count'] ?> ทีม</a>
                                <a href="<?= base_url('admin/judges.php') ?>" class="badge bg-warning text-dark text-decoration-none me-1">⚖️ <?= (int)$d['judge_count'] ?> กรรมการ</a>
                            </div>
                            <?php if ($d['criteria_file_path']): ?>
                                <a href="<?= base_url('download_criteria.php?division_id=' . (int)$d['id']) ?>" class="btn btn-sm btn-outline-success">
                                    📎 ดาวน์โหลดเกณฑ์: <?= e($d['criteria_file_name']) ?>
                                </a>
                                <form method="post" class="d-inline" data-confirm="ลบไฟล์เกณฑ์การแข่งขันนี้ใช่หรือไม่?">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="remove_file">
                                    <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">🗑️ ลบไฟล์</button>
                                </form>
                            <?php else: ?>
                                <span class="text-muted small">📎 ยังไม่มีไฟล์เกณฑ์การแข่งขันแนบ</span>
                            <?php endif; ?>
                        </div>
                        <div class="text-end">
                            <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#editDivision<?= (int)$d['id'] ?>">✏️ แก้ไข</button>
                            <form method="post" class="d-inline" data-confirm="ลบทักษะ '<?= e($d['name']) ?>' ใช่หรือไม่? กลุ่มเกณฑ์ ทีม และคะแนนทั้งหมดในทักษะนี้จะถูกลบ">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger">🗑️ ลบ</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="modal fade" id="editDivision<?= (int)$d['id'] ?>" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form method="post" enctype="multipart/form-data">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                        <div class="modal-header">
                            <h5 class="modal-title">✏️ แก้ไขทักษะ: <?= e($d['code']) ?></h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label">ชื่อทักษะ</label>
                                <input type="text" name="name" class="form-control" value="<?= e($d['name']) ?>" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">รายละเอียดเพิ่มเติม</label>
                                <textarea name="description" class="form-control" rows="3" placeholder="เช่น ระดับ, กลุ่มผู้เข้าแข่งขัน, หมายเหตุอื่น ๆ"><?= e($d['description'] ?? '') ?></textarea>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">ไฟล์เกณฑ์การแข่งขัน (PDF/Word/Excel/รูปภาพ ไม่เกิน 10MB)</label>
                                <input type="file" name="criteria_file" class="form-control">
                                <?php if ($d['criteria_file_name']): ?>
                                    <div class="form-text">ไฟล์ปัจจุบัน: <?= e($d['criteria_file_name']) ?> (อัปโหลดไฟล์ใหม่เพื่อแทนที่)</div>
                                <?php endif; ?>
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
    <?php if (empty($divisions)): ?>
        <p class="text-muted text-center py-4">ยังไม่มีทักษะในระบบ</p>
    <?php endif; ?>
</div>

<div class="modal fade" id="createDivisionModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create">
                <div class="modal-header">
                    <h5 class="modal-title">➕ เพิ่มทักษะ</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">รหัส (เช่น POS-DEVOPS)</label>
                        <input type="text" name="code" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">ชื่อทักษะ</label>
                        <input type="text" name="name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">รายละเอียดเพิ่มเติม</label>
                        <textarea name="description" class="form-control" rows="3" placeholder="เช่น ระดับ, กลุ่มผู้เข้าแข่งขัน, หมายเหตุอื่น ๆ"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">ไฟล์เกณฑ์การแข่งขัน (PDF/Word/Excel/รูปภาพ ไม่เกิน 10MB)</label>
                        <input type="file" name="criteria_file" class="form-control">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">✖️ ยกเลิก</button>
                    <button type="submit" class="btn btn-primary">✅ เพิ่ม</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
