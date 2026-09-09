<?php
/* 셔틀(차량 운행) 명단 API
   - GET  ?date=YYYY-MM-DD  : 해당 날짜 전체 명단 (관리자 로그인 필요)
   - POST action=save_all   : 해당 날짜 명단 통째로 저장 (관리자 로그인 필요)
   - POST action=lookup     : 수강생 본인 조회 (로그인 불필요, 본인 것만 반환)

   개인정보(이름·연락처)를 다루므로 lookup은 아래 원칙을 지킵니다.
   1) 이름과 전화번호 뒷 4자리가 모두 맞아야 하고,
   2) 일치한 본인의 행만 돌려주며 (다른 사람 명단은 절대 나가지 않음),
   3) 오늘 이후 날짜만 보여주고 (지난 기록 조회 차단),
   4) 세션당 조회 횟수를 제한해 무작위 대입을 늦춥니다.
   PHP 5.5에서도 동작하도록 구형 문법으로 작성했습니다. */

require __DIR__ . '/_db.php';

$method = $_SERVER['REQUEST_METHOD'];
$table = yj_table('shuttle_riders');

/* 전화번호에서 숫자만 남깁니다 (010-1234-5678 → 01012345678) */
function yj_digits($s) {
    return preg_replace('/[^0-9]/', '', (string)$s);
}

if ($method === 'GET') {
    yj_require_login();
    $date = isset($_GET['date']) ? (string)$_GET['date'] : '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        yj_json(['error' => '날짜 형식이 올바르지 않습니다 (YYYY-MM-DD).'], 400);
    }
    $stmt = yj_db()->prepare("SELECT id, depart_time, name, place, phone FROM $table WHERE ride_date = ? ORDER BY depart_time ASC, sort_order ASC, id ASC");
    $stmt->execute([$date]);
    $riders = array_map(function ($r) {
        return [
            'id' => (int)$r['id'],
            'time' => $r['depart_time'],
            'name' => $r['name'],
            'place' => $r['place'],
            'phone' => $r['phone'],
        ];
    }, $stmt->fetchAll());
    yj_json(['date' => $date, 'riders' => $riders]);
}

if ($method !== 'POST') {
    yj_json(['error' => 'Method not allowed'], 405);
}

$body = yj_input();
$action = isset($body['action']) ? $body['action'] : '';

/* ---------------- 수강생 본인 조회 (비로그인) ---------------- */
if ($action === 'lookup') {
    /* 세션당 10분에 15회로 제한 */
    $now = time();
    if (!isset($_SESSION['yj_shuttle_try']) || !is_array($_SESSION['yj_shuttle_try'])) {
        $_SESSION['yj_shuttle_try'] = [];
    }
    $tries = [];
    foreach ($_SESSION['yj_shuttle_try'] as $t) {
        if ($now - $t < 600) { $tries[] = $t; }
    }
    if (count($tries) >= 15) {
        $_SESSION['yj_shuttle_try'] = $tries;
        yj_json(['error' => '조회를 너무 많이 시도했습니다. 잠시 후 다시 시도해주세요.'], 429);
    }
    $tries[] = $now;
    $_SESSION['yj_shuttle_try'] = $tries;

    $name = trim((string)(isset($body['name']) ? $body['name'] : ''));
    $tail = yj_digits(isset($body['phoneTail']) ? $body['phoneTail'] : '');
    if ($name === '' || strlen($tail) !== 4) {
        yj_json(['error' => '이름과 전화번호 뒷 4자리를 정확히 입력해주세요.'], 400);
    }

    $today = date('Y-m-d');
    $stmt = yj_db()->prepare(
        "SELECT ride_date, depart_time, name, place, phone FROM $table
         WHERE ride_date >= ? AND name = ?
         ORDER BY ride_date ASC, depart_time ASC"
    );
    $stmt->execute([$today, $name]);

    /* 전화번호 뒷자리 대조는 PHP에서 처리합니다 (DB에 하이픈 유무가 섞여 있어도 맞도록) */
    $mine = [];
    foreach ($stmt->fetchAll() as $r) {
        if (substr(yj_digits($r['phone']), -4) === $tail) {
            $mine[] = [
                'date' => $r['ride_date'],
                'time' => $r['depart_time'],
                'place' => $r['place'],
            ];
        }
    }
    /* 이름만 맞고 번호가 틀린 경우와 아예 없는 경우를 구분하지 않습니다 (명단 유추 방지) */
    yj_json(['found' => count($mine) > 0, 'rides' => $mine]);
}

/* ---------------- 명단 저장 (관리자) ---------------- */
yj_require_login();

if ($action !== 'save_all') {
    yj_json(['error' => 'Bad request'], 400);
}

$date = isset($body['date']) ? (string)$body['date'] : '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    yj_json(['error' => '날짜 형식이 올바르지 않습니다 (YYYY-MM-DD).'], 400);
}
$rows = isset($body['riders']) && is_array($body['riders']) ? $body['riders'] : [];

$db = yj_db();
$db->beginTransaction();
try {
    /* 해당 날짜 명단을 통째로 교체합니다 */
    $del = $db->prepare("DELETE FROM $table WHERE ride_date = ?");
    $del->execute([$date]);

    $insert = $db->prepare("INSERT INTO $table (ride_date, depart_time, name, place, phone, sort_order) VALUES (?, ?, ?, ?, ?, ?)");
    $i = 0;
    foreach (array_values($rows) as $r) {
        $time = trim((string)(isset($r['time']) ? $r['time'] : ''));
        $name = trim((string)(isset($r['name']) ? $r['name'] : ''));
        $place = trim((string)(isset($r['place']) ? $r['place'] : ''));
        $phone = trim((string)(isset($r['phone']) ? $r['phone'] : ''));
        /* 이름이 비어 있는 줄(빈 칸)은 저장하지 않습니다 */
        if ($name === '') { $i++; continue; }
        if ($time === '') { $time = '미정'; }
        $insert->execute([$date, $time, $name, $place, $phone, $i]);
        $i++;
    }
    $db->commit();
} catch (Exception $e) {
    $db->rollBack();
    yj_json(['error' => '저장 실패: ' . $e->getMessage()], 500);
}

yj_json(['ok' => true]);
