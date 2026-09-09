<?php
/* 계정 관리 API — 관리자(원장) 전용
   - GET                    : 계정 목록 (아이디·권한만, 비밀번호는 절대 나가지 않음)
   - POST action=set_password : 계정 비밀번호 설정/변경
   - POST action=create     : office 계정 추가
   - POST action=delete     : office 계정 삭제

   권한은 'admin'(전체)과 'office'(셔틀 명단만) 두 가지입니다.
   PHP 5.5에서도 동작하도록 구형 문법으로 작성했습니다. */

require __DIR__ . '/_db.php';

yj_require_admin();

$method = $_SERVER['REQUEST_METHOD'];
$table = yj_table('admin_users');

if ($method === 'GET') {
    $stmt = yj_db()->query("SELECT id, username, role, created_at FROM $table ORDER BY (role='admin') DESC, username ASC");
    $users = array_map(function ($r) {
        return [
            'id' => (int)$r['id'],
            'username' => $r['username'],
            'role' => ($r['role'] === 'office') ? 'office' : 'admin',
        ];
    }, $stmt->fetchAll());
    yj_json(['users' => $users, 'me' => $_SESSION['yj_admin']]);
}

if ($method !== 'POST') {
    yj_json(['error' => 'Method not allowed'], 405);
}

$body = yj_input();
$action = isset($body['action']) ? $body['action'] : '';
$username = trim((string)(isset($body['username']) ? $body['username'] : ''));
$db = yj_db();

if ($action === 'set_password') {
    $password = (string)(isset($body['password']) ? $body['password'] : '');
    if ($username === '') {
        yj_json(['error' => '계정을 선택해주세요.'], 400);
    }
    if (strlen($password) < 8) {
        yj_json(['error' => '비밀번호는 8자 이상으로 정해주세요.'], 400);
    }
    $stmt = $db->prepare("UPDATE $table SET password_hash = ? WHERE username = ?");
    $stmt->execute([password_hash($password, PASSWORD_DEFAULT), $username]);
    if ($stmt->rowCount() === 0) {
        /* 값이 같아도 rowCount가 0이 될 수 있어 존재 여부를 따로 확인합니다 */
        $chk = $db->prepare("SELECT COUNT(*) FROM $table WHERE username = ?");
        $chk->execute([$username]);
        if ((int)$chk->fetchColumn() === 0) {
            yj_json(['error' => '없는 계정입니다.'], 404);
        }
    }
    yj_json(['ok' => true]);
}

if ($action === 'create') {
    $password = (string)(isset($body['password']) ? $body['password'] : '');
    if (!preg_match('/^[A-Za-z0-9_]{3,20}$/', $username)) {
        yj_json(['error' => '아이디는 영문·숫자·밑줄 3~20자로 입력해주세요.'], 400);
    }
    if (strlen($password) < 8) {
        yj_json(['error' => '비밀번호는 8자 이상으로 정해주세요.'], 400);
    }
    $chk = $db->prepare("SELECT COUNT(*) FROM $table WHERE username = ?");
    $chk->execute([$username]);
    if ((int)$chk->fetchColumn() > 0) {
        yj_json(['error' => '이미 있는 아이디입니다.'], 400);
    }
    /* 이 화면에서 만드는 계정은 항상 office 권한입니다 (관리자 계정은 늘리지 않습니다) */
    $ins = $db->prepare("INSERT INTO $table (username, password_hash, role) VALUES (?, ?, 'office')");
    $ins->execute([$username, password_hash($password, PASSWORD_DEFAULT)]);
    yj_json(['ok' => true]);
}

if ($action === 'delete') {
    if ($username === '') {
        yj_json(['error' => '계정을 선택해주세요.'], 400);
    }
    if ($username === $_SESSION['yj_admin']) {
        yj_json(['error' => '지금 로그인한 계정은 삭제할 수 없습니다.'], 400);
    }
    $stmt = $db->prepare("SELECT role FROM $table WHERE username = ? LIMIT 1");
    $stmt->execute([$username]);
    $row = $stmt->fetch();
    if (!$row) {
        yj_json(['error' => '없는 계정입니다.'], 404);
    }
    if ($row['role'] !== 'office') {
        yj_json(['error' => '관리자 계정은 이 화면에서 삭제할 수 없습니다.'], 400);
    }
    $del = $db->prepare("DELETE FROM $table WHERE username = ?");
    $del->execute([$username]);
    yj_json(['ok' => true]);
}

yj_json(['error' => 'Bad request'], 400);
