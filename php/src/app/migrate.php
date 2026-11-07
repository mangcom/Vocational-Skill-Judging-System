<?php
declare(strict_types=1);

require __DIR__ . '/db.php';

$pdo = db();

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

// --- seed: admin user ---
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
        'code' => 'S1', 'name' => 'ส่วนที่ 1 ระบบเครือข่าย', 'max_score' => 10, 'sort_order' => 1, 'is_bonus' => 0,
        'criteria' => [
            ['1.1', 'VLAN Server, Dev, Guest', 'ดูรายการ VLAN บน Switch — VLAN ละ 0.33 ถ้าครบ 3 VLAN ได้ 1 คะแนน มี VLAN ใช้งานอื่นเพิ่มได้ 0', 1],
            ['1.2.1', 'IP Interface', 'ดู Interface ของทั้ง 3 VLAN — IP ตรงแผ่นโจทย์ครบ 3 VLAN ไม่ครบได้ 0', 1],
            ['1.2.2', 'Routing ระหว่าง VLAN', 'จากเครื่อง Dev Ping Gateway ของ Server และ Guest แล้ว Ping VM2 — ถึงปลายทางครบ ไม่ครบได้ 0', 1],
            ['1.3.1', 'DHCP VLAN แรก', 'เครื่องใน VLAN Dev ขอ IP ใหม่ — ได้ IP, Gateway ตรงแผ่นโจทย์', 1],
            ['1.3.2', 'DHCP VLAN ที่สอง', 'เครื่องทดสอบใน VLAN Server ขอ IP ใหม่ — ได้ IP, Gateway ตรงแผ่นโจทย์', 1],
            ['1.3.3', 'DHCP Snooping', 'ดูค่าบน Switch ว่าเชื่อถือเฉพาะพอร์ตของ DHCP Server — เปิดใช้งาน และมี Trusted Port ตามผังของทีม', 1],
            ['1.4', 'NAT', 'จากเครื่องใน 3 VLAN สั่ง curl http://10.10.100.10:4873/-/ping — ได้ผล {} ครบ 3 VLAN', 1],
            ['1.5.1', 'Standard ACL', 'จาก Guest Ping เครื่อง Dev — ดูกฎไปไม่ถึง และกฎกรองจาก Source', 1],
            ['1.5.2', 'Extended ACL อนุญาตเฉพาะ Service', 'จาก Guest ทดสอบ VM2 พอร์ต 443, 1883 และพอร์ต SSH — 443 กับ 1883 ผ่าน ส่วน SSH ไม่ผ่าน', 1],
            ['1.5.3', 'Extended ACL ป้องกัน Server', 'จาก Guest และ Dev ทดสอบ SSH กับหน้าจัดการ Virtualization — Guest ไม่ผ่าน Dev ผ่าน', 1],
        ],
    ],
    [
        'code' => 'S2', 'name' => 'ส่วนที่ 2 Server และ DevOps', 'max_score' => 20, 'sort_order' => 2, 'is_bonus' => 0,
        'criteria' => [
            ['2.1.1', 'Virtualization', 'เปิดหน้า GUI — เข้าใช้งานได้', 1],
            ['2.1.2', 'VM ตามผัง', 'ดูรายการ VM — มี VM1 และ VM2 ทำงานอยู่', 1],
            ['2.1.3', 'IP Virtualization', 'เปิดด้วย IP ลงท้าย .5 — ตรงแผ่นโจทย์', 1],
            ['2.2.1', 'ติดตั้ง Linux และเปิดอัตโนมัติ', 'ดูตัวเลือก Start at boot หรือสั่งรีบูต Host — VM ทั้งสองเปิดเองหลัง Host เปิดใหม่', 1],
            ['2.2.2', 'IP ของ VM', 'สั่ง ip a — ตรงแผ่นโจทย์ทั้งสองเครื่อง', 1],
            ['2.2.3', 'Hostname และ Domain', 'สั่ง hostname -f — ตรงแผ่นโจทย์ทั้งสองเครื่อง', 1],
            ['2.3.1', 'เปิด SSH', 'สั่ง systemctl status ssh — บริการทำงานทั้งสองเครื่อง', 1],
            ['2.3.2', 'เพิ่ม User', 'ดู User ในเครื่อง — มี User ทั้ง 2 บัญชีตามแผ่นโจทย์', 1],
            ['2.3.3', 'Remote ตาม Port', 'จากเครื่อง Dev สั่ง ssh -p <พอร์ต> <user>@<IP> — เข้าได้ด้วย User และ Port ที่กำหนด', 1],
            ['2.3.4', 'Firewall', 'สั่ง ufw status หรือ firewall-cmd --list-all — เปิดเฉพาะพอร์ตตามแผ่นโจทย์', 1],
            ['2.4.1', 'Docker', 'สั่ง docker ps, run, exec — ทำงานได้ครบ 3 คำสั่ง', 1],
            ['2.4.2', 'GitLab', 'เปิดหน้าเว็บ GitLab — Login ได้', 1],
            ['2.4.3', 'User ใน GitLab', 'Login ด้วย User ที่ทีมสร้าง — มี backend, frontend, devops และเข้าหน้า UI ได้', 1],
            ['2.5.1', 'Dockerfile', 'Build image จาก Dockerfile ของแอป — Build สำเร็จและรัน Container ได้', 1],
            ['2.5.2', 'Reverse Proxy', 'เปิด https://gitlab, registry, app, api ตามโดเมน — เปิดได้ครบ 4 ชื่อ ยอมรับคำเตือนใบรับรองภายใน', 1],
            ['2.5.3', 'Docker Compose', 'ดู docker-compose.yaml และสั่ง docker compose ps — มีอย่างน้อย 2 บริการใน Compose เดียว เช่น app และ db', 1],
            ['2.6', 'เขียน .gitlab-ci.yml', 'อ่านไฟล์ — มี Stage build, test, push, deploy และคำสั่งถูกต้อง (ไม่พิจารณาว่ารันผ่านหรือไม่)', 1],
            ['2.7.1', 'Trigger', 'Push code ตามที่กรรมการกำหนด — Pipeline เริ่มเอง', 0.5],
            ['2.7.2', 'ทำงานจริงครบ 4 Stage', 'ดูผล Pipeline และหน้า Production — ผ่านทั้ง 4 Stage และเว็บเปลี่ยนตามโค้ดใหม่ ขาดข้อใดข้อหนึ่งได้ 0 ไม่หารเฉลี่ย', 2],
            ['2.7.3', 'Build image', 'ดู Job build และ Image ใน Registry — สร้าง Image ได้', 0.5],
        ],
    ],
    [
        'code' => 'S3', 'name' => 'ส่วนที่ 3 IoT และ Dashboard', 'max_score' => 10, 'sort_order' => 3, 'is_bonus' => 0,
        'criteria' => [
            ['3.1', 'Broker และสิทธิ์', 'ต่อแบบไม่ใส่รหัส และใช้ viewer ลอง Publish — แบบไม่ใส่รหัสเข้าไม่ได้ และ viewer Publish ไม่ได้', 1],
            ['3.2', 'ทดสอบ Pub/Sub', 'ใช้ MQTT Explorer หรือ Node-RED ส่งและรับตาม Topic — รับข้อความตรง Topic ในแผ่นโจทย์', 1],
            ['3.3', 'ESP32 + Sensor', 'กรรมการดู telemetry แล้วส่ง cmd — มีค่าจากเซนเซอร์ทุก 5 วินาที LED ทำตามคำสั่ง และมี ack', 1],
            ['3.4', 'บันทึกฐานข้อมูล', 'ดูแถวล่าสุดในตาราง — จำนวนแถวเพิ่ม และค่าตรงกับ telemetry', 1],
            ['3.5.1', 'Node-RED Dashboard', 'เปิดหน้า Dashboard แล้วส่ง cmd — ค่าเปลี่ยนแบบเรียลไทม์ และแสดงผลเมื่อได้รับ ack', 1],
            ['3.6.1', 'Web Dashboard หน้าที่ 1', 'เปิดด้วย URL ตามแผ่นโจทย์บนเครื่อง Production ได้ ใช้รูปแบบตามแผ่นโจทย์ ข้อมูลจาก API/ฐานข้อมูลจริง', 1],
            ['3.6.2', 'Web Dashboard หน้าที่ 2', 'เปิดด้วย URL ตามแผ่นโจทย์บนเครื่อง Production ได้ ใช้รูปแบบตามแผ่นโจทย์ ข้อมูลจาก API/ฐานข้อมูลจริง', 1],
            ['3.6.3', 'Web Dashboard หน้าที่ 3', 'เปิดด้วย URL ตามแผ่นโจทย์บนเครื่อง Production ได้ ใช้รูปแบบตามแผ่นโจทย์ ข้อมูลจาก API/ฐานข้อมูลจริง', 1],
            ['3.6.4', 'Web Dashboard หน้าที่ 4', 'เปิดด้วย URL ตามแผ่นโจทย์บนเครื่อง Production ได้ ใช้รูปแบบตามแผ่นโจทย์ ข้อมูลจาก API/ฐานข้อมูลจริง', 1],
            ['3.6.5', 'Web Dashboard หน้าที่ 5', 'เปิดด้วย URL ตามแผ่นโจทย์บนเครื่อง Production ได้ ใช้รูปแบบตามแผ่นโจทย์ ข้อมูลจาก API/ฐานข้อมูลจริง', 1],
        ],
    ],
    [
        'code' => 'S4', 'name' => 'ส่วนที่ 4 พัฒนา Web Application', 'max_score' => 50, 'sort_order' => 4, 'is_bonus' => 0,
        'criteria' => [
            ['4.1.1', 'Flowchart: Login', 'ต้องตรงกับระบบที่พัฒนา', 1],
            ['4.1.2', 'Flowchart: ผู้ดูแลระบบ', 'ต้องตรงกับระบบที่พัฒนา', 1],
            ['4.1.3', 'Flowchart: ผู้ใช้ระดับ 1', 'ต้องตรงกับระบบที่พัฒนา', 1],
            ['4.1.4', 'Flowchart: ผู้ใช้ระดับ 2', 'ต้องตรงกับระบบที่พัฒนา', 1],
            ['4.1.5', 'Use Case Diagram ภาพรวม', 'ต้องตรงกับระบบที่พัฒนา', 1],
            ['4.2.1', 'ฐานข้อมูล: มีอย่างน้อย 3 ตาราง', '', 1],
            ['4.2.2', 'ฐานข้อมูล: ความสัมพันธ์เหมาะสม', '', 1],
            ['4.2.3', 'ฐานข้อมูล: ชนิดข้อมูลเหมาะสม', '', 1],
            ['4.2.4', 'Class Diagram ถูกต้อง', '', 1],
            ['4.2.5', 'ชื่อตารางและฟิลด์สื่อความหมาย', '', 1],
            ['4.3.1', 'Layout: ผู้ดูแลระบบ', '', 1],
            ['4.3.2', 'Layout: ผู้ใช้ระดับ 1', '', 1],
            ['4.3.3', 'Layout: ผู้ใช้ระดับ 2', '', 1],
            ['4.3.4', 'Layout: Dashboard', '', 1],
            ['4.3.5', 'Layout: Login', '', 1],
            ['4.4.1', 'README.md: HTTP Method เหมาะสม', '', 1],
            ['4.4.2', 'README.md: ชื่อ Endpoint สอดคล้องกัน', '', 1],
            ['4.4.3', 'README.md: มีตัวอย่าง Response ตาม Status Code', '', 1],
            ['4.4.4', 'README.md: ออกแบบ 401 และ 404', '', 1],
            ['4.4.5', 'README.md: มีแนวทางความปลอดภัย', '', 1],
            ['4.5.1', 'GET รับค่า', 'เรียก API ด้วย Query หรือ Path — ผลลัพธ์เปลี่ยนตามค่าที่ส่ง', 1],
            ['4.5.2', 'POST รับ Body', 'ส่ง JSON หรือแบบฟอร์ม — ข้อมูลเข้าฐานข้อมูลถูกต้อง', 1],
            ['4.5.3', 'อัปโหลดไฟล์', 'ส่งไฟล์ผ่าน API — เปิดไฟล์ที่เก็บแล้วได้', 1],
            ['4.5.4', 'Login ด้วย JWT', 'Login แล้วใช้ Token เรียก API — ได้ Token ที่ลงนาม และใช้ตรวจสิทธิ์ได้', 1],
            ['4.5.5', 'จัดการ Error', 'ส่งข้อมูลไม่ครบ และขอ id ที่ไม่มี — ตอบ 400 หรือ 422 และ 404 ไม่ใช่ 500', 1],
            ['4.5.6', 'รูปแบบ Response', 'ดู JSON — มี status, message, data สม่ำเสมอ', 1],
            ['4.5.7', 'Comment อธิบาย API', 'อ่านโค้ด — มีครบ GET, POST, PUT หรือ PATCH, DELETE อย่างน้อย Method ละ 1 API', 1],
            ['4.5.8', 'ระบุข้อมูลที่ส่ง', 'อ่าน Comment หรือ README.md — ระบุ query, path, body ชัดเจน', 1],
            ['4.5.9', 'โครงสร้างโค้ด', 'อ่านโค้ด — แยก Router และใช้ async/await เหมาะสม', 1],
            ['4.5.10', 'ความปลอดภัยของ API', 'เรียกโดยไม่มี Token และด้วยสิทธิ์ผิด — ตอบ 401 และ 403 ตามกรณี', 1],
            ['4.6.1', 'Layout', 'ย่อหน้าจอเป็นขนาดมือถือ — ใช้งานได้ ไม่ล้นจอ', 1],
            ['4.6.2', 'Login', 'Login ด้วยรหัสถูกและผิด — รหัสถูกเข้าได้ รหัสผิดเข้าไม่ได้', 1],
            ['4.6.3', 'แยกผู้ใช้แต่ละระดับ', 'Login ทั้ง 3 บัญชี — เข้าหน้าและเมนูตามระดับ', 1],
            ['4.6.4', 'เพิ่มข้อมูล', 'กรอกแบบฟอร์ม — ข้อมูลเข้าระบบครบ', 1],
            ['4.6.5', 'แนบไฟล์พร้อมเพิ่มข้อมูล', 'เพิ่มรายการพร้อมไฟล์ — เปิดไฟล์จากรายการได้', 1],
            ['4.6.6', 'แก้ไขข้อมูล', 'แก้แล้วเปิดดูใหม่ — ค่าใหม่ยังอยู่', 1],
            ['4.6.7', 'แก้ไขไฟล์แนบ', 'เปลี่ยนไฟล์ — ไฟล์ใหม่แทนไฟล์เดิม', 1],
            ['4.6.8', 'เตือนก่อนลบ', 'กดลบ — มีหน้าต่างยืนยัน และยกเลิกได้', 1],
            ['4.6.9', 'แสดงตามสิทธิ์', 'เทียบรายการของ 3 บัญชี — เห็นข้อมูลตามขอบเขต', 1],
            ['4.6.10', 'ค้นหาหรือกรอง', 'กรองในแต่ละระดับ — ผลตรงเงื่อนไขและไม่เกินสิทธิ์', 1],
            ['4.6.11', 'แบ่งหน้า', 'เปลี่ยนหน้าในแต่ละระดับ — หน้าละ 10 แถว ข้อมูลไม่ซ้ำ', 1],
            ['4.6.12', 'Validate', 'เว้นช่องบังคับ — แจ้งเตือนก่อนส่ง Backend', 1],
            ['4.6.13', 'Error ฝั่ง Frontend', 'ตัดการเชื่อมต่อ API — แสดงข้อความที่เข้าใจง่าย', 1],
            ['4.6.14', 'Error จาก Backend', 'ทำให้เกิด 401, 403 หรือ 404 — แปลงเป็นข้อความเหมาะสม', 1],
            ['4.6.15', 'Dockerfile ของ Backend', 'Build และรัน — ทำงานได้', 1],
            ['4.6.16', 'Test ใน Pipeline', 'ดู Job test — มี Unit และ Integration อย่างละ 1 กรณีและผ่าน', 1],
            ['4.6.17', 'E2E Test', 'รันกรณีในแผ่นโจทย์ — ผ่านทั้ง E2E-001 และ E2E-002', 1],
            ['4.6.18', 'Git Branch', 'ดูประวัติ Git — แยก backend, frontend และ devops เป็นผู้ Merge เข้า main', 1],
            ['4.6.19', 'ความสมบูรณ์ของระบบ', 'ทำครบวงจรบนเครื่อง Production — เหตุจาก ESP32 ไปถึงเว็บ 1 คะแนน และงานของทั้ง 3 ระดับครบ 1 คะแนน', 2],
        ],
    ],
    [
        'code' => 'S5', 'name' => 'ส่วนที่ 5 การนำเสนอ', 'max_score' => 10, 'sort_order' => 5, 'is_bonus' => 0,
        'criteria' => [
            ['5.1', 'ตอบคำถาม', 'ด้าน Backend, Frontend และแนวทางแก้ปัญหา ไม่เกิน 3 คำถาม', 3],
            ['5.2', 'นำเสนอการกระจายงาน', 'การกระจายงานและการทำงานร่วมกัน', 3],
            ['5.3', 'บุคลิกภาพ', '', 3],
            ['5.4', 'นำเสนอภายในเวลาที่กำหนด', '', 1],
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
            ['5.1.3', 'เพิ่มตัวชี้วัดในแต่ละหัวข้อได้', 'โดยประกอบด้วย ชื่อ, รายละเอียด, และหลักฐานที่ใช้แนบ', 1],
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
            ['8.3', 'Function การอัปโหลด และดาวน์โหลดไฟล์', '', 2],
            ['8.4', 'Function การสำรองและกู้คืนข้อมูล', 'Backup/Restore', 2],
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
foreach ([$devopsDivisionId, $hrDivisionId] as $divId) {
    for ($i = 1; $i <= 5; $i++) {
        $checkTeam->execute([$divId, $i]);
        if (!$checkTeam->fetch()) {
            $insTeam->execute([$divId, $i, "ทีมที่ {$i}"]);
        }
    }
}

echo "[migrate] done\n";
