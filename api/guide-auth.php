<?php
/* 안내장 앱(guide-print, 학사서버 로컬 앱) 로그인을 홈페이지 계정으로 처리합니다.
   안내장 앱에 계정을 따로 만들지 않고, 홈페이지 관리자 계정(최고관리자·사무실)을
   그대로 씁니다.

   1) action=token (POST) — 홈페이지 관리자 화면에 로그인한 직원이 "안내장 작성"
      버튼을 누르면, 안내장 앱으로 넘겨줄 2분짜리 일회용 확인표를 발급합니다.
      안내장 앱은 같은 키(guide_sso_key)로 서명을 검사하고, 한 번 쓴 확인표는
      다시 받지 않습니다.
   2) action=ping (GET) — 안내장 앱이 "비상 비밀번호를 받아도 되는 상황인지"
      판단할 때 부릅니다. 이 파일·설정·DB가 모두 정상이어야 ok를 돌려줍니다.
      ok가 아니면(파일 없음·설정 누락·DB 장애·인터넷 장애) 버튼으로 로그인할 수
      없는 상황이므로 안내장 앱이 비상 비밀번호를 받습니다.

   (2026-09-30 변경) 예전에는 action=verify로 안내장 앱 로그인 화면에 입력한
   홈페이지 아이디·비밀번호를 확인해줬는데, 안내장 앱이 사내 HTTP라 홈페이지
   비밀번호가 학원 네트워크에 평문으로 지나가는 문제가 있어 없앴습니다(외부
   검토 지적). 이제 안내장 앱에는 버튼(확인표) 또는 원장님 비상 비밀번호로만
   들어갑니다.

   서명 키는 필기시험 연동 키(written_exam_sync_key)와 따로 둡니다(guide_sso_key).
   필기시험 연동 키가 새도 안내장 앱 로그인까지 위조되지 않게 하기 위함입니다. */

require __DIR__ . '/_db.php';

$GUIDE_SSO_KEY_MIN_LEN = 32;

function yj_guide_config() {
    $c = yj_config();
    return [
        'key' => isset($c['guide_sso_key']) ? (string)$c['guide_sso_key'] : '',
        'url' => isset($c['guide_print_url']) ? rtrim((string)$c['guide_print_url'], '/') : '',
    ];
}

function yj_b64url($raw) {
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = isset($_GET['action']) ? (string)$_GET['action'] : '';
    if ($action !== 'ping') {
        yj_json(['error' => 'Bad request'], 400);
    }
    $g = yj_guide_config();
    if (strlen($g['key']) < $GUIDE_SSO_KEY_MIN_LEN || $g['url'] === '') {
        yj_json(['ok' => false, 'error' => 'guide-sso-not-configured'], 503);
    }
    yj_db()->query('SELECT 1');   // 로그인에 필요한 DB가 살아있는지 (장애면 yj_db가 500)
    yj_json(['ok' => true]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    yj_json(['error' => 'Method not allowed'], 405);
}

$body = yj_input();
$action = isset($body['action']) ? (string)$body['action'] : '';

if ($action === 'token') {
    /* 안내장 작성은 수강생 개인정보·일정을 다루므로 최고관리자·사무실만 허용합니다
       (셔틀 전용 계정·강사 계정은 제외). */
    yj_require_content_admin();

    $g = yj_guide_config();
    if (strlen($g['key']) < $GUIDE_SSO_KEY_MIN_LEN || $g['url'] === '') {
        yj_json(['error' => 'config.php에 guide_print_url과 guide_sso_key(32자 이상)를 설정해주세요.'], 500);
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
    $sig = yj_b64url(hash_hmac('sha256', $payload, $g['key'], true));
    yj_json(['url' => $g['url'] . '/sso?t=' . $payload . '.' . $sig]);
}

yj_json(['error' => 'Bad request'], 400);
