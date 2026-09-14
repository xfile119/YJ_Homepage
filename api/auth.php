<?php
require __DIR__ . '/_db.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    yj_json([
        'loggedIn' => !empty($_SESSION['yj_admin']),
        'username' => isset($_SESSION['yj_admin']) ? $_SESSION['yj_admin'] : '',
        'roles' => !empty($_SESSION['yj_admin']) ? yj_roles() : [],
    ]);
}

if ($method !== 'POST') {
    yj_json(['error' => 'Method not allowed'], 405);
}

$body = yj_input();
$action = isset($body['action']) ? $body['action'] : 'login';

if ($action === 'logout') {
    unset($_SESSION['yj_admin']);
    unset($_SESSION['yj_role']);
    yj_json(['ok' => true]);
}

$username = trim((string)(isset($body['username']) ? $body['username'] : ''));
$password = (string)(isset($body['password']) ? $body['password'] : '');

if ($username === '' || $password === '') {
    yj_json(['error' => '아이디와 비밀번호를 입력해주세요.'], 400);
}

$table = yj_table('admin_users');
$stmt = yj_db()->prepare("SELECT password_hash, role FROM $table WHERE username = ? LIMIT 1");
$stmt->execute([$username]);
$row = $stmt->fetch();

if (!$row || !password_verify($password, $row['password_hash'])) {
    yj_json(['error' => '아이디 또는 비밀번호가 올바르지 않습니다.'], 401);
}

/* role은 이제 쉼표(,)로 구분된 하나 이상의 값일 수 있어(겸직), 그대로 세션에 저장하고
   해석은 yj_roles()/yj_has_role()에서 합니다. */
$role = (isset($row['role']) && $row['role'] !== '') ? (string)$row['role'] : 'admin';
$_SESSION['yj_admin'] = $username;
$_SESSION['yj_role'] = $role;
yj_json(['ok' => true, 'username' => $username, 'roles' => explode(',', $role)]);
