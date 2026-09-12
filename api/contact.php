<?php
require __DIR__ . '/_db.php';

$method = $_SERVER['REQUEST_METHOD'];
$table = yj_table('contact_messages');

if ($method === 'GET') {
    yj_require_admin();
    $stmt = yj_db()->query("SELECT id, name, phone, message, status, created_at FROM $table ORDER BY id DESC");
    $rows = $stmt->fetchAll();
    $messages = array_map(function ($r) {
        return [
            'id' => (int)$r['id'],
            'name' => $r['name'],
            'phone' => $r['phone'],
            'message' => $r['message'],
            'status' => $r['status'],
            'createdAt' => $r['created_at'],
        ];
    }, $rows);
    yj_json(['messages' => $messages]);
}

if ($method !== 'POST') {
    yj_json(['error' => 'Method not allowed'], 405);
}

$body = yj_input();
$action = isset($body['action']) ? $body['action'] : '';

/* 문의 등록은 누구나(로그인 없이) 할 수 있어야 하므로 다른 액션들과 달리
   관리자 권한 확인보다 먼저 처리합니다. */
if ($action === 'submit') {
    $name = trim((string)(isset($body['name']) ? $body['name'] : ''));
    $phone = trim((string)(isset($body['phone']) ? $body['phone'] : ''));
    $message = trim((string)(isset($body['message']) ? $body['message'] : ''));

    if ($name === '' || $phone === '' || $message === '') {
        yj_json(['error' => '이름, 연락처, 문의 내용을 모두 입력해주세요.'], 400);
    }
    if (mb_strlen($name) > 50 || mb_strlen($phone) > 30 || mb_strlen($message) > 2000) {
        yj_json(['error' => '입력한 내용이 너무 깁니다.'], 400);
    }

    $stmt = yj_db()->prepare("INSERT INTO $table (name, phone, message, status) VALUES (?, ?, ?, 'unread')");
    $stmt->execute([$name, $phone, $message]);
    yj_json(['ok' => true]);
}

yj_require_admin();

if ($action === 'mark_status') {
    $id = isset($body['id']) ? (int)$body['id'] : 0;
    $status = isset($body['status']) && $body['status'] === 'unread' ? 'unread' : 'read';
    if ($id <= 0) {
        yj_json(['error' => '잘못된 요청입니다.'], 400);
    }
    $stmt = yj_db()->prepare("UPDATE $table SET status = ? WHERE id = ?");
    $stmt->execute([$status, $id]);
    yj_json(['ok' => true]);
}

if ($action === 'delete') {
    $id = isset($body['id']) ? (int)$body['id'] : 0;
    if ($id <= 0) {
        yj_json(['error' => '잘못된 요청입니다.'], 400);
    }
    $stmt = yj_db()->prepare("DELETE FROM $table WHERE id = ?");
    $stmt->execute([$id]);
    yj_json(['ok' => true]);
}

yj_json(['error' => 'Bad request'], 400);
