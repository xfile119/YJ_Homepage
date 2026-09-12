<?php
/* 학사서버의 일정 동기화 프로그램이 주기적으로 호출하는 전용 API입니다.
   로그인 세션이 아니라 X-Sync-Key 헤더(config.php의 schedule_sync_key)로 인증합니다.

   원본이 아니라 "다가오는 일정"의 사본(캐시)이므로, 매번 통째로 비우고 다시 채웁니다.
   주민번호 등 민감정보는 절대 이 API로 보내지 마세요 — 이름/연락처/일정 정보만 받습니다. */

require __DIR__ . '/_db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    yj_json(['error' => 'Method not allowed'], 405);
}

yj_require_sync_key();

$table = yj_table('student_schedule');
$body = yj_input();
$rows = isset($body['schedule']) ? $body['schedule'] : null;

if (!is_array($rows)) {
    yj_json(['error' => 'schedule 배열이 필요합니다.'], 400);
}

$db = yj_db();
$db->beginTransaction();
try {
    $db->exec("DELETE FROM $table");

    $insert = $db->prepare(
        "INSERT INTO $table (student_key, name, phone, edu_type, reservation_date, reservation_time, staff_name, place)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $count = 0;
    foreach ($rows as $r) {
        if (!is_array($r)) { continue; }
        $name = trim((string)(isset($r['name']) ? $r['name'] : ''));
        $phone = trim((string)(isset($r['phone']) ? $r['phone'] : ''));
        $date = trim((string)(isset($r['date']) ? $r['date'] : ''));
        if ($name === '' || $phone === '' || $date === '') { continue; }

        $insert->execute([
            (string)(isset($r['studentKey']) ? $r['studentKey'] : ''),
            $name,
            $phone,
            (string)(isset($r['eduType']) ? $r['eduType'] : ''),
            $date,
            (string)(isset($r['time']) ? $r['time'] : ''),
            (string)(isset($r['staffName']) ? $r['staffName'] : ''),
            (string)(isset($r['place']) ? $r['place'] : ''),
        ]);
        $count++;
    }

    $db->commit();
} catch (Exception $e) {
    $db->rollBack();
    yj_json(['error' => '동기화 실패: ' . $e->getMessage()], 500);
}

yj_json(['ok' => true, 'count' => $count]);
