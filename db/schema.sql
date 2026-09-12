-- 참고용 스키마입니다. 실제 테이블 생성은 db/setup.php를 브라우저에서 열어 실행하세요.
-- {prefix}는 config.php의 table_prefix 값으로 치환됩니다 (기본값: yj_).

CREATE TABLE IF NOT EXISTS {prefix}admin_users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  -- 'admin' = 전체 권한(원장), 'office' = 셔틀 명단만
  role VARCHAR(20) NOT NULL DEFAULT 'admin',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS {prefix}notices (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS {prefix}license_data (
  id INT PRIMARY KEY,
  data_json LONGTEXT NOT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS {prefix}staff (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(50) NOT NULL,
  mbti VARCHAR(10) NOT NULL DEFAULT '',
  work_type VARCHAR(20) NOT NULL DEFAULT '강사',
  course_types TEXT NULL,
  photo VARCHAR(255) NULL,
  greeting VARCHAR(200) NULL,
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS {prefix}shuttle_riders (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ride_date VARCHAR(10) NOT NULL,
  -- slot_no = 이 사람이 타는 운행 편성 번호({prefix}shuttle_slots.slot_no와 짝)
  -- 시간이 바뀌어도 소속이 유지되도록, 시간이 아니라 번호로 묶습니다. -1 = 옛 데이터(미배정)
  slot_no INT NOT NULL DEFAULT -1,
  depart_time VARCHAR(10) NOT NULL DEFAULT '',
  -- 탑승 장소마다 태우는 시각이 다르므로 사람별 탑승시간을 따로 둡니다. 비우면 출발시간과 같습니다.
  board_time VARCHAR(10) NOT NULL DEFAULT '',
  name VARCHAR(50) NOT NULL DEFAULT '',
  place VARCHAR(100) NOT NULL DEFAULT '',
  phone VARCHAR(30) NOT NULL DEFAULT '',
  sort_order INT NOT NULL DEFAULT 0,
  updated_by VARCHAR(50) NOT NULL DEFAULT '',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ride_date (ride_date),
  INDEX idx_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS {prefix}contact_messages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(50) NOT NULL DEFAULT '',
  phone VARCHAR(30) NOT NULL DEFAULT '',
  message TEXT NOT NULL,
  -- 'unread' = 원장님이 아직 확인 안 함, 'read' = 확인함
  status VARCHAR(10) NOT NULL DEFAULT 'unread',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS {prefix}shuttle_slots (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ride_date VARCHAR(10) NOT NULL,
  -- 한 날짜에 여러 대가 동시에 움직이므로, 시간대가 아니라 "운행 편성" 한 건입니다.
  slot_no INT NOT NULL DEFAULT -1,
  depart_time VARCHAR(10) NOT NULL DEFAULT '',
  -- 차량 호수 또는 차량번호 (예: 1호차, 광주70바1234)
  vehicle VARCHAR(40) NOT NULL DEFAULT '',
  sort_order INT NOT NULL DEFAULT 0,
  INDEX idx_slot_date (ride_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
