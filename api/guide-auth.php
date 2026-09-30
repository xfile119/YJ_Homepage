<?php
/* 안내장 앱(guide-print, 학사서버 로컬 앱) 로그인을 홈페이지 계정으로 처리합니다.
   안내장 앱에 계정을 따로 만들지 않고, 홈페이지 관리자 계정(최고관리자·사무실)을
   그대로 씁니다.

   1) action=verify — 안내장 앱 서버가 직원이 입력한 아이디·비밀번호를 확인받는
      서버-투-서버 호출. X-Sync-Key(written_exam_sync_key)가 있어야 하고, 로그인과
      같은 IP 기준 실패 횟수 제한을 받습니다.
   2) action=token — 홈페이지 관리자 화면에 로그인한 직원이 "안내장 작성" 버튼을
      누르면, 안내장 앱으로 넘겨줄 2분짜리 일회용 확인표를 발급합니다. 안내장 앱은
      같은 키로 서명을 검사하고, 한 번 쓴 확인표는 다시 받지 않습니다.

   서명 키로 written_exam_sync_key를 같이 쓰는 이유: 안내장 앱과 홈페이지 사이의
   신뢰는 원래 이 키 하나로 맺어져 있어서(필기시험 저장·삭제), 키를 하나 더 늘려도
   둘 다 같은 config.json에 들어가므로 보호 수준이 달라지지 않습니다. 이 키가
   새면 필기시험 기록 조작과 안내장 앱 로그인이 모두 가능해지므로, 새었다고 의심되면
   config.php와 안내장 앱 config.json 양쪽에서 즉시 바꿔야 합니다. */

require __DIR__ . '/_db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    yj_json(['error' => 'Method not allowed'], 405);
}

$body = yj_input();
$action = isset($body['action']) ? (string)$body['action'] : '';

/* 안내장 작성은 수강생 개인정보·일정을 다루므로 최고관리자·사무실만 허용합니다
   (셔틀 전용 계정·강사 계정은 제외). */
function yj_guide_allowed($roleCsv) {
    $roles = array_filter(array_map('trim', explode(',', (string)$roleCsv)));
    return in_array('admin', $roles, true) || in_array('manager', $roles, true);
}

function yj_b64url($raw) {
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

if ($action === 'verify') {
    yj_require_sync_key('written_exam_sync_key');
    yj_ensure_auth_schema();
    yj_login_rate_limit_check();

    $username = trim((string)(isset($body['username']) ? $body['username'] : ''));
    $password = (string)(isset($body['password']) ? $body['password'] : '');
    if ($username === '' || $password === '') {
        yj_json(['error' => '아이디와 비밀번호를 입력해주세요.'], 400);
    }

    $table = yj_table('admin_users');
    $stmt = yj_db()->prepare("SELECT password_hash, role, real_name FROM $table WHERE username = ? LIMIT 1");
    $stmt->execute([$username]);
    $row = $stmt->fetch();
    if (!$row || !password_verify($password, $row['password_hash'])) {
        yj_login_rate_limit_record_failure();
        yj_json(['error' => '아이디 또는 비밀번호가 올바르지 않습니다.'], 401);
    }
    if (!yj_guide_allowed($row['role'])) {
        yj_json(['error' => '안내장 작성 권한이 없는 계정입니다. 최고관리자·사무실 계정으로 로그인해주세요.'], 403);
    }
    yj_json(['ok' => true, 'username' => $username, 'realName' => (string)$row['real_name']]);
}

if ($action === 'token') {
    yj_require_content_admin();

    $c = yj_config();
    $key = isset($c['written_exam_sync_key']) ? (string)$c['written_exam_sync_key'] : '';
    $baseUrl = isset($c['guide_print_url']) ? rtrim((string)$c['guide_print_url'], '/') : '';
    if ($key === '' || $baseUrl === '') {
        yj_json(['error' => 'config.php에 guide_print_url과 written_exam_sync_key를 설정해주세요.'], 500);
    }

    $table = yj_table('admin_users');
    $stmt = yj_db()->prepare("SELECT real_name FROM $table WHERE id = ? LIMIT 1");
    $stmt->execute([(int)$_SESSION['yj_uid']]);
    $realName = (string)$stmt->fetchColumn();

    $now = time();
    $payload = yj_b64url(json_encode([
        'aud' => 'guide-print',
        'u' => (string)$_SESSION['yj_admin'],
        'n' => $realName,
        'iat' => $now,
        'exp' => $now + 120,
        /* random_bytes는 PHP 7+ 전용이라, 5.x 서버에서도 돌도록 openssl로 대신합니다 */
        'jti' => bin2hex(function_exists('random_bytes') ? random_bytes(16) : openssl_random_pseudo_bytes(16)),
    ], JSON_UNESCAPED_UNICODE));
    $sig = yj_b64url(hash_hmac('sha256', $payload, $key, true));
    yj_json(['url' => $baseUrl . '/sso?t=' . $payload . '.' . $sig]);
}

yj_json(['error' => 'Bad request'], 400);
