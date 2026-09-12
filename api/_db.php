<?php
/* PHP 5.5 이상에서 동작하도록 구형 문법으로 작성했습니다 (return type 선언, ??,
   Throwable 등 PHP 7+ 전용 문법을 쓰지 않습니다). */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function yj_config() {
    static $config = null;
    if ($config === null) {
        $path = __DIR__ . '/../config.php';
        if (!file_exists($path)) {
            yj_json(['error' => 'config.php가 없습니다. config.example.php를 복사해서 config.php를 만들고 DB 정보를 입력해주세요.'], 500);
        }
        $config = require $path;
    }
    return $config;
}

function yj_db() {
    static $pdo = null;
    if ($pdo === null) {
        $c = yj_config();
        $dsn = "mysql:host={$c['db_host']};dbname={$c['db_name']};charset=utf8";
        try {
            $pdo = new PDO($dsn, $c['db_user'], $c['db_pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (Exception $e) {
            yj_json(['error' => 'DB 연결 실패: ' . $e->getMessage()], 500);
        }
    }
    return $pdo;
}

function yj_table($name) {
    $c = yj_config();
    return $c['table_prefix'] . $name;
}

function yj_json($data, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function yj_require_login() {
    if (empty($_SESSION['yj_admin'])) {
        yj_json(['error' => '로그인이 필요합니다.'], 401);
    }
}

/* 현재 로그인한 계정의 권한: 'admin'(전체) 또는 'office'(셔틀 명단만) */
function yj_role() {
    return isset($_SESSION['yj_role']) ? $_SESSION['yj_role'] : 'admin';
}

/* 공지·수강료·임직원·업로드처럼 원장님만 손대야 하는 기능에 씁니다. */
function yj_require_admin() {
    yj_require_login();
    if (yj_role() !== 'admin') {
        yj_json(['error' => '이 작업은 관리자 계정만 할 수 있습니다.'], 403);
    }
}

/* 학사서버의 동기화 프로그램처럼, 로그인 세션이 없는 서버-투-서버 호출을 인증할 때 씁니다.
   요청 헤더 X-Sync-Key 값이 config.php의 schedule_sync_key와 일치해야 통과합니다. */
function yj_require_sync_key() {
    $c = yj_config();
    $expected = isset($c['schedule_sync_key']) ? (string)$c['schedule_sync_key'] : '';
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $given = '';
    foreach ($headers as $k => $v) {
        if (strtolower($k) === 'x-sync-key') { $given = (string)$v; break; }
    }
    if ($expected === '' || $given === '' || !hash_equals($expected, $given)) {
        yj_json(['error' => '인증에 실패했습니다.'], 401);
    }
}

function yj_input() {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}
