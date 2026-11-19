<?php
declare(strict_types=1);

/**
 * โครงสร้างทักษะทั้งหมดพร้อมเกณฑ์ย่อย เรียงตาม sort_order
 */
function scoring_skills_with_criteria(PDO $pdo, ?int $divisionId = null): array
{
    if ($divisionId !== null) {
        $stmt = $pdo->prepare('SELECT * FROM skills WHERE division_id = ? ORDER BY sort_order, id');
        $stmt->execute([$divisionId]);
        $skills = $stmt->fetchAll();
    } else {
        $skills = $pdo->query('SELECT * FROM skills ORDER BY sort_order, id')->fetchAll();
    }
    $criteria = $pdo->query('
        SELECT c.*, cg.code AS group_code, cg.name AS group_name
        FROM criteria c
        LEFT JOIN criteria_groups cg ON cg.id = c.group_id
        ORDER BY c.skill_id, c.sort_order, c.id
    ')->fetchAll();

    $bySkill = [];
    foreach ($criteria as $c) {
        $bySkill[(int)$c['skill_id']][] = $c;
    }

    foreach ($skills as &$s) {
        $s['criteria'] = $bySkill[(int)$s['id']] ?? [];
    }

    return $skills;
}

/**
 * คะแนนเฉลี่ยของแต่ละ (team_id, criteria_id) จากกรรมการทุกคนที่บันทึกไว้
 * คืนค่าเป็น [team_id][criteria_id] => float
 */
function scoring_average_matrix(PDO $pdo): array
{
    $rows = $pdo->query('SELECT team_id, criteria_id, AVG(score) avg_score FROM scores GROUP BY team_id, criteria_id')->fetchAll();
    $matrix = [];
    foreach ($rows as $r) {
        $matrix[(int)$r['team_id']][(int)$r['criteria_id']] = (float)$r['avg_score'];
    }
    return $matrix;
}

/**
 * คะแนนดิบของแต่ละ (team_id, criteria_id, judge_id) คืนค่าเป็น [team_id][criteria_id][judge_id] => float
 */
function scoring_raw_matrix(PDO $pdo): array
{
    $rows = $pdo->query('SELECT team_id, criteria_id, judge_id, score FROM scores')->fetchAll();
    $matrix = [];
    foreach ($rows as $r) {
        $matrix[(int)$r['team_id']][(int)$r['criteria_id']][(int)$r['judge_id']] = (float)$r['score'];
    }
    return $matrix;
}

/**
 * รายชื่อ division ทั้งหมด เรียงตามชื่อ (ก-ฮ / A-Z)
 */
function scoring_all_divisions(PDO $pdo): array
{
    return $pdo->query('SELECT * FROM divisions ORDER BY name, id')->fetchAll();
}

/**
 * division ปัจจุบันจาก query string (?division_id=) ถ้าไม่ระบุหรือไม่ถูกต้อง ใช้ division แรกตาม sort_order
 */
function scoring_current_division(PDO $pdo, array $divisions): array
{
    $requested = (int)($_GET['division_id'] ?? 0);
    foreach ($divisions as $d) {
        if ((int)$d['id'] === $requested) {
            return $d;
        }
    }
    return $divisions[0];
}

/**
 * ทีมที่กรรมการคนนี้ถูกมอบหมายให้ตรวจใน division นี้ (จากประธานกรรมการ)
 * คืนค่า null = ไม่ได้ถูกจำกัด (ให้คะแนนได้ทุกทีมในทักษะที่ตนรับผิดชอบ)
 * คืนค่า array ของ team_id = ถูกจำกัดเฉพาะทีมเหล่านี้เท่านั้น
 */
function scoring_judge_allowed_teams(PDO $pdo, int $judgeId, int $divisionId): ?array
{
    $stmt = $pdo->prepare('
        SELECT jta.team_id FROM judge_team_assignments jta
        JOIN teams t ON t.id = jta.team_id
        WHERE jta.judge_id = ? AND t.division_id = ?
    ');
    $stmt->execute([$judgeId, $divisionId]);
    $rows = $stmt->fetchAll();
    if (empty($rows)) {
        return null;
    }
    return array_map(fn($r) => (int)$r['team_id'], $rows);
}

/**
 * สรุปคะแนนรวมของแต่ละทีม: ต่อทักษะ, รวม 100 (ไม่รวมโจทย์พิเศษ), และโจทย์พิเศษ
 */
function scoring_team_totals(array $teams, array $skills, array $avgMatrix): array
{
    $result = [];
    foreach ($teams as $team) {
        $teamId = (int)$team['id'];
        $perSkill = [];
        $total = 0.0;
        $bonus = 0.0;
        $scoredCriteria = 0;
        $totalCriteria = 0;

        foreach ($skills as $skill) {
            $skillId = (int)$skill['id'];
            $skillScore = 0.0;
            foreach ($skill['criteria'] as $c) {
                $totalCriteria++;
                $val = $avgMatrix[$teamId][(int)$c['id']] ?? null;
                if ($val !== null) {
                    $scoredCriteria++;
                    $skillScore += $val;
                }
            }
            $perSkill[$skillId] = $skillScore;
            if ($skill['is_bonus']) {
                $bonus += $skillScore;
            } else {
                $total += $skillScore;
            }
        }

        $result[$teamId] = [
            'team' => $team,
            'per_skill' => $perSkill,
            'total' => $total,
            'bonus' => $bonus,
            'scored_criteria' => $scoredCriteria,
            'total_criteria' => $totalCriteria,
        ];
    }

    return $result;
}
