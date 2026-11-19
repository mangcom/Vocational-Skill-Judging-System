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

// --- หัวข้อ (เช่น "1.2 Inter-VLAN Routing") ที่ไม่มีคะแนนในตัวเอง แต่เป็นหมวดของเกณฑ์ย่อยหลายข้อ (บรรทัดที่มี "-" นำหน้าในเอกสารต้นฉบับ) ---
$pdo->exec("CREATE TABLE IF NOT EXISTS criteria_groups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    skill_id INT NOT NULL,
    code VARCHAR(20) NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_criteria_groups_skill FOREIGN KEY (skill_id) REFERENCES skills(id) ON DELETE CASCADE,
    UNIQUE KEY uq_skill_group_code (skill_id, code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
safe_exec($pdo, "ALTER TABLE criteria ADD COLUMN group_id INT NULL AFTER skill_id");
add_fk_if_missing($pdo, 'criteria', 'fk_criteria_group', 'FOREIGN KEY (group_id) REFERENCES criteria_groups(id) ON DELETE SET NULL');

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
    ['code' => 'POS-DEVOPS', 'name' => 'ปวส. - ทักษะเทคโนโลยีสารสนเทศด้านโปรแกรมและเครือข่ายด้วยกระบวนการ DevOps', 'sort_order' => 1],
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
        'groups' => [
            ['1.1', 'VLAN Configuration', null],
            ['1.2', 'Inter-VLAN Routing', 'ตั้งค่า IP Interface และ Routing ระหว่าง VLAN'],
            ['1.3', 'แจก IP DHCP ด้วย Linux Server เครื่องเดียวที่รับผิดชอบแจก DHCP 3 เครือข่าย พร้อม DHCP Snooping', 'ต้องใช้ Linux Server ในการทำด้วยการ์ดเครือข่าย 1 การ์ดและใช้การทำ Sub Interface ในการแบ่งหน้าที่รับผิดชอบแต่ละ VLAN'],
            ['1.4', 'การทำ NAT เพื่อติดต่อกับเครื่องของกรรมการได้', 'ใช้ NAT เพื่อให้อุปกรณ์เชื่อมต่อเครือข่ายที่จัดเตรียมได้'],
            ['1.5', 'การทำ Access Control List', 'การตั้งค่าความปลอดภัยจาก Switch ทั้ง Standard ACL และ Extended ACL'],
        ],
        'criteria' => [
            ['1.1', 'มี VLAN server, dev, guest เท่านั้น และไม่ใช่ Default VLAN', 'ตรวจสอบรายการ VLAN ที่สร้างไว้บน Switch เช่น ใช้คำสั่ง show vlan brief หรือดูจากหน้า GUI ของอุปกรณ์ — ต้องมีครบทั้ง 3 VLAN (server, dev, guest) เท่านั้น ห้ามมีพอร์ตที่ยังอยู่ใน VLAN 1 (Default VLAN) ให้คะแนนแบบแบ่งย่อย VLAN ละ 0.33 คะแนน', 1, '1.1'],
            ['1.2.1', 'มี IP ทั้ง VLAN Server, Dev, Guest', 'ตรวจสอบการตั้งค่า IP Interface (เช่น SVI หรือ Sub-interface) ของทั้ง 3 VLAN เช่น ใช้คำสั่ง show ip interface brief — ต้องมี IP ตรงตามแผ่นโจทย์ครบทั้ง 3 VLAN ไม่ครบได้ 0', 1, '1.2'],
            ['1.2.2', 'ทำ Routing ระหว่าง VLAN ได้ครบทั้งสาม', 'ทดสอบ Ping ข้าม VLAN เช่น จากเครื่องใน VLAN Dev ping ไปยังเครื่องใน VLAN Server และ VLAN Guest — ต้อง Ping ผ่านครบทั้งสองเส้นทาง ไม่ครบได้ 0', 1, '1.2'],
            ['1.3.1', 'แจก DHCP ได้ 1 VLAN (VLAN Dev หรือ Server)', 'ให้เครื่องลูกข่ายใน VLAN Dev หรือ Server ขอ IP ใหม่ เช่น สั่ง ipconfig /renew หรือรีสตาร์ท interface — ต้องได้ IP และ Gateway ตรงตามแผ่นโจทย์อย่างน้อย 1 VLAN', 1, '1.3'],
            ['1.3.2', 'แจก DHCP ได้ 2 VLAN (VLAN Dev และ Server)', 'ทดสอบเช่นเดียวกับข้อก่อนหน้า แต่ต้องได้ผลถูกต้องครบทั้ง VLAN Dev และ VLAN Server พร้อมกัน', 1, '1.3'],
            ['1.3.3', 'ให้ Switch ยอมรับการใช้งาน DHCP Server เฉพาะ Port ที่กำหนดได้', 'ตรวจสอบการตั้งค่า DHCP Snooping บน Switch เช่น ใช้คำสั่ง show ip dhcp snooping — ต้องเปิดใช้งานและกำหนด Trusted Port เฉพาะพอร์ตที่ต่อกับ DHCP Server ตัวจริงตามผังเครือข่ายของทีม และทดสอบ ย้าย DHCP Server ไปเสียบยัง Port Access/UnTag อื่น ที่ Untrust จะต้องไม่แจก IP ออกมาให้', 1, '1.3'],
            ['1.4', 'NAT ได้ทุก VLAN เพื่อใช้งานเครือข่ายภายนอก', 'ทดสอบจากเครื่องในแต่ละ VLAN ให้เชื่อมต่อออกสู่เครือข่ายภายนอก เช่น สั่ง ping ไปยัง IP ของกรรมการ หรือ curl ไปยังปลายทางที่กำหนด — ต้องเชื่อมต่อได้ครบทุก VLAN', 1, '1.4'],
            ['1.5.1', 'Standard ACL — การทำ ACL ควบคุมการสื่อสารภายในเครือข่าย', 'ทดสอบ Ping ข้าม VLAN ที่ถูกบล็อกด้วย Standard ACL เช่น จาก VLAN Guest ping ไปยังเครื่องใน VLAN Dev — ต้อง Ping ไม่ผ่านตามกฎที่ตั้งไว้', 1, '1.5'],
            ['1.5.2', 'Extended ACL — การทำ ACL อนุญาตเฉพาะ Service ที่กำหนด', 'ทดสอบเข้าถึงเฉพาะ Service ที่อนุญาต เช่น ทดสอบพอร์ตที่กำหนดในแผ่นโจทย์ — ต้องผ่านเฉพาะ Service ที่อนุญาต ส่วน Service อื่นต้องถูกปฏิเสธ', 1, '1.5'],
            ['1.5.3', 'การทำ ACL ป้องกันการเข้าถึง Server', 'ทดสอบเข้าถึง Server จาก VLAN ที่ไม่ได้รับอนุญาต เช่น จาก VLAN Guest พยายามเข้าถึง Server — ต้องถูกบล็อกตามกฎ ACL ที่ตั้งค่าไว้', 1, '1.5'],
        ],
    ],
    [
        'code' => 'S2', 'name' => 'ส่วนที่ 2 ตั้งค่าเครือข่ายตามผังระบบเครือข่าย', 'max_score' => 20, 'sort_order' => 2, 'is_bonus' => 0,
        'groups' => [
            ['2.1', 'ติดตั้งระบบ Virtualization เช่น Proxmox, VirtualBox, VMware', 'ใช้ Virtualization  ที่ทำงานในรูปแบบ Type-1 Hypervisor เท่านั้น หากใช้ VMware Workstation ที่ทำงานบนระบบปฏิบัติการเช่น Windows หรือ Linux จะไม่ได้คะแนนและไม่พิจารณาตรวจในส่วนนี้'],
            ['2.2', 'ติดตั้ง Linux Server บน VM โดย ISO ได้แก่ Ubuntu, Debian', 'ห้ามใช้ Linux สำเร็จรูปเช่น Trunkey หรือ LXC Template บน Proxmox'],
            ['2.3', 'บริหารจัดการจากระยะไกล SSH / Remote Tools', null],
            ['2.4', 'ติดตั้งเครื่องมือ DevOps ได้แก่ Docker + GitLab', null],
            ['2.5', 'เขียน Dockerfile / docker-compose.yaml ใช้งานจริง', null],
            ['2.6', 'กำหนด CI/CD Pipeline', null],
            ['2.7', 'ทดสอบใช้งาน Pipeline', null],
        ],
        'criteria' => [
            ['2.1.1', 'ติดตั้งระบบ Virtualization ได้ มี GUI ใช้งานได้', 'เปิดหน้า GUI ของ Virtualization เช่น Proxmox/VirtualBox/VMware — ต้องเข้าใช้งานได้จริง', 1, '2.1'],
            ['2.1.2', 'สร้าง VM ได้ตามแผนผัง มีโครงสร้างตามโจทย์', 'ดูรายการ VM ที่สร้างไว้ เช่น เปิดหน้า VM list — ต้องมีครบตามจำนวนและโครงสร้างที่โจทย์กำหนด', 1, '2.1'],
            ['2.1.3', 'ตั้งค่า IP Address Virtualization ตามที่คณะกรรมการกำหนด', 'ตรวจสอบ IP ของ Virtualization Host เช่น เปิดด้วย URL/IP ที่กรรมการกำหนด — ต้องตรงตามแผ่นโจทย์', 1, '2.1'],
            ['2.2.1', 'ติดตั้งได้และบูตใช้งานได้ และเปิดอัตโนมัติเมื่อ Virtualization ดับแล้วเปิดขึ้นมาใหม่', 'สั่งรีบูต Virtualization Host หรือดูตัวเลือก Start at boot ของ VM — VM ต้องเปิดใช้งานได้เองหลัง Host เปิดใหม่', 1, '2.2'],
            ['2.2.2', 'ตั้งค่า IP แบบ Static / DHCP ได้ถูกต้องตามผังที่กรรมการกำหนดให้', 'สั่ง ip a หรือ ipconfig บนเครื่อง VM — IP ต้องตรงแผ่นโจทย์ทั้งสองเครื่อง', 1, '2.2'],
            ['2.2.3', 'ตั้งค่า hostname และ Domain name ตามที่คณะกรรมการกำหนด', 'สั่ง hostname -f บนเครื่อง VM — ชื่อ hostname/domain ต้องตรงแผ่นโจทย์ทั้งสองเครื่อง', 1, '2.2'],
            ['2.3.1', 'เปิดบริการ SSH', 'สั่ง systemctl status ssh บนเครื่อง VM — บริการต้องทำงาน (active/running) ทั้งสองเครื่อง', 1, '2.3'],
            ['2.3.2', 'เพิ่ม User ตามที่กรรมการกำหนดได้ อย่างน้อย 2 User', 'ดูรายชื่อ User ในเครื่อง เช่น สั่ง cat /etc/passwd — ต้องมี User ครบตามที่กรรมการกำหนดอย่างน้อย 2 บัญชี และต้องไม่ใช้ User root ในการสั่งงานเป็นหลัก แต่ใช้ sudo ได้', 1, '2.3'],
            ['2.3.3', 'Remote จากเครื่องอื่นใน Network เข้ามาได้ด้วย user และ Port ที่กรรมการกำหนด', 'จากเครื่องอื่นสั่ง ssh -p <พอร์ต> <user>@<IP> — ต้องเข้าได้ด้วย User และ Port ตามที่กำหนด', 1, '2.3'],
            ['2.3.4', 'ตั้งค่า firewall (UFW / firewalld) เพื่อเปิดพอร์ตบริการได้ ตามที่กรรมการกำหนด', 'สั่ง ufw status หรือ firewall-cmd --list-all — ต้องเปิดเฉพาะพอร์ตบริการตามแผ่นโจทย์เท่านั้น', 1, '2.4'],
            ['2.4.1', 'ติดตั้ง Docker ได้และใช้งานได้ เช่น docker ps, run, exec', 'สั่ง docker ps, docker run, docker exec บนเครื่อง — ต้องทำงานได้ครบทั้ง 3 คำสั่ง', 1, '2.4'],
            ['2.4.2', 'ติดตั้ง GitLab ได้ใน container หรือ VM โดยเข้าเว็บได้', 'เปิดหน้าเว็บ GitLab ตาม URL ที่ทีมตั้งไว้ — ต้องเข้าใช้งานได้จริง', 1, '2.4'],
            ['2.4.3', 'สร้าง User ใน Gitlab และเข้าหน้า UI ได้', 'Login ด้วย User ที่ทีมสร้างไว้ใน GitLab — ต้องมี User ตามบทบาท (backend, frontend, devops) และเข้าหน้า UI ได้ โดยการทำงานร่วมกัน จะต้องมีการสร้าง Team หรือ Group ขึ้นมาก่อน', 1, '2.4'],
            ['2.5.1', 'สร้างและใช้งาน Dockerfile สำหรับแอป Node/เว็บที่พัฒนา ได้', 'สั่ง docker build จาก Dockerfile ของทีม แล้ว docker run — ต้อง Build สำเร็จและรัน Container ได้จริง', 1, '2.5'],
            ['2.5.2', 'ใช้ reverse proxy กับ host และ domain ของระบบที่พัฒนาได้ (registry, gitlab, frontend, backend)', 'เปิดด้วยโดเมน โดยเข้าใช้ผ่านทาง http หรือ https ได้เช่น http://gitlab.mydomain.lab, https://registry..., https://app... — ต้องเปิดได้ครบทุกชื่อ (ยอมรับคำเตือนใบรับรองที่ออกเองได้)', 1, '2.5'],
            ['2.5.3', 'ใช้ docker-compose.yaml สำหรับบริการมากกว่า 1 (เช่น app + db) ใน 1 compose', 'เปิดไฟล์ docker-compose.yaml และสั่ง docker compose ps — ต้องมีอย่างน้อย 2 บริการทำงานร่วมกันในไฟล์เดียว เช่น app และ db  โดยต้องประกอบด้วย services , networks , volumes เป็นอย่างน้อย', 1, '2.5'],
            ['2.6', 'สร้าง .gitlab-ci.yml เชื่อมโยงกับการกำหนด State ครบทั้ง 4 ขั้นตอน Build, Test, Push ขึ้น Registry ของตนเอง, Deploy ไปยัง Server ของตนเอง', 'อ่านไฟล์ .gitlab-ci.yml — ต้องมี Stage ครบ build, test, push, deploy และเขียนคำสั่งถูกต้องตามหลักการ (พิจารณาความถูกต้องของคำสั่งที่เขียน ไม่พิจารณาว่ารันผ่านจริงหรือไม่)', 1, '2.6'],
            ['2.7.1', 'Push code แล้วเกิด Trigger ได้จริง', 'สั่ง git push ตามที่กรรมการกำหนด แล้วดูหน้า Pipeline ของ GitLab — Pipeline ต้องเริ่มทำงานเองโดยอัตโนมัติ', 0.5, '2.7'],
            ['2.7.2', 'กำหนด Stage ครบ 4 ขั้นตอน (build, test, push ขึ้น registry, deploy) และทำงานได้จริงทุกขั้นตอน', 'ดูผลการรัน Pipeline และหน้า Production จริง — ต้องผ่านครบทั้ง 4 Stage และเว็บต้องเปลี่ยนตามโค้ดใหม่ที่ Deploy (ขาดข้อใดข้อหนึ่งไม่ได้คะแนนข้อนี้เลย ไม่มีการหารเฉลี่ย)', 2, '2.7'],
            ['2.7.3', 'ระบบสามารถ build และสร้าง image ได้', 'ดู Job build ใน Pipeline และรายการ Image ใน Registry — ต้องสร้าง Image สำเร็จจริง', 0.5, '2.7'],
        ],
    ],
    [
        'code' => 'S3', 'name' => 'ส่วนที่ 3 ติดตั้งและทดสอบ MQTT Server, Node-RED, เชื่อมต่อ Hardware, แสดงผลบน Dashboard', 'max_score' => 10, 'sort_order' => 3, 'is_bonus' => 0,
        'groups' => [
            ['3.1', 'ติดตั้งและตั้งค่า MQTT Server + Node-RED', null],
            ['3.2', 'ทดสอบ MQTT Client ได้ (เช่น MQTT Explorer หรือ Node-RED MQTT In/Out)', null],
            ['3.3', 'Hardware Integration (ESP32 + Sensor)', null],
            ['3.4', 'Software Interaction & Database Logging', null],
            ['3.5', 'การแสดงผลบน Dashboard', null],
        ],
        'criteria' => [
            ['3.1', 'ติดตั้ง MQTT Server และตั้งค่าการเข้าถึง (ACL/Auth) ได้', 'ตรวจสอบการเชื่อมต่อ MQTT Broker เช่น ใช้ MQTT Explorer ต่อแบบไม่ใส่รหัสผ่าน และต่อด้วย User/Password — ต้องเชื่อมต่อแบบไม่ใส่รหัสไม่ได้ และมีการจำกัดสิทธิ์ตาม ACL ที่ตั้งไว้', 1, '3.1'],
            ['3.2', 'Pub/Sub สำเร็จตาม Topic ที่กำหนด', 'ใช้ MQTT Explorer หรือ Node-RED ทดสอบส่ง (Publish) และรับ (Subscribe) ข้อความตาม Topic ที่โจทย์กำหนด — ต้องรับ-ส่งข้อความได้ตรง Topic', 1, '3.2'],
            ['3.3', 'Hardware Pub ค่า payload ไปยัง Topic ที่กำหนด และ Hardware Sub ค่าเพื่อตอบสนองคำสั่งจาก Server', 'กรรมการดูค่า telemetry ที่ส่งเข้ามาแบบเรียลไทม์ แล้วทดลองส่งคำสั่งกลับไปยัง Hardware เช่น สั่งเปิด/ปิด LED — ต้องมีค่าจากเซนเซอร์ส่งเข้ามาสม่ำเสมอ และ Hardware ต้องตอบสนองคำสั่งที่ส่งไปจริง', 1, '3.3'],
            ['3.4', 'Software (Node-RED หรือ Script) Sub ข้อมูลจาก Topic โดยบันทึกข้อมูลลงฐานข้อมูล (เช่น MySQL, MariaDB, SQLite)', 'ดูแถวล่าสุดในตารางฐานข้อมูล เทียบกับเวลาปัจจุบัน — จำนวนแถวต้องเพิ่มขึ้นตามเวลาจริง และค่าต้องตรงกับข้อมูลที่ Hardware ส่งเข้ามา', 1, '3.4'],
            ['3.5.1', 'มีใช้ Node-RED Dashboard แสดงข้อมูลแบบเรียลไทม์ โดยมีการแจ้งเตือน/แสดงผลเมื่อ Hardware ตอบสนอง', 'เปิดหน้า Node-RED Dashboard แล้วสั่งงานจาก Hardware — ค่าบนหน้า Dashboard ต้องเปลี่ยนแบบเรียลไทม์และแสดงผล/แจ้งเตือนเมื่อ Hardware ตอบสนอง', 1, '3.5'],
            ['3.5.2', 'มีการแสดงผลผ่านหน้า Web Application ในรูปแบบ Dashboard ไม่น้อยกว่า 5 รูปแบบที่แตกต่างกัน (รูปแบบละ 1 หน้าเว็บ)', 'เปิดแต่ละหน้าด้วย URL ตามแผ่นโจทย์บนเครื่อง Production — ต้องมีอย่างน้อย 5 หน้าที่แสดงผลรูปแบบต่างกัน ใช้ข้อมูลจาก API/ฐานข้อมูลจริงตามที่กรรมการกำหนด', 5, '3.5'],
        ],
    ],
    [
        'code' => 'S4', 'name' => 'ส่วนที่ 4 ซอฟต์แวร์และการประยุกต์', 'max_score' => 50, 'sort_order' => 4, 'is_bonus' => 0,
        'groups' => [
            ['4.1', 'การออกแบบผังงาน (Flowchart) ตามความต้องการของผู้ใช้ (Requirement)', null],
            ['4.2', 'การออกแบบฐานข้อมูล (Database Design) พร้อม Class Diagram', null],
            ['4.3', 'การออกแบบหน้าจอด้วยโปรแกรมสำเร็จรูป', null],
            ['4.4', 'การออกแบบ RESTful API สำหรับใช้งานในระบบ (Design / README.md)', null],
            ['4.5', 'พัฒนาระบบ Back-end การพัฒนา API', null],
            ['4.6', 'พัฒนาระบบ Front-end', null],
        ],
        'criteria' => [
            ['4.1.1', 'จัดทำผังงานระบบการเข้าสู่ระบบ (Login Flow)', 'เทียบผังงานที่จัดทำกับระบบ Login ที่พัฒนาจริง — ขั้นตอนในผังงานต้องตรงกับการทำงานจริงของระบบ', 1, '4.1'],
            ['4.1.2', 'จัดทำผังงานกระบวนการของผู้ดูแลระบบ', 'เทียบผังงานกับหน้าที่/เมนูของผู้ดูแลระบบที่พัฒนาจริง — ต้องสอดคล้องกัน', 1, '4.1'],
            ['4.1.3', 'จัดทำผังงานกระบวนการของผู้ใช้งานระดับ 1', 'เทียบผังงานกับหน้าที่/เมนูของผู้ใช้งานระดับ 1 ที่พัฒนาจริง — ต้องสอดคล้องกัน', 1, '4.1'],
            ['4.1.4', 'จัดทำผังงานกระบวนการของผู้ใช้งานระดับ 2', 'เทียบผังงานกับหน้าที่/เมนูของผู้ใช้งานระดับ 2 ที่พัฒนาจริง — ต้องสอดคล้องกัน', 1, '4.1'],
            ['4.1.5', 'จัดทำผังงานภาพรวมของระบบทั้งหมด (Usecase Diagram)', 'เทียบ Usecase Diagram กับฟังก์ชันทั้งหมดของระบบที่พัฒนาจริง — ต้องครอบคลุมและสอดคล้องกัน', 1, '4.1'],
            ['4.2.1', 'มีการสร้างฐานข้อมูลอย่างน้อย 3 ตาราง', 'เปิดดูโครงสร้างฐานข้อมูลจริง เช่น SHOW TABLES — ต้องมีอย่างน้อย 3 ตาราง', 1, '4.2'],
            ['4.2.2', 'ตารางฐานข้อมูลมีความสัมพันธ์กันอย่างเหมาะสม (Relational Design)', 'ดูความสัมพันธ์ระหว่างตาราง เช่น Foreign Key ที่กำหนดไว้ — ต้องออกแบบความสัมพันธ์ได้อย่างเหมาะสมกับข้อมูลจริง', 1, '4.2'],
            ['4.2.3', 'มีการเลือกใช้ประเภทข้อมูล (Data Type) ได้เหมาะสมกับชนิดของข้อมูล', 'ดูชนิดข้อมูล (Data Type) ของแต่ละฟิลด์ เช่น วันที่ใช้ DATE/DATETIME ตัวเลขใช้ INT/DECIMAL — ต้องเลือกใช้เหมาะสมกับลักษณะข้อมูลจริง', 1, '4.2'],
            ['4.2.4', 'แสดง Class Diagram ที่สามารถอธิบายโครงสร้างได้อย่างถูกต้องและสมบูรณ์', 'เทียบ Class Diagram กับโครงสร้างฐานข้อมูล/โค้ดจริง — ต้องอธิบายความสัมพันธ์และ Attribute ได้ถูกต้องครบถ้วน', 1, '4.2'],
            ['4.2.5', 'มีการตั้งชื่อตารางและฟิลด์สอดคล้องกับวัตถุประสงค์ของระบบ และเป็นไปตามมาตรฐานการเขียนชื่อทางเทคนิค', 'ดูชื่อตาราง/ฟิลด์จริงในฐานข้อมูล — ต้องสื่อความหมาย เขียนตามมาตรฐาน (เช่น snake_case) และสอดคล้องกับวัตถุประสงค์การใช้งาน', 1, '4.2'],
            ['4.3.1', 'มี Template Layout สำหรับหน้าจอผู้ดูแลระบบ', 'เปิดหน้าจอผู้ดูแลระบบที่พัฒนาจริง — ต้องมี Layout ที่ออกแบบไว้ใช้งานได้จริง', 1, '4.3'],
            ['4.3.2', 'มี Template Layout สำหรับหน้าจอผู้ใช้งานระดับ 1', 'เปิดหน้าจอผู้ใช้งานระดับ 1 ที่พัฒนาจริง — ต้องมี Layout ที่ออกแบบไว้ใช้งานได้จริง', 1, '4.3'],
            ['4.3.3', 'มี Template Layout สำหรับหน้าจอผู้ใช้งานระดับ 2', 'เปิดหน้าจอผู้ใช้งานระดับ 2 ที่พัฒนาจริง — ต้องมี Layout ที่ออกแบบไว้ใช้งานได้จริง', 1, '4.3'],
            ['4.3.4', 'มี Template Layout สำหรับหน้าจอหลักของระบบ (Dashboard/Overview)', 'เปิดหน้า Dashboard/Overview ที่พัฒนาจริง — ต้องมี Layout ที่ออกแบบไว้ใช้งานได้จริง', 1, '4.3'],
            ['4.3.5', 'มี Template Layout สำหรับหน้าจอเข้าสู่ระบบ (Login Screen)', 'เปิดหน้า Login ที่พัฒนาจริง — ต้องมี Layout ที่ออกแบบไว้ใช้งานได้จริง', 1, '4.3'],
            ['4.4.1', 'ออกแบบ API ตามหลัก RESTful (เช่น ใช้ GET, POST, PUT/Patch, DELETE อย่างเหมาะสม)', 'อ่านเอกสารออกแบบ API หรือ README.md — ต้องเลือกใช้ HTTP Method ตรงกับลักษณะการทำงานของแต่ละ Endpoint', 1, '4.4'],
            ['4.4.2', 'ตั้งชื่อ Endpoint สื่อความหมาย และมีความสอดคล้องกัน เช่น /api/teachers, /api/scores/{id}', 'ดูรายชื่อ Endpoint ทั้งหมด — ต้องตั้งชื่อสื่อความหมายและใช้รูปแบบสอดคล้องกันทั้งระบบ', 1, '4.4'],
            ['4.4.3', 'มีตัวอย่างรูปแบบ Response Body ที่สอดคล้องกับ HTTP Status Code อย่างเหมาะสม', 'อ่านเอกสาร/README.md ที่มีตัวอย่าง Response — ต้องมีตัวอย่างและ Status Code ตรงกับสถานการณ์ (เช่น 200 สำเร็จ, 201 สร้างสำเร็จ)', 1, '4.4'],
            ['4.4.4', 'มีการออกแบบรองรับกรณีเกิด Error เช่น Unauthorized (401), Not Found (404)', 'อ่านเอกสารออกแบบ — ต้องระบุกรณี Error ที่อาจเกิดขึ้นพร้อม Status Code ที่ถูกต้อง', 1, '4.4'],
            ['4.4.5', 'มีแนวทางด้านความปลอดภัยของ API เช่น JWT Token หรือการตรวจสอบสิทธิ์การเข้าถึง', 'อ่านเอกสารออกแบบความปลอดภัย — ต้องระบุแนวทางป้องกัน เช่น การใช้ JWT Token ตรวจสอบสิทธิ์ก่อนเข้าถึงข้อมูล', 1, '4.4'],
            ['4.5.1', 'สร้าง API แบบ GET พร้อมรับค่า (Query หรือ Path Parameters)', 'เรียก API ด้วย Query String หรือ Path Parameter เช่น GET /api/teams?id=1 — ผลลัพธ์ต้องเปลี่ยนตามค่าพารามิเตอร์ที่ส่ง', 1, '4.5'],
            ['4.5.2', 'สร้าง API แบบ POST ที่รับข้อมูลจาก Body อย่างถูกต้อง', 'ส่งข้อมูลแบบ JSON หรือฟอร์มผ่าน POST เช่นด้วย Postman — ข้อมูลต้องถูกบันทึกลงฐานข้อมูลถูกต้อง', 1, '4.5'],
            ['4.5.3', 'สร้าง API สำหรับอัปโหลดไฟล์', 'ส่งไฟล์ผ่าน API ด้วย multipart/form-data — ต้องอัปโหลดสำเร็จและเปิดไฟล์ที่เก็บไว้ได้', 1, '4.5'],
            ['4.5.4', 'สร้าง API Login โดยใช้ JWT Token (ซึ่งมีการลงนาม(Signing)เพื่อความปลอดภัย และใช้สำหรับตรวจสอบสิทธิ์ในการเข้าถึงระบบ)', 'เรียก API Login ด้วย Username/Password แล้วตรวจสอบ Token ที่ได้รับ เช่น ถอดรหัสด้วย jwt.io — Token ต้องมีลายเซ็นถูกต้อง และใช้เรียก API อื่นเพื่อตรวจสอบสิทธิ์ได้', 1, '4.5'],
            ['4.5.5', 'มีการจัดการข้อผิดพลาด (Exception Handling) เช่น ตรวจสอบข้อมูลไม่ครบ, ไม่พบข้อมูล', 'ส่งข้อมูลไม่ครบ และเรียกหา id ที่ไม่มีอยู่จริง — ระบบต้องตอบ 400/422 และ 404 ตามลำดับ ไม่ใช่ 500', 1, '4.5'],
            ['4.5.6', 'API ส่ง Response ที่เหมาะสม เช่น JSON มี status, message เพิ่มเติม, data อย่างเป็นระบบ', 'ดูโครงสร้าง JSON ที่ API ตอบกลับ — ต้องมี status, message, data ในรูปแบบเดียวกันทุก Endpoint', 1, '4.5'],
            ['4.5.7', 'มีคำอธิบาย API และการเรียกใช้แต่ละ Service (GET, POST, PUT/PATCH, DELETE) ครบอย่างน้อย Method ละ 1 API', 'อ่านโค้ด/เอกสาร — ต้องมีคำอธิบาย (Comment หรือเอกสาร) ครบทุก Method อย่างน้อย Method ละ 1 API', 1, '4.5'],
            ['4.5.8', 'ระบุข้อมูลที่ใช้ส่งในแต่ละ API เช่น query, path, body อย่างชัดเจน', 'อ่าน Comment หรือ README.md ของแต่ละ API — ต้องระบุชัดเจนว่าแต่ละ API รับค่าแบบใด (query/path/body)', 1, '4.5'],
            ['4.5.9', 'โค้ด Back-end มีโครงสร้างดี อ่านง่าย โดยใช้ router หรือ async/await อย่างเหมาะสม', 'อ่านโค้ด Back-end — ต้องแยกไฟล์ตาม Router/Controller และใช้ async/await หรือรูปแบบจัดการ asynchronous อย่างเหมาะสม', 1, '4.5'],
            ['4.5.10', 'API มีความปลอดภัย และมีระบบตรวจสอบสิทธิ์ก่อนเข้าถึงข้อมูล', 'เรียก API ที่ต้องมีสิทธิ์โดยไม่ส่ง Token และส่ง Token ของสิทธิ์ที่ไม่ถูกต้อง — ต้องตอบ 401 และ 403 ตามกรณีตามลำดับ', 1, '4.5'],
            ['4.6.1', 'มี Layout ใช้งานง่าย มี Responsive', 'ย่อหน้าจอเป็นขนาดมือถือ (หรือใช้ DevTools จำลอง) — หน้าจอต้องปรับตามขนาดได้ ไม่ล้นจอ ใช้งานได้จริง', 1, '4.6'],
            ['4.6.2', 'มีหน้าจอ ระบบ Login หรือ Authentication ด้วย JWT ที่ใช้งานได้จริง', 'ทดสอบ Login ด้วยรหัสผ่านถูกและผิด — รหัสถูกต้องเข้าระบบได้ รหัสผิดต้องเข้าไม่ได้ และมีการใช้ JWT จริง', 1, '4.6'],
            ['4.6.3', 'แยกระบบ Login ของกลุ่มผู้ใช้งานแต่ละระดับได้ ด้วย JWT', 'Login ด้วยบัญชีแต่ละระดับ (เช่น ผู้ดูแลระบบ/ผู้ใช้ระดับ 1/ระดับ 2) — ต้องเข้าหน้าและเมนูตามสิทธิ์ของระดับนั้น ๆ ถูกต้อง', 1, '4.6'],
            ['4.6.4', 'มีการเพิ่มข้อมูลได้สมบูรณ์ตามแบบฟอร์มที่ออกแบบ', 'กรอกข้อมูลผ่านฟอร์มที่พัฒนาไว้แล้วบันทึก — ข้อมูลต้องเข้าสู่ระบบครบถ้วนตามที่กรอก', 1, '4.6'],
            ['4.6.5', 'มีการแนบไฟล์พร้อมกับเพิ่มข้อมูลได้สมบูรณ์', 'เพิ่มรายการพร้อมแนบไฟล์ แล้วเปิดไฟล์จากรายการที่บันทึก — ต้องเปิดไฟล์ที่แนบไว้ได้จริง', 1, '4.6'],
            ['4.6.6', 'มีการแก้ไขและปรับปรุงข้อมูลได้', 'แก้ไขข้อมูลแล้วเปิดดูใหม่ — ค่าที่แก้ไขต้องถูกบันทึกและแสดงผลถูกต้อง', 1, '4.6'],
            ['4.6.7', 'มีการแก้ไขไฟล์แนบที่เกี่ยวข้องกับข้อมูลได้', 'เปลี่ยนไฟล์แนบของรายการเดิมแล้วเปิดดูใหม่ — ไฟล์ใหม่ต้องแทนที่ไฟล์เดิม', 1, '4.6'],
            ['4.6.8', 'มีแจ้งเตือนการลบข้อมูลได้', 'กดปุ่มลบข้อมูล — ต้องมีหน้าต่างยืนยันก่อนลบจริง และสามารถยกเลิกได้', 1, '4.6'],
            ['4.6.9', 'มีการแสดงข้อมูลตามสิทธิ์ของผู้ใช้แต่ละระดับได้', 'เปรียบเทียบรายการข้อมูลที่เห็นจากบัญชีแต่ละระดับ — แต่ละระดับต้องเห็นข้อมูลตามขอบเขตสิทธิ์ของตนเท่านั้น', 1, '4.6'],
            ['4.6.10', 'มีระบบค้นหา/Filter ข้อมูลในหน้า UI ตามสิทธิ์ผู้ใช้งานแต่ละระดับ', 'ทดสอบค้นหา/กรองข้อมูลในแต่ละระดับผู้ใช้ — ผลลัพธ์ต้องตรงเงื่อนไขที่กรอกและไม่เกินขอบเขตสิทธิ์', 1, '4.6'],
            ['4.6.11', 'มีระบบการแบ่งหน้าแสดงข้อมูล ในหน้า UI ตามสิทธิ์ผู้ใช้งานแต่ละระดับ', 'เปลี่ยนหน้า (pagination) ในแต่ละระดับผู้ใช้ — ต้องแบ่งหน้าได้ถูกต้อง ข้อมูลไม่ซ้ำกันระหว่างหน้า', 1, '4.6'],
            ['4.6.12', 'มีการ Validate ข้อมูล ฝั่ง Frontend ก่อนส่งไปยัง Backend', 'เว้นช่องที่บังคับกรอกแล้วกดส่งฟอร์ม — ต้องมีข้อความแจ้งเตือนก่อนที่ข้อมูลจะถูกส่งไป Backend', 1, '4.6'],
            ['4.6.13', 'มีการเขียนโปรแกรมที่มีการทำ Error Handling ในฝั่ง Frontend (ข้อความแจ้งข้อผิดพลาดที่ผู้ใช้งานเข้าใจง่าย)', 'ตัดการเชื่อมต่อ API แล้วลองใช้งานหน้าเว็บ — ต้องแสดงข้อความแจ้งข้อผิดพลาดที่เข้าใจง่าย ไม่ใช่หน้าจอ error ดิบ', 1, '4.6'],
            ['4.6.14', 'มีการเขียนโปรแกรมที่มีการแสดงข้อความผิดพลาด ที่เกิดจาก Error ฝั่ง Backend (การแปลงรหัสข้อผิดพลาดเป็นข้อความที่เหมาะสม)', 'ทำให้เกิด Error จาก Backend เช่น 401, 403, 404 — Frontend ต้องแปลงเป็นข้อความที่ผู้ใช้เข้าใจได้ ไม่ใช่แสดงรหัส error ดิบ', 1, '4.6'],
            ['4.6.15', 'มีการสร้าง Dockerfile สำหรับ Backend', 'สั่ง docker build ด้วย Dockerfile ของ Backend แล้วรัน Container — ต้อง Build และรันได้สำเร็จ', 1, '4.6'],
            ['4.6.16', 'มี Test ขั้นพื้นฐานใน Pipeline (Unit Testing / Integration Testing) อย่างละ 1 Test Case', 'ดู Job test ใน Pipeline — ต้องมีทั้ง Unit Test และ Integration Test อย่างน้อยอย่างละ 1 กรณี และรันผ่าน', 1, '4.6'],
            ['4.6.17', 'มี E to E Test เป็นการทดสอบ Work flow ตั้งแต่เริ่มต้นจนจบ (เช่น E2E-001 ผู้ใช้เข้าสู่ระบบสำเร็จ, E2E-002 ผู้ใช้ส่งข้อมูลสำเร็จ)', 'รันกรณีทดสอบ E2E ตามที่กรรมการจัดการแข่งขันกำหนด เช่น Login แล้วเห็นหน้า Dashboard, กรอกฟอร์มแล้วบันทึกสำเร็จ — ต้องผ่านครบทุกกรณีที่กำหนด', 1, '4.6'],
            ['4.6.18', 'มีการใช้ Git และ Branch Strategy ในการกำหนดบทบาท Backend / Frontend / DevOps (merge)', 'ดูประวัติ Git เช่น git log --graph หรือหน้า Branches ใน GitLab — ต้องแยก Branch ตามบทบาท backend/frontend/devops และมีการ Merge เข้า main', 1, '4.6'],
            ['4.6.19', 'ความสมบูรณ์ของระบบทั้งหมด Application', 'ทดสอบใช้งานระบบครบวงจรบนเครื่อง Production จริง ตั้งแต่ Hardware/ข้อมูลต้นทางไปจนถึงหน้าเว็บ — ให้คะแนนตามความสมบูรณ์โดยรวมของระบบที่ใช้งานได้จริง', 2, '4.6'],
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
            ['SB.1', '1. Payload มี seq และ age ถูกต้อง', '1 คะแนน seq เป็นเลขจำนวนเต็มที่เพิ่มทีละ 1 ไม่ข้ามและไม่ซ้ำ รวมถึงช่วงก่อน ระหว่าง และหลัง Broker ล่ม 
โดย ดูข้อความใน MQTT Explorer หรือดูข้อมูลในฐานข้อมูล
1 คะแนน seq เป็นเลขจำนวนเต็มที่เพิ่มทีละ 1 ไม่ข้ามและไม่ซ้ำ รวมถึงช่วงก่อน ระหว่าง และหลัง Broker ล่ม
โดย ดู Payload ที่ ESP32 ส่งออกมาหลัง Broker กลับมา', 2],
            ['SB.2', '2. ข้อมูลครบภายใน 30 วินาที', '1 คะแนน ฐานข้อมูลมี seq ต่อเนื่องตั้งแต่ค่าก่อน Broker ล่มจนถึงค่าหลัง Broker กลับมา โดยไม่มีเลขขาด 
โดยใช้ SQL เรียง seq แล้วดูว่ามีช่องว่างหรือไม่
1 คะแนน ค่าที่ค้างทั้งหมดเข้าฐานข้อมูลภายใน 30 วินาทีหลังเปิด Broker และส่งตามลำดับ seq จากน้อยไปมาก
โดย ดูเวลาที่บันทึกแต่ละแถว หรือดูลำดับข้อความใน MQTT Explorer', 2],
            ['SB.3', '3. ส่งซ้ำแล้วไม่เกิดแถวซ้ำ', '1 คะแนน กรรมการ Publish Payload เดิมที่มี seq เดิมซ้ำ 2 ครั้ง แล้วฐานข้อมูลยังมี seq นั้นเพียง 1 แถว
โดยใช้ SELECT COUNT(*) ของ seq นั้น ต้องได้ 1
1 คะแนน หลังส่งข้อมูลซ้ำ ระบบยังทำงานต่อได้ ค่าใหม่ยังเข้าฐานข้อมูล และ Flow หรือ Script ไม่ค้างหรือหยุดเพราะ Error
โดยรอ 15 วินาที แล้วดูว่ามีแถวใหม่เพิ่มหรือไม่', 2],
            ['SB.4', '4. เวลาตรงกับเวลาวัดจริง', '1 คะแนน ค่าที่ส่งย้อนหลังบันทึกเวลาเป็น "เวลารับ − age" ไม่ใช่เวลาที่ได้รับ เวลาในฐานข้อมูลจึงห่างกันประมาณ 5 วินาทีตลอดช่วงที่ล่ม และไม่กองอยู่ที่เวลาเดียวกัน
โดย ดูคอลัมน์เวลาของแถวที่ส่งย้อนหลัง
1 คะแนน แถวที่ค้างแถวแรกมีเวลาไม่เกิน 10 วินาทีหลังเวลาหยุด Broker ที่กรรมการจดไว้ และแถวที่ค้างแถวสุดท้ายมีเวลาไม่เกิน 10 วินาทีก่อนเวลาเปิด Broker
โดย เทียบกับเวลาจากคำสั่ง date ในขั้นตอนทดสอบ', 2],
            ['SB.5', '5. แสดงผลบน Dashboard', '1 คะแนน /dash/trend แสดงเส้นกราฟต่อเนื่องในช่วงที่ Broker ล่ม จุดข้อมูลอยู่ในตำแหน่งเวลาที่ถูกต้อง และกราฟไม่ขาดช่วงหรือตกเป็น 0
โดย เปิดหน้ากราฟช่วง 1 ชั่วโมง แล้วดูช่วงเวลาที่ Broker ล่ม
1 คะแนน /dash/live แสดงจำนวนค่าที่ส่งย้อนหลังในการเชื่อมต่อครั้งล่าสุด และตัวเลขตรงกับจำนวนแถวในฐานข้อมูล
โดยเทียบตัวเลขบนหน้าเว็บกับผลจาก SQL', 2],
        ],
    ],

    // ===== ปวส. DevOps (ไม่มี IoT) — เหมือน POS-DEVOPS แต่ไม่มีส่วนที่ 3 (IoT) เลย คะแนนส่วนที่ 1/2/4 เพิ่มขึ้นแทน ตามเอกสารทางการ =====
    [
        'division_id' => $devopsNoIotDivisionId, 'code' => 'S1', 'name' => 'ส่วนที่ 1 Network ตั้งค่าพร้อมทั้งทดสอบอุปกรณ์เครือข่าย', 'max_score' => 13, 'sort_order' => 1, 'is_bonus' => 0,
        'groups' => [
            ['1.1', 'VLAN Configuration', null],
            ['1.2', 'Inter-VLAN Routing', 'ตั้งค่า IP Interface และ Routing ระหว่าง VLAN'],
            ['1.3', 'แจก IP DHCP ด้วย Linux Server เครื่องเดียวที่รับผิดชอบแจก DHCP 3 เครือข่าย พร้อม DHCP Snooping', 'ต้องใช้ Linux Server ในการทำด้วยการ์ดเครือข่าย 1 การ์ดและใช้การทำ Sub Interface ในการแบ่งหน้าที่รับผิดชอบแต่ละ VLAN'],
            ['1.4', 'การทำ NAT เพื่อติดต่อกับเครื่องของกรรมการได้', 'ใช้ NAT เพื่อให้อุปกรณ์เชื่อมต่อเครือข่ายที่จัดเตรียมได้'],
            ['1.5', 'การทำ Access Control List', 'การตั้งค่าความปลอดภัยจาก Switch ทั้ง Standard ACL และ Extended ACL'],
        ],
        'criteria' => [
            ['1.1', 'มี VLAN server, dev, guest เท่านั้น และไม่ใช่ Default VLAN', 'ตรวจสอบรายการ VLAN ที่สร้างไว้บน Switch เช่น ใช้คำสั่ง show vlan brief หรือดูจากหน้า GUI ของอุปกรณ์ — ต้องมีครบทั้ง 3 VLAN (server, dev, guest) เท่านั้น ห้ามมีพอร์ตที่ยังอยู่ใน VLAN 1 (Default VLAN) ให้คะแนนแบบแบ่งย่อย VLAN ละ 0.33 คะแนน', 1, '1.1'],
            ['1.2.1', 'มี IP ทั้ง VLAN server, dev, guest', 'ตรวจสอบการตั้งค่า IP Interface ของทั้ง 3 VLAN เช่น ใช้คำสั่ง show ip interface brief — ต้องมี IP ตรงตามแผ่นโจทย์ครบทั้ง 3 VLAN ได้ 2 คะแนน  ถ้าได้ 2 VLAN ได้ 1 คะแนน ถ้าได้แค่ 1 หรือไม่ได้เลย ได้ 0 คะแนน', 2, '1.2'],
            ['1.2.2', 'ทำ Routing ระหว่าง VLAN ได้ครบทั้งสาม', 'ทดสอบ Ping ข้าม VLAN เช่น จากเครื่องใน VLAN Dev ping ไปยังเครื่องใน VLAN Server และ VLAN Guest — ต้อง Ping ผ่านครบทั้งสองเส้นทาง ไม่ครบได้ 0', 1, '1.2'],
            ['1.3.1', 'แจก DHCP ได้ 1 VLAN (VLAN Dev หรือ Server)', 'ให้เครื่องลูกข่ายใน VLAN Dev หรือ Server ขอ IP ใหม่ เช่น สั่ง ipconfig /renew หรือรีสตาร์ท interface — ต้องได้ IP และ Gateway ตรงตามแผ่นโจทย์อย่างน้อย 1 VLAN', 1, '1.3'],
            ['1.3.2', 'แจก DHCP ได้ 2 VLAN (VLAN Dev และ Server)', 'ทดสอบเช่นเดียวกับข้อก่อนหน้า แต่ต้องได้ผลถูกต้องครบทั้ง VLAN Dev และ VLAN Server พร้อมกัน', 1, '1.3'],
            ['1.3.3', 'ให้ Switch ยอมรับการใช้งาน DHCP Server เฉพาะ Port ที่กำหนดได้', 'ตรวจสอบการตั้งค่า DHCP Snooping บน Switch เช่น ใช้คำสั่ง show ip dhcp snooping — ต้องเปิดใช้งานและกำหนด Trusted Port เฉพาะพอร์ตที่ต่อกับ DHCP Server ตัวจริงตามผังเครือข่ายของทีม และทดสอบ ย้าย DHCP Server ไปเสียบยัง Port Access/UnTag อื่น ที่ Untrust จะต้องไม่แจก IP ออกมาให้', 1, '1.3'],
            ['1.4', 'NAT ได้ทุก VLAN เพื่อใช้งานเครือข่ายภายนอก', 'ทดสอบจากเครื่องในแต่ละ VLAN ให้เชื่อมต่อออกสู่เครือข่ายภายนอก เช่น สั่ง ping ไปยัง IP ของกรรมการ หรือ curl ไปยังปลายทางที่กำหนด — ต้องเชื่อมต่อได้ครบทุก VLAN', 1, '1.4'],
            ['1.5.1', 'Standard ACL — การทำ ACL ควบคุมการสื่อสารภายในเครือข่าย', 'ทดสอบ Ping ข้าม VLAN ที่ถูกบล็อกด้วย Standard ACL เช่น จาก VLAN Guest ping ไปยังเครื่องใน VLAN Dev — ต้อง Ping ไม่ผ่านตามกฎที่ตั้งไว้', 1, '1.5'],
            ['1.5.2', 'Extended ACL — การทำ ACL อนุญาตเฉพาะ Service ที่กำหนด', 'ทดสอบเข้าถึงเฉพาะ Service ที่อนุญาต เช่น ทดสอบพอร์ตที่กำหนดในแผ่นโจทย์ — ต้องผ่านเฉพาะ Service ที่อนุญาต ส่วน Service อื่นต้องถูกปฏิเสธ', 1, '1.5'],
            ['1.5.3', 'การทำ ACL ป้องกันการเข้าถึง Server', 'ทดสอบเข้าถึง Server จาก VLAN ที่ไม่ได้รับอนุญาต เช่น จาก VLAN Guest พยายามเข้าถึง Server — ต้องถูกบล็อกตามกฎ ACL ที่ตั้งค่าไว้', 1, '1.5'],
            ['1.5.4', 'การทำ ACL สำหรับ Management VLAN', 'ทดสอบเข้าถึงอุปกรณ์จัดการเครือข่าย (เช่น หน้า Admin ของ Switch) จาก VLAN ที่ไม่ได้รับอนุญาต — ต้องถูกบล็อก เข้าได้เฉพาะจาก Management VLAN ตามที่กำหนด', 1, '1.5'],
            ['1.5.5', 'การตั้งค่าเพื่อป้องกัน Loop บน Switch', 'ตรวจสอบการตั้งค่า Spanning Tree Protocol (STP) บน Switch เช่น ใช้คำสั่ง show spanning-tree — ต้องเปิดใช้งานเพื่อป้องกัน Loop ของเครือข่าย', 1, '1.5'],
        ],
    ],
    [
        'division_id' => $devopsNoIotDivisionId, 'code' => 'S2', 'name' => 'ส่วนที่ 2 ตั้งค่าเครือข่ายตามผังระบบเครือข่าย', 'max_score' => 24, 'sort_order' => 2, 'is_bonus' => 0,
        'groups' => [
            ['2.1', 'ติดตั้งระบบ Virtualization เช่น Proxmox, VirtualBox, VMware', 'ใช้ Virtualization  ที่ทำงานในรูปแบบ Type-1 Hypervisor เท่านั้น หากใช้ VMware Workstation ที่ทำงานบนระบบปฏิบัติการเช่น Windows หรือ Linux จะไม่ได้คะแนนและไม่พิจารณาตรวจในส่วนนี้'],
            ['2.2', 'ติดตั้ง Linux Server บน VM โดย ISO ได้แก่ Ubuntu, Debian', 'ห้ามใช้ Linux สำเร็จรูปเช่น Trunkey หรือ LXC Template บน Proxmox'],
            ['2.3', 'บริหารจัดการจากระยะไกล SSH / Remote Tools', null],
            ['2.4', 'ติดตั้งเครื่องมือ DevOps ได้แก่ Docker + GitLab', null],
            ['2.5', 'เขียน Dockerfile / docker-compose.yaml ใช้งานจริง', null],
            ['2.6', 'กำหนด CI/CD Pipeline', null],
            ['2.7', 'ทดสอบใช้งาน Pipeline', null],
        ],
        'criteria' => [
            ['2.1.1', 'ติดตั้งระบบ Virtualization ได้ มี GUI ใช้งานได้', 'เปิดหน้า GUI ของ Virtualization เช่น Proxmox/VirtualBox/VMware — ต้องเข้าใช้งานได้จริง', 1, '2.1'],
            ['2.1.2', 'สร้าง VM ได้ตามแผนผัง มีโครงสร้างตามโจทย์', 'ดูรายการ VM ที่สร้างไว้ เช่น เปิดหน้า VM list — ต้องมีครบตามจำนวนและโครงสร้างที่โจทย์กำหนด', 1, '2.1'],
            ['2.1.3', 'ตั้งค่า IP Address Virtualization ตามที่คณะกรรมการกำหนด', 'ตรวจสอบ IP ของ Virtualization Host เช่น เปิดด้วย URL/IP ที่กรรมการกำหนด — ต้องตรงตามแผ่นโจทย์', 1, '2.1'],
            ['2.2.1', 'ติดตั้งได้และบูตใช้งานได้ และเปิดอัตโนมัติเมื่อ Virtualization ดับแล้วเปิดขึ้นมาใหม่', 'สั่งรีบูต Virtualization Host หรือดูตัวเลือก Start at boot ของ VM — VM ต้องเปิดใช้งานได้เองหลัง Host เปิดใหม่', 1, '2.2'],
            ['2.2.2', 'ตั้งค่า IP แบบ Static / DHCP ได้ถูกต้องตามผังที่กรรมการกำหนดให้', 'สั่ง ip a หรือ ipconfig บนเครื่อง VM — IP ต้องตรงแผ่นโจทย์ทั้งสองเครื่อง', 1, '2.2'],
            ['2.2.3', 'ตั้งค่า hostname และ Domain name ตามที่คณะกรรมการกำหนด', 'สั่ง hostname -f บนเครื่อง VM — ชื่อ hostname/domain ต้องตรงแผ่นโจทย์ทั้งสองเครื่อง', 1, '2.2'],
            ['2.2.4', 'มีการติดตั้งและใช้งาน DNS Server ใน Network ของตนเอง บน Docker หรือ Linux Server ตามชื่อที่กรรมการกำหนด โดยเครื่องลูกข่ายสามารถเรียกใช้งาน DNS Server ได้', 'ให้เครื่องลูกข่าย resolve ชื่อโดเมนผ่าน DNS Server ของทีม เช่น สั่ง nslookup — ต้อง resolve ได้ถูกต้อง (ตั้งค่า DNS บน Router/Switch/Hosts file แทนไม่ได้คะแนน ต้องเป็น DNS Server จริงเท่านั้น)', 1, '2.2'],
            ['2.2.5', 'กำหนดการเข้าถึง DNS Server ใน Network โดยใช้ IP Address ที่กำหนดเท่านั้น (ACL)', 'ทดสอบเข้าถึง DNS Server จาก IP ที่ไม่ได้รับอนุญาต — ต้องถูกบล็อกตามกฎ ACL ที่ตั้งไว้ เข้าได้เฉพาะ IP ที่กำหนด', 1, '2.2'],
            ['2.3.1', 'เปิดบริการ SSH', 'สั่ง systemctl status ssh บนเครื่อง VM — บริการต้องทำงาน (active/running) ทั้งสองเครื่อง', 1, '2.3'],
            ['2.3.2', 'เพิ่ม User ตามที่กรรมการกำหนดได้ อย่างน้อย 2 User', 'ดูรายชื่อ User ในเครื่อง เช่น สั่ง cat /etc/passwd — ต้องมี User ครบตามที่กรรมการกำหนดอย่างน้อย 2 บัญชี และต้องไม่ใช้ User root ในการสั่งงานเป็นหลัก แต่ใช้ sudo ได้', 1, '2.3'],
            ['2.3.3', 'Remote จากเครื่องอื่นใน Network เข้ามาได้ด้วย user และ Port ที่กรรมการกำหนด', 'จากเครื่องอื่นสั่ง ssh -p <พอร์ต> <user>@<IP> — ต้องเข้าได้ด้วย User และ Port ตามที่กำหนด', 1, '2.3'],
            ['2.3.4', 'ตั้งค่า firewall (UFW / firewalld) เพื่อเปิดพอร์ตบริการได้ ตามที่กรรมการกำหนด', 'สั่ง ufw status หรือ firewall-cmd --list-all — ต้องเปิดเฉพาะพอร์ตบริการตามแผ่นโจทย์เท่านั้น', 1, '2.3'],
            ['2.3.5', 'กำหนดให้ user ที่ Login เข้ามาสามารถใช้คำสั่ง sudo -i โดยไม่ต้องยืนยันรหัสผ่าน', 'Login ด้วย user ที่กำหนดแล้วสั่ง sudo -i — ต้องใช้สิทธิ์ root ได้ทันทีโดยไม่ถามรหัสผ่านซ้ำ', 1, '2.3'],
            ['2.4.1', 'ติดตั้ง Docker ได้และใช้งานได้ เช่น docker ps, run, exec', 'สั่ง docker ps, docker run, docker exec บนเครื่อง — ต้องทำงานได้ครบทั้ง 3 คำสั่ง', 1, '2.4'],
            ['2.4.2', 'ติดตั้ง GitLab ได้ใน container หรือ VM โดยเข้าเว็บได้', 'เปิดหน้าเว็บ GitLab ตาม URL ที่ทีมตั้งไว้ — ต้องเข้าใช้งานได้จริง', 1, '2.4'],
            ['2.4.3', 'สร้าง User ใน Gitlab และเข้าหน้า UI ได้', 'Login ด้วย User ที่ทีมสร้างไว้ใน GitLab — ต้องมี User ตามบทบาท (backend, frontend, devops) และเข้าหน้า UI ได้ โดยการทำงานร่วมกัน จะต้องมีการสร้าง Team หรือ Group ขึ้นมาก่อน', 1, '2.4'],
            ['2.5.1', 'สร้างและใช้งาน Dockerfile สำหรับแอป Node/เว็บที่พัฒนา ได้', 'สั่ง docker build จาก Dockerfile ของทีม แล้ว docker run — ต้อง Build สำเร็จและรัน Container ได้จริง', 1, '2.5'],
            ['2.5.2', 'ใช้ docker-compose.yaml สำหรับบริการ reverse proxy เพื่อใช้บริการ https (caddy, NPM)', 'ดูไฟล์ docker-compose.yaml ของบริการ reverse proxy แล้วเปิดเว็บด้วย https — ต้องใช้งาน https ได้จริงผ่าน reverse proxy ที่ตั้งค่าไว้', 1, '2.5'],
            ['2.5.3', 'ใช้ reverse proxy กับ host และ domain ของระบบที่พัฒนาได้ (registry, gitlab, frontend, backend)', 'เปิดด้วยโดเมน โดยเข้าใช้ผ่านทาง http หรือ https ได้เช่น http://gitlab.mydomain.lab, https://registry..., https://app... — ต้องเปิดได้ครบทุกชื่อ (ยอมรับคำเตือนใบรับรองที่ออกเองได้)', 1, '2.5'],
            ['2.5.4', 'ใช้ docker-compose.yaml สำหรับบริการมากกว่า 1 (เช่น app + db) ใน 1 compose', 'เปิดไฟล์ docker-compose.yaml และสั่ง docker compose ps — ต้องมีอย่างน้อย 2 บริการทำงานร่วมกันในไฟล์เดียว เช่น app และ db  โดยต้องประกอบด้วย services , networks , volumes เป็นอย่างน้อย', 1, '2.5'],
            ['2.6', 'สร้าง .gitlab-ci.yml เชื่อมโยงกับการกำหนด State ครบทั้ง 4 ขั้นตอน Build, Test, Push ขึ้น Registry ของตนเอง, Deploy ไปยัง Server ของตนเอง', 'อ่านไฟล์ .gitlab-ci.yml — ต้องมี Stage ครบ build, test, push, deploy และเขียนคำสั่งถูกต้องตามหลักการ (พิจารณาความถูกต้องของคำสั่งที่เขียน ไม่พิจารณาว่ารันผ่านจริงหรือไม่)', 1, '2.6'],
            ['2.7.1', 'Push code แล้วเกิด Trigger ได้จริง', 'สั่ง git push ตามที่กรรมการกำหนด แล้วดูหน้า Pipeline ของ GitLab — Pipeline ต้องเริ่มทำงานเองโดยอัตโนมัติ', 0.5, '2.7'],
            ['2.7.2', 'กำหนด Stage ครบ 4 ขั้นตอน (build, test, push ขึ้น registry, deploy) และทำงานได้จริงทุกขั้นตอน', 'ดูผลการรัน Pipeline และหน้า Production จริง — ต้องผ่านครบทั้ง 4 Stage และเว็บต้องเปลี่ยนตามโค้ดใหม่ที่ Deploy (ขาดข้อใดข้อหนึ่งไม่ได้คะแนนข้อนี้เลย ไม่มีการหารเฉลี่ย)', 2, '2.7'],
            ['2.7.3', 'ระบบสามารถ build และสร้าง image ได้', 'ดู Job build ใน Pipeline และรายการ Image ใน Registry — ต้องสร้าง Image สำเร็จจริง', 0.5, '2.7'],
        ],
    ],
    [
        'division_id' => $devopsNoIotDivisionId, 'code' => 'S4', 'name' => 'ส่วนที่ 4 ซอฟต์แวร์และการประยุกต์', 'max_score' => 53, 'sort_order' => 4, 'is_bonus' => 0,
        'groups' => [
            ['4.1', 'การออกแบบผังงาน (Flowchart) ตามความต้องการของผู้ใช้ (Requirement)', null],
            ['4.2', 'การออกแบบฐานข้อมูล (Database Design) พร้อม Class Diagram', null],
            ['4.3', 'การออกแบบหน้าจอด้วยโปรแกรมสำเร็จรูป', null],
            ['4.4', 'การออกแบบ RESTful API สำหรับใช้งานในระบบ (Design / README.md)', null],
            ['4.5', 'พัฒนาระบบ Back-end การพัฒนา API', null],
            ['4.6', 'พัฒนาระบบ Front-end', null],
        ],
        'criteria' => [
            ['4.1.1', 'จัดทำผังงานระบบการเข้าสู่ระบบ (Login Flow)', 'เทียบผังงานที่จัดทำกับระบบ Login ที่พัฒนาจริง — ขั้นตอนในผังงานต้องตรงกับการทำงานจริงของระบบ', 1, '4.1'],
            ['4.1.2', 'จัดทำผังงานกระบวนการของผู้ดูแลระบบ', 'เทียบผังงานกับหน้าที่/เมนูของผู้ดูแลระบบที่พัฒนาจริง — ต้องสอดคล้องกัน', 1, '4.1'],
            ['4.1.3', 'จัดทำผังงานกระบวนการของผู้ใช้งานระดับ 1', 'เทียบผังงานกับหน้าที่/เมนูของผู้ใช้งานระดับ 1 ที่พัฒนาจริง — ต้องสอดคล้องกัน', 1, '4.1'],
            ['4.1.4', 'จัดทำผังงานกระบวนการของผู้ใช้งานระดับ 2', 'เทียบผังงานกับหน้าที่/เมนูของผู้ใช้งานระดับ 2 ที่พัฒนาจริง — ต้องสอดคล้องกัน', 1, '4.1'],
            ['4.1.5', 'จัดทำผังงานภาพรวมของระบบทั้งหมด (Usecase Diagram)', 'เทียบ Usecase Diagram กับฟังก์ชันทั้งหมดของระบบที่พัฒนาจริง — ต้องครอบคลุมและสอดคล้องกัน', 1, '4.1'],
            ['4.2.1', 'มีการสร้างฐานข้อมูลอย่างน้อย 3 ตาราง', 'เปิดดูโครงสร้างฐานข้อมูลจริง เช่น SHOW TABLES — ต้องมีอย่างน้อย 3 ตาราง', 1, '4.2'],
            ['4.2.2', 'ตารางฐานข้อมูลมีความสัมพันธ์กันอย่างเหมาะสม (Relational Design)', 'ดูความสัมพันธ์ระหว่างตาราง เช่น Foreign Key ที่กำหนดไว้ — ต้องออกแบบความสัมพันธ์ได้อย่างเหมาะสมกับข้อมูลจริง', 1, '4.2'],
            ['4.2.3', 'มีการเลือกใช้ประเภทข้อมูล (Data Type) ได้เหมาะสมกับชนิดของข้อมูล', 'ดูชนิดข้อมูล (Data Type) ของแต่ละฟิลด์ เช่น วันที่ใช้ DATE/DATETIME ตัวเลขใช้ INT/DECIMAL — ต้องเลือกใช้เหมาะสมกับลักษณะข้อมูลจริง', 1, '4.2'],
            ['4.2.4', 'แสดง Class Diagram ที่สามารถอธิบายโครงสร้างได้อย่างถูกต้องและสมบูรณ์', 'เทียบ Class Diagram กับโครงสร้างฐานข้อมูล/โค้ดจริง — ต้องอธิบายความสัมพันธ์และ Attribute ได้ถูกต้องครบถ้วน', 1, '4.2'],
            ['4.2.5', 'มีการตั้งชื่อตารางและฟิลด์สอดคล้องกับวัตถุประสงค์ของระบบ และเป็นไปตามมาตรฐานการเขียนชื่อทางเทคนิค', 'ดูชื่อตาราง/ฟิลด์จริงในฐานข้อมูล — ต้องสื่อความหมาย เขียนตามมาตรฐาน (เช่น snake_case) และสอดคล้องกับวัตถุประสงค์การใช้งาน', 1, '4.2'],
            ['4.3.1', 'มี Template Layout สำหรับหน้าจอผู้ดูแลระบบ', 'เปิดหน้าจอผู้ดูแลระบบที่พัฒนาจริง — ต้องมี Layout ที่ออกแบบไว้ใช้งานได้จริง', 1, '4.3'],
            ['4.3.2', 'มี Template Layout สำหรับหน้าจอผู้ใช้งานระดับ 1', 'เปิดหน้าจอผู้ใช้งานระดับ 1 ที่พัฒนาจริง — ต้องมี Layout ที่ออกแบบไว้ใช้งานได้จริง', 1, '4.3'],
            ['4.3.3', 'มี Template Layout สำหรับหน้าจอผู้ใช้งานระดับ 2', 'เปิดหน้าจอผู้ใช้งานระดับ 2 ที่พัฒนาจริง — ต้องมี Layout ที่ออกแบบไว้ใช้งานได้จริง', 1, '4.3'],
            ['4.3.4', 'มี Template Layout สำหรับหน้าจอหลักของระบบ (Dashboard/Overview)', 'เปิดหน้า Dashboard/Overview ที่พัฒนาจริง — ต้องมี Layout ที่ออกแบบไว้ใช้งานได้จริง', 1, '4.3'],
            ['4.3.5', 'มี Template Layout สำหรับหน้าจอเข้าสู่ระบบ (Login Screen)', 'เปิดหน้า Login ที่พัฒนาจริง — ต้องมี Layout ที่ออกแบบไว้ใช้งานได้จริง', 1, '4.3'],
            ['4.4.1', 'ออกแบบ API ตามหลัก RESTful (เช่น ใช้ GET, POST, PUT/Patch, DELETE อย่างเหมาะสม)', 'อ่านเอกสารออกแบบ API หรือ README.md — ต้องเลือกใช้ HTTP Method ตรงกับลักษณะการทำงานของแต่ละ Endpoint', 1, '4.4'],
            ['4.4.2', 'ตั้งชื่อ Endpoint สื่อความหมาย และมีความสอดคล้องกัน เช่น /api/teachers, /api/scores/{id}', 'ดูรายชื่อ Endpoint ทั้งหมด — ต้องตั้งชื่อสื่อความหมายและใช้รูปแบบสอดคล้องกันทั้งระบบ', 1, '4.4'],
            ['4.4.3', 'มีตัวอย่างรูปแบบ Response Body ที่สอดคล้องกับ HTTP Status Code อย่างเหมาะสม', 'อ่านเอกสาร/README.md ที่มีตัวอย่าง Response — ต้องมีตัวอย่างและ Status Code ตรงกับสถานการณ์ (เช่น 200 สำเร็จ, 201 สร้างสำเร็จ)', 1, '4.4'],
            ['4.4.4', 'มีการออกแบบรองรับกรณีเกิด Error เช่น Unauthorized (401), Not Found (404)', 'อ่านเอกสารออกแบบ — ต้องระบุกรณี Error ที่อาจเกิดขึ้นพร้อม Status Code ที่ถูกต้อง', 1, '4.4'],
            ['4.4.5', 'มีแนวทางด้านความปลอดภัยของ API เช่น JWT Token หรือการตรวจสอบสิทธิ์การเข้าถึง', 'อ่านเอกสารออกแบบความปลอดภัย — ต้องระบุแนวทางป้องกัน เช่น การใช้ JWT Token ตรวจสอบสิทธิ์ก่อนเข้าถึงข้อมูล', 1, '4.4'],
            ['4.5.1', 'สร้าง API แบบ GET พร้อมรับค่า (Query หรือ Path Parameters)', 'เรียก API ด้วย Query String หรือ Path Parameter เช่น GET /api/teams?id=1 — ผลลัพธ์ต้องเปลี่ยนตามค่าพารามิเตอร์ที่ส่ง', 1, '4.5'],
            ['4.5.2', 'สร้าง API แบบ POST ที่รับข้อมูลจาก Body อย่างถูกต้อง', 'ส่งข้อมูลแบบ JSON หรือฟอร์มผ่าน POST เช่นด้วย Postman — ข้อมูลต้องถูกบันทึกลงฐานข้อมูลถูกต้อง', 1, '4.5'],
            ['4.5.3', 'สร้าง API สำหรับอัปโหลดไฟล์', 'ส่งไฟล์ผ่าน API ด้วย multipart/form-data — ต้องอัปโหลดสำเร็จและเปิดไฟล์ที่เก็บไว้ได้', 1, '4.5'],
            ['4.5.4', 'สร้าง API Login โดยใช้ JWT Token (ซึ่งมีการลงนาม(Signing)เพื่อความปลอดภัย และใช้สำหรับตรวจสอบสิทธิ์ในการเข้าถึงระบบ)', 'เรียก API Login ด้วย Username/Password แล้วตรวจสอบ Token ที่ได้รับ เช่น ถอดรหัสด้วย jwt.io — Token ต้องมีลายเซ็นถูกต้อง และใช้เรียก API อื่นเพื่อตรวจสอบสิทธิ์ได้', 1, '4.5'],
            ['4.5.5', 'มีการจัดการข้อผิดพลาด (Exception Handling) เช่น ตรวจสอบข้อมูลไม่ครบ, ไม่พบข้อมูล', 'ส่งข้อมูลไม่ครบ และเรียกหา id ที่ไม่มีอยู่จริง — ระบบต้องตอบ 400/422 และ 404 ตามลำดับ ไม่ใช่ 500', 1, '4.5'],
            ['4.5.6', 'API ส่ง Response ที่เหมาะสม เช่น JSON มี status, message เพิ่มเติม, data อย่างเป็นระบบ', 'ดูโครงสร้าง JSON ที่ API ตอบกลับ — ต้องมี status, message, data ในรูปแบบเดียวกันทุก Endpoint', 1, '4.5'],
            ['4.5.7', 'มีคำอธิบาย API และการเรียกใช้แต่ละ Service (GET, POST, PUT/PATCH, DELETE) ครบอย่างน้อย Method ละ 1 API (เขียน Comment)', 'อ่านโค้ด/เอกสาร — ต้องมี Comment อธิบายครบทุก Method อย่างน้อย Method ละ 1 API', 1, '4.5'],
            ['4.5.8', 'ระบุข้อมูลที่ใช้ส่งในแต่ละ API เช่น query, path, body อย่างชัดเจน', 'อ่าน Comment หรือ README.md ของแต่ละ API — ต้องระบุชัดเจนว่าแต่ละ API รับค่าแบบใด (query/path/body)', 1, '4.5'],
            ['4.5.9', 'โค้ด Back-end มีโครงสร้างดี อ่านง่าย โดยใช้ router หรือ async/await อย่างเหมาะสม', 'อ่านโค้ด Back-end — ต้องแยกไฟล์ตาม Router/Controller และใช้ async/await หรือรูปแบบจัดการ asynchronous อย่างเหมาะสม', 1, '4.5'],
            ['4.5.10', 'API มีความปลอดภัย และมีระบบตรวจสอบสิทธิ์ก่อนเข้าถึงข้อมูล', 'เรียก API ที่ต้องมีสิทธิ์โดยไม่ส่ง Token และส่ง Token ของสิทธิ์ที่ไม่ถูกต้อง — ต้องตอบ 401 และ 403 ตามกรณีตามลำดับ', 1, '4.5'],
            ['4.6.1', 'มี Layout ใช้งานง่าย มี Responsive', 'ย่อหน้าจอเป็นขนาดมือถือ (หรือใช้ DevTools จำลอง) — หน้าจอต้องปรับตามขนาดได้ ไม่ล้นจอ ใช้งานได้จริง', 1, '4.6'],
            ['4.6.2', 'มีหน้าจอ ระบบ Login หรือ Authentication ด้วย JWT ที่ใช้งานได้จริง', 'ทดสอบ Login ด้วยรหัสผ่านถูกและผิด — รหัสถูกต้องเข้าระบบได้ รหัสผิดต้องเข้าไม่ได้ และมีการใช้ JWT จริง', 1, '4.6'],
            ['4.6.3', 'แยกระบบ Login ของกลุ่มผู้ใช้งานแต่ละระดับได้ ด้วย JWT', 'Login ด้วยบัญชีแต่ละระดับ (เช่น ผู้ดูแลระบบ/ผู้ใช้ระดับ 1/ระดับ 2) — ต้องเข้าหน้าและเมนูตามสิทธิ์ของระดับนั้น ๆ ถูกต้อง', 1, '4.6'],
            ['4.6.4', 'มีการเพิ่มข้อมูลได้สมบูรณ์ตามแบบฟอร์มที่ออกแบบ', 'กรอกข้อมูลผ่านฟอร์มที่พัฒนาไว้แล้วบันทึก — ข้อมูลต้องเข้าสู่ระบบครบถ้วนตามที่กรอก', 1, '4.6'],
            ['4.6.5', 'มีการแนบไฟล์พร้อมกับเพิ่มข้อมูลได้สมบูรณ์', 'เพิ่มรายการพร้อมแนบไฟล์ แล้วเปิดไฟล์จากรายการที่บันทึก — ต้องเปิดไฟล์ที่แนบไว้ได้จริง', 1, '4.6'],
            ['4.6.6', 'มีการแก้ไขและปรับปรุงข้อมูลได้', 'แก้ไขข้อมูลแล้วเปิดดูใหม่ — ค่าที่แก้ไขต้องถูกบันทึกและแสดงผลถูกต้อง', 1, '4.6'],
            ['4.6.7', 'มีการแก้ไขไฟล์แนบที่เกี่ยวข้องกับข้อมูลได้', 'เปลี่ยนไฟล์แนบของรายการเดิมแล้วเปิดดูใหม่ — ไฟล์ใหม่ต้องแทนที่ไฟล์เดิม', 1, '4.6'],
            ['4.6.8', 'มีแจ้งเตือนการลบข้อมูลได้', 'กดปุ่มลบข้อมูล — ต้องมีหน้าต่างยืนยันก่อนลบจริง และสามารถยกเลิกได้', 1, '4.6'],
            ['4.6.9', 'มีระบบค้นหา/Filter ข้อมูลในหน้า UI ตามสิทธิ์ผู้ใช้งานแต่ละระดับ', 'ทดสอบค้นหา/กรองข้อมูลในแต่ละระดับผู้ใช้ — ผลลัพธ์ต้องตรงเงื่อนไขที่กรอกและไม่เกินขอบเขตสิทธิ์', 1, '4.6'],
            ['4.6.10', 'มีระบบการแบ่งหน้าแสดงข้อมูล ในหน้า UI ตามสิทธิ์ผู้ใช้งานแต่ละระดับ', 'เปลี่ยนหน้า (pagination) ในแต่ละระดับผู้ใช้ — ต้องแบ่งหน้าได้ถูกต้อง ข้อมูลไม่ซ้ำกันระหว่างหน้า', 1, '4.6'],
            ['4.6.11', 'มีการ Validate ข้อมูล ฝั่ง Frontend ก่อนส่งไปยัง Backend', 'เว้นช่องที่บังคับกรอกแล้วกดส่งฟอร์ม — ต้องมีข้อความแจ้งเตือนก่อนที่ข้อมูลจะถูกส่งไป Backend', 1, '4.6'],
            ['4.6.12', 'มีการเขียนโปรแกรมที่มีการทำ Error Handling ในฝั่ง Frontend (ข้อความแจ้งข้อผิดพลาดที่ผู้ใช้งานเข้าใจง่าย)', 'ตัดการเชื่อมต่อ API แล้วลองใช้งานหน้าเว็บ — ต้องแสดงข้อความแจ้งข้อผิดพลาดที่เข้าใจง่าย ไม่ใช่หน้าจอ error ดิบ', 1, '4.6'],
            ['4.6.13', 'มีการเขียนโปรแกรมที่มีการแสดงข้อความผิดพลาด ที่เกิดจาก Error ฝั่ง Backend (การแปลงรหัสข้อผิดพลาดเป็นข้อความที่เหมาะสม)', 'ทำให้เกิด Error จาก Backend เช่น 401, 403, 404 — Frontend ต้องแปลงเป็นข้อความที่ผู้ใช้เข้าใจได้ ไม่ใช่แสดงรหัส error ดิบ', 1, '4.6'],
            ['4.6.14', 'มีการสร้าง Dockerfile สำหรับ Backend', 'สั่ง docker build ด้วย Dockerfile ของ Backend แล้วรัน Container — ต้อง Build และรันได้สำเร็จ', 1, '4.6'],
            ['4.6.15', 'เขียน Pipeline config ประกอบด้วย stages build, test, push, deploy ทำงานได้ทุก stages', 'อ่านไฟล์ Pipeline config แล้วดูผลการรัน — ต้องมีครบ 4 stages และทำงานได้จริงทุก stage', 1, '4.6'],
            ['4.6.16', 'เขียน Pipeline ทำงานอัตโนมัติเมื่อมี Push ขึ้น Git Server (Auto Build/Test)', 'สั่ง git push แล้วดูหน้า Pipeline — ต้องเริ่ม Build/Test เองโดยอัตโนมัติโดยไม่ต้องกดรันเอง', 1, '4.6'],
            ['4.6.17', 'มี Test ขั้นพื้นฐานใน Pipeline (Unit Testing / Integration Testing) อย่างละ 1 Test Case', 'ดู Job test ใน Pipeline — ต้องมีทั้ง Unit Test และ Integration Test อย่างน้อยอย่างละ 1 กรณี และรันผ่าน', 1, '4.6'],
            ['4.6.18', 'มี E to E Test เป็นการทดสอบ Work flow ตั้งแต่เริ่มต้นจนจบ (เช่น E2E-001 ผู้ใช้เข้าสู่ระบบสำเร็จ, E2E-002 ผู้ใช้ส่งข้อมูลสำเร็จ — กรรมการจัดการแข่งขันกำหนดเอง)', 'รันกรณีทดสอบ E2E ตามที่กรรมการจัดการแข่งขันกำหนด เช่น Login แล้วเห็นหน้า Dashboard, กรอกฟอร์มแล้วบันทึกสำเร็จ — ต้องผ่านครบทุกกรณีที่กำหนด', 2, '4.6'],
            ['4.6.19', 'มีการใช้ Git และ Branch Strategy ในการกำหนดบทบาท Backend / Frontend / DevOps (merge)', 'ดูประวัติ Git เช่น git log --graph หรือหน้า Branches ใน GitLab — ต้องแยก Branch ตามบทบาท backend/frontend/devops และมีการ Merge เข้า main', 1, '4.6'],
            ['4.6.20', 'ความสมบูรณ์ของระบบทั้งหมด Application', 'ทดสอบใช้งานระบบครบวงจรบนเครื่อง Production จริง ตั้งแต่ต้นจนจบ — ให้คะแนนตามความสมบูรณ์โดยรวมของระบบที่ใช้งานได้จริง', 3, '4.6'],
        ],
    ],
    [
        'division_id' => $devopsNoIotDivisionId, 'code' => 'S5', 'name' => 'ส่วนที่ 5 การนำเสนอ', 'max_score' => 10, 'sort_order' => 5, 'is_bonus' => 0,
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
            ['1.1', 'ผังงานระบบการเข้าสู่ระบบ', 'ตรวจสอบผังงาน (Flowchart) ที่จัดทำ เทียบกับขั้นตอนการเข้าสู่ระบบจริงของระบบที่พัฒนา เช่น ตรวจสอบเงื่อนไขกรณีชื่อผู้ใช้/รหัสผ่านถูกหรือผิด — ต้องสอดคล้องกับการทำงานจริง', 1],
            ['1.2', 'ผังงานกระบวนการของงานบุคลากร', 'ตรวจสอบผังงานกระบวนการของเจ้าหน้าที่งานบุคลากร (เช่น สร้างหัวข้อประเมิน กำหนดช่วงเวลา มอบหมายกรรมการ) เทียบกับเมนู/ฟังก์ชันที่พัฒนาจริง — ต้องครอบคลุมและสอดคล้องกัน', 1],
            ['1.3', 'ผังงานกระบวนการของผู้รับการประเมิน', 'ตรวจสอบผังงานขั้นตอนของผู้รับการประเมิน (เช่น กรอกข้อมูล แนบหลักฐาน ส่งผล) เทียบกับระบบที่พัฒนาจริง — ต้องสอดคล้องกัน', 1],
            ['1.4', 'ผังงานกระบวนการของกรรมการผู้ประเมิน', 'ตรวจสอบผังงานขั้นตอนของกรรมการผู้ประเมิน (เช่น ดูข้อมูล ให้คะแนน ลงนาม) เทียบกับระบบที่พัฒนาจริง — ต้องสอดคล้องกัน', 1],
            ['1.5', 'ผังงานภาพรวมของระบบทั้งหมด', 'ตรวจสอบ Usecase Diagram ว่าครอบคลุมผู้ใช้งานทุกบทบาท (งานบุคลากร ผู้รับการประเมิน กรรมการ) และฟังก์ชันหลักของระบบ — ต้องครบถ้วนและสอดคล้องกับระบบจริง', 1],
        ],
    ],
    [
        'division_id' => $hrDivisionId, 'code' => 'P2', 'name' => 'ส่วนที่ 2 การออกแบบฐานข้อมูล (Database Design) พร้อม ER-Diagram', 'max_score' => 5, 'sort_order' => 2, 'is_bonus' => 0,
        'criteria' => [
            ['2.1', 'มีการสร้างฐานข้อมูลอย่างน้อย 3 ตาราง', 'เปิดดูโครงสร้างฐานข้อมูลจริง เช่น สั่ง SHOW TABLES — ต้องมีอย่างน้อย 3 ตาราง', 1],
            ['2.2', 'ตารางฐานข้อมูลมีความสัมพันธ์กันอย่างเหมาะสม', 'ดูความสัมพันธ์ระหว่างตาราง เช่น Foreign Key ที่กำหนดไว้ — ต้องออกแบบความสัมพันธ์ได้เหมาะสมกับข้อมูลจริง', 1],
            ['2.3', 'มีการเลือกใช้ประเภทข้อมูล (Data Type) ได้เหมาะสมกับชนิดของข้อมูล', 'ดูชนิดข้อมูล (Data Type) ของแต่ละฟิลด์ เช่น วันที่ใช้ DATE/DATETIME ตัวเลขใช้ INT/DECIMAL — ต้องเลือกใช้เหมาะสมกับลักษณะข้อมูลจริง', 1],
            ['2.4', 'แสดง Class Diagram ที่สามารถอธิบายโครงสร้างได้อย่างถูกต้อง และสมบูรณ์', 'เทียบ Class Diagram กับโครงสร้างฐานข้อมูล/โค้ดจริง — ต้องอธิบายความสัมพันธ์และ Attribute ได้ถูกต้องครบถ้วน', 1],
            ['2.5', 'มีการตั้งชื่อตาราง และฟิลด์สอดคล้องกับวัตถุประสงค์ของระบบ และเป็นไปตามมาตรฐานการเขียนชื่อทางเทคนิค', 'ดูชื่อตาราง/ฟิลด์จริงในฐานข้อมูล — ต้องสื่อความหมาย เขียนตามมาตรฐาน (เช่น snake_case) และสอดคล้องกับวัตถุประสงค์การใช้งาน', 1],
        ],
    ],
    [
        'division_id' => $hrDivisionId, 'code' => 'P3', 'name' => 'ส่วนที่ 3 การออกแบบหน้าจอด้วยโปรแกรมสำเร็จรูป', 'max_score' => 5, 'sort_order' => 3, 'is_bonus' => 0,
        'criteria' => [
            ['3.1', 'Template Layout สำหรับหน้าจอเข้าสู่ระบบ', 'เปิดหน้า Login ที่พัฒนาจริง — ต้องมี Layout ที่ออกแบบไว้ใช้งานได้จริง', 1],
            ['3.2', 'Template Layout สำหรับหน้าจอหลักของระบบ', 'เปิดหน้า Dashboard/Overview ที่พัฒนาจริง — ต้องมี Layout ที่ออกแบบไว้ใช้งานได้จริง', 1],
            ['3.3', 'Template Layout สำหรับหน้าจองานบุคลากร', 'เปิดหน้าจอของเจ้าหน้าที่งานบุคลากรที่พัฒนาจริง — ต้องมี Layout ที่ออกแบบไว้ใช้งานได้จริง', 1],
            ['3.4', 'Template Layout สำหรับหน้าจอผู้เข้ารับการประเมิน', 'เปิดหน้าจอของผู้รับการประเมินที่พัฒนาจริง — ต้องมี Layout ที่ออกแบบไว้ใช้งานได้จริง', 1],
            ['3.5', 'Template Layout สำหรับหน้าจอกรรมการผู้ประเมิน', 'เปิดหน้าจอของกรรมการผู้ประเมินที่พัฒนาจริง — ต้องมี Layout ที่ออกแบบไว้ใช้งานได้จริง', 1],
        ],
    ],
    [
        'division_id' => $hrDivisionId, 'code' => 'P4', 'name' => 'ส่วนที่ 4 การออกแบบ RESTful API สำหรับใช้งานในระบบ', 'max_score' => 5, 'sort_order' => 4, 'is_bonus' => 0,
        'criteria' => [
            ['4.1', 'ออกแบบ API ตามหลัก RESTful', 'อ่านเอกสารออกแบบ API หรือ README.md — ต้องเลือกใช้ HTTP Method ตรงกับลักษณะการทำงานของแต่ละ Endpoint เช่น ใช้ GET, POST, PUT, DELETE อย่างเหมาะสม', 1],
            ['4.2', 'ตั้งชื่อ Endpoint สื่อความหมาย และมีความสอดคล้องกัน', 'ดูรายชื่อ Endpoint ทั้งหมด — ต้องตั้งชื่อสื่อความหมายและสอดคล้องกันทั้งระบบ เช่น /api/teachers, /api/scores/{id}', 1],
            ['4.3', 'มีตัวอย่างรูปแบบ Response Body ที่สอดคล้องกับ HTTP Status Code อย่างเหมาะสม', 'อ่านเอกสาร/README.md ที่มีตัวอย่าง Response — ต้องมีตัวอย่างและ Status Code ตรงกับสถานการณ์ เช่น 200 สำเร็จ, 201 สร้างสำเร็จ', 1],
            ['4.4', 'มีการออกแบบรองรับกรณีเกิด Error', 'อ่านเอกสารออกแบบ — ต้องระบุกรณี Error ที่อาจเกิดขึ้นพร้อม Status Code ที่ถูกต้อง เช่น Unauthorized (401), Not Found (404)', 1],
            ['4.5', 'มีแนวทางด้านความปลอดภัยของ API', 'อ่านเอกสารออกแบบความปลอดภัย — ต้องระบุแนวทางป้องกัน เช่น การใช้ JWT, Token หรือการตรวจสอบสิทธิ์การเข้าถึง', 1],
        ],
    ],
    [
        'division_id' => $hrDivisionId, 'code' => 'P5.1', 'name' => 'ส่วนที่ 5.1 พัฒนาระบบ: งานบุคลากร', 'max_score' => 14, 'sort_order' => 5, 'is_bonus' => 0,
        'criteria' => [
            ['5.1.1', 'เพิ่มหัวข้อการประเมินได้', 'ทดลองเพิ่มหัวข้อการประเมินใหม่ผ่านหน้าจอเจ้าหน้าที่ — ต้องบันทึกและแสดงผลในระบบได้จริง', 1],
            ['5.1.2', 'กำหนดช่วงเวลาเปิด/ปิดการประเมินได้', 'ทดสอบตั้งค่าวันเริ่ม-สิ้นสุดการประเมิน — ระบบต้องเปิด/ปิดให้กรอกข้อมูลได้ตามช่วงเวลาที่กำหนดจริง', 1],
            ['5.1.3', 'เพิ่มตัวชี้วัดในแต่ละหัวข้อได้', 'ทดลองเพิ่มตัวชี้วัดในหัวข้อประเมิน — ต้องกรอกและบันทึกได้ครบทั้ง 4 ส่วน คือ ชื่อ, รายละเอียด, น้ำหนักคะแนน, และหลักฐานที่ใช้แนบ', 1],
            ['5.1.4.1', 'กำหนดรูปแบบการประเมินแบบตัวเลือก "มี/ไม่มี" ได้', 'ทดสอบตั้งค่าตัวชี้วัดเป็นรูปแบบตัวเลือก "มี/ไม่มี" แล้วให้คะแนนจริง — ต้องบันทึกและแสดงผลถูกต้อง', 1],
            ['5.1.4.2', 'กำหนดรูปแบบการประเมิน แบบสเกลคะแนน 1-4 ได้ พร้อมคำอธิบายระดับ', 'ทดสอบตั้งค่าตัวชี้วัดเป็นแบบสเกลคะแนน 1-4 — ต้องมีคำอธิบายระดับครบตามเอกสาร (ระดับ 1-4 ตามคำอธิบายในเอกสาร) และใช้ให้คะแนนได้จริง', 1],
            ['5.1.5', 'เพิ่มข้อมูลผู้รับการประเมินได้', 'ทดลองเพิ่มข้อมูลผู้รับการประเมินใหม่ — ต้องบันทึกและแสดงในระบบได้จริง', 1],
            ['5.1.6', 'เพิ่มข้อมูลกรรมการผู้ประเมินได้', 'ทดลองเพิ่มข้อมูลกรรมการผู้ประเมินใหม่ — ต้องบันทึกและแสดงในระบบได้จริง', 1],
            ['5.1.7', 'คืนค่ารหัสผ่านของผู้รับการประเมินและกรรมการผู้ประเมินได้', 'ทดสอบสั่งรีเซ็ตรหัสผ่าน (Reset Password) ของผู้ใช้ทั้งสองกลุ่ม — ต้องได้รหัสผ่านใหม่และใช้เข้าสู่ระบบได้จริง', 1],
            ['5.1.8', 'มอบหมายกรรมการให้ประเมินผู้รับการประเมินแต่ละคนได้', 'ทดสอบมอบหมายกรรมการให้ประเมินผู้รับการประเมิน — ต้องบันทึกได้ และกรรมการเห็นรายชื่อที่ได้รับมอบหมายจริง', 1],
            ['5.1.9', 'กำหนดบทบาทของกรรมการได้', 'ทดสอบตั้งค่าบทบาทของกรรมการแต่ละคน เช่น ประธาน / กรรมการร่วม — ต้องบันทึกและแสดงบทบาทถูกต้องตามที่กำหนด', 1],
            ['5.1.10', 'แสดงผลสรุปการประเมินรายกรรมการ และรายผู้รับการประเมิน', 'เปิดหน้าสรุปผล — ต้องแสดงตัวชี้วัดและคะแนนครบถ้วนทั้งรายกรรมการและรายผู้รับการประเมิน', 1],
            ['5.1.11', 'ติดตามสถานะการประเมินของกรรมการแต่ละคนได้', 'เปิดหน้าติดตามสถานะ — ต้องแสดงสถานะของกรรมการแต่ละคนตรงกับความคืบหน้าจริง เช่น ยังไม่ได้ประเมิน, ประเมินและบันทึกร่างคะแนน, ยืนยันผลการประเมิน', 1],
            ['5.1.12', 'แสดงค่าร้อยละการบันทึกข้อมูลตามตัวชี้วัดของผู้รับการประเมินได้', 'เปิดหน้าติดตามความคืบหน้า — ต้องแสดงค่าร้อยละการกรอกข้อมูลตามตัวชี้วัดของผู้รับการประเมินถูกต้องตามจริง', 1],
            ['5.1.13', 'แสดงรายงานผลการประเมินรายบุคคลได้', 'เปิดรายงานผลรายบุคคล — ต้องแสดงข้อมูลและคะแนนของผู้รับการประเมินแต่ละคนถูกต้องครบถ้วน', 1],
        ],
    ],
    [
        'division_id' => $hrDivisionId, 'code' => 'P5.2', 'name' => 'ส่วนที่ 5.2 พัฒนาระบบ: ผู้รับการประเมิน', 'max_score' => 10, 'sort_order' => 6, 'is_bonus' => 0,
        'criteria' => [
            ['5.2.1', 'บังคับให้เปลี่ยนรหัสผ่านเมื่อเข้าใช้งานครั้งแรก', 'Login ด้วยบัญชีที่ยังไม่เคยเปลี่ยนรหัสผ่าน — ระบบต้องบังคับให้เปลี่ยนรหัสผ่านก่อนเข้าใช้งานส่วนอื่น', 1],
            ['5.2.2', 'แก้ไขข้อมูลส่วนตัวเบื้องต้น', 'ทดลองแก้ไขข้อมูลส่วนตัวแล้วบันทึก — ค่าที่แก้ไขต้องถูกบันทึกและแสดงผลถูกต้อง', 1],
            ['5.2.3', 'เพิ่มรายละเอียดประกอบการประเมินในแต่ละตัวชี้วัด', 'ทดสอบเพิ่มรายละเอียดประกอบการประเมินในตัวชี้วัดเดียวกัน — ถ้าเพิ่มได้ 1 รายการให้ 1 คะแนน ถ้าเพิ่มได้มากกว่า 1 รายการให้ 2 คะแนน', 2],
            ['5.2.4', 'แนบหลักฐานประกอบการประเมินได้', 'ทดสอบแนบไฟล์ประกอบการประเมิน เช่น PDF, รูปภาพ หรือ URL — ต้องแนบและเปิดดูหลักฐานที่แนบได้จริง', 1],
            ['5.2.5', 'กรอกคะแนนการประเมินของตนเองได้', 'ทดสอบกรอกคะแนนประเมินตนเองในแต่ละตัวชี้วัด — ต้องบันทึกได้ถูกต้อง', 1],
            ['5.2.6', 'แก้ไขข้อมูลการประเมินตนเองในแต่ละตัวชี้วัด', 'ทดสอบแก้ไขคะแนน/ข้อมูลที่ประเมินตนเองไว้แล้ว — ค่าที่แก้ไขต้องถูกบันทึกและแสดงผลถูกต้อง', 1],
            ['5.2.7', 'ดูความก้าวหน้าในการดำเนินงานของแต่ละตัวชี้วัด', 'เปิดหน้าความก้าวหน้า — ต้องแสดงสถานะการกรอกข้อมูลของแต่ละตัวชี้วัดถูกต้องตามจริง', 1],
            ['5.2.8', 'สามารถ Export ออกมาเป็นไฟล์ PDF ได้', 'ทดสอบกดส่งออกข้อมูลการประเมินของตนเองเป็นไฟล์ PDF — ต้องได้ไฟล์ PDF ที่มีข้อมูลครบถ้วนถูกต้อง', 1],
            ['5.2.9', 'ดูความคิดเห็นของกรรมการที่ประเมินตนเองได้', 'เปิดหน้าดูผลการประเมิน — ต้องเห็นความคิดเห็น/คะแนนที่กรรมการให้ไว้', 1],
        ],
    ],
    [
        'division_id' => $hrDivisionId, 'code' => 'P5.3', 'name' => 'ส่วนที่ 5.3 พัฒนาระบบ: กรรมการผู้ประเมิน', 'max_score' => 10, 'sort_order' => 7, 'is_bonus' => 0,
        'criteria' => [
            ['5.3.1', 'แสดงข้อมูลของผู้รับการประเมินที่ต้องประเมิน', 'Login ด้วยบัญชีกรรมการ — ต้องเห็นรายชื่อผู้รับการประเมินที่ได้รับมอบหมายให้ประเมิน', 1],
            ['5.3.2', 'แสดงหัวข้อ ตัวชี้วัด รายละเอียดข้อมูล และหลักฐานของผู้รับการประเมิน', 'เปิดหน้ารายละเอียดผู้รับการประเมิน — ต้องแสดงหัวข้อ ตัวชี้วัด รายละเอียด และหลักฐานที่แนบไว้ครบถ้วน', 1],
            ['5.3.3', 'แสดงคะแนนที่ผู้รับการประเมินประเมินตนเอง', 'เปิดหน้าให้คะแนน — ต้องเห็นคะแนนที่ผู้รับการประเมินกรอกประเมินตนเองไว้', 1],
            ['5.3.4', 'ให้คะแนนผู้รับการประเมินตามตัวชี้วัด', 'ทดสอบกรอกคะแนนให้ผู้รับการประเมินแต่ละตัวชี้วัดแล้วบันทึก — ต้องบันทึกได้ถูกต้อง', 1],
            ['5.3.5', 'กรรมการให้ความคิดเห็นสรุปโดยภาพรวมของการประเมิน', 'ทดสอบกรอกความคิดเห็นสรุปโดยภาพรวมแล้วบันทึก — ต้องบันทึกและแสดงผลถูกต้อง', 1],
            ['5.3.6', 'แสดงผลลัพธ์การประเมินแต่ละตัวชี้วัด ในรูปแบบของตาราง และสรุปภาพรวมของผู้รับการประเมินรายบุคคล', 'เปิดหน้าสรุปผล — ต้องแสดงผลลัพธ์เป็นตารางรายตัวชี้วัด พร้อมสรุปภาพรวมของผู้รับการประเมินรายบุคคล', 1],
            ['5.3.7', 'ลงนามการประเมิน', 'ทดสอบลงนาม (แนบลายเซ็น) ในแบบประเมิน — ต้องบันทึกลายเซ็นและแสดงในเอกสาร/รายงานได้', 1],
            ['5.3.8', 'แก้ไขข้อมูลร่างของคะแนนตามตัวชี้วัดและความคิดเห็นสรุป', 'ทดสอบแก้ไขคะแนน/ความคิดเห็นที่ยังเป็นร่าง (ก่อนยืนยันผล) — ค่าที่แก้ไขต้องถูกบันทึกได้ก่อนยืนยันผล', 1],
            ['5.3.9', 'ลงนามการประเมิน', 'ทดสอบลงนาม (แนบลายเซ็น) ยืนยันผลครั้งสุดท้ายก่อนส่งผล — ต้องบันทึกลายเซ็นได้ถูกต้อง', 1],
            ['5.3.10', 'ยืนยันและส่งผลการประเมิน', 'ทดสอบกดยืนยันและส่งผลการประเมิน — สถานะต้องเปลี่ยนเป็น "ยืนยันแล้ว" และไม่สามารถแก้ไขคะแนนย้อนหลังได้อีก', 1],
        ],
    ],
    [
        'division_id' => $hrDivisionId, 'code' => 'P6', 'name' => 'ส่วนที่ 6 พัฒนาระบบ Back-end การพัฒนา API', 'max_score' => 12, 'sort_order' => 8, 'is_bonus' => 0,
        'criteria' => [
            ['6.1', 'สร้าง API แบบ GET พร้อมรับค่า (Query หรือ Path Parameters) อย่างน้อย 1 Endpoint', 'เรียก API แบบ GET พร้อมรับค่า (Query หรือ Path Parameters) อย่างน้อย 1 Endpoint — ผลลัพธ์ต้องเปลี่ยนตามค่าพารามิเตอร์ที่ส่ง', 1],
            ['6.2', 'สร้าง API แบบ POST ที่รับข้อมูลจาก Body อย่างถูกต้อง อย่างน้อย 1 Endpoint', 'ส่งข้อมูลผ่าน API แบบ POST อย่างน้อย 1 Endpoint เช่นด้วย Postman — ข้อมูลต้องถูกบันทึกลงฐานข้อมูลถูกต้อง', 1],
            ['6.3', 'สร้าง API แบบ PUT อย่างถูกต้อง อย่างน้อย 1 Endpoint', 'ส่งคำขอแก้ไขข้อมูลผ่าน API แบบ PUT อย่างน้อย 1 Endpoint — ข้อมูลเดิมต้องถูกแก้ไขถูกต้องตามที่ส่ง', 1],
            ['6.4', 'สร้าง API แบบ DELETE อย่างถูกต้อง อย่างน้อย 1 Endpoint', 'ส่งคำขอลบข้อมูลผ่าน API แบบ DELETE อย่างน้อย 1 Endpoint — ข้อมูลต้องถูกลบออกจากระบบจริง', 1],
            ['6.5', 'สร้าง API สำหรับอัปโหลดไฟล์', 'ส่งไฟล์ผ่าน API ด้วย multipart/form-data เช่น เอกสารประกอบการประเมิน — ต้องอัปโหลดสำเร็จและเปิดไฟล์ที่เก็บไว้ได้', 1],
            ['6.6', 'สร้าง API Login โดยใช้ JWT Token', 'เรียก API Login ด้วย Username/Password แล้วตรวจสอบ Token ที่ได้รับ เช่น ถอดรหัสด้วย jwt.io — Token ต้องมีการลงนาม (Signing) ถูกต้อง และใช้เรียก API อื่นเพื่อตรวจสอบสิทธิ์การเข้าถึงระบบได้', 1],
            ['6.7', 'มีการจัดการข้อผิดพลาด (Exception Handling)', 'ส่งข้อมูลไม่ครบ และเรียกหา id ที่ไม่มีอยู่จริง — ระบบต้องตอบรหัสข้อผิดพลาดที่เหมาะสม (เช่น 400/422 และ 404) ไม่ใช่ 500', 1],
            ['6.8', 'API ส่ง Response ที่เหมาะสม', 'ดูโครงสร้าง JSON ที่ API ตอบกลับ — ต้องมี status, message เพิ่มเติม, data ในรูปแบบเดียวกันทุก Endpoint', 1],
            ['6.9', 'มีคำอธิบาย API แต่ละ Service ตามข้อ 6.1-6.4', 'อ่านโค้ด/เอกสาร — ต้องมีคำอธิบาย (Comment หรือเอกสาร) ครบทั้ง 4 Service ตามข้อ 6.1-6.4', 1],
            ['6.10', 'ระบุ parameter ที่ใช้ในแต่ละ API', 'อ่าน Comment หรือเอกสารของแต่ละ API — ต้องระบุชัดเจนว่าแต่ละ API รับค่าแบบใด เช่น query, path, body', 1],
            ['6.11', 'โค้ด Back-end มีโครงสร้างดี แยกไฟล์ ใช้ router, async/await อย่างเหมาะสม', 'อ่านโค้ด Back-end — ต้องแยกไฟล์ตาม Router/Controller และใช้ async/await หรือรูปแบบจัดการ asynchronous อย่างเหมาะสม', 1],
            ['6.12', 'API มีความปลอดภัย และมีระบบตรวจสอบสิทธิ์ก่อนเข้าถึงข้อมูล', 'เรียก API ที่ต้องมีสิทธิ์โดยไม่ส่ง Token หรือส่ง Token ของสิทธิ์ที่ไม่ถูกต้อง — ต้องถูกปฏิเสธการเข้าถึงอย่างเหมาะสม', 1],
        ],
    ],
    [
        'division_id' => $hrDivisionId, 'code' => 'P7', 'name' => 'ส่วนที่ 7 การทดสอบโปรแกรม (Finish Detail Design Process)', 'max_score' => 14, 'sort_order' => 9, 'is_bonus' => 0,
        'criteria' => [
            ['7.1', 'ทดสอบระบบ Login', 'ทดสอบ Login ด้วย Username/Password ที่ถูกและผิด — รหัสถูกต้องเข้าระบบได้ รหัสผิดต้องเข้าไม่ได้', 1],
            ['7.2', 'ทดสอบการ Insert ข้อมูล', 'ทดลองเพิ่มข้อมูลใหม่ เช่น ผู้ใช้งานหรือแบบประเมิน แล้วตรวจสอบผล — ข้อมูลต้องถูกบันทึกถูกต้อง', 1],
            ['7.3', 'ทดสอบการ Update ข้อมูล', 'ทดลองแก้ไขข้อมูลเดิมแล้วเปิดดูใหม่เพื่อตรวจสอบผลลัพธ์ — ค่าที่แก้ไขต้องถูกบันทึกถูกต้อง', 1],
            ['7.4', 'ดำเนินการ Validate ข้อมูลก่อนส่ง', 'เว้นช่องบังคับ หรือกรอกรูปแบบผิด เช่น รูปแบบอีเมล/ความยาวไม่ถูกต้อง แล้วกดส่ง — ระบบต้องตรวจสอบและแจ้งเตือนก่อนข้อมูลถูกส่ง', 1],
            ['7.5', 'ทดสอบฟังก์ชันเฉพาะจุด', 'ทดสอบฟังก์ชันเฉพาะจุด เช่น ฟังก์ชันคำนวณคะแนน, การเรียงข้อมูล — ผลลัพธ์ต้องถูกต้องตามที่ควรเป็น', 1],
            ['7.6', 'ทดสอบ Service API อย่างน้อย 3 รายการ', 'เรียกใช้ Service API อย่างน้อย 3 รายการผ่านเครื่องมือทดสอบ เช่น POST, GET, PUT — ต้องได้ผลลัพธ์ถูกต้องครบทุกรายการ', 1],
            ['7.7', 'ใช้ Inspect/DevTools ตรวจสอบ Console และ Network ว่าไม่มี Error', 'เปิด Inspect/DevTools ของเบราว์เซอร์ขณะใช้งานระบบ — ต้องไม่มี Error แสดงใน Console และ Network', 1],
            ['7.8', 'มีการใช้งาน GitLab Server', 'ตรวจสอบการใช้งาน GitLab Server ของทีม เช่น ดูประวัติ Commit/Pipeline — ต้องมีการใช้งานจริงตลอดการพัฒนา', 2],
            ['7.9', 'สามารถแสดงผลของระบบในเครื่องคอมพิวเตอร์แม่ข่ายที่จัดเตรียมไว้ให้ได้', 'เปิดระบบผ่านเครื่องคอมพิวเตอร์แม่ข่ายที่จัดเตรียมไว้ให้ — ต้องแสดงผลและใช้งานได้จริงบนเครื่องดังกล่าว ไม่ใช่เฉพาะเครื่องพัฒนา', 2],
            ['7.10', 'ทดสอบการแสดงผลแบบ Responsive', 'ทดสอบเปิดระบบบนหน้าจอขนาดต่าง ๆ — ระดับสถานศึกษา/จังหวัด/ภาค รองรับการแสดงผลหน้าจอ Desktop = 3 คะแนน; ระดับชาติ รองรับทั้งหน้าจอมือถือ (แสดงผ่าน DevTools) และ Desktop แสดงผลได้แบบละ 1.5 คะแนน รวม 3 คะแนน', 3],
        ],
    ],
    [
        'division_id' => $hrDivisionId, 'code' => 'P8', 'name' => 'ส่วนที่ 8 โจทย์พิเศษเพิ่มความท้าทาย', 'max_score' => 10, 'sort_order' => 10, 'is_bonus' => 0,
        'criteria' => [
            ['8.1', 'Function การแสดงข้อมูลบน Dashboard', 'เปิดหน้า Dashboard — ต้องแสดงข้อมูลสรุป/กราฟที่ดึงจากข้อมูลจริงในระบบ', 2],
            ['8.2', 'Function การตรวจสอบความถูกต้องของข้อมูล', 'ทดสอบกรอกข้อมูลผิดรูปแบบ (Data Validation) — ระบบต้องตรวจจับและแจ้งเตือนก่อนบันทึก', 2],
            ['8.3', 'Function การสำรองและกู้คืนข้อมูล', 'ทดสอบสั่งสำรองข้อมูล (Backup) แล้วกู้คืนกลับ (Restore) — ข้อมูลหลังกู้คืนต้องถูกต้องครบถ้วนเหมือนก่อนสำรอง', 2],
            ['8.4', 'Function การ Backup/Restore', 'ทดสอบฟังก์ชัน Backup/Restore ของระบบอีกครั้งในสถานการณ์ที่ต่างจากข้อ 8.3 เช่น กู้คืนบางส่วนหรือสำรองอัตโนมัติ — ต้องทำงานได้ถูกต้อง', 2],
            ['8.5', 'Function การออกรายงาน', 'ทดสอบสั่งออกรายงาน (Reporting) จากระบบ — ต้องได้รายงานที่มีข้อมูลถูกต้องตรงกับข้อมูลจริงในระบบ', 2],
        ],
    ],
    [
        'division_id' => $hrDivisionId, 'code' => 'P9', 'name' => 'ส่วนที่ 9 การนำเสนอผลงาน', 'max_score' => 10, 'sort_order' => 11, 'is_bonus' => 0,
        'criteria' => [
            ['9.1', 'การตอบคำถามจากคณะกรรมการ', 'กรรมการถามคำถามจากชุดคำถาม Backend, Frontend และแนวทางการแก้ปัญหา — ประเมินจากความถูกต้องและความเข้าใจในการตอบของทีม', 3],
            ['9.2', 'นำเสนอวิธีการทำงานร่วมกัน', 'ฟังการนำเสนอวิธีการแบ่งหน้าที่ความรับผิดชอบของสมาชิกในทีม — ประเมินจากความชัดเจนและความเหมาะสมของการกระจายงาน', 3],
            ['9.3', 'บุคลิกภาพ', 'ประเมินจากบุคลิกภาพ การแต่งกาย ความมั่นใจ และมารยาทในการนำเสนอของทีม', 3],
            ['9.4', 'การนำเสนอภายในระยะเวลาที่กำหนด', 'จับเวลาการนำเสนอ — ต้องนำเสนอให้เสร็จภายในระยะเวลาที่กรรมการกำหนด', 1],
        ],
    ],
];

$checkSkill = $pdo->prepare('SELECT id FROM skills WHERE division_id = ? AND code = ?');
$insSkill = $pdo->prepare('INSERT INTO skills (division_id, code, name, max_score, sort_order, is_bonus) VALUES (?, ?, ?, ?, ?, ?)');
$checkGroup = $pdo->prepare('SELECT id FROM criteria_groups WHERE skill_id = ? AND code = ?');
$insGroup = $pdo->prepare('INSERT INTO criteria_groups (skill_id, code, name, description, sort_order) VALUES (?, ?, ?, ?, ?)');
$checkCriteria = $pdo->prepare('SELECT id FROM criteria WHERE skill_id = ? AND code = ?');
$insCriteria = $pdo->prepare('INSERT INTO criteria (skill_id, group_id, code, name, description, max_score, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)');

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

    // หัวข้อ (ไม่มีคะแนนในตัวเอง) ของทักษะนี้ เช่น "1.2 Inter-VLAN Routing" — ไม่ใช่ทุกทักษะจะมี
    $groupIdByCode = [];
    $groupOrder = 0;
    foreach ($skill['groups'] ?? [] as [$gCode, $gName, $gDesc]) {
        $groupOrder++;
        $checkGroup->execute([$skillId, $gCode]);
        $gRow = $checkGroup->fetch();
        if ($gRow) {
            $groupIdByCode[$gCode] = (int)$gRow['id'];
        } else {
            $insGroup->execute([$skillId, $gCode, $gName, $gDesc, $groupOrder]);
            $groupIdByCode[$gCode] = (int)$pdo->lastInsertId();
        }
    }

    $order = 0;
    foreach ($skill['criteria'] as $row) {
        [$code, $name, $desc, $max] = $row;
        $groupCode = $row[4] ?? null;
        $groupId = $groupCode !== null ? ($groupIdByCode[$groupCode] ?? null) : null;
        $order++;
        $checkCriteria->execute([$skillId, $code]);
        if (!$checkCriteria->fetch()) {
            $insCriteria->execute([$skillId, $groupId, $code, $name, $desc, $max, $order]);
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
