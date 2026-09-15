<?php
/* 날짜별 전체 교육현황 API (관리자 전용)
   GET ?date=YYYY-MM-DD : 그 날짜의 강사별·교시별 수업 현황을 표 형태로 반환합니다.

   교시는 1교시=08시부터 1시간 단위로 매깁니다(2교시=09시 ... 10교시=17시).
   기본 1~10교시는 항상 표시하고, 그 범위를 벗어난 시각에 실제로 수업이
   있는 경우에만 해당 교시를 시간 순서에 맞게 표에 끼워 넣습니다. */

require __DIR__ . '/_db.php';

yj_require_content_admin();

$table = yj_table('student_schedule');

$date8 = isset($_GET['date']) ? preg_replace('/[^0-9]/', '', (string)$_GET['date']) : '';
if (strlen($date8) !== 8) {
    yj_json(['error' => '날짜 형식이 올바르지 않습니다 (YYYYMMDD).'], 400);
}

/* 표 칸에는 자리가 좁아 짧은 이름을 씁니다(공개용 licenseLabel보다 축약된 표기). */
$LICENSE_SHORT = [
    '1' => '1종수동', 'A' => '1종자동',
    '2' => '2종수동', '3' => '2종자동',
    '4' => '대형', '5' => '대형견인', '6' => '구난차',
    '7' => '2종소형', '8' => '원자', '9' => '소형견인',
];
$EDU_SHORT = ['기능' => '장내', '도로' => '도로', '학과' => '학과'];

$stmt = yj_db()->prepare(
    "SELECT staff_name, edu_type, license_type, reservation_time
       FROM $table
      WHERE reservation_date = ?
      ORDER BY staff_name ASC, reservation_time ASC"
);
$stmt->execute([$date8]);

/* 같은 강사·같은 교시에 여러 행(학과 단체수업)이 들어와도 한 칸에는 하나만 표시합니다. */
$cells = [];
$periodsSeen = [];
foreach ($stmt->fetchAll() as $r) {
    $staffName = trim((string)$r['staff_name']);
    if ($staffName === '') {
        continue;
    }
    $hour = (int)substr((string)$r['reservation_time'], 0, 2);
    $period = $hour - 7;
    $periodsSeen[$period] = true;
    if (!isset($cells[$staffName])) {
        $cells[$staffName] = [];
    }
    if (isset($cells[$staffName][$period])) {
        continue;
    }
    $eduType = $r['edu_type'];
    $cells[$staffName][$period] = [
        'eduType' => $eduType,
        'licenseType' => $eduType === '학과' ? '' : $r['license_type'],
    ];
}

/* 표준 1~10교시는 항상 표시합니다. 그 밖의 교시는 실제 데이터가 있을 때만
   자연스럽게 시간 순서 자리에 끼워 넣습니다(거의 없을 것으로 예상되는
   예외 상황을 위한 처리입니다). */
for ($p = 1; $p <= 10; $p++) {
    $periodsSeen[$p] = true;
}
$periods = array_keys($periodsSeen);
sort($periods, SORT_NUMERIC);

$periodList = [];
foreach ($periods as $p) {
    $hour = (($p + 7) % 24 + 24) % 24;
    $periodList[] = [
        'period' => $p,
        'label' => $p . '교시',
        'time' => sprintf('%02d:00', $hour),
    ];
}

$staffNames = array_keys($cells);
sort($staffNames, SORT_STRING | SORT_FLAG_CASE);

$staffRows = [];
foreach ($staffNames as $name) {
    $row = ['name' => $name, 'cells' => []];
    foreach ($periods as $p) {
        if (isset($cells[$name][$p])) {
            $c = $cells[$name][$p];
            $licenseShort = ($c['licenseType'] !== '' && isset($LICENSE_SHORT[$c['licenseType']]))
                ? $LICENSE_SHORT[$c['licenseType']] : '';
            $eduShort = isset($EDU_SHORT[$c['eduType']]) ? $EDU_SHORT[$c['eduType']] : $c['eduType'];
            $label = ($c['eduType'] === '학과')
                ? '학과'
                : ($licenseShort !== '' ? $licenseShort . '(' . $eduShort . ')' : $eduShort);
            $row['cells'][] = [
                'eduType' => $c['eduType'],
                'licenseType' => $c['licenseType'],
                'label' => $label,
            ];
        } else {
            $row['cells'][] = ['eduType' => '', 'licenseType' => '', 'label' => ''];
        }
    }
    $staffRows[] = $row;
}

yj_json(['date' => $date8, 'periods' => $periodList, 'staff' => $staffRows]);
