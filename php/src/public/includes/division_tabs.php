<?php
/**
 * ต้องเตรียมตัวแปรก่อน include:
 * @var array $divisions       scoring_all_divisions()
 * @var array $currentDivision scoring_current_division()
 *
 * แสดงเฉพาะชื่อทักษะที่กำลังจัดการอยู่ (ไม่แสดงรายชื่อทักษะอื่นเกะกะ)
 * ชื่อทักษะเป็นลิงก์กลับไปหน้าจัดการทักษะ (admin/divisions.php) และมีปุ่มลูกศรไว้สลับทักษะ รองรับกรณีมีหลายทักษะในอนาคต
 */
$scriptPath = preg_replace('/\.php$/', '', $_SERVER['SCRIPT_NAME']);
$divTabsUser = current_user();
?>
<div class="btn-group mb-3">
    <?php if ($divTabsUser && $divTabsUser['role'] === 'admin'): ?>
        <a href="<?= base_url('admin/divisions.php') ?>" class="btn btn-primary" title="ไปหน้าจัดการข้อมูลทักษะนี้">
            🏆 <?= e($currentDivision['name']) ?>
        </a>
    <?php else: ?>
        <span class="btn btn-primary disabled">🏆 <?= e($currentDivision['name']) ?></span>
    <?php endif; ?>
    <?php if (count($divisions) > 1): ?>
        <button type="button" class="btn btn-primary dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown" aria-expanded="false">
            <span class="visually-hidden">สลับทักษะ</span>
        </button>
        <ul class="dropdown-menu">
            <?php foreach ($divisions as $d): ?>
                <li>
                    <a class="dropdown-item <?= (int)$d['id'] === (int)$currentDivision['id'] ? 'active' : '' ?>"
                       href="<?= e($scriptPath . '?division_id=' . (int)$d['id']) ?>">
                        <?= e($d['name']) ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
