<?php
require __DIR__ . '/../../app/bootstrap.php';
require_role('admin');

$pdo = db();

$uploadsDir = __DIR__ . '/../../storage/uploads';

function backup_db_env(): array
{
    return [
        'host' => getenv('DB_HOST') ?: 'db',
        'name' => getenv('DB_NAME') ?: 'judge_scoring',
        'user' => getenv('DB_USER') ?: 'judge_app',
        'pass' => getenv('DB_PASSWORD') ?: '',
    ];
}

function rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($dir);
}

function copy_dir_recursive(string $src, string $dst): void
{
    if (!is_dir($src)) {
        return;
    }
    if (!is_dir($dst)) {
        mkdir($dst, 0775, true);
    }
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($items as $item) {
        $destPath = $dst . '/' . substr($item->getPathname(), strlen($src) + 1);
        if ($item->isDir()) {
            if (!is_dir($destPath)) {
                mkdir($destPath, 0775, true);
            }
        } else {
            copy($item->getPathname(), $destPath);
        }
    }
}

function run_mysqldump(string $outFile): bool
{
    $env = backup_db_env();
    $cmd = sprintf(
        'mysqldump --single-transaction --skip-lock-tables --routines --host=%s --user=%s %s',
        escapeshellarg($env['host']),
        escapeshellarg($env['user']),
        escapeshellarg($env['name'])
    );
    $descriptors = [1 => ['file', $outFile, 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($cmd, $descriptors, $pipes, null, ['MYSQL_PWD' => $env['pass']]);
    if (!is_resource($process)) {
        return false;
    }
    stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $code = proc_close($process);
    return $code === 0;
}

function run_mysql_import(string $sqlFile): bool
{
    $env = backup_db_env();
    $cmd = sprintf(
        'mysql --host=%s --user=%s %s',
        escapeshellarg($env['host']),
        escapeshellarg($env['user']),
        escapeshellarg($env['name'])
    );
    $descriptors = [0 => ['file', $sqlFile, 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($cmd, $descriptors, $pipes, null, ['MYSQL_PWD' => $env['pass']]);
    if (!is_resource($process)) {
        return false;
    }
    fclose($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $code = proc_close($process);
    return $code === 0;
}

function build_backup_zip(string $zipPath, string $sqlPath, string $uploadsDir): void
{
    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFile($sqlPath, 'database.sql');
    $manifest = [
        'app' => 'Vocational Skill Judging System',
        'exported_at' => date('c'),
    ];
    $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    if (is_dir($uploadsDir)) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($uploadsDir, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $localName = 'uploads/' . str_replace('\\', '/', substr($file->getPathname(), strlen($uploadsDir) + 1));
            if ($file->isDir()) {
                $zip->addEmptyDir($localName);
            } else {
                $zip->addFile($file->getPathname(), $localName);
            }
        }
    }
    $zip->close();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'export') {
        $tmpDir = sys_get_temp_dir() . '/jsbackup_' . bin2hex(random_bytes(8));
        mkdir($tmpDir, 0700, true);
        $sqlPath = $tmpDir . '/database.sql';

        if (!run_mysqldump($sqlPath) || !is_file($sqlPath) || filesize($sqlPath) === 0) {
            rrmdir($tmpDir);
            flash_set('error', '❌ Export ฐานข้อมูลไม่สำเร็จ กรุณาตรวจสอบการเชื่อมต่อฐานข้อมูลแล้วลองใหม่');
            redirect(base_url('admin/backup.php'));
        }

        $zipPath = $tmpDir . '/backup.zip';
        build_backup_zip($zipPath, $sqlPath, $uploadsDir);

        $filename = 'judge-scoring-backup-' . date('Ymd_His') . '.zip';
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($zipPath));
        readfile($zipPath);
        rrmdir($tmpDir);
        exit;
    }

    if ($action === 'import') {
        if (!isset($_POST['confirm_overwrite'])) {
            flash_set('error', 'กรุณายืนยันว่าต้องการแทนที่ข้อมูลทั้งหมดก่อน Import');
            redirect(base_url('admin/backup.php'));
        }
        if (empty($_FILES['backup_file']['tmp_name']) || $_FILES['backup_file']['error'] !== UPLOAD_ERR_OK) {
            flash_set('error', 'กรุณาเลือกไฟล์ .zip สำรองข้อมูลให้ถูกต้อง');
            redirect(base_url('admin/backup.php'));
        }
        $ext = strtolower(pathinfo($_FILES['backup_file']['name'], PATHINFO_EXTENSION));
        if ($ext !== 'zip') {
            flash_set('error', 'ไฟล์ต้องเป็นนามสกุล .zip เท่านั้น');
            redirect(base_url('admin/backup.php'));
        }

        $tmpDir = sys_get_temp_dir() . '/jsrestore_' . bin2hex(random_bytes(8));
        mkdir($tmpDir, 0700, true);

        $zip = new ZipArchive();
        if ($zip->open($_FILES['backup_file']['tmp_name']) !== true) {
            rrmdir($tmpDir);
            flash_set('error', 'ไม่สามารถเปิดไฟล์ .zip ได้ ไฟล์อาจเสียหาย');
            redirect(base_url('admin/backup.php'));
        }
        $zip->extractTo($tmpDir);
        $zip->close();

        $sqlPath = $tmpDir . '/database.sql';
        if (!is_file($sqlPath)) {
            rrmdir($tmpDir);
            flash_set('error', 'ไม่พบไฟล์ database.sql ในไฟล์สำรองข้อมูล — อาจไม่ใช่ไฟล์สำรองของระบบนี้');
            redirect(base_url('admin/backup.php'));
        }

        if (!run_mysql_import($sqlPath)) {
            rrmdir($tmpDir);
            flash_set('error', 'นำเข้าฐานข้อมูลไม่สำเร็จ กรุณาตรวจสอบไฟล์สำรองข้อมูล');
            redirect(base_url('admin/backup.php'));
        }

        $extractedUploads = $tmpDir . '/uploads';
        if (is_dir($extractedUploads)) {
            copy_dir_recursive($extractedUploads, $uploadsDir);
        }

        rrmdir($tmpDir);
        flash_set('success', '✅ นำเข้าข้อมูลสำเร็จ ข้อมูลเดิมถูกแทนที่ด้วยข้อมูลจากไฟล์สำรองแล้ว');
        redirect(base_url('admin/backup.php'));
    }

    redirect(base_url('admin/backup.php'));
}

$pageTitle = 'สำรองข้อมูล / นำเข้าข้อมูล';
require __DIR__ . '/../includes/header.php';
?>
<h3 class="mb-3">🗄️ สำรองข้อมูล (Export) / นำเข้าข้อมูล (Import)</h3>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-body d-flex flex-column">
                <h5>📤 Export ข้อมูลทั้งหมด</h5>
                <p class="text-muted small flex-grow-1">
                    ดาวน์โหลดไฟล์ .zip ที่รวมฐานข้อมูลทั้งหมด (ทักษะ, กรรมการ, ทีม, กลุ่มเกณฑ์/เกณฑ์ย่อย, การมอบหมาย, คะแนน ฯลฯ)
                    พร้อมไฟล์เกณฑ์การแข่งขันที่อัปโหลดไว้ ใช้สำหรับย้ายข้อมูลไปติดตั้งที่เครื่องอื่น หรือสำรองไว้ก่อน/หลังการแข่งขัน
                </p>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="export">
                    <button type="submit" class="btn btn-primary">📥 ดาวน์โหลดไฟล์สำรองข้อมูล (.zip)</button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card h-100 border-danger">
            <div class="card-body d-flex flex-column">
                <h5>📥 Import ข้อมูล</h5>
                <p class="text-muted small">นำเข้าไฟล์ .zip สำรองข้อมูลที่ Export มาจากระบบนี้ (เครื่องอื่น หรือครั้งก่อนหน้า)</p>
                <div class="alert alert-danger small">
                    ⚠️ <b>คำเตือน:</b> การ Import จะ<strong>ลบและแทนที่ข้อมูลปัจจุบันทั้งหมด</strong>ในระบบนี้
                    (ทักษะ กรรมการ ทีม คะแนน ไฟล์เกณฑ์ที่อัปโหลด) ด้วยข้อมูลจากไฟล์สำรองที่เลือก
                    <u>ไม่สามารถย้อนกลับได้</u> แนะนำให้ Export ข้อมูลปัจจุบันเก็บไว้ก่อน
                </div>
                <form method="post" enctype="multipart/form-data"
                      data-confirm="ยืนยันนำเข้าข้อมูล? ข้อมูลปัจจุบันทั้งหมดในระบบจะถูกลบและแทนที่ด้วยไฟล์นี้ และไม่สามารถย้อนกลับได้">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="import">
                    <div class="mb-3">
                        <label class="form-label">เลือกไฟล์สำรองข้อมูล (.zip)</label>
                        <input type="file" name="backup_file" accept=".zip" class="form-control" required>
                    </div>
                    <div class="form-check mb-3">
                        <input type="checkbox" class="form-check-input" name="confirm_overwrite" value="1" id="confirmOverwrite" required>
                        <label class="form-check-label" for="confirmOverwrite">ฉันเข้าใจว่าข้อมูลปัจจุบันทั้งหมดจะถูกลบและแทนที่ และไม่สามารถย้อนกลับได้</label>
                    </div>
                    <button type="submit" class="btn btn-danger">⚠️ นำเข้าและแทนที่ข้อมูลทั้งหมด</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
