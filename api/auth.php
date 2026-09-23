<?php
require __DIR__ . '/_db.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $valid = yj_session_is_valid();
    yj_json([
        'loggedIn' => $valid,
        'username' => $valid ? $_SESSION['yj_admin'] : '',
        'roles' => $valid ? yj_roles() : [],
    ]);
}

if ($method !== 'POST') {
    yj_json(['error' => 'Method not allowed'], 405);
}

$body = yj_input();
$action = isset($body['action']) ? $body['action'] : 'login';

if ($action === 'logout') {
    yj_destroy_session();
    yj_json(['ok' => true]);
}

yj_ensure_auth_schema();
yj_login_rate_limit_check();

$username = trim((string)(isset($body['username']) ? $body['username'] : ''));
$password = (string)(isset($body['password']) ? $body['password'] : '');

if ($username === '' || $password === '') {
    yj_json(['error' => '아이디와 비밀번호를 입력해주세요.'], 400);
}

$table = yj_table('admin_users');
$stmt = yj_db()->prepare("SELECT id, password_hash, role, session_version FROM $table WHERE username = ? LIMIT 1");
$stmt->execute([$username]);
$row = $stmt->fetch();

if (!$row || !password_verify($password, $row['password_hash'])) {
    yj_login_rate_limit_record_failure();
    yj_json(['error' => '아이디 또는 비밀번호가 올바르지 않습니다.'], 401);
}

/* 로그인할 때마다 세션ID를 새로 발급합니다 (세션 고정 공격 방지 — 로그인 전에
   찍힌 세션ID를 로그인 후에도 그대로 쓰면, 그 ID를 미리 알고 있던 제3자가
   로그인한 사용자와 같은 세션을 쓸 수 있습니다). */
session_regenerate_id(true);

/* role은 이제 쉼표(,)로 구분된 하나 이상의 값일 수 있어(겸직), 그대로 세션에 저장하고
   해석은 yj_roles()/yj_has_role()에서 합니다. */
$role = (isset($row['role']) && $row['role'] !== '') ? (string)$row['role'] : 'admin';
$_SESSION['yj_admin'] = $username;
$_SESSION['yj_role'] = $role;
$_SESSION['yj_uid'] = (int)$row['id'];
$_SESSION['yj_sver'] = (int)$row['session_version'];
yj_json(['ok' => true, 'username' => $username, 'roles' => explode(',', $role)]);
