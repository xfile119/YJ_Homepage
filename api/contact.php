<?php
require __DIR__ . '/_db.php';

$method = $_SERVER['REQUEST_METHOD'];
$table = yj_table('contact_messages');

/* 면허 탐색기 상담 신청용 칼럼이 아직 없으면(파일만 먼저 올리고 db/setup.php를
   아직 안 돌린 경우) 만들어 둡니다. 정의는 db/setup.php와 같게 유지하세요. */
function yj_contact_ensure_schema($table) {
    yj_db_ensure_column($table, 'source', "VARCHAR(20) NOT NULL DEFAULT ''");
    yj_db_ensure_column($table, 'course_code', "VARCHAR(20) NOT NULL DEFAULT ''");
    yj_db_ensure_column($table, 'course_title', "VARCHAR(100) NOT NULL DEFAULT ''");
    yj_db_ensure_column($table, 'course_path', "VARCHAR(255) NOT NULL DEFAULT ''");
    yj_db_ensure_column($table, 'est_total', "VARCHAR(30) NOT NULL DEFAULT ''");
    yj_db_ensure_column($table, 'contact_time', "VARCHAR(5) NOT NULL DEFAULT ''");
}

if ($method === 'GET') {
    yj_require_content_admin();
    yj_contact_ensure_schema($table);

    /* 관리자 메인의 "새 문의 N" 뱃지용 — 목록 전체 대신 개수만 */
    if (isset($_GET['count']) && $_GET['count'] === 'unread') {
        $n = (int)yj_db()->query("SELECT COUNT(*) FROM $table WHERE status = 'unread'")->fetchColumn();
        yj_json(['unread' => $n]);
    }

    $stmt = yj_db()->query("SELECT id, name, phone, message, status, source, course_code, course_title, course_path, est_total, contact_time, created_at FROM $table ORDER BY id DESC");
    $rows = $stmt->fetchAll();
    $messages = array_map(function ($r) {
        return [
            'id' => (int)$r['id'],
            'name' => $r['name'],
            'phone' => $r['phone'],
            'message' => $r['message'],
            'status' => $r['status'],
            'source' => $r['source'],
            'courseCode' => $r['course_code'],
            'courseTitle' => $r['course_title'],
            'coursePath' => $r['course_path'],
            'estTotal' => $r['est_total'],
            'contactTime' => $r['contact_time'],
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

/* 면허 탐색기 결과 화면의 "상담 신청하기" — 누구나(로그인 없이) 보낼 수 있습니다.
   과정 정보는 화면에 보여줄 참고용이라 서버에서 금액을 다시 계산하지는 않고,
   형식·길이만 검사합니다 (관리자 화면에서는 이스케이프해서 보여줍니다). */
if ($action === 'consult') {
    /* 세션당 10분에 5회 (중복 클릭·장난 제출 방지) */
    $now = time();
    $tries = [];
    if (isset($_SESSION['yj_consult_try']) && is_array($_SESSION['yj_consult_try'])) {
        foreach ($_SESSION['yj_consult_try'] as $t) {
            if ($now - $t < 600) { $tries[] = $t; }
        }
    }
    if (count($tries) >= 5) {
        $_SESSION['yj_consult_try'] = $tries;
        yj_json(['error' => '상담 신청이 너무 많이 접수됐습니다. 잠시 후 다시 시도하시거나 전화로 문의해주세요.'], 429);
    }

    $str = function ($k) use ($body) { return trim((string)(isset($body[$k]) ? $body[$k] : '')); };
    $name = $str('name');
    $phone = $str('phone');
    $message = $str('message');
    $courseCode = $str('courseCode');
    $courseTitle = $str('courseTitle');
    $coursePath = $str('coursePath');
    $estTotal = $str('estTotal');
    $contactTime = $str('contactTime');

    if ($name === '' || $phone === '') {
        yj_json(['error' => '이름과 연락처를 입력해주세요.'], 400);
    }
    if (strlen(preg_replace('/[^0-9]/', '', $phone)) < 9) {
        yj_json(['error' => '연락처를 다시 확인해주세요.'], 400);
    }
    if (!preg_match('/^(\d{2}):(00|30)$/', $contactTime, $m) || (int)$m[1] * 60 + (int)$m[2] < 540 || (int)$m[1] * 60 + (int)$m[2] > 1080) {
        yj_json(['error' => '연락 가능한 시간은 09:00~18:00 사이로 골라주세요.'], 400);
    }
    if ($courseCode !== '' && !preg_match('/^[A-Z0-9]{1,4}-\d{2}$/', $courseCode)) {
        yj_json(['error' => '잘못된 요청입니다.'], 400);
    }
    if (mb_strlen($name) > 50 || mb_strlen($phone) > 30 || mb_strlen($message) > 2000
        || mb_strlen($courseTitle) > 100 || mb_strlen($coursePath) > 255 || mb_strlen($estTotal) > 30) {
        yj_json(['error' => '입력한 내용이 너무 깁니다.'], 400);
    }

    $tries[] = $now;
    $_SESSION['yj_consult_try'] = $tries;

    yj_contact_ensure_schema($table);
    $stmt = yj_db()->prepare(
        "INSERT INTO $table (name, phone, message, status, source, course_code, course_title, course_path, est_total, contact_time)
         VALUES (?, ?, ?, 'unread', 'license', ?, ?, ?, ?, ?)"
    );
    $stmt->execute([$name, $phone, $message, $courseCode, $courseTitle, $coursePath, $estTotal, $contactTime]);
    yj_json(['ok' => true]);
}

yj_require_content_admin();

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
