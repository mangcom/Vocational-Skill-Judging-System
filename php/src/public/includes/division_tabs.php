<?php
/**
 * ต้องเตรียมตัวแปรก่อน include:
 * @var array $divisions       scoring_all_divisions()
 * @var array $currentDivision scoring_current_division()
 *
 * แสดงทักษะที่กำลังจัดการอยู่เป็น dropdown แบบ select (สลับทักษะอื่นได้ถ้ามีมากกว่า 1 ทักษะ)
 */
$scriptPath = preg_replace('/\.php$/', '', $_SERVER['SCRIPT_NAME']);
$divTabsUser = current_user();
?>
<div class="division-select-bar mb-3 d-flex flex-wrap align-items-center gap-2">
    <?php if ($divTabsUser && $divTabsUser['role'] === 'admin'): ?>
        <a href="<?= base_url('admin/divisions.php') ?>" class="btn btn-sm btn-outline-primary" title="ไปหน้าจัดการข้อมูลทักษะนี้">
            ⚙️ จัดการทักษะ
        </a>
    <?php endif; ?>
    <?php if (count($divisions) > 1): ?>
        <label class="d-flex align-items-center gap-2 mb-0">
            <span>🏆</span>
            <select class="form-select form-select-sm division-select" onchange="if (this.value) { location.href = this.value; }">
                <?php foreach ($divisions as $d): ?>
                    <option value="<?= e($scriptPath . '?division_id=' . (int)$d['id']) ?>" <?= (int)$d['id'] === (int)$currentDivision['id'] ? 'selected' : '' ?>>
                        <?= e($d['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
    <?php else: ?>
        <span class="fw-semibold">🏆 <?= e($currentDivision['name']) ?></span>
    <?php endif; ?>
</div>
