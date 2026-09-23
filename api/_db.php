<?php
/* PHP 5.5 이상에서 동작하도록 구형 문법으로 작성했습니다 (return type 선언, ??,
   Throwable 등 PHP 7+ 전용 문법을 쓰지 않습니다). */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* 치명적 오류(fatal error)가 나도 빈 화면 대신 원인을 알 수 있는 JSON을 돌려줍니다.
   (디버깅용 — 문제 원인이 밝혀지면 이 블록은 다시 지워도 됩니다) */
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'error' => 'Fatal error: ' . $err['message'],
            'file' => $err['file'],
            'line' => $err['line'],
        ], JSON_UNESCAPED_UNICODE);
    }
});

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

/* 세션을 완전히 비우고 쿠키도 지웁니다 (로그아웃, 또는 세션이 더 이상
   유효하지 않다고 판단됐을 때 공통으로 씁니다). */
function yj_destroy_session() {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/* 로그인 세션이 지금도 유효한지 매 요청마다 DB로 다시 확인합니다. 로그인 시
   세션에 저장해둔 session_version이 admin_users의 현재 값과 다르면(비밀번호가
   바뀌었거나) 계정이 아예 없어졌으면(삭제됐으면), 세션을 지우고 false를
   돌려줍니다. 통과하면 role도 그 사이 바뀌었을 수 있으니 세션 값을 최신으로
   맞춰둡니다 — 이렇게 하면 관리자 화면에서 계정을 지우거나 비밀번호·역할을
   바꾼 순간부터 그 계정의 예전 세션은 즉시 못 쓰게 됩니다. */
function yj_session_is_valid() {
    static $result = null;
    if ($result !== null) {
        return $result;
    }
    if (empty($_SESSION['yj_admin']) || empty($_SESSION['yj_uid'])) {
        $result = false;
        return false;
    }
    $table = yj_table('admin_users');
    $stmt = yj_db()->prepare("SELECT username, role, session_version FROM $table WHERE id = ? LIMIT 1");
    $stmt->execute([(int)$_SESSION['yj_uid']]);
    $row = $stmt->fetch();
    $sessionVer = isset($_SESSION['yj_sver']) ? (int)$_SESSION['yj_sver'] : -1;
    if (!$row || $row['username'] !== $_SESSION['yj_admin'] || (int)$row['session_version'] !== $sessionVer) {
        yj_destroy_session();
        $result = false;
        return false;
    }
    $_SESSION['yj_role'] = $row['role'];
    $result = true;
    return true;
}

/* CSRF(사이트 간 요청 위조) 방지 — 로그인 세션 쿠키로 인증하는 상태변경 요청은
   다른 사이트가 관리자의 브라우저를 통해 몰래 대신 보낼 수 있으면 안 됩니다.
   (JSON 본문은 php://input으로 Content-Type과 무관하게 읽히기 때문에, text/plain
   폼처럼 <form>만으로도 이런 요청을 만들어낼 수 있는 알려진 기법이 있습니다.)
   브라우저가 요청에 자동으로 붙이는 Origin(없으면 Referer) 헤더가 지금 이
   사이트 자신인지 확인해서, 아니면 막습니다. 도메인을 하드코딩하지 않고 지금
   요청이 온 Host와 비교하므로 어느 도메인/서브도메인에 배포해도 그대로 동작합니다.
   조회만 하는 GET은 상태를 바꾸지 않으니 대상이 아닙니다. */
function yj_verify_same_origin() {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') { return; }
    $host = isset($_SERVER['HTTP_HOST']) ? strtolower($_SERVER['HTTP_HOST']) : '';
    $check = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : (isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '');
    /* HTTP_HOST에는 기본 포트(80/443)가 아니면 포트까지 포함되는데, parse_url()의
       PHP_URL_HOST는 포트를 떼어내므로 그냥 비교하면 포트가 다른 개발 환경 등에서
       오탐이 납니다. 포트까지 다시 붙여서 같은 형태로 맞춰 비교합니다. */
    $checkHost = null;
    if ($check !== '') {
        $h = parse_url($check, PHP_URL_HOST);
        $p = parse_url($check, PHP_URL_PORT);
        if ($h !== null && $h !== false) {
            $checkHost = strtolower($h) . ($p ? ':' . $p : '');
        }
    }
    if ($host === '' || $checkHost === null || $checkHost !== $host) {
        yj_json(['error' => '요청을 확인할 수 없습니다. 새로고침 후 다시 시도해주세요.'], 403);
    }
}

function yj_require_login() {
    if (!yj_session_is_valid()) {
        yj_json(['error' => '로그인이 만료되었습니다. 다시 로그인해주세요.'], 401);
    }
    yj_verify_same_origin();
}

/* 현재 로그인한 계정의 역할들 (겸직 가능해서 쉼표로 구분된 하나 이상의 값):
   'admin' = 최고관리자(전체+계정관리), 'manager' = 사무실(전체, 계정관리 제외),
   'office' = 셔틀계정(셔틀 명단만), 'instructor' = 강사(본인 예약만 조회) */
function yj_roles() {
    $raw = isset($_SESSION['yj_role']) ? (string)$_SESSION['yj_role'] : 'admin';
    $parts = array_filter(array_map('trim', explode(',', $raw)), function ($v) { return $v !== ''; });
    return $parts ? array_values($parts) : ['admin'];
}
function yj_has_role($role) {
    return in_array($role, yj_roles(), true);
}

/* 계정 관리(다른 로그인 생성·삭제)처럼 최고관리자만 손대야 하는 기능에 씁니다. */
function yj_require_admin() {
    yj_require_login();
    if (!yj_has_role('admin')) {
        yj_json(['error' => '이 작업은 최고관리자 계정만 할 수 있습니다.'], 403);
    }
}

/* 공지·수강료·임직원·업로드·문의메시지·면허가이드처럼, 계정 관리를 제외한
   콘텐츠 전반을 다루는 기능에 씁니다 (최고관리자 + 사무실, 겸직도 통과). */
function yj_require_content_admin() {
    yj_require_login();
    if (!yj_has_role('admin') && !yj_has_role('manager')) {
        yj_json(['error' => '이 작업은 관리자 또는 사무실 계정만 할 수 있습니다.'], 403);
    }
}

/* 셔틀 명단 작성/조회에 씁니다 (최고관리자 + 사무실 + 셔틀계정, 겸직도 통과).
   강사 역할만 있는 계정은 셔틀 명단을 건드릴 필요가 없어 막습니다. */
function yj_require_shuttle_admin() {
    yj_require_login();
    if (!yj_has_role('admin') && !yj_has_role('manager') && !yj_has_role('office')) {
        yj_json(['error' => '이 작업은 관리자·사무실·셔틀 계정만 할 수 있습니다.'], 403);
    }
}

/* 학사서버의 자동화 프로그램처럼, 로그인 세션이 없는 서버-투-서버 호출을 인증할 때
   씁니다. 요청 헤더 X-Sync-Key 값이 config.php의 $configKey 값과 일치해야 통과합니다.
   프로그램마다 용도별로 다른 config 키를 쓰면(예: schedule_sync_key, written_exam_sync_key)
   하나가 새어나가도 다른 프로그램에는 영향이 없습니다. */
function yj_require_sync_key($configKey = 'schedule_sync_key') {
    $c = yj_config();
    $expected = isset($c[$configKey]) ? (string)$c[$configKey] : '';
    /* $_SERVER의 HTTP_X_SYNC_KEY는 웹서버 종류(Apache/nginx+PHP-FPM 등)와 무관하게
       항상 쓸 수 있어서, getallheaders()보다 더 안전합니다. */
    $given = isset($_SERVER['HTTP_X_SYNC_KEY']) ? (string)$_SERVER['HTTP_X_SYNC_KEY'] : '';
    $match = function_exists('hash_equals')
        ? hash_equals($expected, $given)
        : ($expected !== '' && $expected === $given);
    if ($expected === '' || $given === '' || !$match) {
        yj_json(['error' => '인증에 실패했습니다.'], 401);
    }
}

function yj_input() {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/* 로그인 무차별 대입(비밀번호 자동 시도) 방지 — 같은 IP에서 15분 안에 로그인
   실패가 너무 많으면 잠깐 막습니다. 세션 카운터(셔틀/필기시험 조회 제한처럼)는
   쿠키를 새로 받으면 바로 우회되므로 로그인처럼 값이 큰 대상에는 약해서,
   IP 기준으로 DB에 기록합니다. */
function yj_login_rate_limit_check() {
    $ip = isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '';
    if ($ip === '') { return; }
    $table = yj_table('login_attempts');
    $db = yj_db();
    $stmt = $db->prepare("SELECT COUNT(*) FROM $table WHERE ip = ? AND created_at > (NOW() - INTERVAL 15 MINUTE)");
    $stmt->execute([$ip]);
    if ((int)$stmt->fetchColumn() >= 10) {
        yj_json(['error' => '로그인 시도가 너무 많습니다. 15분 후 다시 시도해주세요.'], 429);
    }
    /* 오래된 기록은 매번 지우지 않고 가끔(1% 확률)만 정리해서, 매 요청마다
       DELETE가 도는 부담을 줄입니다. */
    if (mt_rand(1, 100) === 1) {
        $db->exec("DELETE FROM $table WHERE created_at < (NOW() - INTERVAL 1 DAY)");
    }
}
function yj_login_rate_limit_record_failure() {
    $ip = isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '';
    if ($ip === '') { return; }
    $table = yj_table('login_attempts');
    $stmt = yj_db()->prepare("INSERT INTO $table (ip) VALUES (?)");
    $stmt->execute([$ip]);
}
