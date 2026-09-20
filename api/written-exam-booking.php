<?php
/* 필기시험 예약 API — 수강생 자가 신청/변경/취소 + 관리자 회차·예약 관리.
   설계 근거: docs-11-written-exam-db.md, docs-12-written-exam-screens.md
   (yj-academy-messaging 저장소). 안내장 앱 전용 api/written-exam.php와는
   별개입니다 — 이건 수강생이 직접 쓰는 정식 예약 시스템입니다.

   PHP 5.5 이상에서 동작하도록 구형 문법으로 작성했습니다. */

require __DIR__ . '/_db.php';

$slotsTable = yj_table('written_exam_slots');
$bookingsTable = yj_table('written_exam_bookings');
$logTable = yj_table('written_exam_log');
$holidaysTable = yj_table('holidays');
$scheduleTable = yj_table('student_schedule');

/* 관리자는 안내장 앱이 쓰는 role과 별개로, 셔틀 담당과 같은 사람들이 다루므로
   셔틀과 같은 권한(최고관리자·사무실·셔틀계정)을 그대로 씁니다. */
function yj_require_wexam_admin() { yj_require_shuttle_admin(); }

function yj_wexam_digits($s) { return preg_replace('/[^0-9]/', '', (string)$s); }
function yj_wexam_name_key($s) { return preg_replace('/\s+/u', '', (string)$s); }

/* 'YYYY-MM-DD' -> 'YYYYMMDD' (student_schedule과 비교하기 위함) */
function yj_wexam_ymd8($ymd10) { return str_replace('-', '', (string)$ymd10); }

/* 'HH:MM' -> 분 단위 정수. 비교용. */
function yj_wexam_to_min($hhmm) {
    if (!preg_match('/^(\d{1,2}):(\d{2})$/', (string)$hhmm, $m)) { return null; }
    return ((int)$m[1]) * 60 + (int)$m[2];
}
/* student_schedule.reservation_time은 'HHMM' 형태라 별도 변환 */
function yj_wexam_hhmm_to_min($hhmm4) {
    $s = preg_replace('/[^0-9]/', '', (string)$hhmm4);
    if (strlen($s) < 3) { return null; }
    $s = str_pad($s, 4, '0', STR_PAD_LEFT);
    return ((int)substr($s, 0, 2)) * 60 + (int)substr($s, 2, 2);
}

/* 그 학생의 그날 수업 목록 (edu_type, 시작~끝 추정, 시각) — 겹침 판정용.
   교육시간은 -2시간(50분=1교시) 규칙이 아니라 student_schedule에 끝 시각이
   없으므로, 안내장 쪽과 달리 여기서는 시작 시각만 압니다. 겹침 판정은
   "그날 수업이 있는지"로 충분하며(문서 규칙), 관리자 팝업에서는 관리자가
   직접 눈으로 보고 판단합니다. */
function yj_wexam_classes_that_day($name, $phone, $examYmd10) {
    global $scheduleTable;
    $ymd8 = yj_wexam_ymd8($examYmd10);
    $nameKey = yj_wexam_name_key($name);
    $tail = substr(yj_wexam_digits($phone), -4);
    $stmt = yj_db()->prepare(
        "SELECT edu_type, reservation_time, phone FROM $scheduleTable
          WHERE reservation_date = ? AND REPLACE(REPLACE(name, ' ', ''), '\t', '') = ?"
    );
    $stmt->execute([$ymd8, $nameKey]);
    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        if ($tail !== '' && substr(yj_wexam_digits($r['phone']), -4) !== $tail) { continue; }
        $out[] = ['eduType' => $r['edu_type'], 'time' => $r['reservation_time']];
    }
    return $out;
}

/* 관리자 팝업용 — 겹침(빨강/초록) 표시까지 계산해서 돌려줌.
   겹침 = 수업 시작 < (복귀 예상 + 30분) AND 수업은 그 전날 것이 아님(같은 날짜라 자동 충족).
   수업의 끝 시각을 모르므로, "시험 출발 전에 수업이 끝난다"는 조건은 판단하지 않고
   보수적으로 "복귀 전에 시작하는 수업은 전부 겹침 후보"로 표시합니다(문서의 취지:
   빨강/초록은 참고용, 최종 판단은 사람이 함). */
function yj_wexam_classes_with_overlap($name, $phone, $examYmd10, $departTime, $returnTime) {
    $classes = yj_wexam_classes_that_day($name, $phone, $examYmd10);
    $returnMin = yj_wexam_to_min($returnTime);
    $graceMin = ($returnMin === null) ? null : $returnMin + 30;
    $departMin = yj_wexam_to_min($departTime);
    $out = [];
    foreach ($classes as $c) {
        $cMin = yj_wexam_hhmm_to_min($c['time']);
        $overlap = false;
        if ($cMin !== null && $graceMin !== null && $departMin !== null) {
            $overlap = ($cMin < $graceMin) && ($cMin >= $departMin - 240); /* 출발 4시간 전부터로 넉넉히 잡음 */
        }
        $out[] = ['eduType' => $c['eduType'], 'time' => $c['time'], 'overlap' => $overlap];
    }
    return $out;
}

function yj_wexam_log($examDate, $slotNo, $action, $name, $detail, $actor) {
    global $logTable;
    yj_db()->prepare(
        "INSERT INTO $logTable (exam_date, slot_no, action, name, detail, actor) VALUES (?, ?, ?, ?, ?, ?)"
    )->execute([$examDate, $slotNo, $action, $name, $detail, $actor]);
}

/* 취소·변경 마감: 시험일 - 오늘 >= 2일. 날짜만 봅니다(시각 무시). */
function yj_wexam_can_cancel($examYmd10) {
    $today = new DateTime(date('Y-m-d'));
    $exam = DateTime::createFromFormat('Y-m-d', $examYmd10);
    if (!$exam) { return false; }
    $diff = (int)$today->diff($exam)->format('%r%a');
    return $diff >= 2;
}

$method = $_SERVER['REQUEST_METHOD'];
$body = ($method === 'POST') ? yj_input() : [];
$action = $method === 'GET'
    ? (isset($_GET['action']) ? $_GET['action'] : '')
    : (isset($body['action']) ? $body['action'] : '');

/* ════════════════════ 공개(비로그인) — 조회 ════════════════════ */

if ($method === 'GET' && $action === 'slots') {
    $from = isset($_GET['from']) ? (string)$_GET['from'] : date('Y-m-d');
    $to = isset($_GET['to']) ? (string)$_GET['to'] : date('Y-m-d', strtotime('+45 days'));
    $stmt = yj_db()->prepare(
        "SELECT s.exam_date, s.slot_no, s.depart_time, s.exam_place, s.capacity, s.closed,
                (SELECT COUNT(*) FROM $bookingsTable b WHERE b.exam_date = s.exam_date AND b.slot_no = s.slot_no) AS booked
           FROM $slotsTable s
          WHERE s.exam_date BETWEEN ? AND ?
          ORDER BY s.exam_date, s.slot_no"
    );
    $stmt->execute([$from, $to]);
    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $out[] = [
            'examDate' => $r['exam_date'], 'slotNo' => (int)$r['slot_no'],
            'departTime' => $r['depart_time'], 'examPlace' => $r['exam_place'],
            'capacity' => (int)$r['capacity'], 'closed' => (bool)$r['closed'],
            'booked' => (int)$r['booked'], 'remaining' => max(0, (int)$r['capacity'] - (int)$r['booked']),
        ];
    }
    yj_json(['slots' => $out]);
}

if ($method === 'GET' && $action === 'holidays') {
    $from = isset($_GET['from']) ? (string)$_GET['from'] : date('Y-m-d');
    $to = isset($_GET['to']) ? (string)$_GET['to'] : date('Y-m-d', strtotime('+45 days'));
    $stmt = yj_db()->prepare("SELECT holiday_date, name FROM $holidaysTable WHERE holiday_date BETWEEN ? AND ? ORDER BY holiday_date");
    $stmt->execute([$from, $to]);
    yj_json(['holidays' => $stmt->fetchAll()]);
}

/* 세션당 10분에 15회 — 셔틀·내일정 조회와 같은 기준, 독립 카운터 */
function yj_wexam_rate_limit() {
    $now = time();
    if (!isset($_SESSION['yj_wexam_try']) || !is_array($_SESSION['yj_wexam_try'])) {
        $_SESSION['yj_wexam_try'] = [];
    }
    $tries = [];
    foreach ($_SESSION['yj_wexam_try'] as $t) { if ($now - $t < 600) { $tries[] = $t; } }
    if (count($tries) >= 15) {
        $_SESSION['yj_wexam_try'] = $tries;
        yj_json(['error' => '조회를 너무 많이 시도했습니다. 잠시 후 다시 시도해주세요.'], 429);
    }
    $tries[] = $now;
    $_SESSION['yj_wexam_try'] = $tries;
}

/* 수강생 본인 예약 조회 — 이름 + 생년월일(YYMMDD 또는 YYYY-MM-DD 등 숫자만 씀) */
if ($method === 'POST' && $action === 'lookup') {
    yj_wexam_rate_limit();
    $name = trim((string)(isset($body['name']) ? $body['name'] : ''));
    $birth = yj_wexam_digits(isset($body['birthDate']) ? $body['birthDate'] : '');
    if ($name === '' || strlen($birth) < 6) {
        yj_json(['error' => '이름과 생년월일을 정확히 입력해주세요.'], 400);
    }
    $nameKey = yj_wexam_name_key($name);
    $today = date('Y-m-d');
    $stmt = yj_db()->prepare(
        "SELECT id, exam_date, slot_no, depart_type, phone, birth_date FROM $bookingsTable
          WHERE exam_date >= ? AND REPLACE(REPLACE(name, ' ', ''), '\t', '') = ?"
    );
    $stmt->execute([$today, $nameKey]);
    $found = null;
    foreach ($stmt->fetchAll() as $r) {
        if (yj_wexam_digits($r['birth_date']) === $birth) { $found = $r; break; }
    }
    if (!$found) { yj_json(['found' => false]); }

    $slotInfo = null;
    if ((int)$found['slot_no'] >= 0) {
        $s = yj_db()->prepare("SELECT depart_time, exam_place FROM $slotsTable WHERE exam_date = ? AND slot_no = ?");
        $s->execute([$found['exam_date'], $found['slot_no']]);
        $slotInfo = $s->fetch();
    }
    yj_json([
        'found' => true,
        'examDate' => $found['exam_date'],
        'slotNo' => (int)$found['slot_no'],
        'departType' => $found['depart_type'],
        'departTime' => $slotInfo ? $slotInfo['depart_time'] : '',
        'examPlace' => $slotInfo ? $slotInfo['exam_place'] : '',
        'canCancel' => yj_wexam_can_cancel($found['exam_date']),
    ]);
}

/* ════════════════════ 공개(비로그인) — 신청/변경/취소 ════════════════════ */

if ($method === 'POST' && ($action === 'book' || $action === 'change' || $action === 'cancel')) {
    yj_wexam_rate_limit();
    $name = trim((string)(isset($body['name']) ? $body['name'] : ''));
    $phone = trim((string)(isset($body['phone']) ? $body['phone'] : ''));
    $birth = yj_wexam_digits(isset($body['birthDate']) ? $body['birthDate'] : '');
    if ($name === '' || strlen(yj_wexam_digits($phone)) < 10 || strlen($birth) < 6) {
        yj_json(['error' => '이름·생년월일·연락처를 정확히 입력해주세요.'], 400);
    }
    $nameKey = yj_wexam_name_key($name);

    /* 사람 단위 잠금 — 이 사람에 대해 한 번에 한 요청만 통과 */
    $lockKey = 'wexam:' . $nameKey . ':' . yj_wexam_digits($phone);
    $lockStmt = yj_db()->prepare('SELECT GET_LOCK(?, 5)');
    $lockStmt->execute([$lockKey]);
    if (!$lockStmt->fetchColumn()) {
        yj_json(['error' => '처리 중입니다. 잠시 후 다시 시도해주세요.'], 429);
    }

    try {
        $db = yj_db();
        $db->beginTransaction();

        /* 본인 확인 + 기존 예약 찾기 (이름+생년월일 일치, 앞으로 있을 것) */
        $today = date('Y-m-d');
        $existStmt = $db->prepare(
            "SELECT id, exam_date, slot_no, birth_date FROM $bookingsTable
              WHERE exam_date >= ? AND REPLACE(REPLACE(name, ' ', ''), '\t', '') = ? FOR UPDATE"
        );
        $existStmt->execute([$today, $nameKey]);
        $existing = null;
        foreach ($existStmt->fetchAll() as $r) {
            if (yj_wexam_digits($r['birth_date']) === $birth) { $existing = $r; break; }
        }

        if ($action === 'cancel') {
            if (!$existing) { $db->rollBack(); yj_json(['error' => '취소할 예약을 찾을 수 없습니다.'], 404); }
            if (!yj_wexam_can_cancel($existing['exam_date'])) {
                $db->rollBack();
                yj_json(['error' => '취소는 시험 2일 전까지만 가능합니다. 사무실(062-951-5100)로 연락해주세요.'], 400);
            }
            $db->prepare("DELETE FROM $bookingsTable WHERE id = ?")->execute([$existing['id']]);
            yj_wexam_log($existing['exam_date'], $existing['slot_no'], '취소', $name, '수강생 본인 취소', '수강생');
            $db->commit();
            yj_json(['ok' => true]);
        }

        /* book / change 공통: 새 회차 정보와 정원 확인 */
        $examDate = trim((string)(isset($body['examDate']) ? $body['examDate'] : ''));
        $slotNo = isset($body['slotNo']) ? (int)$body['slotNo'] : -1;
        $departType = (isset($body['departType']) && $body['departType'] === '개인방문') ? '개인방문' : '학원출발';
        if ($departType === '개인방문') { $slotNo = -1; }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $examDate)) {
            $db->rollBack(); yj_json(['error' => '날짜 형식이 올바르지 않습니다.'], 400);
        }
        if ($examDate < date('Y-m-d')) {
            $db->rollBack(); yj_json(['error' => '지난 날짜로는 신청할 수 없습니다.'], 400);
        }

        if ($action === 'change') {
            if (!$existing) { $db->rollBack(); yj_json(['error' => '변경할 예약을 찾을 수 없습니다.'], 404); }
            if (!yj_wexam_can_cancel($existing['exam_date'])) {
                $db->rollBack();
                yj_json(['error' => '변경은 시험 2일 전까지만 가능합니다. 사무실(062-951-5100)로 연락해주세요.'], 400);
            }
        } else {
            if ($existing) {
                $db->rollBack();
                yj_json(['error' => '이미 ' . $existing['exam_date'] . '에 예약이 있습니다. 새로 신청하지 마시고 [변경하기]를 이용해주세요.', 'existingDate' => $existing['exam_date']], 409);
            }
        }

        /* 같은 날 수업이 있으면 수강생은 무조건 막습니다 (문서 규칙) */
        $classes = yj_wexam_classes_that_day($name, $phone, $examDate);
        if (count($classes) > 0) {
            $db->rollBack();
            yj_json(['error' => '같은 날 수업이 예약되어 있습니다. 사무실(062-951-5100)에 문의해주세요.', 'hasClassConflict' => true], 400);
        }

        if ($slotNo >= 0) {
            $slotStmt = $db->prepare("SELECT capacity, closed FROM $slotsTable WHERE exam_date = ? AND slot_no = ? FOR UPDATE");
            $slotStmt->execute([$examDate, $slotNo]);
            $slot = $slotStmt->fetch();
            if (!$slot) { $db->rollBack(); yj_json(['error' => '그 회차를 찾을 수 없습니다.'], 404); }
            if ((int)$slot['closed']) { $db->rollBack(); yj_json(['error' => '마감된 회차입니다.'], 400); }

            $cntStmt = $db->prepare("SELECT COUNT(*) FROM $bookingsTable WHERE exam_date = ? AND slot_no = ?" . ($action === 'change' ? " AND id != ?" : ""));
            $params = [$examDate, $slotNo];
            if ($action === 'change') { $params[] = $existing['id']; }
            $cntStmt->execute($params);
            if ((int)$cntStmt->fetchColumn() >= (int)$slot['capacity']) {
                $db->rollBack();
                yj_json(['error' => '방금 마감되었습니다. 다른 회차를 골라주세요.'], 409);
            }
        }

        if ($action === 'change') {
            $db->prepare(
                "UPDATE $bookingsTable SET exam_date = ?, slot_no = ?, depart_type = ?, phone = ?, birth_date = ?, updated_by = '수강생' WHERE id = ?"
            )->execute([$examDate, $slotNo, $departType, $phone, $birth, $existing['id']]);
            yj_wexam_log($examDate, $slotNo, '변경', $name, $existing['exam_date'] . ' → ' . $examDate, '수강생');
        } else {
            $db->prepare(
                "INSERT INTO $bookingsTable (exam_date, slot_no, depart_type, name, phone, birth_date, updated_by)
                 VALUES (?, ?, ?, ?, ?, ?, '수강생')"
            )->execute([$examDate, $slotNo, $departType, $name, $phone, $birth]);
            yj_wexam_log($examDate, $slotNo, '신청', $name, $examDate . ' 신청', '수강생');
        }
        $db->commit();
        yj_json(['ok' => true]);
    } catch (Exception $e) {
        if ($db->inTransaction()) { $db->rollBack(); }
        yj_json(['error' => '처리 중 오류가 발생했습니다: ' . $e->getMessage()], 500);
    } finally {
        $rel = yj_db()->prepare('SELECT RELEASE_LOCK(?)');
        $rel->execute([$lockKey]);
    }
}

/* ════════════════════ 관리자 ════════════════════ */

if (strpos($action, 'admin_') === 0) {
    yj_require_wexam_admin();
}

if ($method === 'GET' && $action === 'admin_calendar') {
    $from = isset($_GET['from']) ? (string)$_GET['from'] : date('Y-m-d');
    $to = isset($_GET['to']) ? (string)$_GET['to'] : date('Y-m-d', strtotime('+45 days'));
    $stmt = yj_db()->prepare(
        "SELECT s.exam_date, s.slot_no, s.depart_time, s.return_time, s.exam_place, s.capacity, s.closed, s.memo,
                (SELECT COUNT(*) FROM $bookingsTable b WHERE b.exam_date = s.exam_date AND b.slot_no = s.slot_no) AS booked
           FROM $slotsTable s
          WHERE s.exam_date BETWEEN ? AND ?
          ORDER BY s.exam_date, s.slot_no"
    );
    $stmt->execute([$from, $to]);
    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $out[] = [
            'examDate' => $r['exam_date'], 'slotNo' => (int)$r['slot_no'],
            'departTime' => $r['depart_time'], 'returnTime' => $r['return_time'],
            'examPlace' => $r['exam_place'], 'capacity' => (int)$r['capacity'],
            'closed' => (bool)$r['closed'], 'memo' => $r['memo'], 'booked' => (int)$r['booked'],
        ];
    }
    yj_json(['slots' => $out]);
}

if ($method === 'GET' && $action === 'admin_slot_detail') {
    $examDate = isset($_GET['examDate']) ? (string)$_GET['examDate'] : '';
    $slotNo = isset($_GET['slotNo']) ? (int)$_GET['slotNo'] : -1;
    $stmt = yj_db()->prepare(
        "SELECT id, name, phone, birth_date, depart_type, memo FROM $bookingsTable
          WHERE exam_date = ? AND slot_no = ? ORDER BY id"
    );
    $stmt->execute([$examDate, $slotNo]);
    yj_json(['bookings' => $stmt->fetchAll()]);
}

if ($method === 'POST' && $action === 'admin_lookup') {
    $name = trim((string)(isset($body['name']) ? $body['name'] : ''));
    if ($name === '') { yj_json(['error' => '이름을 입력해주세요.'], 400); }
    $nameKey = yj_wexam_name_key($name);
    $birth = yj_wexam_digits(isset($body['birthDate']) ? $body['birthDate'] : '');

    $today = date('Y-m-d');
    $stmt = yj_db()->prepare(
        "SELECT id, exam_date, slot_no, depart_type, phone, birth_date FROM $bookingsTable
          WHERE exam_date >= ? AND REPLACE(REPLACE(name, ' ', ''), '\t', '') = ?
          ORDER BY exam_date"
    );
    $stmt->execute([$today, $nameKey]);
    $rows = $stmt->fetchAll();
    if ($birth !== '') {
        $rows = array_values(array_filter($rows, function ($r) use ($birth) { return yj_wexam_digits($r['birth_date']) === $birth; }));
    }
    if (!$rows) { yj_json(['bookings' => []]); }

    $out = [];
    foreach ($rows as $r) {
        $slotInfo = null;
        if ((int)$r['slot_no'] >= 0) {
            $s = yj_db()->prepare("SELECT depart_time, return_time, exam_place FROM $slotsTable WHERE exam_date = ? AND slot_no = ?");
            $s->execute([$r['exam_date'], $r['slot_no']]);
            $slotInfo = $s->fetch();
        }
        $out[] = [
            'id' => (int)$r['id'], 'examDate' => $r['exam_date'], 'slotNo' => (int)$r['slot_no'],
            'departType' => $r['depart_type'], 'phone' => $r['phone'], 'birthDate' => $r['birth_date'],
            'departTime' => $slotInfo ? $slotInfo['depart_time'] : '', 'examPlace' => $slotInfo ? $slotInfo['exam_place'] : '',
            'classes' => yj_wexam_classes_with_overlap(
                $name, $r['phone'], $r['exam_date'],
                $slotInfo ? $slotInfo['depart_time'] : '', $slotInfo ? $slotInfo['return_time'] : ''
            ),
        ];
    }
    yj_json(['bookings' => $out]);
}

if ($method === 'POST' && ($action === 'admin_book' || $action === 'admin_change' || $action === 'admin_cancel')) {
    $name = trim((string)(isset($body['name']) ? $body['name'] : ''));
    $phone = trim((string)(isset($body['phone']) ? $body['phone'] : ''));
    $birth = yj_wexam_digits(isset($body['birthDate']) ? $body['birthDate'] : '');
    $studentKey = trim((string)(isset($body['studentKey']) ? $body['studentKey'] : ''));
    if ($name === '') { yj_json(['error' => '이름이 필요합니다.'], 400); }
    $actor = isset($_SESSION['yj_admin']) ? (string)$_SESSION['yj_admin'] : '관리자';

    $db = yj_db();
    $db->beginTransaction();
    try {
        if ($action === 'admin_cancel') {
            $bookingId = isset($body['bookingId']) ? (int)$body['bookingId'] : 0;
            $existStmt = $db->prepare("SELECT exam_date, slot_no FROM $bookingsTable WHERE id = ? FOR UPDATE");
            $existStmt->execute([$bookingId]);
            $existing = $existStmt->fetch();
            if (!$existing) { $db->rollBack(); yj_json(['error' => '예약을 찾을 수 없습니다.'], 404); }
            $db->prepare("DELETE FROM $bookingsTable WHERE id = ?")->execute([$bookingId]);
            yj_wexam_log($existing['exam_date'], $existing['slot_no'], '취소', $name, '관리자 취소', $actor);
            $db->commit();
            yj_json(['ok' => true]);
        }

        $examDate = trim((string)(isset($body['examDate']) ? $body['examDate'] : ''));
        $slotNo = isset($body['slotNo']) ? (int)$body['slotNo'] : -1;
        $departType = (isset($body['departType']) && $body['departType'] === '개인방문') ? '개인방문' : '학원출발';
        if ($departType === '개인방문') { $slotNo = -1; }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $examDate)) {
            $db->rollBack(); yj_json(['error' => '날짜 형식이 올바르지 않습니다.'], 400);
        }

        if ($slotNo >= 0) {
            $slotStmt = $db->prepare("SELECT capacity FROM $slotsTable WHERE exam_date = ? AND slot_no = ? FOR UPDATE");
            $slotStmt->execute([$examDate, $slotNo]);
            $slot = $slotStmt->fetch();
            if (!$slot) { $db->rollBack(); yj_json(['error' => '그 회차를 찾을 수 없습니다.'], 404); }
            /* 관리자는 마감이어도 넣을 수 있게 정원 초과 체크만 빼고 생략하지 않고,
               다만 넘겨도 진행할 수 있도록 경고만 하고 막지는 않습니다(전화로 조정하는
               경우가 있어 관리자는 마감·정원 제한 없이 처리 가능해야 함 — docs 참고). */
        }

        if ($action === 'admin_change') {
            $bookingId = isset($body['bookingId']) ? (int)$body['bookingId'] : 0;
            $oldStmt = $db->prepare("SELECT exam_date FROM $bookingsTable WHERE id = ?");
            $oldStmt->execute([$bookingId]);
            $old = $oldStmt->fetch();
            if (!$old) { $db->rollBack(); yj_json(['error' => '예약을 찾을 수 없습니다.'], 404); }
            $db->prepare(
                "UPDATE $bookingsTable SET exam_date = ?, slot_no = ?, depart_type = ?, phone = ?, birth_date = ?, student_key = ?, updated_by = ? WHERE id = ?"
            )->execute([$examDate, $slotNo, $departType, $phone, $birth, $studentKey, $actor, $bookingId]);
            yj_wexam_log($examDate, $slotNo, '변경', $name, $old['exam_date'] . ' → ' . $examDate, $actor);
        } else {
            $db->prepare(
                "INSERT INTO $bookingsTable (exam_date, slot_no, depart_type, name, phone, birth_date, student_key, updated_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE slot_no = VALUES(slot_no), depart_type = VALUES(depart_type),
                   birth_date = VALUES(birth_date), student_key = VALUES(student_key), updated_by = VALUES(updated_by)"
            )->execute([$examDate, $slotNo, $departType, $name, $phone, $birth, $studentKey, $actor]);
            yj_wexam_log($examDate, $slotNo, '신청', $name, $examDate . ' 신청(관리자)', $actor);
        }
        $db->commit();
        yj_json(['ok' => true]);
    } catch (Exception $e) {
        if ($db->inTransaction()) { $db->rollBack(); }
        yj_json(['error' => '처리 중 오류: ' . $e->getMessage()], 500);
    }
}

if ($method === 'POST' && $action === 'admin_save_slot') {
    $examDate = trim((string)(isset($body['examDate']) ? $body['examDate'] : ''));
    $slotNo = isset($body['slotNo']) ? (int)$body['slotNo'] : null;
    $departTime = trim((string)(isset($body['departTime']) ? $body['departTime'] : ''));
    $returnTime = trim((string)(isset($body['returnTime']) ? $body['returnTime'] : ''));
    $examPlace = trim((string)(isset($body['examPlace']) ? $body['examPlace'] : ''));
    $capacity = isset($body['capacity']) ? (int)$body['capacity'] : 8;
    $memo = trim((string)(isset($body['memo']) ? $body['memo'] : ''));

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $examDate) || $examPlace === '') {
        yj_json(['error' => '날짜·시험장이 필요합니다.'], 400);
    }
    $db = yj_db();
    if ($slotNo === null) {
        $nextStmt = $db->prepare("SELECT COALESCE(MAX(slot_no), -1) + 1 FROM $slotsTable WHERE exam_date = ?");
        $nextStmt->execute([$examDate]);
        $slotNo = (int)$nextStmt->fetchColumn();
        $db->prepare(
            "INSERT INTO $slotsTable (exam_date, slot_no, depart_time, return_time, exam_place, capacity, memo) VALUES (?, ?, ?, ?, ?, ?, ?)"
        )->execute([$examDate, $slotNo, $departTime, $returnTime, $examPlace, $capacity, $memo]);
    } else {
        $db->prepare(
            "UPDATE $slotsTable SET depart_time = ?, return_time = ?, exam_place = ?, capacity = ?, memo = ? WHERE exam_date = ? AND slot_no = ?"
        )->execute([$departTime, $returnTime, $examPlace, $capacity, $memo, $examDate, $slotNo]);
    }
    yj_json(['ok' => true, 'slotNo' => $slotNo]);
}

if ($method === 'POST' && $action === 'admin_close_slot') {
    $examDate = trim((string)(isset($body['examDate']) ? $body['examDate'] : ''));
    $slotNo = isset($body['slotNo']) ? (int)$body['slotNo'] : -1;
    $closed = !empty($body['closed']) ? 1 : 0;
    yj_db()->prepare("UPDATE $slotsTable SET closed = ? WHERE exam_date = ? AND slot_no = ?")->execute([$closed, $examDate, $slotNo]);
    yj_json(['ok' => true]);
}

if ($method === 'POST' && $action === 'admin_delete_slot') {
    $examDate = trim((string)(isset($body['examDate']) ? $body['examDate'] : ''));
    $slotNo = isset($body['slotNo']) ? (int)$body['slotNo'] : -1;
    $force = !empty($body['force']);

    $cntStmt = yj_db()->prepare("SELECT COUNT(*) FROM $bookingsTable WHERE exam_date = ? AND slot_no = ?");
    $cntStmt->execute([$examDate, $slotNo]);
    $cnt = (int)$cntStmt->fetchColumn();
    if ($cnt > 0 && !$force) {
        yj_json(['error' => '이 회차에 ' . $cnt . '명이 신청되어 있습니다. 정말 지우시려면 다시 한 번 확인해주세요.', 'bookedCount' => $cnt], 409);
    }
    $db = yj_db();
    $db->beginTransaction();
    $db->prepare("DELETE FROM $bookingsTable WHERE exam_date = ? AND slot_no = ?")->execute([$examDate, $slotNo]);
    $db->prepare("DELETE FROM $slotsTable WHERE exam_date = ? AND slot_no = ?")->execute([$examDate, $slotNo]);
    $db->commit();
    yj_json(['ok' => true]);
}

/* 요일 규칙으로 한 달치 일괄 생성. preview=true면 만들지 않고 몇 건인지만 계산. */
if ($method === 'POST' && $action === 'admin_bulk_create') {
    $year = isset($body['year']) ? (int)$body['year'] : 0;
    $month = isset($body['month']) ? (int)$body['month'] : 0;
    $rules = isset($body['rules']) && is_array($body['rules']) ? $body['rules'] : [];
    $preview = !empty($body['preview']);
    if ($year < 2020 || $month < 1 || $month > 12 || !$rules) {
        yj_json(['error' => '연도·월·규칙이 필요합니다.'], 400);
    }

    $holidayStmt = yj_db()->prepare("SELECT holiday_date FROM $holidaysTable WHERE holiday_date LIKE ?");
    $holidayStmt->execute([sprintf('%04d-%02d-%%', $year, $month)]);
    $holidays = array_flip(array_map(function ($r) { return $r['holiday_date']; }, $holidayStmt->fetchAll()));

    $existStmt = yj_db()->prepare("SELECT exam_date, slot_no FROM $slotsTable WHERE exam_date LIKE ?");
    $existStmt->execute([sprintf('%04d-%02d-%%', $year, $month)]);
    $existing = [];
    foreach ($existStmt->fetchAll() as $r) { $existing[$r['exam_date']][] = (int)$r['slot_no']; }

    $daysInMonth = (int)date('t', mktime(0, 0, 0, $month, 1, $year));
    $toCreate = [];
    $skippedHoliday = 0;
    $skippedExisting = 0;
    for ($d = 1; $d <= $daysInMonth; $d++) {
        $date = sprintf('%04d-%02d-%02d', $year, $month, $d);
        $weekday = (int)date('w', mktime(0, 0, 0, $month, $d, $year)); /* 0=일 ... 6=토 */
        foreach ($rules as $rule) {
            if ((int)(isset($rule['weekday']) ? $rule['weekday'] : -1) !== $weekday) { continue; }
            if (isset($holidays[$date])) { $skippedHoliday++; continue; }
            $nextSlotNo = isset($existing[$date]) ? (max($existing[$date]) + 1) : 0;
            /* 같은 규칙이 이미 그 요일에 만들어져 있는지는 출발시각+시험장으로 대충 판단하지 않고,
               "이미 있는 회차는 건드리지 않는다"는 문서 원칙에 따라 단순히 다음 번호로 추가합니다.
               완전히 똑같은 규칙을 두 번 일괄생성하면 중복 회차가 생길 수 있어 미리보기에서
               꼭 확인하도록 안내합니다. */
            $toCreate[] = [
                'examDate' => $date, 'slotNo' => $nextSlotNo,
                'departTime' => isset($rule['departTime']) ? $rule['departTime'] : '',
                'returnTime' => isset($rule['returnTime']) ? $rule['returnTime'] : '',
                'examPlace' => isset($rule['examPlace']) ? $rule['examPlace'] : '',
                'capacity' => isset($rule['capacity']) ? (int)$rule['capacity'] : 8,
            ];
            $existing[$date][] = $nextSlotNo;
        }
    }

    if ($preview) {
        yj_json(['toCreate' => count($toCreate), 'skippedHoliday' => $skippedHoliday, 'preview' => $toCreate]);
    }

    $ins = yj_db()->prepare(
        "INSERT INTO $slotsTable (exam_date, slot_no, depart_time, return_time, exam_place, capacity) VALUES (?, ?, ?, ?, ?, ?)"
    );
    foreach ($toCreate as $t) {
        $ins->execute([$t['examDate'], $t['slotNo'], $t['departTime'], $t['returnTime'], $t['examPlace'], $t['capacity']]);
    }
    yj_json(['ok' => true, 'created' => count($toCreate)]);
}

if ($method === 'POST' && $action === 'admin_save_holiday') {
    $date = trim((string)(isset($body['date']) ? $body['date'] : ''));
    $name = trim((string)(isset($body['name']) ? $body['name'] : ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { yj_json(['error' => '날짜 형식이 올바르지 않습니다.'], 400); }
    yj_db()->prepare("INSERT INTO $holidaysTable (holiday_date, name) VALUES (?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name)")
        ->execute([$date, $name]);
    yj_json(['ok' => true]);
}

if ($method === 'POST' && $action === 'admin_delete_holiday') {
    $date = trim((string)(isset($body['date']) ? $body['date'] : ''));
    yj_db()->prepare("DELETE FROM $holidaysTable WHERE holiday_date = ?")->execute([$date]);
    yj_json(['ok' => true]);
}

/* 셔틀 담당자용 — 최근 변경 이력(미확인만) */
if ($method === 'GET' && $action === 'admin_recent_changes') {
    $stmt = yj_db()->query(
        "SELECT id, exam_date, slot_no, action, name, detail, actor, created_at FROM $logTable
          WHERE seen_at IS NULL ORDER BY created_at DESC LIMIT 30"
    );
    yj_json(['changes' => $stmt->fetchAll()]);
}
if ($method === 'POST' && $action === 'admin_ack_change') {
    $id = isset($body['id']) ? (int)$body['id'] : 0;
    $actor = isset($_SESSION['yj_admin']) ? (string)$_SESSION['yj_admin'] : '관리자';
    yj_db()->prepare("UPDATE $logTable SET seen_by = ?, seen_at = NOW() WHERE id = ?")->execute([$actor, $id]);
    yj_json(['ok' => true]);
}

/* 오래된(3개월 지난) 회차·신청자 일괄 삭제 — 개인정보 최소보관을 위한 정리.
   수강생 조회는 애초에 exam_date >= 오늘만 보여주므로 지난 자료는 화면에
   보일 일이 없고, 이 삭제는 순수히 DB에 개인정보(이름·연락처·생년월일)를
   불필요하게 오래 남겨두지 않기 위한 것입니다. 기준일은 셔틀 delete_past와
   같은 이유로 클라이언트가 아닌 서버 시각으로만 정합니다. 변경 이력
   (written_exam_log)은 이름 정도만 담고 있고 분쟁 확인용으로 계속 쓰이므로
   여기서는 지우지 않습니다. */
if ($method === 'POST' && $action === 'admin_delete_old') {
    $cutoff = date('Y-m-d', strtotime('-3 months'));
    $db = yj_db();
    $db->beginTransaction();
    try {
        $delBookings = $db->prepare("DELETE FROM $bookingsTable WHERE exam_date < ?");
        $delBookings->execute([$cutoff]);
        $bookingCount = $delBookings->rowCount();

        $delSlots = $db->prepare("DELETE FROM $slotsTable WHERE exam_date < ?");
        $delSlots->execute([$cutoff]);
        $slotCount = $delSlots->rowCount();

        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        yj_json(['error' => '삭제 실패: ' . $e->getMessage()], 500);
    }
    yj_json(['ok' => true, 'cutoff' => $cutoff, 'deletedSlots' => $slotCount, 'deletedBookings' => $bookingCount]);
}

yj_json(['error' => 'Bad request'], 400);
