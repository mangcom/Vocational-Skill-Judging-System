<?php
declare(strict_types=1);

/** รัน SQL โดยไม่ล้มทั้ง migration ถ้า error เป็นเรื่อง column/index มีอยู่แล้วหรือไม่มีอยู่ (ทำให้ migrate.php รันซ้ำได้อย่างปลอดภัย) */
function safe_exec(PDO $pdo, string $sql): void
{
    try {
        $pdo->exec($sql);
    } catch (PDOException $e) {
        $code = $e->errorInfo[1] ?? null; // MySQL/MariaDB error code
        // 1005/121 = ชนกับชื่อ FOREIGN KEY/INDEX ที่มีอยู่แล้ว (มักเกิดตอนรัน ADD CONSTRAINT ซ้ำ)
        $ignorable = [1005, 1060, 1061, 1091, 1068, 121]; // duplicate column, duplicate key name, can't drop (doesn't exist), multiple primary key, duplicate FK name
        if (!in_array($code, $ignorable, true)) {
            throw $e;
        }
    }
}

/** เพิ่ม FOREIGN KEY เฉพาะเมื่อยังไม่มี constraint ชื่อนี้ในตาราง (กัน error ซ้ำตอนรัน migrate.php หลายรอบ) */
function add_fk_if_missing(PDO $pdo, string $table, string $constraintName, string $definitionSql): void
{
    $stmt = $pdo->prepare('
        SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?
    ');
    $stmt->execute([$table, $constraintName]);
    if ($stmt->fetch()) {
        return;
    }
    $pdo->exec("ALTER TABLE {$table} ADD CONSTRAINT {$constraintName} {$definitionSql}");
}

/**
 * สร้าง/อัปเดตโครงสร้างตารางทั้งหมด และ seed ข้อมูลเริ่มต้น (ทักษะ/เกณฑ์/ทีมตัวอย่าง + บัญชี Admin)
 * เรียกได้ทั้งจาก migrate.php (ตอน container start) และจากหน้า /install.php (ตอนติดตั้งผ่านเว็บ)
 * ปลอดภัยที่จะเรียกซ้ำหลายครั้ง (idempotent)
 */
function run_migrations(PDO $pdo): void
{
$pdo->exec("CREATE TABLE IF NOT EXISTS divisions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(30) NOT NULL UNIQUE,
    name VARCHAR(200) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    description TEXT NULL,
    criteria_file_path VARCHAR(255) NULL,
    criteria_file_name VARCHAR(255) NULL,
    criteria_file_uploaded_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
safe_exec($pdo, "ALTER TABLE divisions ADD COLUMN description TEXT NULL");
safe_exec($pdo, "ALTER TABLE divisions ADD COLUMN criteria_file_path VARCHAR(255) NULL");
safe_exec($pdo, "ALTER TABLE divisions ADD COLUMN criteria_file_name VARCHAR(255) NULL");
safe_exec($pdo, "ALTER TABLE divisions ADD COLUMN criteria_file_uploaded_at TIMESTAMP NULL");

$pdo->exec("CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    role ENUM('admin','judge') NOT NULL DEFAULT 'judge',
    position ENUM('ประธานกรรมการ','รองประธานกรรมการ','กรรมการ','กรรมการและเลขานุการ','ผู้ช่วยกรรมการและเลขานุการ') NOT NULL DEFAULT 'กรรมการ',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
safe_exec($pdo, "ALTER TABLE users ADD COLUMN position ENUM('ประธานกรรมการ','รองประธานกรรมการ','กรรมการ','กรรมการและเลขานุการ','ผู้ช่วยกรรมการและเลขานุการ') NOT NULL DEFAULT 'กรรมการ' AFTER role");

$pdo->exec("CREATE TABLE IF NOT EXISTS skills (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(20) NOT NULL UNIQUE,
    name VARCHAR(150) NOT NULL,
    max_score DECIMAL(6,2) NOT NULL DEFAULT 0,
    sort_order INT NOT NULL DEFAULT 0,
    is_bonus TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS criteria (
    id INT AUTO_INCREMENT PRIMARY KEY,
    skill_id INT NOT NULL,
    code VARCHAR(20) NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    max_score DECIMAL(6,2) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_criteria_skill FOREIGN KEY (skill_id) REFERENCES skills(id) ON DELETE CASCADE,
    UNIQUE KEY uq_skill_code (skill_id, code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS teams (
    id INT AUTO_INCREMENT PRIMARY KEY,
    team_no INT NOT NULL UNIQUE,
    team_name VARCHAR(150) NOT NULL,
    problem_set VARCHAR(150) DEFAULT NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS judge_skills (
    judge_id INT NOT NULL,
    skill_id INT NOT NULL,
    PRIMARY KEY (judge_id, skill_id),
    CONSTRAINT fk_js_judge FOREIGN KEY (judge_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_js_skill FOREIGN KEY (skill_id) REFERENCES skills(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS judge_team_assignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    judge_id INT NOT NULL,
    team_id INT NOT NULL,
    assigned_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_judge_team (judge_id, team_id),
    CONSTRAINT fk_jta_judge FOREIGN KEY (judge_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_jta_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
    CONSTRAINT fk_jta_assigned_by FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS scores (
    id INT AUTO_INCREMENT PRIMARY KEY,
    judge_id INT NOT NULL,
    team_id INT NOT NULL,
    criteria_id INT NOT NULL,
    score DECIMAL(6,2) NOT NULL DEFAULT 0,
    note VARCHAR(500) DEFAULT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_judge_team_criteria (judge_id, team_id, criteria_id),
    CONSTRAINT fk_scores_judge FOREIGN KEY (judge_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_scores_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
    CONSTRAINT fk_scores_criteria FOREIGN KEY (criteria_id) REFERENCES criteria(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// --- seed: divisions (ประเภทการแข่งขัน) ---
$divisionsSeed = [
    ['code' => 'POS-DEVOPS', 'name' => 'ปวส. - ชุดโจทย์จับฉลาก DevOps', 'sort_order' => 1],
    ['code' => 'POC-HR', 'name' => 'ปวช. - ทักษะการพัฒนาระบบประเมินบุคลากรด้วยระบบเทคโนโลยีสารสนเทศสมัยใหม่', 'sort_order' => 2],
    ['code' => 'POS-DEVOPS-NOIOT', 'name' => 'ปวส. - ทักษะเทคโนโลยีสารสนเทศด้านโปรแกรมและเครือข่ายด้วยกระบวนการ DevOps (ไม่มี IoT)', 'sort_order' => 3],
];
$checkDivision = $pdo->prepare('SELECT id FROM divisions WHERE code = ?');
$insDivision = $pdo->prepare('INSERT INTO divisions (code, name, sort_order) VALUES (?, ?, ?)');
$divisionIds = [];
foreach ($divisionsSeed as $d) {
    $checkDivision->execute([$d['code']]);
    $row = $checkDivision->fetch();
    if ($row) {
        $divisionIds[$d['code']] = (int)$row['id'];
    } else {
        $insDivision->execute([$d['code'], $d['name'], $d['sort_order']]);
        $divisionIds[$d['code']] = (int)$pdo->lastInsertId();
        echo "[migrate] created division {$d['code']} {$d['name']}\n";
    }
}
$devopsDivisionId = $divisionIds['POS-DEVOPS'];
$hrDivisionId = $divisionIds['POC-HR'];
$devopsNoIotDivisionId = $divisionIds['POS-DEVOPS-NOIOT'];

// --- schema upgrade: ผูก skills และ teams เข้ากับ division (รองรับหลายประเภทการแข่งขันในระบบเดียว) ---
safe_exec($pdo, "ALTER TABLE skills ADD COLUMN division_id INT NULL AFTER id");
$pdo->exec("UPDATE skills SET division_id = {$devopsDivisionId} WHERE division_id IS NULL");
safe_exec($pdo, "ALTER TABLE skills MODIFY COLUMN division_id INT NOT NULL");
safe_exec($pdo, "ALTER TABLE skills DROP INDEX code");
safe_exec($pdo, "ALTER TABLE skills ADD UNIQUE KEY uq_division_code (division_id, code)");
add_fk_if_missing($pdo, 'skills', 'fk_skills_division', 'FOREIGN KEY (division_id) REFERENCES divisions(id) ON DELETE CASCADE');

safe_exec($pdo, "ALTER TABLE teams ADD COLUMN division_id INT NULL AFTER id");
$pdo->exec("UPDATE teams SET division_id = {$devopsDivisionId} WHERE division_id IS NULL");
safe_exec($pdo, "ALTER TABLE teams MODIFY COLUMN division_id INT NOT NULL");
safe_exec($pdo, "ALTER TABLE teams DROP INDEX team_no");
safe_exec($pdo, "ALTER TABLE teams ADD UNIQUE KEY uq_division_teamno (division_id, team_no)");
add_fk_if_missing($pdo, 'teams', 'fk_teams_division', 'FOREIGN KEY (division_id) REFERENCES divisions(id) ON DELETE CASCADE');

// --- seed: admin user (ใช้เฉพาะตอนยังไม่มีผู้ใช้ชื่อนี้ — การตั้งค่าบัญชี Admin จริงทำผ่าน /install.php) ---
$adminUser = getenv('ADMIN_DEFAULT_USERNAME') ?: 'admin';
$adminPass = getenv('ADMIN_DEFAULT_PASSWORD') ?: 'Admin@2569';

$stmt = $pdo->prepare('SELECT id FROM users WHERE username = ?');
$stmt->execute([$adminUser]);
if (!$stmt->fetch()) {
    $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role) VALUES (?, ?, ?, ?)')
        ->execute([$adminUser, password_hash($adminPass, PASSWORD_DEFAULT), 'ผู้ดูแลระบบ', 'admin']);
    echo "[migrate] created default admin user '{$adminUser}'\n";
}

// --- seed: skills + criteria (preload จากเกณฑ์การตรวจ ชุดโจทย์จับฉลาก DevOps ปวส.) ---
$skillsSeed = [
    [
        'code' => 'S1', 'name' => 'ส่วนที่ 1 Network ตั้งค่าพร้อมทั้งทดสอบอุปกรณ์เครือข่าย', 'max_score' => 10, 'sort_order' => 1, 'is_bonus' => 0,
        'criteria' => [
            ['1.1', 'VLAN Configuration', 'มี VLAN server, dev, guest เท่านั้น และไม่ใช่ Default VLAN (VLAN ละ 0.33)', 1],
            ['1.2.1', 'Inter-VLAN Routing — ตั้งค่า IP Interface', 'ตั้งค่า IP Interface ครบทั้ง 3 VLAN (Server, Dev, Guest) — ไม่ครบได้ 0', 1],
            ['1.2.2', 'Inter-VLAN Routing — Routing ระหว่าง VLAN', 'ทำ Routing ระหว่าง VLAN ได้ครบทั้งสาม — ไม่ครบได้ 0', 1],
            ['1.3.1', 'DHCP — แจกได้ 1 VLAN', 'แจก DHCP ได้ 1 VLAN (VLAN Dev หรือ Server)', 1],
            ['1.3.2', 'DHCP — แจกได้ 2 VLAN', 'แจก DHCP ได้ 2 VLAN (VLAN Dev และ Server)', 1],
            ['1.3.3', 'DHCP — Trusted Port', 'ให้ Switch ยอมรับการใช้งาน DHCP Server เฉพาะ Port ที่กำหนดได้', 1],
            ['1.4', 'การทำ NAT', 'ใช้ NAT เพื่อให้อุปกรณ์เชื่อมต่อเครือข่ายที่จัดเตรียมได้ติดต่อกับเครื่องของกรรมการได้ — NAT ได้ทุก VLAN เพื่อใช้งานเครือข่ายภายนอก', 1],
            ['1.5.1', 'Standard ACL', 'การทำ ACL ควบคุมการสื่อสารภายในเครือข่าย', 1],
            ['1.5.2', 'Extended ACL', 'การทำ ACL อนุญาตเฉพาะ Service ที่กำหนด', 1],
            ['1.5.3', 'ACL ป้องกันการเข้าถึง Server', 'การทำ ACL ป้องกันการเข้าถึง Server', 1],
        ],
    ],
    [
        'code' => 'S2', 'name' => 'ส่วนที่ 2 ตั้งค่าเครือข่ายตามผังระบบเครือข่าย', 'max_score' => 20, 'sort_order' => 2, 'is_bonus' => 0,
        'criteria' => [
            ['2.1.1', 'ติดตั้งระบบ Virtualization', 'ติดตั้งระบบ Virtualization เช่น Proxmox, VirtualBox, VMware ได้ มี GUI ใช้งานได้', 1],
            ['2.1.2', 'สร้าง VM ตามแผนผัง', 'สร้าง VM ได้ตามแผนผัง มีโครงสร้างตามโจทย์', 1],
            ['2.1.3', 'ตั้งค่า IP Virtualization', 'การตั้งค่า IP Address Virtualization ตามที่คณะกรรมการกำหนด', 1],
            ['2.2.1', 'ติดตั้ง Linux Server', 'ติดตั้ง Linux Server บน VM โดย ISO ได้แก่ Ubuntu, Debian ได้และบูตใช้งานได้ และเปิดอัตโนมัติเมื่อ Virtualization ดับแล้วเปิดขึ้นมาใหม่', 1],
            ['2.2.2', 'ตั้งค่า IP Static/DHCP', 'ตั้งค่า IP แบบ Static / DHCP ได้ถูกต้องตามผังที่กรรมการกำหนดให้', 1],
            ['2.2.3', 'ตั้งค่า hostname/Domain', 'ตั้งค่า hostname และ Domain name ตามที่คณะกรรมการกำหนด', 1],
            ['2.3.1', 'เปิดบริการ SSH', 'เปิดบริการ SSH', 1],
            ['2.3.2', 'เพิ่ม User', 'เพิ่ม User ตามที่กรรมการกำหนดได้ อย่างน้อย 2 User', 1],
            ['2.3.3', 'Remote ตาม User/Port', 'Remote จากเครื่องอื่นใน Network เข้ามาได้ด้วย user และ Port ที่กรรมการกำหนด', 1],
            ['2.3.4', 'ตั้งค่า Firewall', 'ตั้งค่า firewall (UFW / firewalld) เพื่อเปิดพอร์ตบริการได้ ตามที่กรรมการกำหนด', 1],
            ['2.4.1', 'ติดตั้ง Docker', 'ติดตั้ง Docker ได้และใช้งานได้ เช่น docker ps, run, exec', 1],
            ['2.4.2', 'ติดตั้ง GitLab', 'ติดตั้ง GitLab ได้ใน container หรือ VM โดยเข้าเว็บได้', 1],
            ['2.4.3', 'สร้าง User ใน GitLab', 'สร้าง User ใน Gitlab และเข้าหน้า UI ได้ (รอพิจารณา)', 1],
            ['2.5.1', 'สร้าง Dockerfile', 'สร้างและใช้งาน Dockerfile สำหรับแอป Node/เว็บที่พัฒนา ได้', 1],
            ['2.5.2', 'Reverse Proxy (No IoT)', 'ใช้ reverse proxy กับ host และ domain ของระบบที่พัฒนาได้ (registry, gitlab, frontend, backend) เช่น https://gitlab.mydomain.lab', 1],
            ['2.5.3', 'Docker Compose หลายบริการ', 'ใช้ docker-compose.yaml สำหรับบริการมากกว่า 1 (เช่น app + db) ใน 1 compose', 1],
            ['2.6', 'กำหนด CI/CD Pipeline', 'สร้าง .gitlab-ci.yml เชื่อมโยงกับการกำหนด State ครบทั้ง 4 ขั้นตอน Build, Test, Push ขึ้น Registry ของตนเอง, Deploy ไปยัง Server ของตนเอง (เขียนคำสั่งได้ถูกต้อง ส่วนจะทำงานได้หรือไม่ ไม่นำมาพิจารณา)', 1],
            ['2.7.1', 'Pipeline Trigger', 'Push code แล้วเกิด Trigger ได้จริง', 0.5],
            ['2.7.2', 'Pipeline ครบ 4 Stage', 'กำหนด Stage ครบ 4 ขั้นตอน (build, test, push ขึ้น registry, deploy) และทำงานได้จริงทุกขั้นตอน (ถ้าได้อย่างใดอย่างหนึ่งจะไม่ได้คะแนน ไม่มีการหารเฉลี่ย)', 2],
            ['2.7.3', 'Build image สำเร็จ', 'ระบบสามารถ build และสร้าง image ได้', 0.5],
        ],
    ],
    [
        'code' => 'S3', 'name' => 'ส่วนที่ 3 ติดตั้งและทดสอบ MQTT Server, Node-RED, เชื่อมต่อ Hardware, แสดงผลบน Dashboard', 'max_score' => 10, 'sort_order' => 3, 'is_bonus' => 0,
        'criteria' => [
            ['3.1', 'ติดตั้งและตั้งค่า MQTT Server + Node-RED', 'ติดตั้ง MQTT Server และตั้งค่าการเข้าถึง (ACL/Auth) ได้', 1],
            ['3.2', 'ทดสอบ MQTT Client', 'ทดสอบ MQTT Client ได้ (เช่น MQTT Explorer หรือ Node-RED MQTT In/Out) — Pub/Sub สำเร็จตาม Topic ที่กำหนด', 1],
            ['3.3', 'Hardware Integration (ESP32 + Sensor)', 'Hardware Pub ค่า payload ไปยัง Topic ที่กำหนด และ Hardware Sub ค่าเพื่อตอบสนองคำสั่งจาก Server', 1],
            ['3.4', 'Software Interaction & Database Logging', 'Software (Node-RED หรือ Script) Sub ข้อมูลจาก Topic โดยบันทึกข้อมูลลงฐานข้อมูล (เช่น MySQL, MariaDB, SQLite)', 1],
            ['3.5.1', 'Node-RED Dashboard', 'มีใช้ Node-RED Dashboard แสดงข้อมูลแบบเรียลไทม์ โดยมีการแจ้งเตือน/แสดงผลเมื่อ Hardware ตอบสนอง', 1],
            ['3.5.2', 'Web Dashboard ไม่น้อยกว่า 5 รูปแบบ', 'มีการแสดงผลผ่านหน้า Web Application ในรูปแบบ Dashboard ไม่น้อยกว่า 5 รูปแบบที่แตกต่างกัน (กำหนดให้แสดงรูปแบบละ 1 หน้าเว็บ) ตามที่กรรมการกำหนด', 5],
        ],
    ],
    [
        'code' => 'S4', 'name' => 'ส่วนที่ 4 ซอฟต์แวร์และการประยุกต์', 'max_score' => 50, 'sort_order' => 4, 'is_bonus' => 0,
        'criteria' => [
            ['4.1.1', 'Flowchart: Login', 'จัดทำผังงานระบบการเข้าสู่ระบบ (Login Flow)', 1],
            ['4.1.2', 'Flowchart: ผู้ดูแลระบบ', 'จัดทำผังงานกระบวนการของผู้ดูแลระบบ', 1],
            ['4.1.3', 'Flowchart: ผู้ใช้งานระดับ 1', 'จัดทำผังงานกระบวนการของผู้ใช้งานระดับ 1', 1],
            ['4.1.4', 'Flowchart: ผู้ใช้งานระดับ 2', 'จัดทำผังงานกระบวนการของผู้ใช้งานระดับ 2', 1],
            ['4.1.5', 'Use Case Diagram ภาพรวม', 'จัดทำผังงานภาพรวมของระบบทั้งหมด (Usecase Diagram)', 1],
            ['4.2.1', 'ฐานข้อมูลอย่างน้อย 3 ตาราง', 'มีการสร้างฐานข้อมูลอย่างน้อย 3 ตาราง', 1],
            ['4.2.2', 'ความสัมพันธ์ตาราง', 'ตารางฐานข้อมูลมีความสัมพันธ์กันอย่างเหมาะสม (Relational Design)', 1],
            ['4.2.3', 'เลือกใช้ Data Type เหมาะสม', 'มีการเลือกใช้ประเภทข้อมูล (Data Type) ได้เหมาะสมกับชนิดของข้อมูล', 1],
            ['4.2.4', 'Class Diagram', 'แสดง Class Diagram ที่สามารถอธิบายโครงสร้างได้อย่างถูกต้องและสมบูรณ์', 1],
            ['4.2.5', 'ตั้งชื่อตาราง/ฟิลด์', 'มีการตั้งชื่อตารางและฟิลด์สอดคล้องกับวัตถุประสงค์ของระบบ และเป็นไปตามมาตรฐานการเขียนชื่อทางเทคนิค', 1],
            ['4.3.1', 'Layout ผู้ดูแลระบบ', 'มี Template Layout สำหรับหน้าจอผู้ดูแลระบบ', 1],
            ['4.3.2', 'Layout ผู้ใช้งานระดับ 1', 'มี Template Layout สำหรับหน้าจอผู้ใช้งานระดับ 1', 1],
            ['4.3.3', 'Layout ผู้ใช้งานระดับ 2', 'มี Template Layout สำหรับหน้าจอผู้ใช้งานระดับ 2', 1],
            ['4.3.4', 'Layout Dashboard/Overview', 'มี Template Layout สำหรับหน้าจอหลักของระบบ (Dashboard/Overview)', 1],
            ['4.3.5', 'Layout Login Screen', 'มี Template Layout สำหรับหน้าจอเข้าสู่ระบบ (Login Screen)', 1],
            ['4.4.1', 'ออกแบบ API ตามหลัก RESTful', 'ออกแบบ API ตามหลัก RESTful (เช่น ใช้ GET, POST, PUT/Patch, DELETE อย่างเหมาะสม)', 1],
            ['4.4.2', 'ตั้งชื่อ Endpoint สื่อความหมาย', 'ตั้งชื่อ Endpoint สื่อความหมาย และมีความสอดคล้องกัน เช่น /api/teachers, /api/scores/{id}', 1],
            ['4.4.3', 'ตัวอย่าง Response Body', 'มีตัวอย่างรูปแบบ Response Body ที่สอดคล้องกับ HTTP Status Code อย่างเหมาะสม', 1],
            ['4.4.4', 'ออกแบบรองรับ Error', 'มีการออกแบบรองรับกรณีเกิด Error เช่น Unauthorized (401), Not Found (404)', 1],
            ['4.4.5', 'แนวทางความปลอดภัย API', 'มีแนวทางด้านความปลอดภัยของ API เช่น JWT Token หรือการตรวจสอบสิทธิ์การเข้าถึง', 1],
            ['4.5.1', 'API แบบ GET', 'สร้าง API แบบ GET พร้อมรับค่า (Query หรือ Path Parameters)', 1],
            ['4.5.2', 'API แบบ POST', 'สร้าง API แบบ POST ที่รับข้อมูลจาก Body อย่างถูกต้อง', 1],
            ['4.5.3', 'API อัปโหลดไฟล์', 'สร้าง API สำหรับอัปโหลดไฟล์', 1],
            ['4.5.4', 'API Login ด้วย JWT', 'สร้าง API Login โดยใช้ JWT Token (ซึ่งมีการลงนาม(Signing)เพื่อความปลอดภัย และใช้สำหรับตรวจสอบสิทธิ์ในการเข้าถึงระบบ)', 1],
            ['4.5.5', 'Exception Handling', 'มีการจัดการข้อผิดพลาด (Exception Handling) เช่น ตรวจสอบข้อมูลไม่ครบ, ไม่พบข้อมูล', 1],
            ['4.5.6', 'รูปแบบ Response เหมาะสม', 'API ส่ง Response ที่เหมาะสม เช่น JSON มี status, message เพิ่มเติม, data อย่างเป็นระบบ', 1],
            ['4.5.7', 'คำอธิบาย API', 'มีคำอธิบาย API และการเรียกใช้แต่ละ Service (GET, POST, PUT/PATCH, DELETE) ครบอย่างน้อย Method ละ 1 API', 1],
            ['4.5.8', 'ระบุ parameter ชัดเจน', 'ระบุข้อมูลที่ใช้ส่งในแต่ละ API เช่น query, path, body อย่างชัดเจน', 1],
            ['4.5.9', 'โครงสร้างโค้ด Back-end', 'โค้ด Back-end มีโครงสร้างดี อ่านง่าย โดยใช้ router หรือ async/await อย่างเหมาะสม', 1],
            ['4.5.10', 'ความปลอดภัย API', 'API มีความปลอดภัย และมีระบบตรวจสอบสิทธิ์ก่อนเข้าถึงข้อมูล', 1],
            ['4.6.1', 'Layout/Responsive', 'มี Layout ใช้งานง่าย มี Responsive', 1],
            ['4.6.2', 'Login/Authentication ด้วย JWT', 'มีหน้าจอ ระบบ Login หรือ Authentication ด้วย JWT ที่ใช้งานได้จริง', 1],
            ['4.6.3', 'แยก Login ตามระดับผู้ใช้', 'แยกระบบ Login ของกลุ่มผู้ใช้งานแต่ละระดับได้ ด้วย JWT', 1],
            ['4.6.4', 'เพิ่มข้อมูลตามฟอร์ม', 'มีการเพิ่มข้อมูลได้สมบูรณ์ตามแบบฟอร์มที่ออกแบบ', 1],
            ['4.6.5', 'แนบไฟล์พร้อมเพิ่มข้อมูล', 'มีการแนบไฟล์พร้อมกับเพิ่มข้อมูลได้สมบูรณ์', 1],
            ['4.6.6', 'แก้ไขและปรับปรุงข้อมูล', 'มีการแก้ไขและปรับปรุงข้อมูลได้', 1],
            ['4.6.7', 'แก้ไขไฟล์แนบ', 'มีการแก้ไขไฟล์แนบที่เกี่ยวข้องกับข้อมูลได้', 1],
            ['4.6.8', 'แจ้งเตือนการลบข้อมูล', 'มีแจ้งเตือนการลบข้อมูลได้', 1],
            ['4.6.9', 'แสดงข้อมูลตามสิทธิ์', 'มีการแสดงข้อมูลตามสิทธิ์ของผู้ใช้แต่ละระดับได้', 1],
            ['4.6.10', 'ค้นหา/Filter ตามสิทธิ์', 'มีระบบค้นหา/Filter ข้อมูลในหน้า UI ตามสิทธิ์ผู้ใช้งานแต่ละระดับ', 1],
            ['4.6.11', 'แบ่งหน้าแสดงข้อมูล', 'มีระบบการแบ่งหน้าแสดงข้อมูล ในหน้า UI ตามสิทธิ์ผู้ใช้งานแต่ละระดับ', 1],
            ['4.6.12', 'Validate ฝั่ง Frontend', 'มีการ Validate ข้อมูล ฝั่ง Frontend ก่อนส่งไปยัง Backend', 1],
            ['4.6.13', 'Error Handling ฝั่ง Frontend', 'มีการเขียนโปรแกรมที่มีการทำ Error Handling ในฝั่ง Frontend (ข้อความแจ้งข้อผิดพลาดที่ผู้ใช้งานเข้าใจง่าย)', 1],
            ['4.6.14', 'แสดงข้อความ Error จาก Backend', 'มีการเขียนโปรแกรมที่มีการแสดงข้อความผิดพลาด ที่เกิดจาก Error ฝั่ง Backend (การแปลงรหัสข้อผิดพลาดเป็นข้อความที่เหมาะสม)', 1],
            ['4.6.15', 'Dockerfile สำหรับ Backend', 'มีการสร้าง Dockerfile สำหรับ Backend', 1],
            ['4.6.16', 'Unit/Integration Test', 'มี Test ขั้นพื้นฐานใน Pipeline (Unit Testing / Integration Testing) อย่างละ 1 Test Case', 1],
            ['4.6.17', 'E2E Test', 'มี E to E Test เป็นการทดสอบ Work flow ตั้งแต่เริ่มต้นจนจบ (เช่น E2E-001 ผู้ใช้เข้าสู่ระบบสำเร็จ, E2E-002 ผู้ใช้ส่งข้อมูลสำเร็จ ตามที่กรรมการจัดการแข่งขันกำหนด)', 1],
            ['4.6.18', 'ใช้ Git และ Branch Strategy', 'มีการใช้ Git และ Branch Strategy ในการกำหนดบทบาท Backend / Frontend / DevOps (merge)', 1],
            ['4.6.19', 'ความสมบูรณ์ของระบบ', 'ความสมบูรณ์ของระบบทั้งหมด Application', 2],
        ],
    ],
    [
        'code' => 'S5', 'name' => 'ส่วนที่ 5 การนำเสนอ', 'max_score' => 10, 'sort_order' => 5, 'is_bonus' => 0,
        'criteria' => [
            ['5.1', 'ตอบคำถามจากคณะกรรมการ', 'การตอบคำถามจากคณะกรรมการ จากชุดคำถาม Backend, Frontend และแนวทางการแก้ปัญหา (5 นาที)', 3],
            ['5.2', 'นำเสนอการกระจายงาน', 'นำเสนอวิธีการทำงานร่วมกัน โดยกระจายการทำงานกันอย่างเหมาะสม', 3],
            ['5.3', 'บุคลิกภาพ', 'บุคลิกภาพในการนำเสนอ', 3],
            ['5.4', 'นำเสนอภายในเวลาที่กำหนด', 'การนำเสนอภายในระยะเวลาที่กำหนด', 1],
        ],
    ],
    [
        'code' => 'SB', 'name' => 'โจทย์พิเศษ (ใช้จัดอันดับเมื่อคะแนนรวมเท่ากัน ไม่รวมใน 100)', 'max_score' => 10, 'sort_order' => 6, 'is_bonus' => 1,
        'criteria' => [
            ['SB.1', 'จุดตรวจคะแนนที่ 1', 'แก้ไขข้อความนี้ให้ตรงกับโจทย์พิเศษที่จับฉลากได้ก่อนเริ่มแข่งขัน', 2],
            ['SB.2', 'จุดตรวจคะแนนที่ 2', 'แก้ไขข้อความนี้ให้ตรงกับโจทย์พิเศษที่จับฉลากได้ก่อนเริ่มแข่งขัน', 2],
            ['SB.3', 'จุดตรวจคะแนนที่ 3', 'แก้ไขข้อความนี้ให้ตรงกับโจทย์พิเศษที่จับฉลากได้ก่อนเริ่มแข่งขัน', 2],
            ['SB.4', 'จุดตรวจคะแนนที่ 4', 'แก้ไขข้อความนี้ให้ตรงกับโจทย์พิเศษที่จับฉลากได้ก่อนเริ่มแข่งขัน', 2],
            ['SB.5', 'จุดตรวจคะแนนที่ 5', 'แก้ไขข้อความนี้ให้ตรงกับโจทย์พิเศษที่จับฉลากได้ก่อนเริ่มแข่งขัน', 2],
        ],
    ],

    // ===== ปวส. DevOps (ไม่มี IoT) — เหมือน POS-DEVOPS แต่ไม่มีส่วนที่ 3 (IoT) เลย คะแนนส่วนที่ 1/2/4 เพิ่มขึ้นแทน ตามเอกสารทางการ =====
    [
        'division_id' => $devopsNoIotDivisionId, 'code' => 'S1', 'name' => 'ส่วนที่ 1 Network ตั้งค่าพร้อมทั้งทดสอบอุปกรณ์เครือข่าย', 'max_score' => 13, 'sort_order' => 1, 'is_bonus' => 0,
        'criteria' => [
            ['1.1', 'VLAN Configuration', 'มี VLAN server, dev, guest เท่านั้น และไม่ใช่ Default VLAN (VLAN ละ 0.33)', 1],
            ['1.2.1', 'Inter-VLAN Routing — ตั้งค่า IP Interface', 'ตั้งค่า IP Interface — มี IP ทั้ง VLAN server, dev, guest ไม่ครบได้ 0', 2],
            ['1.2.2', 'Inter-VLAN Routing — Routing ระหว่าง VLAN', 'ทำ Routing ระหว่าง VLAN ได้ครบทั้งสาม — ไม่ครบได้ 0', 1],
            ['1.3.1', 'DHCP — แจกได้ 1 VLAN', 'แจก DHCP ได้ 1 VLAN (VLAN Dev หรือ Server)', 1],
            ['1.3.2', 'DHCP — แจกได้ 2 VLAN', 'แจก DHCP ได้ 2 VLAN (VLAN Dev และ Server)', 1],
            ['1.3.3', 'DHCP — Trusted Port', 'ให้ Switch ยอมรับการใช้งาน DHCP Server เฉพาะ Port ที่กำหนดได้', 1],
            ['1.4', 'การทำ NAT', 'ใช้ NAT เพื่อให้อุปกรณ์เชื่อมต่อเครือข่ายที่จัดเตรียมได้ติดต่อกับเครื่องของกรรมการได้ — NAT ได้ทุก VLAN เพื่อใช้งานเครือข่ายภายนอก', 1],
            ['1.5.1', 'Standard ACL', 'การทำ ACL ควบคุมการสื่อสารภายในเครือข่าย', 1],
            ['1.5.2', 'Extended ACL', 'การทำ ACL อนุญาตเฉพาะ Service ที่กำหนด', 1],
            ['1.5.3', 'ACL ป้องกันการเข้าถึง Server', 'การทำ ACL ป้องกันการเข้าถึง Server', 1],
            ['1.5.4', 'ACL สำหรับ Management VLAN', 'การทำ ACL สำหรับ Management VLAN', 1],
            ['1.5.5', 'ป้องกัน Loop บน Switch', 'การตั้งค่าเพื่อป้องกัน Loop บน Switch', 1],
        ],
    ],
    [
        'division_id' => $devopsNoIotDivisionId, 'code' => 'S2', 'name' => 'ส่วนที่ 2 ตั้งค่าเครือข่ายตามผังระบบเครือข่าย', 'max_score' => 24, 'sort_order' => 2, 'is_bonus' => 0,
        'criteria' => [
            ['2.1.1', 'ติดตั้งระบบ Virtualization', 'ติดตั้งระบบ Virtualization เช่น Proxmox, VirtualBox, VMware ได้ มี GUI ใช้งานได้', 1],
            ['2.1.2', 'สร้าง VM ตามแผนผัง', 'สร้าง VM ได้ตามแผนผัง มีโครงสร้างตามโจทย์', 1],
            ['2.1.3', 'ตั้งค่า IP Virtualization', 'การตั้งค่า IP Address Virtualization ตามที่คณะกรรมการกำหนด', 1],
            ['2.2.1', 'ติดตั้ง Linux Server', 'ติดตั้ง Linux Server บน VM โดย ISO ได้แก่ Ubuntu, Debian ได้และบูตใช้งานได้ และเปิดอัตโนมัติเมื่อ Virtualization ดับแล้วเปิดขึ้นมาใหม่', 1],
            ['2.2.2', 'ตั้งค่า IP Static/DHCP', 'ตั้งค่า IP แบบ Static / DHCP ได้ถูกต้องตามผังที่กรรมการกำหนดให้', 1],
            ['2.2.3', 'ตั้งค่า hostname/Domain', 'ตั้งค่า hostname และ Domain name ตามที่คณะกรรมการกำหนด', 1],
            ['2.2.4', 'ติดตั้งและใช้งาน DNS Server', 'มีการติดตั้งและใช้งาน DNS Server ใน Network ของตนเอง บน Docker หรือ Linux Server ตามชื่อที่กรรมการกำหนด โดยเครื่องลูกข่ายสามารถเรียกใช้งาน DNS Server ได้ (ตั้งค่าบน Router หรือ Switch หรือ Hosts จะไม่ได้คะแนน)', 1],
            ['2.2.5', 'จำกัดการเข้าถึง DNS Server (ACL)', 'กำหนดการเข้าถึง DNS Server ใน Network โดยใช้ IP Address ที่กำหนดเท่านั้น (ACL)', 1],
            ['2.3.1', 'เปิดบริการ SSH', 'เปิดบริการ SSH', 1],
            ['2.3.2', 'เพิ่ม User', 'เพิ่ม User ตามที่กรรมการกำหนดได้ อย่างน้อย 2 User', 1],
            ['2.3.3', 'Remote ตาม User/Port', 'Remote จากเครื่องอื่นใน Network เข้ามาได้ด้วย user และ Port ที่กรรมการกำหนด', 1],
            ['2.3.4', 'ตั้งค่า Firewall', 'ตั้งค่า firewall (UFW / firewalld) เพื่อเปิดพอร์ตบริการได้ ตามที่กรรมการกำหนด', 1],
            ['2.3.5', 'sudo โดยไม่ต้องยืนยันรหัสผ่าน', 'กำหนดให้ user ที่ Login เข้ามาสามารถใช้คำสั่ง sudo -I โดยไม่ต้องยืนยันรหัสผ่าน', 1],
            ['2.4.1', 'ติดตั้ง Docker', 'ติดตั้ง Docker ได้และใช้งานได้ เช่น docker ps, run, exec', 1],
            ['2.4.2', 'ติดตั้ง GitLab', 'ติดตั้ง GitLab ได้ใน container หรือ VM โดยเข้าเว็บได้', 1],
            ['2.4.3', 'สร้าง User ใน GitLab', 'สร้าง User ใน Gitlab และเข้าหน้า UI ได้', 1],
            ['2.5.1', 'สร้าง Dockerfile', 'สร้างและใช้งาน Dockerfile สำหรับแอป Node/เว็บที่พัฒนา ได้', 1],
            ['2.5.2', 'Reverse Proxy สำหรับ HTTPS', 'ใช้ docker-compose.yaml สำหรับบริการ reverse proxy เพื่อใช้บริการ https (caddy, NPM)', 1],
            ['2.5.3', 'Reverse Proxy ตาม Domain', 'ใช้ reverse proxy กับ host และ domain ของระบบที่พัฒนาได้ (registry, gitlab, frontend, backend) เช่น https://gitlab.mydomain.lab', 1],
            ['2.5.4', 'Docker Compose หลายบริการ', 'ใช้ docker-compose.yaml สำหรับบริการมากกว่า 1 (เช่น app + db) ใน 1 compose', 1],
            ['2.6', 'กำหนด CI/CD Pipeline', 'สร้าง .gitlab-ci.yml เชื่อมโยงกับการกำหนด State ครบทั้ง 4 ขั้นตอน Build, Test, Push ขึ้น Registry ของตนเอง, Deploy ไปยัง Server ของตนเอง (พิจารณาการเขียนคำสั่งได้ถูกต้อง)', 1],
            ['2.7.1', 'Pipeline Trigger', 'Push code แล้วเกิด Trigger ได้จริง', 0.5],
            ['2.7.2', 'Pipeline ครบ 4 Stage', 'กำหนด Stage ครบ 4 ขั้นตอน (build, test, push ขึ้น registry, deploy) และทำงานได้จริงทุกขั้นตอน (ถ้าได้อย่างใดอย่างหนึ่งจะไม่ได้คะแนน ไม่มีการหารเฉลี่ย)', 2],
            ['2.7.3', 'Build image สำเร็จ', 'ระบบสามารถ build และสร้าง image ได้', 0.5],
        ],
    ],
    [
        'division_id' => $devopsNoIotDivisionId, 'code' => 'S4', 'name' => 'ส่วนที่ 4 ซอฟต์แวร์และการประยุกต์', 'max_score' => 53, 'sort_order' => 3, 'is_bonus' => 0,
        'criteria' => [
            ['4.1.1', 'Flowchart: Login', 'จัดทำผังงานระบบการเข้าสู่ระบบ (Login Flow)', 1],
            ['4.1.2', 'Flowchart: ผู้ดูแลระบบ', 'จัดทำผังงานกระบวนการของผู้ดูแลระบบ', 1],
            ['4.1.3', 'Flowchart: ผู้ใช้งานระดับ 1', 'จัดทำผังงานกระบวนการของผู้ใช้งานระดับ 1', 1],
            ['4.1.4', 'Flowchart: ผู้ใช้งานระดับ 2', 'จัดทำผังงานกระบวนการของผู้ใช้งานระดับ 2', 1],
            ['4.1.5', 'Use Case Diagram ภาพรวม', 'จัดทำผังงานภาพรวมของระบบทั้งหมด (Usecase Diagram)', 1],
            ['4.2.1', 'ฐานข้อมูลอย่างน้อย 3 ตาราง', 'มีการสร้างฐานข้อมูลอย่างน้อย 3 ตาราง', 1],
            ['4.2.2', 'ความสัมพันธ์ตาราง', 'ตารางฐานข้อมูลมีความสัมพันธ์กันอย่างเหมาะสม (Relational Design)', 1],
            ['4.2.3', 'เลือกใช้ Data Type เหมาะสม', 'มีการเลือกใช้ประเภทข้อมูล (Data Type) ได้เหมาะสมกับชนิดของข้อมูล', 1],
            ['4.2.4', 'Class Diagram', 'แสดง Class Diagram ที่สามารถอธิบายโครงสร้างได้อย่างถูกต้องและสมบูรณ์', 1],
            ['4.2.5', 'ตั้งชื่อตาราง/ฟิลด์', 'มีการตั้งชื่อตารางและฟิลด์สอดคล้องกับวัตถุประสงค์ของระบบ และเป็นไปตามมาตรฐานการเขียนชื่อทางเทคนิค', 1],
            ['4.3.1', 'Layout ผู้ดูแลระบบ', 'มี Template Layout สำหรับหน้าจอผู้ดูแลระบบ', 1],
            ['4.3.2', 'Layout ผู้ใช้งานระดับ 1', 'มี Template Layout สำหรับหน้าจอผู้ใช้งานระดับ 1', 1],
            ['4.3.3', 'Layout ผู้ใช้งานระดับ 2', 'มี Template Layout สำหรับหน้าจอผู้ใช้งานระดับ 2', 1],
            ['4.3.4', 'Layout Dashboard/Overview', 'มี Template Layout สำหรับหน้าจอหลักของระบบ (Dashboard/Overview)', 1],
            ['4.3.5', 'Layout Login Screen', 'มี Template Layout สำหรับหน้าจอเข้าสู่ระบบ (Login Screen)', 1],
            ['4.4.1', 'ออกแบบ API ตามหลัก RESTful', 'ออกแบบ API ตามหลัก RESTful (เช่น ใช้ GET, POST, PUT/Patch, DELETE อย่างเหมาะสม)', 1],
            ['4.4.2', 'ตั้งชื่อ Endpoint สื่อความหมาย', 'ตั้งชื่อ Endpoint สื่อความหมาย และมีความสอดคล้องกัน เช่น /api/teachers, /api/scores/{id}', 1],
            ['4.4.3', 'ตัวอย่าง Response Body', 'มีตัวอย่างรูปแบบ Response Body ที่สอดคล้องกับ HTTP Status Code อย่างเหมาะสม', 1],
            ['4.4.4', 'ออกแบบรองรับ Error', 'มีการออกแบบรองรับกรณีเกิด Error เช่น Unauthorized (401), Not Found (404)', 1],
            ['4.4.5', 'แนวทางความปลอดภัย API', 'มีแนวทางด้านความปลอดภัยของ API เช่น JWT Token หรือการตรวจสอบสิทธิ์การเข้าถึง', 1],
            ['4.5.1', 'API แบบ GET', 'สร้าง API แบบ GET พร้อมรับค่า (Query หรือ Path Parameters)', 1],
            ['4.5.2', 'API แบบ POST', 'สร้าง API แบบ POST ที่รับข้อมูลจาก Body อย่างถูกต้อง', 1],
            ['4.5.3', 'API อัปโหลดไฟล์', 'สร้าง API สำหรับอัปโหลดไฟล์', 1],
            ['4.5.4', 'API Login ด้วย JWT', 'สร้าง API Login โดยใช้ JWT Token (ซึ่งมีการลงนาม(Signing)เพื่อความปลอดภัย และใช้สำหรับตรวจสอบสิทธิ์ในการเข้าถึงระบบ)', 1],
            ['4.5.5', 'Exception Handling', 'มีการจัดการข้อผิดพลาด (Exception Handling) เช่น ตรวจสอบข้อมูลไม่ครบ, ไม่พบข้อมูล', 1],
            ['4.5.6', 'รูปแบบ Response เหมาะสม', 'API ส่ง Response ที่เหมาะสม เช่น JSON มี status, message เพิ่มเติม, data อย่างเป็นระบบ', 1],
            ['4.5.7', 'คำอธิบาย API', 'มีคำอธิบาย API และการเรียกใช้แต่ละ Service (GET, POST, PUT/PATCH, DELETE) ครบอย่างน้อย Method ละ 1 API (เขียน Comment)', 1],
            ['4.5.8', 'ระบุ parameter ชัดเจน', 'ระบุข้อมูลที่ใช้ส่งในแต่ละ API เช่น query, path, body อย่างชัดเจน', 1],
            ['4.5.9', 'โครงสร้างโค้ด Back-end', 'โค้ด Back-end มีโครงสร้างดี อ่านง่าย โดยใช้ router หรือ async/await อย่างเหมาะสม', 1],
            ['4.5.10', 'ความปลอดภัย API', 'API มีความปลอดภัย และมีระบบตรวจสอบสิทธิ์ก่อนเข้าถึงข้อมูล', 1],
            ['4.6.1', 'Layout/Responsive', 'มี Layout ใช้งานง่าย มี Responsive', 1],
            ['4.6.2', 'Login/Authentication ด้วย JWT', 'มีหน้าจอ ระบบ Login หรือ Authentication ด้วย JWT ที่ใช้งานได้จริง', 1],
            ['4.6.3', 'แยก Login ตามระดับผู้ใช้', 'แยกระบบ Login ของกลุ่มผู้ใช้งานแต่ละระดับได้ ด้วย JWT', 1],
            ['4.6.4', 'เพิ่มข้อมูลตามฟอร์ม', 'มีการเพิ่มข้อมูลได้สมบูรณ์ตามแบบฟอร์มที่ออกแบบ', 1],
            ['4.6.5', 'แนบไฟล์พร้อมเพิ่มข้อมูล', 'มีการแนบไฟล์พร้อมกับเพิ่มข้อมูลได้สมบูรณ์', 1],
            ['4.6.6', 'แก้ไขและปรับปรุงข้อมูล', 'มีการแก้ไขและปรับปรุงข้อมูลได้', 1],
            ['4.6.7', 'แก้ไขไฟล์แนบ', 'มีการแก้ไขไฟล์แนบที่เกี่ยวข้องกับข้อมูลได้', 1],
            ['4.6.8', 'แจ้งเตือนการลบข้อมูล', 'มีแจ้งเตือนการลบข้อมูลได้', 1],
            ['4.6.9', 'ค้นหา/Filter ตามสิทธิ์', 'มีระบบค้นหา/Filter ข้อมูลในหน้า UI ตามสิทธิ์ผู้ใช้งานแต่ละระดับ', 1],
            ['4.6.10', 'แบ่งหน้าแสดงข้อมูล', 'มีระบบการแบ่งหน้าแสดงข้อมูล ในหน้า UI ตามสิทธิ์ผู้ใช้งานแต่ละระดับ', 1],
            ['4.6.11', 'Validate ฝั่ง Frontend', 'มีการ Validate ข้อมูล ฝั่ง Frontend ก่อนส่งไปยัง Backend', 1],
            ['4.6.12', 'Error Handling ฝั่ง Frontend', 'มีการเขียนโปรแกรมที่มีการทำ Error Handling ในฝั่ง Frontend (ข้อความแจ้งข้อผิดพลาดที่ผู้ใช้งานเข้าใจง่าย)', 1],
            ['4.6.13', 'แสดงข้อความ Error จาก Backend', 'มีการเขียนโปรแกรมที่มีการแสดงข้อความผิดพลาด ที่เกิดจาก Error ฝั่ง Backend (การแปลงรหัสข้อผิดพลาดเป็นข้อความที่เหมาะสม)', 1],
            ['4.6.14', 'Dockerfile สำหรับ Backend', 'มีการสร้าง Dockerfile สำหรับ Backend', 1],
            ['4.6.15', 'เขียน Pipeline config', 'เขียน Pipeline config ประกอบด้วย stages build, test, push, deploy ทำงานได้ทุก stages', 1],
            ['4.6.16', 'Pipeline อัตโนมัติเมื่อ Push', 'เขียน Pipeline ทำงานอัตโนมัติเมื่อมี Push ขึ้น Git Server (Auto Build/Test)', 1],
            ['4.6.17', 'Unit/Integration Test', 'มี Test ขั้นพื้นฐานใน Pipeline (Unit Testing / Integration Testing) อย่างละ 1 Test Case', 1],
            ['4.6.18', 'E2E Test', 'มี E to E Test เป็นการทดสอบ Work flow ตั้งแต่เริ่มต้นจนจบ (เช่น E2E-001 ผู้ใช้เข้าสู่ระบบสำเร็จ, E2E-002 ผู้ใช้ส่งข้อมูลสำเร็จ — กรรมการจัดการแข่งขันกำหนดเอง)', 2],
            ['4.6.19', 'ใช้ Git และ Branch Strategy', 'มีการใช้ Git และ Branch Strategy ในการกำหนดบทบาท Backend / Frontend / DevOps (merge)', 1],
            ['4.6.20', 'ความสมบูรณ์ของระบบ', 'ความสมบูรณ์ของระบบทั้งหมด Application', 3],
        ],
    ],
    [
        'division_id' => $devopsNoIotDivisionId, 'code' => 'S5', 'name' => 'ส่วนที่ 5 การนำเสนอ', 'max_score' => 10, 'sort_order' => 4, 'is_bonus' => 0,
        'criteria' => [
            ['5.1', 'ตอบคำถามจากคณะกรรมการ', 'การตอบคำถามจากคณะกรรมการ จากชุดคำถาม Backend, Frontend และแนวทางการแก้ปัญหา (5 นาที)', 3],
            ['5.2', 'นำเสนอการกระจายงาน', 'นำเสนอวิธีการทำงานร่วมกัน โดยกระจายการทำงานกันอย่างเหมาะสม', 3],
            ['5.3', 'บุคลิกภาพ', 'บุคลิกภาพในการนำเสนอ', 3],
            ['5.4', 'นำเสนอภายในเวลาที่กำหนด', 'การนำเสนอภายในระยะเวลาที่กำหนด', 1],
        ],
    ],
    [
        'division_id' => $devopsNoIotDivisionId, 'code' => 'SB', 'name' => 'โจทย์พิเศษ (ใช้จัดอันดับเมื่อคะแนนรวมเท่ากัน ไม่รวมใน 100)', 'max_score' => 10, 'sort_order' => 6, 'is_bonus' => 1,
        'criteria' => [
            ['SB.1', 'จุดตรวจคะแนนที่ 1', 'แก้ไขข้อความนี้ให้ตรงกับโจทย์พิเศษที่จับฉลากได้ก่อนเริ่มแข่งขัน', 2],
            ['SB.2', 'จุดตรวจคะแนนที่ 2', 'แก้ไขข้อความนี้ให้ตรงกับโจทย์พิเศษที่จับฉลากได้ก่อนเริ่มแข่งขัน', 2],
            ['SB.3', 'จุดตรวจคะแนนที่ 3', 'แก้ไขข้อความนี้ให้ตรงกับโจทย์พิเศษที่จับฉลากได้ก่อนเริ่มแข่งขัน', 2],
            ['SB.4', 'จุดตรวจคะแนนที่ 4', 'แก้ไขข้อความนี้ให้ตรงกับโจทย์พิเศษที่จับฉลากได้ก่อนเริ่มแข่งขัน', 2],
            ['SB.5', 'จุดตรวจคะแนนที่ 5', 'แก้ไขข้อความนี้ให้ตรงกับโจทย์พิเศษที่จับฉลากได้ก่อนเริ่มแข่งขัน', 2],
        ],
    ],

    // ===== ปวช. ทักษะการพัฒนาระบบประเมินบุคลากรด้วยระบบเทคโนโลยีสารสนเทศสมัยใหม่ (เกณฑ์ตามไฟล์ "รายการที่ 57") =====
    [
        'division_id' => $hrDivisionId, 'code' => 'P1', 'name' => 'ส่วนที่ 1 การออกแบบผังงาน (Flow Diagram)', 'max_score' => 5, 'sort_order' => 1, 'is_bonus' => 0,
        'criteria' => [
            ['1.1', 'ผังงานระบบการเข้าสู่ระบบ', 'Login Flow', 1],
            ['1.2', 'ผังงานกระบวนการของงานบุคลากร', '', 1],
            ['1.3', 'ผังงานกระบวนการของผู้รับการประเมิน', '', 1],
            ['1.4', 'ผังงานกระบวนการของกรรมการผู้ประเมิน', '', 1],
            ['1.5', 'ผังงานภาพรวมของระบบทั้งหมด', 'Usecase Diagram', 1],
        ],
    ],
    [
        'division_id' => $hrDivisionId, 'code' => 'P2', 'name' => 'ส่วนที่ 2 การออกแบบฐานข้อมูล (Database Design) พร้อม ER-Diagram', 'max_score' => 5, 'sort_order' => 2, 'is_bonus' => 0,
        'criteria' => [
            ['2.1', 'มีการสร้างฐานข้อมูลอย่างน้อย 3 ตาราง', '', 1],
            ['2.2', 'ตารางฐานข้อมูลมีความสัมพันธ์กันอย่างเหมาะสม', 'Relational Design', 1],
            ['2.3', 'มีการเลือกใช้ประเภทข้อมูล (Data Type) ได้เหมาะสมกับชนิดของข้อมูล', '', 1],
            ['2.4', 'แสดง Class Diagram ที่สามารถอธิบายโครงสร้างได้อย่างถูกต้อง และสมบูรณ์', '', 1],
            ['2.5', 'มีการตั้งชื่อตาราง และฟิลด์สอดคล้องกับวัตถุประสงค์ของระบบ และเป็นไปตามมาตรฐานการเขียนชื่อทางเทคนิค', 'Naming Conventions', 1],
        ],
    ],
    [
        'division_id' => $hrDivisionId, 'code' => 'P3', 'name' => 'ส่วนที่ 3 การออกแบบหน้าจอด้วยโปรแกรมสำเร็จรูป', 'max_score' => 5, 'sort_order' => 3, 'is_bonus' => 0,
        'criteria' => [
            ['3.1', 'Template Layout สำหรับหน้าจอเข้าสู่ระบบ', 'Login Screen', 1],
            ['3.2', 'Template Layout สำหรับหน้าจอหลักของระบบ', 'Dashboard/Overview', 1],
            ['3.3', 'Template Layout สำหรับหน้าจองานบุคลากร', '', 1],
            ['3.4', 'Template Layout สำหรับหน้าจอผู้เข้ารับการประเมิน', '', 1],
            ['3.5', 'Template Layout สำหรับหน้าจอกรรมการผู้ประเมิน', '', 1],
        ],
    ],
    [
        'division_id' => $hrDivisionId, 'code' => 'P4', 'name' => 'ส่วนที่ 4 การออกแบบ RESTful API สำหรับใช้งานในระบบ', 'max_score' => 5, 'sort_order' => 4, 'is_bonus' => 0,
        'criteria' => [
            ['4.1', 'ออกแบบ API ตามหลัก RESTful', 'เช่น ใช้ GET, POST, PUT, DELETE อย่างเหมาะสม', 1],
            ['4.2', 'ตั้งชื่อ Endpoint สื่อความหมาย และมีความสอดคล้องกัน', 'เช่น /api/teachers, /api/scores/{id}', 1],
            ['4.3', 'มีตัวอย่างรูปแบบ Response Body ที่สอดคล้องกับ HTTP Status Code อย่างเหมาะสม', '', 1],
            ['4.4', 'มีการออกแบบรองรับกรณีเกิด Error', 'เช่น Unauthorized (401), Not Found (404)', 1],
            ['4.5', 'มีแนวทางด้านความปลอดภัยของ API', 'เช่น JWT, Token หรือการตรวจสอบสิทธิ์การเข้าถึง', 1],
        ],
    ],
    [
        'division_id' => $hrDivisionId, 'code' => 'P5.1', 'name' => 'ส่วนที่ 5.1 พัฒนาระบบ: งานบุคลากร', 'max_score' => 14, 'sort_order' => 5, 'is_bonus' => 0,
        'criteria' => [
            ['5.1.1', 'เพิ่มหัวข้อการประเมินได้', '', 1],
            ['5.1.2', 'กำหนดช่วงเวลาเปิด/ปิดการประเมินได้', 'กำหนดวันเริ่ม - สิ้นสุด', 1],
            ['5.1.3', 'เพิ่มตัวชี้วัดในแต่ละหัวข้อได้', 'โดยประกอบด้วย ชื่อ, รายละเอียด, น้ำหนักคะแนน, และหลักฐานที่ใช้แนบ', 1],
            ['5.1.4.1', 'กำหนดรูปแบบการประเมินแบบตัวเลือก "มี/ไม่มี" ได้', '', 1],
            ['5.1.4.2', 'กำหนดรูปแบบการประเมิน แบบสเกลคะแนน 1-4 ได้ พร้อมคำอธิบายระดับ', 'ระดับ 1-4 ตามคำอธิบายในเอกสาร', 1],
            ['5.1.5', 'เพิ่มข้อมูลผู้รับการประเมินได้', '', 1],
            ['5.1.6', 'เพิ่มข้อมูลกรรมการผู้ประเมินได้', '', 1],
            ['5.1.7', 'คืนค่ารหัสผ่านของผู้รับการประเมินและกรรมการผู้ประเมินได้', 'Reset Password', 1],
            ['5.1.8', 'มอบหมายกรรมการให้ประเมินผู้รับการประเมินแต่ละคนได้', '', 1],
            ['5.1.9', 'กำหนดบทบาทของกรรมการได้', 'เช่น ประธาน / กรรมการร่วม', 1],
            ['5.1.10', 'แสดงผลสรุปการประเมินรายกรรมการ และรายผู้รับการประเมิน', 'แสดงตัวชี้วัดและคะแนน', 1],
            ['5.1.11', 'ติดตามสถานะการประเมินของกรรมการแต่ละคนได้', 'เช่น ยังไม่ได้ประเมิน, ประเมินและบันทึกร่างคะแนน, ยืนยันผลการประเมิน', 1],
            ['5.1.12', 'แสดงค่าร้อยละการบันทึกข้อมูลตามตัวชี้วัดของผู้รับการประเมินได้', '', 1],
            ['5.1.13', 'แสดงรายงานผลการประเมินรายบุคคลได้', '', 1],
        ],
    ],
    [
        'division_id' => $hrDivisionId, 'code' => 'P5.2', 'name' => 'ส่วนที่ 5.2 พัฒนาระบบ: ผู้รับการประเมิน', 'max_score' => 10, 'sort_order' => 6, 'is_bonus' => 0,
        'criteria' => [
            ['5.2.1', 'บังคับให้เปลี่ยนรหัสผ่านเมื่อเข้าใช้งานครั้งแรก', '', 1],
            ['5.2.2', 'แก้ไขข้อมูลส่วนตัวเบื้องต้น', '', 1],
            ['5.2.3', 'เพิ่มรายละเอียดประกอบการประเมินในแต่ละตัวชี้วัด', 'เพิ่มได้เพียง 1 รายการ = 1 คะแนน, มากกว่า 1 รายการ = 2 คะแนน', 2],
            ['5.2.4', 'แนบหลักฐานประกอบการประเมินได้', 'เช่น PDF, รูปภาพ หรือ URL', 1],
            ['5.2.5', 'กรอกคะแนนการประเมินของตนเองได้', '', 1],
            ['5.2.6', 'แก้ไขข้อมูลการประเมินตนเองในแต่ละตัวชี้วัด', '', 1],
            ['5.2.7', 'ดูความก้าวหน้าในการดำเนินงานของแต่ละตัวชี้วัด', '', 1],
            ['5.2.8', 'สามารถ Export ออกมาเป็นไฟล์ PDF ได้', '', 1],
            ['5.2.9', 'ดูความคิดเห็นของกรรมการที่ประเมินตนเองได้', '', 1],
        ],
    ],
    [
        'division_id' => $hrDivisionId, 'code' => 'P5.3', 'name' => 'ส่วนที่ 5.3 พัฒนาระบบ: กรรมการผู้ประเมิน', 'max_score' => 10, 'sort_order' => 7, 'is_bonus' => 0,
        'criteria' => [
            ['5.3.1', 'แสดงข้อมูลของผู้รับการประเมินที่ต้องประเมิน', '', 1],
            ['5.3.2', 'แสดงหัวข้อ ตัวชี้วัด รายละเอียดข้อมูล และหลักฐานของผู้รับการประเมิน', '', 1],
            ['5.3.3', 'แสดงคะแนนที่ผู้รับการประเมินประเมินตนเอง', '', 1],
            ['5.3.4', 'ให้คะแนนผู้รับการประเมินตามตัวชี้วัด', '', 1],
            ['5.3.5', 'กรรมการให้ความคิดเห็นสรุปโดยภาพรวมของการประเมิน', '', 1],
            ['5.3.6', 'แสดงผลลัพธ์การประเมินแต่ละตัวชี้วัด ในรูปแบบของตาราง และสรุปภาพรวมของผู้รับการประเมินรายบุคคล', '', 1],
            ['5.3.7', 'ลงนามการประเมิน', 'โดยแนบลายเซ็น', 1],
            ['5.3.8', 'แก้ไขข้อมูลร่างของคะแนนตามตัวชี้วัดและความคิดเห็นสรุป', '', 1],
            ['5.3.9', 'ลงนามการประเมิน', 'โดยแนบลายเซ็น', 1],
            ['5.3.10', 'ยืนยันและส่งผลการประเมิน', '', 1],
        ],
    ],
    [
        'division_id' => $hrDivisionId, 'code' => 'P6', 'name' => 'ส่วนที่ 6 พัฒนาระบบ Back-end การพัฒนา API', 'max_score' => 12, 'sort_order' => 8, 'is_bonus' => 0,
        'criteria' => [
            ['6.1', 'สร้าง API แบบ GET พร้อมรับค่า (Query หรือ Path Parameters) อย่างน้อย 1 Endpoint', '', 1],
            ['6.2', 'สร้าง API แบบ POST ที่รับข้อมูลจาก Body อย่างถูกต้อง อย่างน้อย 1 Endpoint', '', 1],
            ['6.3', 'สร้าง API แบบ PUT อย่างถูกต้อง อย่างน้อย 1 Endpoint', '', 1],
            ['6.4', 'สร้าง API แบบ DELETE อย่างถูกต้อง อย่างน้อย 1 Endpoint', '', 1],
            ['6.5', 'สร้าง API สำหรับอัปโหลดไฟล์', 'เช่น เอกสารประกอบการประเมิน', 1],
            ['6.6', 'สร้าง API Login โดยใช้ JWT Token', 'ซึ่งมีการลงนาม (Signing) เพื่อความปลอดภัย และใช้สำหรับตรวจสอบสิทธิ์ในการเข้าถึงระบบ', 1],
            ['6.7', 'มีการจัดการข้อผิดพลาด (Exception Handling)', 'เช่น ตรวจสอบข้อมูลไม่ครบ, ไม่พบข้อมูล', 1],
            ['6.8', 'API ส่ง Response ที่เหมาะสม', 'เช่น JSON มี status, message เพิ่มเติม, data อย่างเป็นระบบ', 1],
            ['6.9', 'มีคำอธิบาย API แต่ละ Service ตามข้อ 6.1-6.4', '', 1],
            ['6.10', 'ระบุ parameter ที่ใช้ในแต่ละ API', 'เช่น query, path, body อย่างชัดเจน', 1],
            ['6.11', 'โค้ด Back-end มีโครงสร้างดี แยกไฟล์ ใช้ router, async/await อย่างเหมาะสม', '', 1],
            ['6.12', 'API มีความปลอดภัย และมีระบบตรวจสอบสิทธิ์ก่อนเข้าถึงข้อมูล', '', 1],
        ],
    ],
    [
        'division_id' => $hrDivisionId, 'code' => 'P7', 'name' => 'ส่วนที่ 7 การทดสอบโปรแกรม (Finish Detail Design Process)', 'max_score' => 14, 'sort_order' => 9, 'is_bonus' => 0,
        'criteria' => [
            ['7.1', 'ทดสอบระบบ Login', 'เช่น ทดสอบ Username/Password ถูก-ผิด', 1],
            ['7.2', 'ทดสอบการ Insert ข้อมูล', 'เพิ่มข้อมูลใหม่ เช่น ผู้ใช้งานหรือแบบประเมิน', 1],
            ['7.3', 'ทดสอบการ Update ข้อมูล', 'แก้ไขข้อมูลเดิมแล้วตรวจสอบผลลัพธ์', 1],
            ['7.4', 'ดำเนินการ Validate ข้อมูลก่อนส่ง', 'เช่น ตรวจสอบช่องว่าง / รูปแบบอีเมล / ความยาว', 1],
            ['7.5', 'ทดสอบฟังก์ชันเฉพาะจุด', 'เช่น ฟังก์ชันคำนวณคะแนน, การเรียงข้อมูล', 1],
            ['7.6', 'ทดสอบ Service API อย่างน้อย 3 รายการ', 'เช่น POST, GET, PUT', 1],
            ['7.7', 'ใช้ Inspect/DevTools ตรวจสอบ Console และ Network ว่าไม่มี Error', '', 1],
            ['7.8', 'มีการใช้งาน GitLab Server', '', 2],
            ['7.9', 'สามารถแสดงผลของระบบในเครื่องคอมพิวเตอร์แม่ข่ายที่จัดเตรียมไว้ให้ได้', '', 2],
            ['7.10', 'ทดสอบการแสดงผลแบบ Responsive', 'ระดับสถานศึกษา/จังหวัด/ภาค รองรับ Desktop = 3 คะแนน; ระดับชาติ รองรับทั้งมือถือ (ผ่าน DevTools) และ Desktop = 1.5+1.5 คะแนน', 3],
        ],
    ],
    [
        'division_id' => $hrDivisionId, 'code' => 'P8', 'name' => 'ส่วนที่ 8 โจทย์พิเศษเพิ่มความท้าทาย', 'max_score' => 10, 'sort_order' => 10, 'is_bonus' => 0,
        'criteria' => [
            ['8.1', 'Function การแสดงข้อมูลบน Dashboard', '', 2],
            ['8.2', 'Function การตรวจสอบความถูกต้องของข้อมูล', 'Data Validation', 2],
            ['8.3', 'Function การสำรองและกู้คืนข้อมูล', 'Backup & Restore', 2],
            ['8.4', 'Function การ Backup/Restore', '', 2],
            ['8.5', 'Function การออกรายงาน', 'Reporting', 2],
        ],
    ],
    [
        'division_id' => $hrDivisionId, 'code' => 'P9', 'name' => 'ส่วนที่ 9 การนำเสนอผลงาน', 'max_score' => 10, 'sort_order' => 11, 'is_bonus' => 0,
        'criteria' => [
            ['9.1', 'การตอบคำถามจากคณะกรรมการ', 'จากชุดคำถาม Backend, Frontend และแนวทางการแก้ปัญหา', 3],
            ['9.2', 'นำเสนอวิธีการทำงานร่วมกัน', 'โดยกระจายการทำงานกันอย่างเหมาะสม', 3],
            ['9.3', 'บุคลิกภาพ', '', 3],
            ['9.4', 'การนำเสนอภายในระยะเวลาที่กำหนด', '', 1],
        ],
    ],
];

$checkSkill = $pdo->prepare('SELECT id FROM skills WHERE division_id = ? AND code = ?');
$insSkill = $pdo->prepare('INSERT INTO skills (division_id, code, name, max_score, sort_order, is_bonus) VALUES (?, ?, ?, ?, ?, ?)');
$checkCriteria = $pdo->prepare('SELECT id FROM criteria WHERE skill_id = ? AND code = ?');
$insCriteria = $pdo->prepare('INSERT INTO criteria (skill_id, code, name, description, max_score, sort_order) VALUES (?, ?, ?, ?, ?, ?)');

foreach ($skillsSeed as $skill) {
    $skillDivisionId = $skill['division_id'] ?? $devopsDivisionId;
    $checkSkill->execute([$skillDivisionId, $skill['code']]);
    $row = $checkSkill->fetch();
    if ($row) {
        $skillId = (int)$row['id'];
    } else {
        $insSkill->execute([$skillDivisionId, $skill['code'], $skill['name'], $skill['max_score'], $skill['sort_order'], $skill['is_bonus']]);
        $skillId = (int)$pdo->lastInsertId();
        echo "[migrate] created skill {$skill['code']} {$skill['name']}\n";
    }

    $order = 0;
    foreach ($skill['criteria'] as [$code, $name, $desc, $max]) {
        $order++;
        $checkCriteria->execute([$skillId, $code]);
        if (!$checkCriteria->fetch()) {
            $insCriteria->execute([$skillId, $code, $name, $desc, $max, $order]);
        }
    }
}

// --- seed: teams 1-5 ต่อ division ---
$checkTeam = $pdo->prepare('SELECT id FROM teams WHERE division_id = ? AND team_no = ?');
$insTeam = $pdo->prepare('INSERT INTO teams (division_id, team_no, team_name) VALUES (?, ?, ?)');
foreach ([$devopsDivisionId, $hrDivisionId, $devopsNoIotDivisionId] as $divId) {
    for ($i = 1; $i <= 5; $i++) {
        $checkTeam->execute([$divId, $i]);
        if (!$checkTeam->fetch()) {
            $insTeam->execute([$divId, $i, "ทีมที่ {$i}"]);
        }
    }
}

echo "[migrate] done\n";
}
