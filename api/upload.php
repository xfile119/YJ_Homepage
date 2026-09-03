<?php
/* 공지사항용 이미지 업로드 API. PHP 5.5 이상에서 동작하도록 구형 문법으로 작성했습니다. */
require __DIR__ . '/_db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    yj_json(['error' => 'Method not allowed'], 405);
}

yj_require_login();

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    yj_json(['error' => '파일을 받지 못했습니다.'], 400);
}

$file = $_FILES['file'];
$maxBytes = 5 * 1024 * 1024;
if ($file['size'] > $maxBytes) {
    yj_json(['error' => '파일 용량은 5MB 이하만 가능합니다.'], 400);
}

/* 확장자는 클라이언트가 보낸 파일명이 아니라, 서버가 실제 이미지 내용을 검사해서 직접 정합니다.
   (파일명만 보고 판단하면 악성 파일을 이미지로 위장해 올릴 수 있어 위험합니다.) */
$info = @getimagesize($file['tmp_name']);
if ($info === false) {
    yj_json(['error' => '올바른 이미지 파일이 아닙니다.'], 400);
}
$extByType = [
    IMAGETYPE_JPEG => 'jpg',
    IMAGETYPE_PNG => 'png',
    IMAGETYPE_GIF => 'gif',
    IMAGETYPE_WEBP => 'webp',
];
if (!isset($extByType[$info[2]])) {
    yj_json(['error' => 'jpg, png, gif, webp 형식만 업로드할 수 있습니다.'], 400);
}
$ext = $extByType[$info[2]];

$uploadDir = __DIR__ . '/../uploads/notices';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$filename = bin2hex(openssl_random_pseudo_bytes(16)) . '.' . $ext;
$destPath = $uploadDir . '/' . $filename;

if (!move_uploaded_file($file['tmp_name'], $destPath)) {
    yj_json(['error' => '파일 저장에 실패했습니다.'], 500);
}

yj_json(['url' => 'uploads/notices/' . $filename]);
