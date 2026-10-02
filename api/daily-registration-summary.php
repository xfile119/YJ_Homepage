<?php
/* 최고관리자(admin) 전용 일일 수강생 입학현황 조회 API.
 * 이름/전화번호/StudentID 같은 개인 식별정보는 테이블에도 없고 응답에도 없습니다.
 * PHP 5.5 호환 문법만 사용합니다. */

require __DIR__ . '/_db.php';

yj_require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    yj_json(['error' => 'Method not allowed'], 405);
}

function yj_daily_summary_valid_date($s) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) { return false; }
    $p = explode('-', $s);
    return checkdate((int)$p[1], (int)$p[2], (int)$p[0]);
}

$date = isset($_GET['date']) ? trim((string)$_GET['date']) : '';
if (!yj_daily_summary_valid_date($date)) {
    yj_json(['error' => '날짜 형식이 올바르지 않습니다 (YYYY-MM-DD).'], 400);
}

$table = yj_table('daily_registration_summary');
$db = yj_db();

$stmt = $db->prepare(
    "SELECT registration_type, class_no, admission_code, registration_count, synced_at
       FROM $table
      WHERE summary_date = ?
      ORDER BY registration_type ASC, class_no ASC, admission_code ASC"
);
$stmt->execute([$date]);

/* 도로등록 중 새 입학이 아니라 "본학원 장내입학 후 도로 단계로 넘어온 것"으로
   보는 코드는 여기 한 곳에서만 관리합니다. 현재 실제 학사자료 대조로 A만 확정됐습니다. */
$transitionDriveCodes = array('A');

$rows = array();
$functionTotal = 0;
$driveNewTotal = 0;
$driveTransitionTotal = 0;
foreach ($stmt->fetchAll() as $r) {
    $count = (int)$r['registration_count'];
    $type = (string)$r['registration_type'];
    $admissionCode = (string)$r['admission_code'];
    $isTransition = ($type === 'drive' && in_array($admissionCode, $transitionDriveCodes, true));

    if ($type === 'function') {
        $functionTotal += $count;
    } elseif ($type === 'drive') {
        if ($isTransition) {
            $driveTransitionTotal += $count;
        } else {
            /* A 외의 도로 코드는 미확인 새 코드까지 모두 '도로 신규'로 셉니다. */
            $driveNewTotal += $count;
        }
    }

    $rows[] = array(
        'registrationType' => $type,
        'classNo' => (string)$r['class_no'],
        'admissionCode' => $admissionCode,
        'count' => $count,
        'isTransition' => $isTransition,
    );
}
$driveTotal = $driveNewTotal + $driveTransitionTotal;
$newAdmissionTotal = $functionTotal + $driveNewTotal;

/* 마지막 동기화 시각은 선택 날짜에 데이터가 0건이어도 알 수 있도록
   전용 테이블 전체에서 가장 최근 synced_at을 확인합니다. 30일 안에 등록이 단 한 건도
   없으면 빈 값일 수 있으며, 그 경우 화면은 '동기화 정보 없음'으로 표시합니다. */
$meta = $db->query(
    "SELECT MIN(summary_date) AS min_date,
            MAX(summary_date) AS max_date,
            MAX(synced_at) AS last_synced_at
       FROM $table"
)->fetch();

yj_json(array(
    'date' => $date,
    'functionTotal' => $functionTotal,
    'driveNewTotal' => $driveNewTotal,
    'driveTransitionTotal' => $driveTransitionTotal,
    'newAdmissionTotal' => $newAdmissionTotal,
    /* driveTotal은 기존 화면/외부 확인용 호환값으로 유지합니다.
       신규 입학 합계에는 쓰지 않습니다. */
    'driveTotal' => $driveTotal,
    'rows' => $rows,
    'availableFrom' => $meta && $meta['min_date'] ? (string)$meta['min_date'] : '',
    'availableTo' => $meta && $meta['max_date'] ? (string)$meta['max_date'] : '',
    'lastSyncedAt' => $meta && $meta['last_synced_at'] ? (string)$meta['last_synced_at'] : '',
));
