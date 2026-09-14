<?php
/* 계정 관리 API — 최고관리자(원장) 전용
   - GET                    : 계정 목록 (아이디·역할·실명만, 비밀번호는 절대 나가지 않음)
   - POST action=set_password : 계정 비밀번호 설정/변경
   - POST action=create     : manager/office/instructor 계정 추가 (겸직 가능, role은 쉼표로 구분)
   - POST action=delete     : manager/office/instructor 계정 삭제 (admin 계정은 이 화면에서 못 지움)

   역할은 'admin'(최고관리자), 'manager'(사무실), 'office'(셔틀계정), 'instructor'(강사) 이고
   한 계정이 쉼표로 구분된 여러 역할을 가질 수 있습니다 (겸직).
   PHP 5.5에서도 동작하도록 구형 문법으로 작성했습니다. */

require __DIR__ . '/_db.php';

yj_require_admin();

$method = $_SERVER['REQUEST_METHOD'];
$table = yj_table('admin_users');
$validRoles = ['admin', 'manager', 'office', 'instructor'];

function yj_parse_roles($raw, $validRoles) {
    $parts = array_filter(array_map('trim', explode(',', (string)$raw)));
    $parts = array_values(array_unique($parts));
    $parts = array_values(array_intersect($parts, $validRoles));
    return $parts;
}

if ($method === 'GET') {
    $stmt = yj_db()->query("SELECT id, username, role, real_name, created_at FROM $table ORDER BY (role='admin') DESC, username ASC");
    $users = array_map(function ($r) use ($validRoles) {
        return [
            'id' => (int)$r['id'],
            'username' => $r['username'],
            'roles' => yj_parse_roles($r['role'], $validRoles),
            'realName' => $r['real_name'],
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
    $roles = yj_parse_roles(isset($body['roles']) ? $body['roles'] : '', $validRoles);
    /* 이 화면에서는 최고관리자 계정을 늘리지 않습니다 */
    $roles = array_values(array_diff($roles, ['admin']));
    if (empty($roles)) {
        yj_json(['error' => '역할을 하나 이상 선택해주세요 (사무실/셔틀/강사).'], 400);
    }
    $realName = trim((string)(isset($body['realName']) ? $body['realName'] : ''));
    if (in_array('instructor', $roles, true) && $realName === '') {
        yj_json(['error' => '강사 역할은 학사서버 실명이 필요합니다.'], 400);
    }
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
    $ins = $db->prepare("INSERT INTO $table (username, password_hash, role, real_name) VALUES (?, ?, ?, ?)");
    $ins->execute([$username, password_hash($password, PASSWORD_DEFAULT), implode(',', $roles), $realName !== '' ? $realName : null]);
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
    if (in_array('admin', yj_parse_roles($row['role'], $validRoles), true)) {
        yj_json(['error' => '최고관리자 계정은 이 화면에서 삭제할 수 없습니다.'], 400);
    }
    $del = $db->prepare("DELETE FROM $table WHERE username = ?");
    $del->execute([$username]);
    yj_json(['ok' => true]);
}

yj_json(['error' => 'Bad request'], 400);
