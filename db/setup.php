<?php
/**
 * 최초 1회만 브라우저에서 열어 실행하는 설치 스크립트입니다.
 * 1) config.php의 DB 정보로 접속해서 테이블을 만들고
 * 2) 공지사항/면허가이드 기본 데이터를 채워 넣고
 * 3) 아래 폼으로 관리자 계정(아이디/비밀번호)을 만듭니다.
 *
 * 완료 후에는 보안을 위해 이 파일(db/setup.php)을 서버에서 삭제해주세요.
 */
declare(strict_types=1);

$configPath = __DIR__ . '/../config.php';
if (!file_exists($configPath)) {
    http_response_code(500);
    echo '<p style="font-family:sans-serif;color:#c00;">config.php가 없습니다. config.example.php를 복사해서 config.php로 저장하고 DB 정보를 입력한 뒤 다시 시도해주세요.</p>';
    exit;
}
$config = require $configPath;
$prefix = $config['table_prefix'] ?? 'yj_';

try {
    $dsn = "mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4";
    $pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo '<p style="font-family:sans-serif;color:#c00;">DB 연결 실패: ' . htmlspecialchars($e->getMessage()) . '</p>';
    exit;
}

$pdo->exec("CREATE TABLE IF NOT EXISTS {$prefix}admin_users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS {$prefix}notices (
  id INT AUTO_INCREMENT PRIMARY KEY,
  display_no VARCHAR(20) NOT NULL DEFAULT '',
  title VARCHAR(255) NOT NULL,
  link VARCHAR(255) NOT NULL DEFAULT '#',
  badge VARCHAR(10) NOT NULL DEFAULT '없음',
  posted_date VARCHAR(20) NOT NULL DEFAULT '',
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS {$prefix}license_data (
  id INT PRIMARY KEY,
  data_json LONGTEXT NOT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$noticeCount = (int)$pdo->query("SELECT COUNT(*) FROM {$prefix}notices")->fetchColumn();
if ($noticeCount === 0) {
    $seed = json_decode(file_get_contents(__DIR__ . '/notice_default_data.json'), true) ?: [];
    $stmt = $pdo->prepare("INSERT INTO {$prefix}notices (display_no, title, link, badge, posted_date, sort_order) VALUES (?, ?, ?, ?, ?, ?)");
    foreach (array_values($seed) as $i => $r) {
        $stmt->execute([$r['no'] ?? '', $r['title'] ?? '', $r['link'] ?? '#', $r['badge'] ?? '없음', $r['date'] ?? '', $i]);
    }
}

$licenseCount = (int)$pdo->query("SELECT COUNT(*) FROM {$prefix}license_data WHERE id = 1")->fetchColumn();
if ($licenseCount === 0) {
    $seed = json_decode(file_get_contents(__DIR__ . '/license_default_data.json'), true) ?: [];
    $stmt = $pdo->prepare("INSERT INTO {$prefix}license_data (id, data_json) VALUES (1, ?)");
    $stmt->execute([json_encode($seed, JSON_UNESCAPED_UNICODE)]);
}

$message = '';
$done = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $password2 = (string)($_POST['password2'] ?? '');

    if ($username === '' || $password === '') {
        $message = '아이디와 비밀번호를 모두 입력해주세요.';
    } elseif ($password !== $password2) {
        $message = '비밀번호가 서로 일치하지 않습니다.';
    } elseif (strlen($password) < 8) {
        $message = '비밀번호는 8자 이상으로 설정해주세요.';
    } else {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO {$prefix}admin_users (username, password_hash) VALUES (?, ?)
            ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash)");
        $stmt->execute([$username, $hash]);
        $done = true;
        $message = '관리자 계정이 생성/변경되었습니다.';
    }
}

$adminCount = (int)$pdo->query("SELECT COUNT(*) FROM {$prefix}admin_users")->fetchColumn();
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>초기 설치</title>
<meta name="robots" content="noindex, nofollow" />
<style>
  body { font-family: -apple-system, "Malgun Gothic", sans-serif; max-width: 520px; margin: 60px auto; padding: 0 20px; color: #332920; }
  h1 { font-size: 20px; }
  .ok { background: #eafaf1; border: 1px solid #a8e6c1; color: #1c8a4f; padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; }
  .err { background: #fdeeee; border: 1px solid #f3b8b8; color: #c53030; padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; }
  .warn { background: #fff4e5; border: 1px solid #ffe1b3; color: #8a5910; padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; font-size: 14px; line-height: 1.6; }
  label { display: block; font-size: 13px; font-weight: 700; margin: 14px 0 6px; }
  input { width: 100%; box-sizing: border-box; padding: 10px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px; }
  button { margin-top: 18px; padding: 10px 20px; border: none; border-radius: 999px; background: #8f6f52; color: #fff; font-weight: 700; cursor: pointer; }
</style>
</head>
<body>
  <h1>초기 설치</h1>
  <p>테이블 생성 및 기본 데이터 삽입이 완료되었습니다 (공지사항 <?= $noticeCount ?: '새로 채움' ?>, 면허가이드 데이터 <?= $licenseCount ? '이미 있음' : '새로 채움' ?>).</p>

  <?php if ($message): ?>
    <div class="<?= $done ? 'ok' : 'err' ?>"><?= htmlspecialchars($message) ?></div>
  <?php endif; ?>

  <?php if ($done): ?>
    <div class="warn">
      설치가 끝났습니다. <strong>보안을 위해 이 파일(db/setup.php)을 서버에서 삭제</strong>해주세요.<br>
      이제 <code>admin.html</code>에서 방금 만든 아이디/비밀번호로 로그인할 수 있습니다.
    </div>
  <?php else: ?>
    <p><?= $adminCount > 0 ? '이미 관리자 계정이 있습니다. 아래에서 비밀번호를 재설정할 수 있습니다.' : '관리자 로그인에 쓸 아이디/비밀번호를 만들어주세요.' ?></p>
    <form method="post">
      <label>아이디</label>
      <input type="text" name="username" required autocomplete="off" />
      <label>비밀번호 (8자 이상)</label>
      <input type="password" name="password" required />
      <label>비밀번호 확인</label>
      <input type="password" name="password2" required />
      <button type="submit">관리자 계정 만들기</button>
    </form>
  <?php endif; ?>
</body>
</html>
