<?php
declare(strict_types=1);

require_once __DIR__ . '/env_file.php';

function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    // อ่านจากไฟล์ .env สด ๆ ก่อน (รองรับการแก้ไขค่าเชื่อมต่อผ่าน /install.php โดยไม่ต้องรีสตาร์ท container)
    // ถ้าไม่มีไฟล์/ไม่มีคีย์นั้น จะ fallback ไปที่ environment variable ตอน container start ตามเดิม
    $host = env_value('DB_HOST', 'db');
    $name = env_value('DB_NAME', 'judge_scoring');
    $user = env_value('DB_USER', 'judge_app');
    $pass = env_value('DB_PASSWORD', '');

    $dsn = "mysql:host={$host};dbname={$name};charset=utf8mb4";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}
