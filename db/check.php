<?php
header('Content-Type: text/html; charset=utf-8');

/* PHP 버전·DB 연결 상태 등을 그대로 보여주는 진단 화면이라, 아무나 열어보면
   안 됩니다. setup.php와 같은 방식: 최고관리자 계정이 이미 있으면 로그인한
   최고관리자만 열 수 있게 막습니다. 계정이 아직 없거나 DB에 연결할 수 없는
   초기 설치 단계에서는 이 화면 자체가 원인을 찾는 용도라 막지 않습니다. */
require __DIR__ . '/../api/_db.php';
try {
    $adminTable = yj_table('admin_users');
    $existingAdminCount = (int) yj_db()->query("SELECT COUNT(*) FROM $adminTable WHERE FIND_IN_SET('admin', role) > 0")->fetchColumn();
    if ($existingAdminCount > 0 && !(yj_session_is_valid() && yj_has_role('admin'))) {
        http_response_code(403);
        echo '<!doctype html><html lang="ko"><head><meta charset="utf-8"><title>서버 진단</title></head>'
           . '<body style="font-family:-apple-system,\'Malgun Gothic\',sans-serif;max-width:520px;margin:60px auto;padding:0 20px;">'
           . '<h1>접근할 수 없습니다</h1>'
           . '<p>이 화면은 최고관리자로 로그인한 뒤에만 볼 수 있습니다. <a href="../admin.html">관리자 로그인</a> 후 다시 열어주세요.</p>'
           . '</body></html>';
        exit;
    }
} catch (Exception $e) {
    /* DB 연결 자체가 안 되는 초기 단계 — 이 진단 화면이 필요한 상황이므로 막지 않습니다. */
}
?>
<!doctype html>
<html lang="ko">
<head><meta charset="UTF-8"><title>서버 진단</title>
<style>
  body { font-family: -apple-system, "Malgun Gothic", sans-serif; max-width: 640px; margin: 40px auto; padding: 0 20px; }
  table { width: 100%; border-collapse: collapse; margin-top: 16px; }
  td, th { border: 1px solid #ddd; padding: 8px 12px; text-align: left; font-size: 14px; }
  .ok { color: #1c8a4f; font-weight: 700; }
  .bad { color: #c53030; font-weight: 700; }
  .warn { background: #fff4e5; border: 1px solid #ffe1b3; color: #8a5910; padding: 12px 16px; border-radius: 8px; margin-top: 20px; font-size: 13px; }
</style>
</head>
<body>
<h1>서버 진단 결과</h1>
<table>
  <tr><th>PHP 버전</th><td><?= htmlspecialchars(PHP_VERSION) ?> <?= version_compare(PHP_VERSION, '5.5.0', '>=') ? '<span class="ok">(OK, 5.5 이상)</span>' : '<span class="bad">(5.5 미만 - 문제 가능성 높음)</span>' ?></td></tr>
  <tr><th>PDO 확장</th><td><?= extension_loaded('pdo') ? '<span class="ok">사용 가능</span>' : '<span class="bad">사용 불가</span>' ?></td></tr>
  <tr><th>PDO MySQL 드라이버</th><td><?= extension_loaded('pdo_mysql') ? '<span class="ok">사용 가능</span>' : '<span class="bad">사용 불가 - 이게 원인일 수 있어요</span>' ?></td></tr>
  <tr><th>session 확장</th><td><?= extension_loaded('session') ? '<span class="ok">사용 가능</span>' : '<span class="bad">사용 불가</span>' ?></td></tr>
  <tr><th>config.php 존재 여부</th><td><?= file_exists(__DIR__ . '/../config.php') ? '<span class="ok">있음</span>' : '<span class="bad">없음 - 아직 안 만드셨거나 업로드 안 됨</span>' ?></td></tr>
  <?php if (file_exists(__DIR__ . '/../config.php')): ?>
  <tr><th>config.php 문법</th><td>
    <?php
      $ok = true;
      try { $c = require __DIR__ . '/../config.php'; } catch (Exception $e) { $ok = false; }
      echo $ok && is_array($c) ? '<span class="ok">정상 (배열 반환됨)</span>' : '<span class="bad">문제 있음</span>';
    ?>
  </td></tr>
  <?php if (isset($c) && is_array($c)): ?>
  <tr><th>DB 연결 테스트</th><td>
    <?php
      try {
        $dsn = "mysql:host={$c['db_host']};dbname={$c['db_name']};charset=utf8";
        $pdo = new PDO($dsn, $c['db_user'], $c['db_pass']);
        echo '<span class="ok">연결 성공!</span>';
      } catch (Exception $e) {
        echo '<span class="bad">연결 실패: ' . htmlspecialchars($e->getMessage()) . '</span>';
      }
    ?>
  </td></tr>
  <?php endif; ?>
  <?php endif; ?>
</table>
<div class="warn"><strong>확인 후 이 파일(db/check.php)은 삭제해주세요.</strong> 서버 정보가 노출되는 진단용 파일이라 계속 올려두면 안 돼요.</div>
</body>
</html>
