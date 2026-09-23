<?php
require __DIR__ . '/_db.php';

$method = $_SERVER['REQUEST_METHOD'];
$table = yj_table('license_data');

if ($method === 'GET') {
    $stmt = yj_db()->query("SELECT data_json FROM $table WHERE id = 1");
    $row = $stmt->fetch();
    yj_json(['data' => $row ? json_decode($row['data_json'], true) : null]);
}

if ($method !== 'POST') {
    yj_json(['error' => 'Method not allowed'], 405);
}

yj_require_content_admin();
$body = yj_input();
$data = isset($body['data']) ? $body['data'] : null;

if (!is_array($data)) {
    yj_json(['error' => '잘못된 데이터입니다.'], 400);
}

/* license-wizard.js(공개 화면)가 이 값들을 그대로 화면에 꽂아 넣으므로(스크립트
   실행은 그쪽에서 이스케이프로 막지만), 여기서도 문자열이어야 할 자리에 배열·
   객체 같은 엉뚱한 값이 들어가 화면이 깨지는 것과, 너무 긴 텍스트가 저장되는
   것을 최소한으로 막아둡니다. 필드 구조 자체(어떤 케이스 코드가 있는지 등)는
   자유롭게 두고, 실제로 화면에 원문 그대로 노출되는 문자열 필드만 검사합니다. */
function yj_license_check_text($v, $maxLen) {
    return $v === null || $v === '' || (is_string($v) && strlen($v) <= $maxLen);
}
if (isset($data['contact']) && is_array($data['contact'])) {
    foreach (['name' => 100, 'address' => 200, 'phone' => 30, 'phoneHref' => 30] as $f => $maxLen) {
        if (isset($data['contact'][$f]) && !yj_license_check_text($data['contact'][$f], $maxLen)) {
            yj_json(['error' => "연락처 항목({$f})이 올바르지 않습니다."], 400);
        }
    }
}
if (isset($data['updatedAt']) && !yj_license_check_text($data['updatedAt'], 40)) {
    yj_json(['error' => 'updatedAt 값이 올바르지 않습니다.'], 400);
}
if (isset($data['cases']) && is_array($data['cases'])) {
    foreach ($data['cases'] as $code => $c) {
        if (!is_array($c)) {
            yj_json(['error' => "케이스({$code}) 데이터가 올바르지 않습니다."], 400);
        }
        if (isset($c['target']) && !yj_license_check_text($c['target'], 100)) {
            yj_json(['error' => "케이스({$code})의 target 값이 올바르지 않습니다."], 400);
        }
        if (isset($c['note']) && !yj_license_check_text($c['note'], 500)) {
            yj_json(['error' => "케이스({$code})의 note 값이 올바르지 않습니다."], 400);
        }
    }
}

$json = json_encode($data, JSON_UNESCAPED_UNICODE);
$stmt = yj_db()->prepare(
    "INSERT INTO $table (id, data_json) VALUES (1, ?) ON DUPLICATE KEY UPDATE data_json = VALUES(data_json)"
);
$stmt->execute([$json]);

yj_json(['ok' => true]);
