<?php
declare(strict_types=1);
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

yj_require_login();
$body = yj_input();
$data = $body['data'] ?? null;

if (!is_array($data)) {
    yj_json(['error' => '잘못된 데이터입니다.'], 400);
}

$json = json_encode($data, JSON_UNESCAPED_UNICODE);
$stmt = yj_db()->prepare(
    "INSERT INTO $table (id, data_json) VALUES (1, ?) ON DUPLICATE KEY UPDATE data_json = VALUES(data_json)"
);
$stmt->execute([$json]);

yj_json(['ok' => true]);
