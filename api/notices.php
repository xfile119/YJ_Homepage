<?php
declare(strict_types=1);
require __DIR__ . '/_db.php';

$method = $_SERVER['REQUEST_METHOD'];
$table = yj_table('notices');

if ($method === 'GET') {
    $stmt = yj_db()->query("SELECT id, display_no, title, link, badge, posted_date FROM $table ORDER BY sort_order ASC, id DESC");
    $rows = $stmt->fetchAll();
    $notices = array_map(function ($r) {
        return [
            'no' => $r['display_no'],
            'title' => $r['title'],
            'link' => $r['link'],
            'badge' => $r['badge'],
            'date' => $r['posted_date'],
        ];
    }, $rows);
    yj_json(['notices' => $notices]);
}

if ($method !== 'POST') {
    yj_json(['error' => 'Method not allowed'], 405);
}

yj_require_login();
$body = yj_input();
$action = $body['action'] ?? '';

if ($action !== 'save_all') {
    yj_json(['error' => 'Bad request'], 400);
}

$rows = $body['notices'] ?? [];
if (!is_array($rows)) {
    yj_json(['error' => '잘못된 데이터입니다.'], 400);
}

$db = yj_db();
$db->beginTransaction();
try {
    $db->exec("DELETE FROM $table");
    $stmt = $db->prepare("INSERT INTO $table (display_no, title, link, badge, posted_date, sort_order) VALUES (?, ?, ?, ?, ?, ?)");
    foreach (array_values($rows) as $i => $r) {
        $stmt->execute([
            (string)($r['no'] ?? ''),
            (string)($r['title'] ?? ''),
            (string)($r['link'] ?? '#'),
            (string)($r['badge'] ?? '없음'),
            (string)($r['date'] ?? ''),
            $i,
        ]);
    }
    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    yj_json(['error' => '저장 실패: ' . $e->getMessage()], 500);
}

yj_json(['ok' => true]);
