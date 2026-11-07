<?php
require __DIR__ . '/../app/bootstrap.php';

require_login();

$pdo = db();
$divisionId = (int)($_GET['division_id'] ?? 0);

$stmt = $pdo->prepare('SELECT name, criteria_file_path, criteria_file_name FROM divisions WHERE id = ?');
$stmt->execute([$divisionId]);
$division = $stmt->fetch();

if (!$division || !$division['criteria_file_path']) {
    http_response_code(404);
    exit('ไม่พบไฟล์เกณฑ์การแข่งขัน');
}

$fullPath = __DIR__ . '/../storage/uploads/criteria/' . basename($division['criteria_file_path']);
if (!is_file($fullPath)) {
    http_response_code(404);
    exit('ไม่พบไฟล์บนเซิร์ฟเวอร์');
}

$downloadName = $division['criteria_file_name'] ?: basename($fullPath);
$mime = mime_content_type($fullPath) ?: 'application/octet-stream';

header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . rawurlencode($downloadName) . '"');
header('Content-Length: ' . filesize($fullPath));
header('X-Content-Type-Options: nosniff');
readfile($fullPath);
exit;
