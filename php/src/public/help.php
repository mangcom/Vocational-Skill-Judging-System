<?php
require __DIR__ . '/../app/bootstrap.php';
$user = require_login();

$manualPath = __DIR__ . '/assets/docs/user-manual.pdf';
$manualSize = is_file($manualPath) ? round(filesize($manualPath) / 1024 / 1024, 1) . ' MB' : null;

$pageTitle = 'คู่มือการใช้งาน';
require __DIR__ . '/includes/header.php';
?>
<h3 class="mb-3">❓ คู่มือการใช้งานระบบ</h3>

<div class="card">
    <div class="card-body">
        <h5 class="card-title">📖 คู่มือการใช้งานฉบับเต็ม (PDF)</h5>
        <p class="card-text text-muted">
            คู่มืออธิบายวิธีใช้งานระบบอย่างละเอียด ทั้งสำหรับผู้ดูแลระบบ (Admin) และกรรมการ
            พร้อมภาพประกอบขั้นตอนการทำงานทีละขั้นตอน ครอบคลุมตั้งแต่การเข้าสู่ระบบ
            การจัดการทักษะ/กรรมการ/ทีม การมอบหมายทีม การให้คะแนน การพิมพ์ใบให้คะแนนสำรอง
            การ Import/Export ข้อมูล ไปจนถึงคำถามที่พบบ่อยและการแก้ไขปัญหาเบื้องต้น
        </p>
        <a href="<?= base_url('assets/docs/user-manual.pdf') ?>" class="btn btn-primary" download>
            📥 ดาวน์โหลดคู่มือ (PDF<?= $manualSize ? ' • ' . e($manualSize) : '' ?>)
        </a>
        <a href="<?= base_url('assets/docs/user-manual.pdf') ?>" class="btn btn-outline-secondary" target="_blank">
            👁️ เปิดดูในเบราว์เซอร์
        </a>
    </div>
</div>

<div class="row g-3 mt-1">
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-body">
                <h5>⚙️ สำหรับผู้ดูแลระบบ (Admin)</h5>
                <ul class="mb-0">
                    <li>จัดการทักษะ / กลุ่มเกณฑ์การให้คะแนน / กรรมการ / ทีมผู้เข้าแข่งขัน</li>
                    <li>มอบหมายทีมให้กรรมการ และดูสรุปคะแนน</li>
                    <li>พิมพ์ใบให้คะแนน (สำรอง) เป็น PDF</li>
                    <li>สำรอง/นำเข้าข้อมูลทั้งระบบ (Import/Export)</li>
                </ul>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-body">
                <h5>⚖️ สำหรับกรรมการ</h5>
                <ul class="mb-0">
                    <li>เข้าสู่ระบบและดูรายการทีมที่รับผิดชอบ</li>
                    <li>ให้คะแนนตามเกณฑ์ที่กำหนด และบันทึกคะแนน</li>
                    <li>(หัวหน้ากรรมการ) มอบหมายทีม และดูผลคะแนนสรุป</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
