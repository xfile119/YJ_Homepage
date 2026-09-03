<?php
require __DIR__ . '/_db.php';

$method = $_SERVER['REQUEST_METHOD'];
$table = yj_table('notices');

if ($method === 'GET') {
    $stmt = yj_db()->query("SELECT id, display_no, title, link, badge, posted_date, content, image FROM $table ORDER BY sort_order ASC, id DESC");
    $rows = $stmt->fetchAll();
    $notices = array_map(function ($r) {
        return [
            'id' => (int)$r['id'],
            'no' => $r['display_no'],
            'title' => $r['title'],
            'link' => $r['link'],
            'badge' => $r['badge'],
            'date' => $r['posted_date'],
            'content' => $r['content'],
            'image' => $r['image'],
        ];
    }, $rows);
    yj_json(['notices' => $notices]);
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

$rows = isset($body['notices']) ? $body['notices'] : [];
if (!is_array($rows)) {
    yj_json(['error' => '잘못된 데이터입니다.'], 400);
}

/* id가 있는 항목은 UPDATE, 없는 항목(신규)은 INSERT, 더 이상 목록에 없는 기존 id는 DELETE합니다.
   (매번 전체 삭제 후 재삽입하지 않는 이유: id를 유지해야 notice-detail.html?id=.. 링크가 저장할 때마다 깨지지 않습니다.) */
$db = yj_db();
$db->beginTransaction();
try {
    $existingIds = [];
    foreach ($db->query("SELECT id FROM $table") as $row) {
        $existingIds[(int)$row['id']] = true;
    }

    $insert = $db->prepare("INSERT INTO $table (display_no, title, link, badge, posted_date, content, image, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $update = $db->prepare("UPDATE $table SET display_no=?, title=?, link=?, badge=?, posted_date=?, content=?, image=?, sort_order=? WHERE id=?");

    $keepIds = [];
    $i = 0;
    foreach (array_values($rows) as $r) {
        $vals = [
            (string)(isset($r['no']) ? $r['no'] : ''),
            (string)(isset($r['title']) ? $r['title'] : ''),
            (string)(isset($r['link']) ? $r['link'] : '#'),
            (string)(isset($r['badge']) ? $r['badge'] : '없음'),
            (string)(isset($r['date']) ? $r['date'] : ''),
            (string)(isset($r['content']) ? $r['content'] : ''),
            (string)(isset($r['image']) ? $r['image'] : ''),
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
