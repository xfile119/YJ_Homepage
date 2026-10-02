<?php
/*
 * 일일 수강생 입학현황 집계 수신 API
 *
 * 학사서버가 Student 테이블을 직접 집계한 뒤, 개인 식별정보 없이
 *   날짜 x 구분(장내입학/도로등록) x ClassNo x AdmissionType 코드 x 건수
 * 만 전송합니다.
 *
 * 최근 30일 창을 매번 통째로 교체합니다. 동기화 중 오류가 나면 트랜잭션을
 * 롤백하므로 직전 정상 자료가 그대로 남습니다.
 *
 * PHP 5.5 호환 문법만 사용합니다.
 */

require __DIR__ . '/_db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    yj_json(['error' => 'Method not allowed'], 405);
}

/* 기존 일정 동기화 키와 분리한 전용 키입니다. */
yj_require_sync_key('daily_registration_sync_key');

$body = yj_input();
$rows = isset($body['rows']) ? $body['rows'] : null;
$windowStart = isset($body['windowStart']) ? trim((string)$body['windowStart']) : '';
$windowEnd = isset($body['windowEnd']) ? trim((string)$body['windowEnd']) : '';

function yj_daily_valid_date($s) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) { return false; }
    $p = explode('-', $s);
    return checkdate((int)$p[1], (int)$p[2], (int)$p[0]);
}

if (!is_array($rows)) {
    yj_json(['error' => 'rows 배열이 필요합니다.'], 400);
}
if (count($rows) > 10000) {
    yj_json(['error' => '집계 행이 너무 많습니다. 최근 30일 집계인지 확인해주세요.'], 400);
}
if (!yj_daily_valid_date($windowStart) || !yj_daily_valid_date($windowEnd)) {
    yj_json(['error' => 'windowStart/windowEnd 날짜 형식이 올바르지 않습니다.'], 400);
}

/* 정확히 30일인지 날짜 문자열 기준으로 확인합니다.
   초/타입 비교에 의존하지 않아 PHP 버전이나 DST 영향이 없습니다. */
$endTs = strtotime($windowEnd . ' 00:00:00');
$expectedStart = $endTs === false ? '' : date('Y-m-d', strtotime($windowEnd . ' -29 days'));
if ($endTs === false || $expectedStart === '' || $windowStart !== $expectedStart) {
    yj_json(['error' => '동기화 구간은 정확히 30일이어야 합니다.'], 400);
}

/* 이 기능에는 개인정보가 들어오면 안 됩니다. 실수로 기존 일정 payload를
   보내는 사고도 서버에서 즉시 막습니다. 키 이름은 대소문자와 무관하게 검사합니다. */
$forbiddenKeys = [
    'name' => true,
    'phone' => true,
    'studentkey' => true,
    'student_id' => true,
    'studentid' => true,
    'birthdate' => true,
    'birth_date' => true,
    'telmobile' => true,
    'ssid' => true,
];

/* 동일 키가 두 번 와도 수신 측에서 한 번 더 합산합니다.
   SQL 집계 결과가 정상이라면 중복되지 않지만, 공백/NULL 처리나 추후 쿼리 변경 실수로
   중복행이 생겨도 전체 동기화가 UNIQUE KEY 오류로 실패하지 않게 하는 방어선입니다. */
$aggregated = [];
foreach ($rows as $r) {
    if (!is_array($r)) { continue; }

    foreach ($r as $key => $unused) {
        $lowerKey = strtolower((string)$key);
        if (isset($forbiddenKeys[$lowerKey])) {
            yj_json(['error' => '개인 식별정보가 포함된 동기화 요청은 받지 않습니다.'], 400);
        }
    }

    $date = isset($r['date']) ? trim((string)$r['date']) : '';
    $type = isset($r['registrationType']) ? trim((string)$r['registrationType']) : '';
    $classNo = isset($r['classNo']) ? trim((string)$r['classNo']) : '';
    $admissionCode = isset($r['admissionCode']) ? trim((string)$r['admissionCode']) : '';
    $count = isset($r['count']) ? (int)$r['count'] : 0;

    if (!yj_daily_valid_date($date)) {
        yj_json(['error' => '집계 행의 날짜 형식이 올바르지 않습니다.'], 400);
    }
    if ($date < $windowStart || $date > $windowEnd) {
        yj_json(['error' => '30일 동기화 구간 밖의 날짜가 포함되어 있습니다.'], 400);
    }
    if ($type !== 'function' && $type !== 'drive') {
        yj_json(['error' => 'registrationType은 function 또는 drive만 가능합니다.'], 400);
    }
    if (strlen($classNo) > 10 || strlen($admissionCode) > 10) {
        yj_json(['error' => 'ClassNo 또는 AdmissionType 코드가 너무 깁니다.'], 400);
    }
    if ($count < 0 || $count > 100000) {
        yj_json(['error' => '건수 값이 올바르지 않습니다.'], 400);
    }
    if ($count === 0) { continue; }

    $key = $date . "\x1F" . $type . "\x1F" . $classNo . "\x1F" . $admissionCode;
    if (!isset($aggregated[$key])) {
        $aggregated[$key] = [
            'date' => $date,
            'type' => $type,
            'classNo' => $classNo,
            'admissionCode' => $admissionCode,
            'count' => 0,
        ];
    }
    $aggregated[$key]['count'] += $count;
    if ($aggregated[$key]['count'] > 100000) {
        yj_json(['error' => '합산된 건수 값이 너무 큽니다.'], 400);
    }
}

$normalized = array_values($aggregated);
if (count($normalized) === 0) {
    yj_json(['error' => '집계 결과가 비어 있어 교체하지 않았습니다.'], 400);
}

$totalCount = 0;
foreach ($normalized as $r) {
    $totalCount += (int)$r['count'];
}

$table = yj_table('daily_registration_summary');
$db = yj_db();
$db->beginTransaction();
try {
    /* 전용 테이블에는 최근 30일만 보관하므로 전체 삭제 후 새 창을 넣는 것이
       가장 단순하고, 과거 31일 이상 자료가 남는 문제도 없습니다. */
    $db->exec("DELETE FROM $table");

    $insert = $db->prepare(
        "INSERT INTO $table
            (summary_date, registration_type, class_no, admission_code, registration_count)
         VALUES (?, ?, ?, ?, ?)"
    );

    $inserted = 0;
    foreach ($normalized as $r) {
        $insert->execute([
            $r['date'],
            $r['type'],
            $r['classNo'],
            $r['admissionCode'],
            $r['count'],
        ]);
        $inserted++;
    }

    $db->commit();
} catch (Exception $e) {
    if ($db->inTransaction()) { $db->rollBack(); }
    error_log('[YJ] Daily registration summary sync failed: ' . $e->getMessage());
    yj_json(['error' => '일일 등록현황 동기화에 실패했습니다.'], 500);
}

yj_json([
    'ok' => true,
    'windowStart' => $windowStart,
    'windowEnd' => $windowEnd,
    'rows' => $inserted,
    'totalCount' => $totalCount,
]);
