<?php
/* 셔틀(차량 운행) 명단 API
   - GET  ?date=YYYY-MM-DD  : 해당 날짜 전체 명단 (관리자 로그인 필요)
   - POST action=save_all   : 해당 날짜의 운행 편성+명단 통째로 저장 (관리자 로그인 필요)
     편성(slots)을 따로 저장하므로, 탑승자가 없는 편성도 유지됩니다.
   - POST action=lookup     : 수강생 본인 조회 (로그인 불필요, 본인 것만 반환)

   "편성(slot)" 한 건 = 차량 한 대의 한 번 운행입니다. 같은 시간에 여러 대가
   동시에 나갈 수 있으므로 편성마다 차량(호수/번호)을 둡니다. 사람과 편성은
   시간 문자열이 아니라 slot_no로 묶습니다 — 그래야 출발시간을 고쳐도 명단이
   그대로 따라옵니다. 탑승 장소마다 태우는 시각이 다르므로 탑승시간(board_time)은
   사람마다 따로 적을 수 있고, 비워두면 편성의 출발시간을 씁니다.

   개인정보(이름·연락처)를 다루므로 lookup은 아래 원칙을 지킵니다.
   1) 이름과 전화번호 뒷 4자리가 모두 맞아야 하고,
   2) 일치한 본인의 행만 돌려주며 (다른 사람 명단은 절대 나가지 않음),
   3) 오늘 이후 날짜만 보여주고 (지난 기록 조회 차단),
   4) 세션당 조회 횟수를 제한해 무작위 대입을 늦춥니다.
   PHP 5.5에서도 동작하도록 구형 문법으로 작성했습니다. */

require __DIR__ . '/_db.php';

$method = $_SERVER['REQUEST_METHOD'];
$table = yj_table('shuttle_riders');
$slotTable = yj_table('shuttle_slots');

/* 전화번호에서 숫자만 남깁니다 (010-1234-5678 → 01012345678) */
function yj_digits($s) {
    return preg_replace('/[^0-9]/', '', (string)$s);
}

/* 새로 추가한 칸(slot_no·board_time·vehicle)이 아직 없는 서버에서, 무슨 일인지 알 수 있게 안내합니다.
   db/setup.php를 한 번 더 실행하면 칸이 만들어집니다. */
function yj_shuttle_db_error($e) {
    $msg = $e->getMessage();
    if (strpos($msg, 'Unknown column') !== false || strpos($msg, '42S22') !== false) {
        yj_json(['error' => '셔틀 명단 표에 새 칸(차량·탑승시간)이 아직 없습니다. db/setup.php를 한 번 더 실행해주세요.'], 500);
    }
    yj_json(['error' => 'DB 오류: ' . $msg], 500);
}

/* 차량 표기 등 길이 제한 (한글도 글자 수 기준으로 세도록) */
function yj_strlen($s) {
    return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s);
}
function yj_cut($s, $n) {
    return function_exists('mb_substr') ? mb_substr($s, 0, $n, 'UTF-8') : substr($s, 0, $n);
}

/* "8:30", "0830", "8시30" 같은 표기를 08:30 모양으로 맞춥니다.
   admin-shuttle.html의 normTime()과 같은 규칙입니다 — 같은 시간을 다르게
   적어서 중복 검사를 피해가는 걸 막기 위해 서버에서도 같은 방식으로 비교합니다. */
function yj_norm_time($v) {
    $t = trim((string)$v);
    if ($t === '') { return ''; }
    if (preg_match('/^(\d{1,2})\s*시?$/u', $t, $m)) { $t = $m[1] . ':00'; }
    if (!preg_match('/^(\d{1,2})\s*[:시]?\s*(\d{2})$/u', $t, $m)) { return ''; }
    $h = (int)$m[1];
    $mi = (int)$m[2];
    if ($h > 23 || $mi > 59) { return ''; }
    return ($h < 10 ? '0' . $h : (string)$h) . ':' . $m[2];
}

/* 차량 표기 비교용 — 앞뒤/중간 공백과 대소문자 차이는 같은 차량으로 봅니다 */
function yj_norm_vehicle($v) {
    $t = preg_replace('/\s+/u', '', (string)$v);
    return function_exists('mb_strtolower') ? mb_strtolower($t, 'UTF-8') : strtolower($t);
}

if ($method === 'GET') {
    yj_require_login();
    $date = isset($_GET['date']) ? (string)$_GET['date'] : '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        yj_json(['error' => '날짜 형식이 올바르지 않습니다 (YYYY-MM-DD).'], 400);
    }
    try {
        $stmt = yj_db()->prepare("SELECT slot_no, depart_time, board_time, name, place, phone, updated_by FROM $table WHERE ride_date = ? ORDER BY sort_order ASC, id ASC");
        $stmt->execute([$date]);
        $rows = $stmt->fetchAll();
    } catch (PDOException $e) {
        yj_shuttle_db_error($e);
    }

    /* 이 날짜 명단을 마지막으로 저장한 사람 (여러 직원이 함께 쓰므로 표시해줍니다) */
    $updatedBy = '';
    foreach ($rows as $r) {
        if ($r['updated_by'] !== '') { $updatedBy = $r['updated_by']; }
    }

    /* 저장된 운행 편성 목록. 탑승자가 없는 편성도 여기에 남아 있습니다. */
    try {
        $slotStmt = yj_db()->prepare("SELECT slot_no, depart_time, vehicle FROM $slotTable WHERE ride_date = ? ORDER BY sort_order ASC, id ASC");
        $slotStmt->execute([$date]);
        $slotRows = $slotStmt->fetchAll();
    } catch (PDOException $e) {
        yj_shuttle_db_error($e);
    }

    /* 옛 데이터(편성 표가 생기기 전)를 위해, 편성 기록이 없으면 탑승자에게서 뽑아냅니다 */
    if (empty($slotRows)) {
        $seen = [];
        foreach ($rows as $r) {
            if (!in_array($r['depart_time'], $seen, true)) {
                $seen[] = $r['depart_time'];
                $slotRows[] = ['slot_no' => null, 'depart_time' => $r['depart_time'], 'vehicle' => ''];
            }
        }
    }

    $slots = [];
    foreach ($slotRows as $sr) {
        $list = [];
        foreach ($rows as $r) {
            /* 편성 번호로 묶되, 번호가 없는 옛 행은 시간으로 맞춰봅니다 */
            $mine = ($sr['slot_no'] !== null && (int)$r['slot_no'] >= 0)
                ? ((int)$r['slot_no'] === (int)$sr['slot_no'])
                : ($r['depart_time'] === $sr['depart_time']);
            if ($mine) {
                $list[] = [
                    'name' => $r['name'],
                    'place' => $r['place'],
                    'phone' => $r['phone'],
                    'boardTime' => $r['board_time'],
                ];
            }
        }
        $slots[] = [
            'time' => $sr['depart_time'],
            'vehicle' => $sr['vehicle'],
            'riders' => $list,
        ];
    }

    yj_json(['date' => $date, 'slots' => $slots, 'updatedBy' => $updatedBy]);
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
    /* 이름은 공백을 무시하고 비교합니다 (명단에 "홍 길동"으로 적혀 있어도 "홍길동"으로 조회되도록) */
    $nameKey = preg_replace('/\s+/u', '', $name);
    /* 차량(호수/번호)은 편성 표에 있으므로 함께 붙여옵니다 */
    $stmt = yj_db()->prepare(
        "SELECT r.ride_date, r.depart_time, r.board_time, r.name, r.place, r.phone,
                COALESCE(s.vehicle, '') AS vehicle
           FROM $table r
           LEFT JOIN $slotTable s
             ON s.ride_date = r.ride_date AND s.slot_no = r.slot_no AND r.slot_no >= 0
          WHERE r.ride_date >= ? AND REPLACE(REPLACE(r.name, ' ', ''), '\t', '') = ?
          ORDER BY r.ride_date ASC, r.depart_time ASC"
    );
    $stmt->execute([$today, $nameKey]);

    /* 전화번호 뒷자리 대조는 PHP에서 처리합니다 (DB에 하이픈 유무가 섞여 있어도 맞도록) */
    $mine = [];
    foreach ($stmt->fetchAll() as $r) {
        if (substr(yj_digits($r['phone']), -4) === $tail) {
            /* 개인 탑승시간이 적혀 있으면 그 시간을, 없으면 편성 출발시간을 안내합니다 */
            $boardTime = trim((string)$r['board_time']);
            $mine[] = [
                'date' => $r['ride_date'],
                'time' => ($boardTime !== '' ? $boardTime : $r['depart_time']),
                'departTime' => $r['depart_time'],
                'place' => $r['place'],
                'vehicle' => $r['vehicle'],
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
$slots = isset($body['slots']) && is_array($body['slots']) ? $body['slots'] : [];

/* 같은 시간에 같은 차량이 두 번 등록되면 조회 화면에서 명단이 뒤섞입니다.
   admin-shuttle.html에서도 같은 검사를 하지만, API를 직접 호출하는 경우까지
   막기 위해 저장하기 전에 서버에서도 한 번 더 확인합니다. 시간·차량 둘 다
   있는 경우만 비교합니다(둘 중 하나라도 비어 있으면 검사에서 뺍니다). */
$seenSlotKeys = [];
foreach (array_values($slots) as $slot) {
    $nTime = yj_norm_time(isset($slot['time']) ? $slot['time'] : '');
    $nVehicle = yj_norm_vehicle(isset($slot['vehicle']) ? $slot['vehicle'] : '');
    if ($nTime === '' || $nVehicle === '') { continue; }
    $key = $nTime . '|' . $nVehicle;
    if (isset($seenSlotKeys[$key])) {
        yj_json(['error' =>
            '같은 시간(' . $nTime . ')에 같은 차량(' . trim((string)$slot['vehicle']) . ')이 두 번 있습니다. ' .
            '차량 호수나 출발 시간을 다르게 적어주세요.'
        ], 400);
    }
    $seenSlotKeys[$key] = true;
}

$db = yj_db();
$db->beginTransaction();
try {
    /* 해당 날짜의 시간대와 명단을 통째로 교체합니다 */
    $db->prepare("DELETE FROM $table WHERE ride_date = ?")->execute([$date]);
    $db->prepare("DELETE FROM $slotTable WHERE ride_date = ?")->execute([$date]);

    $slotIns = $db->prepare("INSERT INTO $slotTable (ride_date, slot_no, depart_time, vehicle, sort_order) VALUES (?, ?, ?, ?, ?)");
    $insert = $db->prepare("INSERT INTO $table (ride_date, slot_no, depart_time, board_time, name, place, phone, sort_order, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");

    $slotIndex = 0;
    $rowIndex = 0;
    foreach (array_values($slots) as $slot) {
        $time = trim((string)(isset($slot['time']) ? $slot['time'] : ''));
        $vehicle = trim((string)(isset($slot['vehicle']) ? $slot['vehicle'] : ''));
        if (yj_strlen($vehicle) > 40) { $vehicle = yj_cut($vehicle, 40); }
        $riders = isset($slot['riders']) && is_array($slot['riders']) ? $slot['riders'] : [];

        /* 시간도 차량도 안 적고 사람도 없는 빈 편성은 버립니다 */
        $hasRider = false;
        foreach ($riders as $r) {
            if (trim((string)(isset($r['name']) ? $r['name'] : '')) !== '') { $hasRider = true; break; }
        }
        if ($time === '' && $vehicle === '' && !$hasRider) { continue; }
        if ($time === '') { $time = '미정'; }

        /* 탑승자가 없어도 편성 자체는 남깁니다 (다음에 열었을 때 그대로 보이도록) */
        $slotIns->execute([$date, $slotIndex, $time, $vehicle, $slotIndex]);

        foreach (array_values($riders) as $r) {
            $name = trim((string)(isset($r['name']) ? $r['name'] : ''));
            if ($name === '') { continue; }
            $place = trim((string)(isset($r['place']) ? $r['place'] : ''));
            $phone = trim((string)(isset($r['phone']) ? $r['phone'] : ''));
            /* 개인 탑승시간. 비워두면 편성 출발시간을 그대로 씁니다 */
            $boardTime = trim((string)(isset($r['boardTime']) ? $r['boardTime'] : ''));
            $insert->execute([$date, $slotIndex, $time, $boardTime, $name, $place, $phone, $rowIndex, $_SESSION['yj_admin']]);
            $rowIndex++;
        }
        $slotIndex++;
    }
    $db->commit();
} catch (Exception $e) {
    $db->rollBack();
    if ($e instanceof PDOException) { yj_shuttle_db_error($e); }
    yj_json(['error' => '저장 실패: ' . $e->getMessage()], 500);
}

yj_json(['ok' => true]);
