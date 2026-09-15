<?php
/* 강사 본인 예약 조회 API (로그인 필요)
   로그인 계정에 연결된 실명(admin_users.real_name)과 학사서버에서 동기화된
   student_schedule.staff_name이 일치하는 행만 돌려줍니다. */

require __DIR__ . '/_db.php';

yj_require_login();

$table = yj_table('student_schedule');
$usersTable = yj_table('admin_users');

$stmt = yj_db()->prepare("SELECT real_name FROM $usersTable WHERE username = ? LIMIT 1");
$stmt->execute([$_SESSION['yj_admin']]);
$realName = (string)$stmt->fetchColumn();

if ($realName === '') {
    yj_json(['error' => '이 계정에는 연동된 실명이 없습니다. 계정 관리에서 실명을 등록해주세요.'], 400);
}

$today = date('Ymd');
$stmt = yj_db()->prepare(
    "SELECT edu_type, license_type, reservation_date, reservation_time, place
       FROM $table
      WHERE reservation_date >= ? AND staff_name = ?
      ORDER BY reservation_date ASC, reservation_time ASC"
);
$stmt->execute([$today, $realName]);

/* 학과교육은 단체수업이라 같은 시간에 수강생 수만큼 행이 들어오는데, 강사는
   교시별로 수업이 하나뿐이므로 같은 날짜+시간이면 한 건으로 묶어서 보여줍니다.
   수강생 이름·연락처는 강사 화면에 필요 없어 아예 조회하지 않습니다. */
$mine = [];
$seen = [];
foreach ($stmt->fetchAll() as $r) {
    $key = $r['reservation_date'] . '|' . $r['reservation_time'];
    if (isset($seen[$key])) {
        continue;
    }
    $seen[$key] = true;
    $eduType = $r['edu_type'];
    $mine[] = [
        'eduType' => $eduType,
        'licenseType' => $eduType === '학과' ? '' : $r['license_type'],
        'date' => $r['reservation_date'],
        'time' => $r['reservation_time'],
        'place' => $r['place'],
    ];
}

yj_json(['realName' => $realName, 'schedule' => $mine]);
