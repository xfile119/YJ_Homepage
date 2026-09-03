<?php
declare(strict_types=1);
require __DIR__ . '/_db.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    yj_json(['loggedIn' => !empty($_SESSION['yj_admin'])]);
}

if ($method !== 'POST') {
    yj_json(['error' => 'Method not allowed'], 405);
}

$body = yj_input();
$action = $body['action'] ?? 'login';

if ($action === 'logout') {
    unset($_SESSION['yj_admin']);
    yj_json(['ok' => true]);
}

$username = trim((string)($body['username'] ?? ''));
$password = (string)($body['password'] ?? '');

if ($username === '' || $password === '') {
    yj_json(['error' => '아이디와 비밀번호를 입력해주세요.'], 400);
}

$table = yj_table('admin_users');
$stmt = yj_db()->prepare("SELECT password_hash FROM $table WHERE username = ? LIMIT 1");
$stmt->execute([$username]);
$row = $stmt->fetch();

if (!$row || !password_verify($password, $row['password_hash'])) {
    yj_json(['error' => '아이디 또는 비밀번호가 올바르지 않습니다.'], 401);
}

$_SESSION['yj_admin'] = $username;
yj_json(['ok' => true]);
