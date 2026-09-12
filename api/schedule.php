<?php
/* 수강생 본인 일정조회 API (로그인 불필요)
   - POST action=lookup : 이름 + 전화번호 뒷 4자리로 본인 일정만 반환

   개인정보(이름·연락처)를 다루므로 shuttle.php의 lookup과 같은 원칙을 지킵니다.
   1) 이름과 전화번호 뒷 4자리가 모두 맞아야 하고,
   2) 일치한 본인의 행만 돌려주며 (다른 사람 일정은 절대 나가지 않음),
   3) 오늘 이후 날짜만 보여주고 (지난 기록 조회 차단),
   4) 세션당 조회 횟수를 제한해 무작위 대입을 늦춥니다.
   PHP 5.5에서도 동작하도록 구형 문법으로 작성했습니다. */

require __DIR__ . '/_db.php';

$table = yj_table('student_schedule');

function yj_sched_digits($s) {
    return preg_replace('/[^0-9]/', '', (string)$s);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    yj_json(['error' => 'Method not allowed'], 405);
}

$body = yj_input();
$action = isset($body['action']) ? $body['action'] : '';

if ($action !== 'lookup') {
    yj_json(['error' => 'Bad request'], 400);
}

/* 세션당 10분에 15회로 제한 */
$now = time();
if (!isset($_SESSION['yj_schedule_try']) || !is_array($_SESSION['yj_schedule_try'])) {
    $_SESSION['yj_schedule_try'] = [];
}
$tries = [];
foreach ($_SESSION['yj_schedule_try'] as $t) {
    if ($now - $t < 600) { $tries[] = $t; }
}
if (count($tries) >= 15) {
    $_SESSION['yj_schedule_try'] = $tries;
    yj_json(['error' => '조회를 너무 많이 시도했습니다. 잠시 후 다시 시도해주세요.'], 429);
}
$tries[] = $now;
$_SESSION['yj_schedule_try'] = $tries;

$name = trim((string)(isset($body['name']) ? $body['name'] : ''));
$tail = yj_sched_digits(isset($body['phoneTail']) ? $body['phoneTail'] : '');
if ($name === '' || strlen($tail) !== 4) {
    yj_json(['error' => '이름과 전화번호 뒷 4자리를 정확히 입력해주세요.'], 400);
}

$today = date('Ymd');
/* 이름은 공백을 무시하고 비교합니다 */
$nameKey = preg_replace('/\s+/u', '', $name);
$stmt = yj_db()->prepare(
    "SELECT edu_type, reservation_date, reservation_time, staff_name, place, phone
       FROM $table
      WHERE reservation_date >= ? AND REPLACE(REPLACE(name, ' ', ''), '\t', '') = ?
      ORDER BY reservation_date ASC, reservation_time ASC"
);
$stmt->execute([$today, $nameKey]);

$mine = [];
foreach ($stmt->fetchAll() as $r) {
    if (substr(yj_sched_digits($r['phone']), -4) === $tail) {
        $mine[] = [
            'eduType' => $r['edu_type'],
            'date' => $r['reservation_date'],
            'time' => $r['reservation_time'],
            'staffName' => $r['staff_name'],
            'place' => $r['place'],
        ];
    }
}

yj_json(['found' => count($mine) > 0, 'schedule' => $mine]);
