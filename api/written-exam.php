<?php
/* 필기시험 API — 두 종류의 호출자가 씁니다.
   1) 안내장 앱(guide-print) — 로그인 세션이 아니라 X-Sync-Key 헤더
      (config.php의 written_exam_sync_key)로 인증하는 저장/조회/삭제.
   2) 수강생 본인 — written-exam.html에서 이름+연락처 뒷4자리로 조회(action=lookup).
      로그인·인증키 없이 접속 가능하므로, 셔틀 조회(api/shuttle.php)와 같은
      원칙을 그대로 따릅니다: 본인 확인 필수, 지난 날짜는 안 보여줌, 시도 횟수 제한.

   학사DB(neoinfo)에 필기시험 자료가 없어, 사무실에서 상담해서 정한 날짜를
   여기 저장해둡니다. 한 학생당 최신 한 건만 관리합니다(예약 이력이 아니라
   "지금 정해진 다음 필기시험" 하나). 주민번호 등 민감정보는 다루지 않습니다.

   PHP 5.5 이상에서 동작하도록 구형 문법으로 작성했습니다. */

require __DIR__ . '/_db.php';

$method = $_SERVER['REQUEST_METHOD'];
$table = yj_table('written_exam');

$DATE_RE = '/^\d{4}-\d{2}-\d{2}$/';
/* 예전엔 관리자 화면이 없어 "오전 09:00"/"오후 13:30" 두 가지로 고정해뒀지만,
   이제 필기시험 관리 화면(admin-written-exam.html)에서 회차 시각을 자유롭게
   정하고, 안내장 앱은 그 실제 값을 미니 달력으로 보여주며 이 칸을 채웁니다.
   그래서 고정 목록 대신 형식만 느슨하게 검사합니다. */

function yj_digits($s) {
    return preg_replace('/[^0-9]/', '', (string)$s);
}

/* php://input은 요청당 한 번만 안전하게 읽힐 수 있는 환경이 있어(서버 설정에 따라
   다름), 이후 어느 분기를 타든 재사용할 수 있도록 여기서 딱 한 번만 읽습니다. */
$body = $method === 'POST' ? yj_input() : [];

/* ---------------- 수강생 본인 조회 (비로그인, 인증키 불필요) ---------------- */
if ($method === 'POST') {
    if (isset($body['action']) && $body['action'] === 'lookup') {
        /* 세션당 10분에 15회로 제한 (셔틀 조회와 같은 기준, 별도 카운터 사용) */
        $now = time();
        if (!isset($_SESSION['yj_written_exam_try']) || !is_array($_SESSION['yj_written_exam_try'])) {
            $_SESSION['yj_written_exam_try'] = [];
        }
        $tries = [];
        foreach ($_SESSION['yj_written_exam_try'] as $t) {
            if ($now - $t < 600) { $tries[] = $t; }
        }
        if (count($tries) >= 15) {
            $_SESSION['yj_written_exam_try'] = $tries;
            yj_json(['error' => '조회를 너무 많이 시도했습니다. 잠시 후 다시 시도해주세요.'], 429);
        }
        $tries[] = $now;
        $_SESSION['yj_written_exam_try'] = $tries;

        $name = trim((string)(isset($body['name']) ? $body['name'] : ''));
        $tail = yj_digits(isset($body['phoneTail']) ? $body['phoneTail'] : '');
        if ($name === '' || strlen($tail) !== 4) {
            yj_json(['error' => '이름과 전화번호 뒷 4자리를 정확히 입력해주세요.'], 400);
        }

        $today = date('Y-m-d');
        $nameKey = preg_replace('/\s+/u', '', $name);
        $stmt = yj_db()->prepare(
            "SELECT exam_date, exam_time, place, student_phone
               FROM $table
              WHERE exam_date >= ? AND REPLACE(REPLACE(student_name, ' ', ''), '\t', '') = ?"
        );
        $stmt->execute([$today, $nameKey]);

        $found = null;
        foreach ($stmt->fetchAll() as $r) {
            if (substr(yj_digits($r['student_phone']), -4) === $tail) {
                $found = $r;
                break;
            }
        }
        /* 이름만 맞고 번호가 틀린 경우와 아예 없는 경우를 구분하지 않습니다 (정보 유추 방지) */
        if (!$found) {
            yj_json(['found' => false]);
        }
        yj_json([
            'found' => true,
            'examDate' => $found['exam_date'],
            'examTime' => $found['exam_time'],
            'place' => $found['place'],
        ]);
    }
}

/* ---------------- 여기부터는 안내장 앱 전용 (인증키 필요) ---------------- */
yj_require_sync_key('written_exam_sync_key');

if ($method === 'GET') {
    $studentId = isset($_GET['studentId']) ? (int)$_GET['studentId'] : 0;
    if ($studentId <= 0) {
        yj_json(['error' => 'studentId가 필요합니다.'], 400);
    }
    $stmt = yj_db()->prepare("SELECT exam_date, exam_time, place FROM $table WHERE student_id = ?");
    $stmt->execute([$studentId]);
    $row = $stmt->fetch();
    if (!$row) {
        yj_json((object)[]);  /* {} — 빈 배열([])이 아니라 빈 객체로, guide-print 쪽과 형식을 맞춥니다 */
    }
    yj_json(['examDate' => $row['exam_date'], 'examTime' => $row['exam_time'], 'place' => $row['place']]);
}

if ($method !== 'POST') {
    yj_json(['error' => 'Method not allowed'], 405);
}

$action = isset($body['action']) ? $body['action'] : '';

$studentId = isset($body['studentId']) ? (int)$body['studentId'] : 0;
if ($studentId <= 0) {
    yj_json(['error' => 'studentId가 필요합니다.'], 400);
}

if ($action === 'delete') {
    yj_db()->prepare("DELETE FROM $table WHERE student_id = ?")->execute([$studentId]);
    yj_json(['ok' => true]);
}

if ($action !== 'save') {
    yj_json(['error' => 'Bad request'], 400);
}

$studentName = trim((string)(isset($body['studentName']) ? $body['studentName'] : ''));
$studentPhone = trim((string)(isset($body['studentPhone']) ? $body['studentPhone'] : ''));
$examDate = trim((string)(isset($body['examDate']) ? $body['examDate'] : ''));
$examTime = trim((string)(isset($body['examTime']) ? $body['examTime'] : ''));
$place = trim((string)(isset($body['place']) ? $body['place'] : '')) ?: '나주';
$updatedBy = trim((string)(isset($body['updatedBy']) ? $body['updatedBy'] : ''));

if ($studentName === '') {
    yj_json(['error' => '수강생 이름이 필요합니다.'], 400);
}
if (!preg_match($DATE_RE, $examDate)) {
    yj_json(['error' => '날짜 형식이 올바르지 않습니다.'], 400);
}
/* exam_time 컬럼이 VARCHAR(20)이라 길이만 지킵니다 (db/schema.sql 참고) */
if (mb_strlen($examTime) > 20) {
    yj_json(['error' => '시각 문구가 너무 깁니다 (20자 이내로 적어주세요).'], 400);
}

$stmt = yj_db()->prepare(
    "INSERT INTO $table (student_id, student_name, student_phone, exam_date, exam_time, place, updated_by)
     VALUES (?, ?, ?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE
       student_name = VALUES(student_name),
       student_phone = VALUES(student_phone),
       exam_date = VALUES(exam_date),
       exam_time = VALUES(exam_time),
       place = VALUES(place),
       updated_by = VALUES(updated_by)"
);
$stmt->execute([$studentId, $studentName, $studentPhone, $examDate, $examTime, $place, $updatedBy]);

yj_json(['ok' => true]);
