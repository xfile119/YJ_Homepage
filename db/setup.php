<?php
/**
 * 최초 1회만 브라우저에서 열어 실행하는 설치 스크립트입니다.
 * 1) config.php의 DB 정보로 접속해서 테이블을 만들고
 * 2) 공지사항/면허가이드 기본 데이터를 채워 넣고
 * 3) 아래 폼으로 관리자 계정(아이디/비밀번호)을 만듭니다.
 *
 * 완료 후에는 보안을 위해 이 파일(db/setup.php)을 서버에서 삭제해주세요.
 * PHP 5.5 이상에서 동작하도록 구형 문법으로 작성했습니다.
 */

$configPath = __DIR__ . '/../config.php';
if (!file_exists($configPath)) {
    http_response_code(500);
    echo '<p style="font-family:sans-serif;color:#c00;">config.php가 없습니다. config.example.php를 복사해서 config.php로 저장하고 DB 정보를 입력한 뒤 다시 시도해주세요.</p>';
    exit;
}
$config = require $configPath;
$prefix = isset($config['table_prefix']) ? $config['table_prefix'] : 'yj_';

try {
    $dsn = "mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8";
    $pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo '<p style="font-family:sans-serif;color:#c00;">DB 연결 실패: ' . htmlspecialchars($e->getMessage()) . '</p>';
    exit;
}

$pdo->exec("CREATE TABLE IF NOT EXISTS {$prefix}admin_users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role VARCHAR(20) NOT NULL DEFAULT 'admin',
  session_version INT NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8");

/* 보안 — 최고관리자(admin) 계정이 이미 있는 사이트에서는, 로그인도 안 한
   외부인이 이 화면에 다시 접속해서 계정을 만들거나 기존 아이디의 비밀번호를
   덮어쓸 수 있으면 안 됩니다. 파일을 지우지 않고 재배포로 다시 올라와도
   안전하도록, 코드 자체에서 막습니다. (아래에서 매번 만드는 office 계정은
   admin이 아니라서 여기 안 걸립니다 — 안 걸리면 최초 설치 때 office 계정이
   먼저 생기고 나서 관리자 계정 생성 폼을 제출하는 순간 막혀버립니다.)
   최초 설치(admin 계정이 하나도 없는 상태)는 지금처럼 로그인 없이 그대로 진행됩니다. */
$existingAdminCount = (int)$pdo->query("SELECT COUNT(*) FROM {$prefix}admin_users WHERE FIND_IN_SET('admin', role) > 0")->fetchColumn();
if ($existingAdminCount > 0) {
    if (session_status() === PHP_SESSION_NONE) { session_start(); }
    $sessionRoles = isset($_SESSION['yj_role']) ? explode(',', (string)$_SESSION['yj_role']) : [];
    $isLoggedInAdmin = !empty($_SESSION['yj_admin']) && in_array('admin', $sessionRoles, true);
    /* 세션이 지금도 유효한지(로그인한 뒤 비밀번호가 바뀌어 무효화되지 않았는지)
       한 번 더 DB로 확인합니다. session_version 컬럼이 아직 없는 예전 설치
       (마이그레이션 전)에서는 이 확인을 건너뛰고 위 role 확인만으로 통과시킵니다. */
    if ($isLoggedInAdmin && !empty($_SESSION['yj_uid'])) {
        try {
            $verStmt = $pdo->prepare("SELECT session_version FROM {$prefix}admin_users WHERE id = ? AND username = ?");
            $verStmt->execute([(int)$_SESSION['yj_uid'], (string)$_SESSION['yj_admin']]);
            $curVer = $verStmt->fetchColumn();
            $sessVer = isset($_SESSION['yj_sver']) ? (int)$_SESSION['yj_sver'] : -1;
            if ($curVer === false || (int)$curVer !== $sessVer) {
                $isLoggedInAdmin = false;
            }
        } catch (Exception $e) {
            /* session_version 컬럼이 아직 없는 예전 설치 — 아래 마이그레이션에서 곧 추가됩니다 */
        }
    }
    if (!$isLoggedInAdmin) {
        http_response_code(403);
        echo '<!doctype html><html lang="ko"><head><meta charset="UTF-8">'
           . '<meta name="viewport" content="width=device-width, initial-scale=1.0"><title>이미 설치됨</title></head>'
           . '<body style="font-family:sans-serif;max-width:560px;margin:60px auto;padding:0 20px;line-height:1.7;">'
           . '<h1 style="font-size:20px;">이미 설치되어 있습니다</h1>'
           . '<p>관리자 계정이 이미 있어서, 이 설치 화면은 <b>최고관리자로 로그인한 상태</b>에서만 다시 열 수 있습니다.</p>'
           . '<p><a href="../admin.html">관리자 로그인</a> 후 같은 주소로 다시 접속해주세요.</p>'
           . '<p style="color:#888;font-size:13px;">설치를 이미 마치셨다면, 이 파일(db/setup.php)은 서버에서 삭제해두시는 걸 권장합니다.</p>'
           . '</body></html>';
        exit;
    }
}

$pdo->exec("CREATE TABLE IF NOT EXISTS {$prefix}notices (
  id INT AUTO_INCREMENT PRIMARY KEY,
  display_no VARCHAR(20) NOT NULL DEFAULT '',
  title VARCHAR(255) NOT NULL,
  link VARCHAR(255) NOT NULL DEFAULT '#',
  badge VARCHAR(10) NOT NULL DEFAULT '없음',
  posted_date VARCHAR(20) NOT NULL DEFAULT '',
  content MEDIUMTEXT NULL,
  image VARCHAR(255) NULL,
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8");

$pdo->exec("CREATE TABLE IF NOT EXISTS {$prefix}license_data (
  id INT PRIMARY KEY,
  data_json LONGTEXT NOT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8");

$pdo->exec("CREATE TABLE IF NOT EXISTS {$prefix}staff (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(50) NOT NULL,
  mbti VARCHAR(10) NOT NULL DEFAULT '',
  work_type VARCHAR(20) NOT NULL DEFAULT '강사',
  course_types TEXT NULL,
  photo VARCHAR(255) NULL,
  greeting VARCHAR(200) NULL,
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8");

$pdo->exec("CREATE TABLE IF NOT EXISTS {$prefix}shuttle_riders (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ride_date VARCHAR(10) NOT NULL,
  slot_no INT NOT NULL DEFAULT -1,
  depart_time VARCHAR(10) NOT NULL DEFAULT '',
  board_time VARCHAR(10) NOT NULL DEFAULT '',
  name VARCHAR(50) NOT NULL DEFAULT '',
  place VARCHAR(100) NOT NULL DEFAULT '',
  phone VARCHAR(30) NOT NULL DEFAULT '',
  sort_order INT NOT NULL DEFAULT 0,
  updated_by VARCHAR(50) NOT NULL DEFAULT '',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ride_date (ride_date),
  INDEX idx_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8");

$pdo->exec("CREATE TABLE IF NOT EXISTS {$prefix}student_schedule (
  id INT AUTO_INCREMENT PRIMARY KEY,
  student_key VARCHAR(50) NOT NULL DEFAULT '',
  name VARCHAR(50) NOT NULL DEFAULT '',
  phone VARCHAR(30) NOT NULL DEFAULT '',
  edu_type VARCHAR(10) NOT NULL DEFAULT '',
  license_type VARCHAR(30) NOT NULL DEFAULT '',
  reservation_date VARCHAR(10) NOT NULL DEFAULT '',
  reservation_time VARCHAR(10) NOT NULL DEFAULT '',
  staff_name VARCHAR(50) NOT NULL DEFAULT '',
  place VARCHAR(100) NOT NULL DEFAULT '',
  synced_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_name (name),
  INDEX idx_date (reservation_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8");

$pdo->exec("CREATE TABLE IF NOT EXISTS {$prefix}contact_messages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(50) NOT NULL DEFAULT '',
  phone VARCHAR(30) NOT NULL DEFAULT '',
  message TEXT NOT NULL,
  status VARCHAR(10) NOT NULL DEFAULT 'unread',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8");

/* 탑승자가 아직 없는 시간대도 남겨두기 위해 시간대를 따로 저장합니다 */
$pdo->exec("CREATE TABLE IF NOT EXISTS {$prefix}shuttle_slots (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ride_date VARCHAR(10) NOT NULL,
  slot_no INT NOT NULL DEFAULT -1,
  depart_time VARCHAR(10) NOT NULL DEFAULT '',
  vehicle VARCHAR(40) NOT NULL DEFAULT '',
  sort_order INT NOT NULL DEFAULT 0,
  INDEX idx_slot_date (ride_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8");

$pdo->exec("CREATE TABLE IF NOT EXISTS {$prefix}written_exam (
  student_id INT PRIMARY KEY,
  student_name VARCHAR(50) NOT NULL DEFAULT '',
  student_phone VARCHAR(30) NOT NULL DEFAULT '',
  exam_date VARCHAR(10) NOT NULL DEFAULT '',
  exam_time VARCHAR(20) NOT NULL DEFAULT '',
  place VARCHAR(100) NOT NULL DEFAULT '나주',
  updated_by VARCHAR(50) NOT NULL DEFAULT '',
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8");

/* 로그인 실패 시도를 IP별로 기록해서 무차별 대입(비밀번호 자동 시도)을 막습니다.
   (api/auth.php에서 일정 시간 내 실패 횟수가 너무 많으면 잠깐 막아둡니다) */
$pdo->exec("CREATE TABLE IF NOT EXISTS {$prefix}login_attempts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ip VARCHAR(45) NOT NULL DEFAULT '',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ip_time (ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8");

/* 이미 만들어진 notices 테이블에 content/image 컬럼이 없으면 추가합니다.
   (기존에 db/setup.php를 이미 한 번 실행한 사이트를 위한 안전한 마이그레이션 — 여러 번 실행해도 안전합니다.) */
function yj_ensure_column($pdo, $table, $column, $definition) {
    $check = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $check->execute([$table, $column]);
    if ((int)$check->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
    }
}
yj_ensure_column($pdo, $prefix . 'notices', 'content', 'MEDIUMTEXT NULL');
yj_ensure_column($pdo, $prefix . 'notices', 'image', 'VARCHAR(255) NULL');
yj_ensure_column($pdo, $prefix . 'staff', 'greeting', 'VARCHAR(200) NULL');
yj_ensure_column($pdo, $prefix . 'admin_users', 'role', "VARCHAR(20) NOT NULL DEFAULT 'admin'");
yj_ensure_column($pdo, $prefix . 'shuttle_riders', 'updated_by', "VARCHAR(50) NOT NULL DEFAULT ''");
yj_ensure_column($pdo, $prefix . 'student_schedule', 'license_type', "VARCHAR(30) NOT NULL DEFAULT ''");
yj_ensure_column($pdo, $prefix . 'written_exam', 'student_phone', "VARCHAR(30) NOT NULL DEFAULT ''");

/* 역할 세분화(최고관리자/사무실/셔틀/강사, 한 사람이 여러 역할을 겸직할 수 있어
   role 컬럼은 이제 쉼표로 구분된 여러 값을 담습니다, 예: "instructor,office").
   강사 역할 계정이 학사서버 예약을 본인 것만 걸러보려면 실명이 필요합니다.
   staff.name은 홈페이지에 공개되는 가명이라, 실명은 staff에 비공개 컬럼으로 따로 두고
   계정을 자동 생성할 때 admin_users.real_name으로 복사해 둡니다. staff_id는 어느
   임직원 카드에서 만들어진 계정인지 연결합니다. */
yj_ensure_column($pdo, $prefix . 'staff', 'real_name', "VARCHAR(50) NULL DEFAULT NULL");
yj_ensure_column($pdo, $prefix . 'admin_users', 'real_name', "VARCHAR(50) NULL DEFAULT NULL");
yj_ensure_column($pdo, $prefix . 'admin_users', 'staff_id', "INT NULL DEFAULT NULL");

/* 비밀번호 변경/계정 삭제 시 이 값을 올려서, 그 전에 이미 로그인해 있던 세션을
   다음 요청부터 무효화합니다 (계정을 지우거나 비밀번호를 바꿨는데도 이미 열려있던
   브라우저 세션으로 계속 접근할 수 있으면 안 되니까요). api/_db.php의
   yj_session_is_valid()에서 로그인 시 세션에 저장해둔 값과 비교합니다. */
yj_ensure_column($pdo, $prefix . 'admin_users', 'session_version', 'INT NOT NULL DEFAULT 1');

/* role은 원래 VARCHAR(20)으로 만들어졌는데, 겸직 지원으로 쉼표 구분 여러 값
   ("admin,manager,office,instructor" 이면 32자)을 담아야 해서 넓혀둡니다. */
function yj_widen_column($pdo, $table, $column, $definition, $minLength) {
    $check = $pdo->prepare("SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $check->execute([$table, $column]);
    $len = $check->fetchColumn();
    if ($len !== false && (int)$len < $minLength) {
        $pdo->exec("ALTER TABLE {$table} MODIFY COLUMN {$column} {$definition}");
    }
}
yj_widen_column($pdo, $prefix . 'admin_users', 'role', "VARCHAR(60) NOT NULL DEFAULT 'admin'", 60);

/* 2026-09: 셔틀 명단 확장
   - slot_no  : 시간이 아니라 "운행 편성 번호"로 사람과 편성을 묶습니다(시간을 고쳐도 명단이 따라옵니다).
   - board_time: 탑승 장소마다 태우는 시각이 다르므로 사람별 탑승시간.
   - vehicle  : 같은 시간에 여러 대가 나가므로 차량 호수/번호. */
yj_ensure_column($pdo, $prefix . 'shuttle_riders', 'slot_no', 'INT NOT NULL DEFAULT -1');
yj_ensure_column($pdo, $prefix . 'shuttle_riders', 'board_time', "VARCHAR(10) NOT NULL DEFAULT ''");
yj_ensure_column($pdo, $prefix . 'shuttle_slots', 'slot_no', 'INT NOT NULL DEFAULT -1');
yj_ensure_column($pdo, $prefix . 'shuttle_slots', 'vehicle', "VARCHAR(40) NOT NULL DEFAULT ''");

/* 옛 데이터 이어붙이기: 편성 번호가 없던 기존 행에 번호를 매깁니다. 여러 번 실행해도 안전합니다. */
$pdo->exec("UPDATE {$prefix}shuttle_slots SET slot_no = sort_order WHERE slot_no < 0");
$pdo->exec(
    "UPDATE {$prefix}shuttle_riders r
       JOIN {$prefix}shuttle_slots s
         ON s.ride_date = r.ride_date AND s.depart_time = r.depart_time
        SET r.slot_no = s.slot_no
      WHERE r.slot_no < 0"
);
/* 시간대 표가 아예 없던 더 옛날 데이터는 0번 편성으로 모읍니다 */
$pdo->exec("UPDATE {$prefix}shuttle_riders SET slot_no = 0 WHERE slot_no < 0");

$noticeCount = (int)$pdo->query("SELECT COUNT(*) FROM {$prefix}notices")->fetchColumn();
if ($noticeCount === 0) {
    $seedJson = file_get_contents(__DIR__ . '/notice_default_data.json');
    $seed = json_decode($seedJson, true);
    if (!is_array($seed)) { $seed = []; }
    $stmt = $pdo->prepare("INSERT INTO {$prefix}notices (display_no, title, link, badge, posted_date, sort_order) VALUES (?, ?, ?, ?, ?, ?)");
    $i = 0;
    foreach (array_values($seed) as $r) {
        $stmt->execute([
            isset($r['no']) ? $r['no'] : '',
            isset($r['title']) ? $r['title'] : '',
            isset($r['link']) ? $r['link'] : '#',
            isset($r['badge']) ? $r['badge'] : '없음',
            isset($r['date']) ? $r['date'] : '',
            $i,
        ]);
        $i++;
    }
}

$licenseCount = (int)$pdo->query("SELECT COUNT(*) FROM {$prefix}license_data WHERE id = 1")->fetchColumn();
if ($licenseCount === 0) {
    $seedJson = file_get_contents(__DIR__ . '/license_default_data.json');
    $seed = json_decode($seedJson, true);
    if (!is_array($seed)) { $seed = []; }
    $stmt = $pdo->prepare("INSERT INTO {$prefix}license_data (id, data_json) VALUES (1, ?)");
    $stmt->execute([json_encode($seed, JSON_UNESCAPED_UNICODE)]);
}

$staffCount = (int)$pdo->query("SELECT COUNT(*) FROM {$prefix}staff")->fetchColumn();
if ($staffCount === 0) {
    $seedJson = file_get_contents(__DIR__ . '/staff_default_data.json');
    $seed = json_decode($seedJson, true);
    if (!is_array($seed)) { $seed = []; }
    $stmt = $pdo->prepare("INSERT INTO {$prefix}staff (name, mbti, work_type, course_types, photo, greeting, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $i = 0;
    foreach (array_values($seed) as $r) {
        $stmt->execute([
            isset($r['name']) ? $r['name'] : '',
            isset($r['mbti']) ? $r['mbti'] : '',
            isset($r['workType']) ? $r['workType'] : '강사',
            isset($r['courseTypes']) ? json_encode($r['courseTypes'], JSON_UNESCAPED_UNICODE) : '[]',
            isset($r['photo']) ? $r['photo'] : null,
            isset($r['greeting']) ? $r['greeting'] : null,
            $i,
        ]);
        $i++;
    }
}

$message = '';
$done = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string)(isset($_POST['username']) ? $_POST['username'] : ''));
    $password = (string)(isset($_POST['password']) ? $_POST['password'] : '');
    $password2 = (string)(isset($_POST['password2']) ? $_POST['password2'] : '');

    if ($username === '' || $password === '') {
        $message = '아이디와 비밀번호를 모두 입력해주세요.';
    } elseif ($password !== $password2) {
        $message = '비밀번호가 서로 일치하지 않습니다.';
    } elseif (strlen($password) < 8) {
        $message = '비밀번호는 8자 이상으로 설정해주세요.';
    } else {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        /* 기존 계정 비밀번호를 재설정하는 경우, session_version을 올려서 이전
           비밀번호로 로그인해 있던 세션을 무효화합니다. */
        $stmt = $pdo->prepare("INSERT INTO {$prefix}admin_users (username, password_hash) VALUES (?, ?)
            ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), session_version = session_version + 1");
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
