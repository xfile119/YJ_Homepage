<?php
/* 임직원 관리 API
   - GET             : 공개 목록 (강사진 캐러셀용). 관리자/사무실로 로그인한 경우에만
                        실명(realName)·연동계정 여부(hasAccount)도 같이 줍니다.
   - POST save_all   : 임직원 목록 통째로 저장 (관리자/사무실 전용)
     행마다 autoCreateAccount가 true이고 아직 연동 계정이 없으면, 선택한
     근무형태(강사/사무실/셔틀)에 맞는 로그인 계정을 자동으로 만듭니다.
     자동 생성된 아이디/임시비밀번호는 이번 응답에서 딱 한 번만 돌려줍니다 —
     비밀번호 해시만 저장하고 평문은 어디에도 남기지 않습니다. */
require __DIR__ . '/_db.php';

$method = $_SERVER['REQUEST_METHOD'];
$table = yj_table('staff');
$usersTable = yj_table('admin_users');

/* 예전 데이터는 work_type이 "강사" 같은 단일 문자열이었습니다. 지금은 겸직을 위해
   JSON 배열(["강사","셔틀"])로 저장하는데, 옛 값도 그대로 한 항목짜리 배열로 다룹니다. */
function yj_staff_work_types($raw) {
    $decoded = json_decode((string)$raw, true);
    if (is_array($decoded)) { return $decoded; }
    $raw = trim((string)$raw);
    return $raw !== '' ? [$raw] : [];
}

/* 근무형태(공개 표기) -> 계정 역할 매핑 */
function yj_work_type_to_role($w) {
    if ($w === '강사') { return 'instructor'; }
    if ($w === '사무실') { return 'manager'; }
    if ($w === '셔틀') { return 'office'; }
    return null;
}

function yj_is_content_admin_session() {
    if (empty($_SESSION['yj_admin'])) { return false; }
    $raw = isset($_SESSION['yj_role']) ? (string)$_SESSION['yj_role'] : 'admin';
    $roles = array_filter(array_map('trim', explode(',', $raw)));
    return in_array('admin', $roles, true) || in_array('manager', $roles, true);
}

if ($method === 'GET') {
    $isAdmin = yj_is_content_admin_session();
    $cols = "id, name, mbti, work_type, course_types, photo, greeting" . ($isAdmin ? ", real_name" : "");
    $stmt = yj_db()->query("SELECT $cols FROM $table ORDER BY sort_order ASC, id ASC");
    $rows = $stmt->fetchAll();

    $accountByStaffId = [];
    if ($isAdmin) {
        foreach (yj_db()->query("SELECT staff_id, username FROM $usersTable WHERE staff_id IS NOT NULL") as $r) {
            $accountByStaffId[(int)$r['staff_id']] = $r['username'];
        }
    }

    $staff = array_map(function ($r) use ($isAdmin, $accountByStaffId) {
        $courseTypes = json_decode($r['course_types'], true);
        if (!is_array($courseTypes)) { $courseTypes = []; }
        $out = [
            'id' => (int)$r['id'],
            'name' => $r['name'],
            'mbti' => $r['mbti'],
            'workTypes' => yj_staff_work_types($r['work_type']),
            'courseTypes' => $courseTypes,
            'photo' => $r['photo'],
            'greeting' => $r['greeting'],
        ];
        if ($isAdmin) {
            $out['realName'] = isset($r['real_name']) ? $r['real_name'] : '';
            $out['accountUsername'] = isset($accountByStaffId[(int)$r['id']]) ? $accountByStaffId[(int)$r['id']] : null;
        }
        return $out;
    }, $rows);
    yj_json(['staff' => $staff]);
}

if ($method !== 'POST') {
    yj_json(['error' => 'Method not allowed'], 405);
}

yj_require_content_admin();
$body = yj_input();
$action = isset($body['action']) ? $body['action'] : '';

if ($action !== 'save_all') {
    yj_json(['error' => 'Bad request'], 400);
}

$rows = isset($body['staff']) ? $body['staff'] : [];
if (!is_array($rows)) {
    yj_json(['error' => '잘못된 데이터입니다.'], 400);
}

/* id가 있는 항목은 UPDATE, 없는 항목(신규)은 INSERT, 더 이상 목록에 없는 기존 id는 DELETE합니다. */
$db = yj_db();
$db->beginTransaction();
$createdAccounts = [];
try {
    $existingIds = [];
    foreach ($db->query("SELECT id FROM $table") as $row) {
        $existingIds[(int)$row['id']] = true;
    }

    $insert = $db->prepare("INSERT INTO $table (name, mbti, work_type, course_types, photo, greeting, real_name, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $update = $db->prepare("UPDATE $table SET name=?, mbti=?, work_type=?, course_types=?, photo=?, greeting=?, real_name=?, sort_order=? WHERE id=?");
    $accountCheck = $db->prepare("SELECT id, username FROM $usersTable WHERE staff_id = ? LIMIT 1");
    $usernameTaken = $db->prepare("SELECT COUNT(*) FROM $usersTable WHERE username = ?");
    $accountInsert = $db->prepare("INSERT INTO $usersTable (username, password_hash, role, real_name, staff_id) VALUES (?, ?, ?, ?, ?)");
    $accountSync = $db->prepare("UPDATE $usersTable SET role = ?, real_name = ? WHERE staff_id = ?");

    $keepIds = [];
    $i = 0;
    foreach (array_values($rows) as $r) {
        $courseTypes = isset($r['courseTypes']) && is_array($r['courseTypes']) ? $r['courseTypes'] : [];
        $workTypes = isset($r['workTypes']) && is_array($r['workTypes']) ? array_values($r['workTypes']) : [];
        $realName = trim((string)(isset($r['realName']) ? $r['realName'] : ''));
        $vals = [
            (string)(isset($r['name']) ? $r['name'] : ''),
            (string)(isset($r['mbti']) ? $r['mbti'] : ''),
            json_encode($workTypes, JSON_UNESCAPED_UNICODE),
            json_encode($courseTypes, JSON_UNESCAPED_UNICODE),
            isset($r['photo']) && $r['photo'] !== '' ? (string)$r['photo'] : null,
            isset($r['greeting']) && $r['greeting'] !== '' ? (string)$r['greeting'] : null,
            $realName !== '' ? $realName : null,
            $i,
        ];
        $id = isset($r['id']) ? (int)$r['id'] : 0;
        if ($id > 0 && isset($existingIds[$id])) {
            $update->execute(array_merge($vals, [$id]));
            $keepIds[] = $id;
        } else {
            $insert->execute($vals);
            $id = (int)$db->lastInsertId();
            $keepIds[] = $id;
        }
        $i++;

        /* 근무형태에서 뽑아낸 역할 목록 (계정 생성/동기화 둘 다에 씁니다) */
        $roles = [];
        foreach ($workTypes as $w) {
            $role = yj_work_type_to_role($w);
            if ($role !== null && !in_array($role, $roles, true)) { $roles[] = $role; }
        }

        $accountCheck->execute([$id]);
        $existingAccount = $accountCheck->fetch();

        if ($existingAccount) {
            /* 이미 연동된 계정이 있으면, 근무형태·실명이 바뀌었을 수 있으니 매번 동기화합니다
               (역할이 하나도 안 남으면 최소 하나는 있어야 로그인 의미가 있으므로 기존 역할 유지). */
            if (!empty($roles)) {
                $accountSync->execute([implode(',', $roles), $realName !== '' ? $realName : null, $id]);
            }
        } elseif (!empty($r['autoCreateAccount'])) {
            if (!empty($roles) && (!in_array('instructor', $roles, true) || $realName !== '')) {
                $username = 'staff' . $id;
                $usernameTaken->execute([$username]);
                if ((int)$usernameTaken->fetchColumn() > 0) {
                    $username = 'staff' . $id . '_' . substr(bin2hex(random_bytes(2)), 0, 3);
                }
                $tempPassword = substr(bin2hex(random_bytes(6)), 0, 10);
                $accountInsert->execute([
                    $username,
                    password_hash($tempPassword, PASSWORD_DEFAULT),
                    implode(',', $roles),
                    $realName !== '' ? $realName : null,
                    $id,
                ]);
                $createdAccounts[] = [
                    'staffId' => $id,
                    'name' => (string)(isset($r['name']) ? $r['name'] : ''),
                    'username' => $username,
                    'password' => $tempPassword,
                    'roles' => $roles,
                ];
            }
        }
    }

    $deleteIds = array_diff(array_keys($existingIds), $keepIds);
    if (!empty($deleteIds)) {
        $in = implode(',', array_map('intval', $deleteIds));
        $db->exec("DELETE FROM $table WHERE id IN ($in)");
    }

    $db->commit();
} catch (Exception $e) {
    $db->rollBack();
    yj_json(['error' => '저장 실패: ' . $e->getMessage()], 500);
}

yj_json(['ok' => true, 'createdAccounts' => $createdAccounts]);
