<?php
/* 필기시험 저장 API — 안내장 앱(guide-print)이 호출하는 전용 API입니다.
   로그인 세션이 아니라 X-Sync-Key 헤더(config.php의 written_exam_sync_key)로 인증합니다.

   학사DB(neoinfo)에 필기시험 자료가 없어, 사무실에서 상담해서 정한 날짜를
   여기 저장해둡니다. 한 학생당 최신 한 건만 관리합니다(예약 이력이 아니라
   "지금 정해진 다음 필기시험" 하나). 주민번호 등 민감정보는 다루지 않습니다.

   PHP 5.5 이상에서 동작하도록 구형 문법으로 작성했습니다. */

require __DIR__ . '/_db.php';

$method = $_SERVER['REQUEST_METHOD'];
$table = yj_table('written_exam');

yj_require_sync_key('written_exam_sync_key');

$DATE_RE = '/^\d{4}-\d{2}-\d{2}$/';
/* 관리자 화면이 생기기 전까지는 이 두 가지 중에서만 고를 수 있습니다.
   guide-print/app.py의 WRITTEN_EXAM_TIMES와 반드시 같게 유지하세요. */
$ALLOWED_TIMES = ['오전 09:00', '오후 13:30'];

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

$body = yj_input();
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
if ($examTime !== '' && !in_array($examTime, $ALLOWED_TIMES, true)) {
    yj_json(['error' => '시각은 목록에 있는 것만 고를 수 있습니다.'], 400);
}

$stmt = yj_db()->prepare(
    "INSERT INTO $table (student_id, student_name, exam_date, exam_time, place, updated_by)
     VALUES (?, ?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE
       student_name = VALUES(student_name),
       exam_date = VALUES(exam_date),
       exam_time = VALUES(exam_time),
       place = VALUES(place),
       updated_by = VALUES(updated_by)"
);
$stmt->execute([$studentId, $studentName, $examDate, $examTime, $place, $updatedBy]);

yj_json(['ok' => true]);
