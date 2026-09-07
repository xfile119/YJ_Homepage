<?php
require __DIR__ . '/_db.php';

$method = $_SERVER['REQUEST_METHOD'];
$table = yj_table('staff');

if ($method === 'GET') {
    $stmt = yj_db()->query("SELECT id, name, mbti, work_type, course_types, photo FROM $table ORDER BY sort_order ASC, id ASC");
    $rows = $stmt->fetchAll();
    $staff = array_map(function ($r) {
        $courseTypes = json_decode($r['course_types'], true);
        if (!is_array($courseTypes)) { $courseTypes = []; }
        return [
            'id' => (int)$r['id'],
            'name' => $r['name'],
            'mbti' => $r['mbti'],
            'workType' => $r['work_type'],
            'courseTypes' => $courseTypes,
            'photo' => $r['photo'],
        ];
    }, $rows);
    yj_json(['staff' => $staff]);
}

if ($method !== 'POST') {
    yj_json(['error' => 'Method not allowed'], 405);
}

yj_require_login();
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
try {
    $existingIds = [];
    foreach ($db->query("SELECT id FROM $table") as $row) {
        $existingIds[(int)$row['id']] = true;
    }

    $insert = $db->prepare("INSERT INTO $table (name, mbti, work_type, course_types, photo, sort_order) VALUES (?, ?, ?, ?, ?, ?)");
    $update = $db->prepare("UPDATE $table SET name=?, mbti=?, work_type=?, course_types=?, photo=?, sort_order=? WHERE id=?");

    $keepIds = [];
    $i = 0;
    foreach (array_values($rows) as $r) {
        $courseTypes = isset($r['courseTypes']) && is_array($r['courseTypes']) ? $r['courseTypes'] : [];
        $vals = [
            (string)(isset($r['name']) ? $r['name'] : ''),
            (string)(isset($r['mbti']) ? $r['mbti'] : ''),
            (string)(isset($r['workType']) ? $r['workType'] : '강사'),
            json_encode($courseTypes, JSON_UNESCAPED_UNICODE),
            isset($r['photo']) && $r['photo'] !== '' ? (string)$r['photo'] : null,
            $i,
        ];
        $id = isset($r['id']) ? (int)$r['id'] : 0;
        if ($id > 0 && isset($existingIds[$id])) {
            $update->execute(array_merge($vals, [$id]));
            $keepIds[] = $id;
        } else {
            $insert->execute($vals);
            $keepIds[] = (int)$db->lastInsertId();
        }
        $i++;
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

yj_json(['ok' => true]);
