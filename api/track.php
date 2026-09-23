<?php
/* 유입 경로 측정 API
   - POST (누구나): js/main.js가 방문·전화·카카오톡·네이버 톡톡 클릭을 보냅니다.
     이름·전화번호 같은 개인정보는 받지 않고 경로(src)·광고 키워드·페이지만 저장합니다.
     상담 신청·문의 남기기는 여기로 받지 않고 api/contact.php가 접수에 성공했을 때만
     직접 기록합니다(클릭만 하고 안 보낸 경우와 구분하려고).
   - GET (관리자·사무실): 기간별로 경로·키워드별 건수를 모아서 돌려줍니다.
   개인정보가 없어서 문의(1개월 파기)와 달리 13개월치를 남깁니다. */

require __DIR__ . '/_db.php';

$method = $_SERVER['REQUEST_METHOD'];
$table = yj_table('track_events');

if ($method === 'GET') {
    yj_require_content_admin();
    $re = '/^\d{4}-\d{2}-\d{2}$/';
    $from = isset($_GET['from']) && preg_match($re, $_GET['from']) ? $_GET['from'] : date('Y-m-d', strtotime('-27 days'));
    $to = isset($_GET['to']) && preg_match($re, $_GET['to']) ? $_GET['to'] : date('Y-m-d');
    $params = [$from . ' 00:00:00', $to . ' 23:59:59'];
    try {
        $bySrc = yj_db()->prepare("SELECT src, event, COUNT(*) AS n FROM $table WHERE created_at BETWEEN ? AND ? GROUP BY src, event");
        $bySrc->execute($params);
        $byKw = yj_db()->prepare("SELECT src, kw, event, COUNT(*) AS n FROM $table WHERE kw <> '' AND created_at BETWEEN ? AND ? GROUP BY src, kw, event");
        $byKw->execute($params);
        yj_json(['from' => $from, 'to' => $to, 'bySource' => $bySrc->fetchAll(), 'byKeyword' => $byKw->fetchAll()]);
    } catch (Exception $e) {
        /* 아직 한 번도 기록된 적이 없어 테이블이 없는 경우 */
        yj_json(['from' => $from, 'to' => $to, 'bySource' => [], 'byKeyword' => []]);
    }
}

if ($method !== 'POST') {
    yj_json(['error' => 'Method not allowed'], 405);
}

$body = yj_input();
$event = isset($body['event']) ? (string)$body['event'] : '';
if (!in_array($event, ['visit', 'call', 'kakao', 'talk'], true)) {
    yj_json(['error' => 'Bad request'], 400);
}

/* 세션당 1시간에 120건 — 장난으로 숫자를 부풀리는 것 방지 */
$now = time();
$tries = [];
if (isset($_SESSION['yj_track_try']) && is_array($_SESSION['yj_track_try'])) {
    foreach ($_SESSION['yj_track_try'] as $t) {
        if ($now - $t < 3600) { $tries[] = $t; }
    }
}
if (count($tries) >= 120) {
    $_SESSION['yj_track_try'] = $tries;
    yj_json(['ok' => false], 429);
}
$tries[] = $now;
$_SESSION['yj_track_try'] = $tries;

yj_track_record($event, isset($body['src']) ? $body['src'] : '', isset($body['kw']) ? $body['kw'] : '', isset($body['page']) ? $body['page'] : '');
yj_json(['ok' => true]);
