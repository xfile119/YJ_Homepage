-- 참고용 스키마입니다. 실제 테이블 생성은 db/setup.php를 브라우저에서 열어 실행하세요.
-- {prefix}는 config.php의 table_prefix 값으로 치환됩니다 (기본값: yj_).

CREATE TABLE IF NOT EXISTS {prefix}admin_users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  -- 쉼표로 구분된 하나 이상의 역할 (겸직 가능), 예: "instructor,office"
  -- 'admin' = 최고관리자(전체+계정관리), 'manager' = 사무실(전체, 계정관리 제외),
  -- 'office' = 셔틀계정(셔틀 명단만), 'instructor' = 강사(본인 예약만 조회)
  role VARCHAR(60) NOT NULL DEFAULT 'admin',
  -- instructor 역할이 학사서버 예약을 본인 것만 걸러보기 위한 비공개 실명
  -- (staff.name은 홈페이지에 공개되는 가명이라 여기 따로 둡니다. 계정 자동 생성 시
  -- staff.real_name에서 복사됩니다.)
  real_name VARCHAR(50) NULL DEFAULT NULL,
  -- 임직원 등록 화면에서 자동 생성된 계정이면, 어느 staff 카드에서 만들어졌는지 연결
  staff_id INT NULL DEFAULT NULL,
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

-- 학사서버(MSSQL)에서 주기적으로 밀어넣는(push) "다가오는 일정"의 최소 정보 사본입니다.
-- 원본이 아니라 캐시이므로, 매 동기화마다 전체 삭제 후 다시 채웁니다(schedule-sync.php 참고).
-- 주민번호 등 민감정보는 애초에 포함하지 않습니다.
CREATE TABLE IF NOT EXISTS {prefix}student_schedule (
  id INT AUTO_INCREMENT PRIMARY KEY,
  student_key VARCHAR(50) NOT NULL DEFAULT '',
  name VARCHAR(50) NOT NULL DEFAULT '',
  phone VARCHAR(30) NOT NULL DEFAULT '',
  edu_type VARCHAR(10) NOT NULL DEFAULT '',
  -- 같은 학생이 면허를 여러 개 등록한 경우(학사 DB의 서로 다른 StudentID) 구분해서 보여주기 위한 값입니다.
  license_type VARCHAR(30) NOT NULL DEFAULT '',
  reservation_date VARCHAR(10) NOT NULL DEFAULT '',
  reservation_time VARCHAR(10) NOT NULL DEFAULT '',
  staff_name VARCHAR(50) NOT NULL DEFAULT '',
  place VARCHAR(100) NOT NULL DEFAULT '',
  synced_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_name (name),
  INDEX idx_date (reservation_date)
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

-- 필기시험은 학사DB(neoinfo)에 자료가 없어, 상담 후 안내장 앱(guide-print)이
-- api/written-exam.php를 통해 저장/수정/삭제합니다. 한 학생당 최신 한 건만
-- 관리합니다(예약 이력이 아니라 "지금 정해진 다음 필기시험" 하나). 주민번호 등은
-- 다루지 않습니다.
CREATE TABLE IF NOT EXISTS {prefix}written_exam (
  student_id INT PRIMARY KEY,
  student_name VARCHAR(50) NOT NULL DEFAULT '',
  -- 수강생 본인 조회(written-exam.html)에서 이름+연락처 뒷4자리로 본인 확인할 때 씁니다.
  student_phone VARCHAR(30) NOT NULL DEFAULT '',
  exam_date VARCHAR(10) NOT NULL DEFAULT '',
  exam_time VARCHAR(20) NOT NULL DEFAULT '',
  place VARCHAR(100) NOT NULL DEFAULT '나주',
  updated_by VARCHAR(50) NOT NULL DEFAULT '',
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- ── 필기시험 예약 시스템 (feature/written-exam-booking) ──────────────────
-- 위 written_exam(안내장 앱 전용, 학생당 최신 한 건)과는 별개입니다. 수강생이
-- 홈페이지에서 직접 신청·변경·취소하는 정식 예약 시스템으로, 셔틀과 같은 구조
-- (관리자가 회차를 만들고, 수강생이 그 회차에 붙음)입니다.
-- 설계 근거: docs-11-written-exam-db.md, docs-12-written-exam-screens.md
-- (yj-academy-messaging 저장소)

CREATE TABLE IF NOT EXISTS {prefix}written_exam_slots (
  id INT AUTO_INCREMENT PRIMARY KEY,
  exam_date VARCHAR(10) NOT NULL,
  -- 그날의 몇 번째 회차인지. 출발시간이 바뀌어도 신청자의 소속이 유지되도록
  -- 시간이 아니라 번호로 묶습니다 (셔틀 slot_no와 같은 방식).
  slot_no INT NOT NULL DEFAULT 0,
  depart_time VARCHAR(10) NOT NULL DEFAULT '',
  -- 수업 겹침 판정에 쓰는 예상 복귀 시각 ('12:30' 등)
  return_time VARCHAR(10) NOT NULL DEFAULT '',
  exam_place VARCHAR(20) NOT NULL DEFAULT '',
  capacity INT NOT NULL DEFAULT 8,
  -- 관리자가 더 이상 신청을 안 받고 싶을 때 1로 둡니다 (정원이 남아도 마감)
  closed TINYINT NOT NULL DEFAULT 0,
  memo VARCHAR(200) NOT NULL DEFAULT '',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_slot (exam_date, slot_no),
  INDEX idx_exam_date (exam_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS {prefix}written_exam_bookings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  exam_date VARCHAR(10) NOT NULL,
  -- 회차 번호. 개인방문(셔틀 안 탐)이면 -1이고 정원에 포함되지 않습니다.
  slot_no INT NOT NULL DEFAULT -1,
  -- '학원출발' = 셔틀로 함께, '개인방문' = 각자 시험장으로
  depart_type VARCHAR(10) NOT NULL DEFAULT '학원출발',
  name VARCHAR(50) NOT NULL DEFAULT '',
  phone VARCHAR(30) NOT NULL DEFAULT '',
  -- 관리자 "이름+생년월일" 조회, 동명이인 구분용
  birth_date VARCHAR(10) NOT NULL DEFAULT '',
  -- 학사DB StudentID. 안내장 앱에서 넣으면 채워지고, 수강생이 직접 신청하면 비어 있습니다.
  student_key VARCHAR(50) NOT NULL DEFAULT '',
  -- 수강생이 자기 예약을 변경·취소할 때 본인 확인에 쓰는 임의 문자열 (지금은 미사용, 자리만)
  edit_token VARCHAR(64) NOT NULL DEFAULT '',
  memo VARCHAR(200) NOT NULL DEFAULT '',
  updated_by VARCHAR(50) NOT NULL DEFAULT '',   -- '수강생' 또는 관리자 아이디
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  -- 같은 사람이 같은 날 두 번 신청하는 것을 DB 차원에서 막습니다 (버튼 두 번 누르기 방지).
  -- 다른 날짜로 두 건 잡는 것은 애플리케이션 레벨 잠금(GET_LOCK)으로 막습니다.
  UNIQUE KEY uq_person_day (exam_date, name, phone),
  INDEX idx_slot (exam_date, slot_no),
  INDEX idx_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- 셔틀 담당자용 변경 이력. "그런 얘기 못 들었다"가 안 생기게 신청/취소/변경을 남기고,
-- 확인 여부를 표시합니다.
CREATE TABLE IF NOT EXISTS {prefix}written_exam_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  exam_date VARCHAR(10) NOT NULL,
  slot_no INT NOT NULL DEFAULT -1,
  action VARCHAR(10) NOT NULL DEFAULT '',   -- '신청' / '취소' / '변경'
  name VARCHAR(50) NOT NULL DEFAULT '',
  detail VARCHAR(200) NOT NULL DEFAULT '',  -- '10-13 → 10-10' 같은 설명
  actor VARCHAR(50) NOT NULL DEFAULT '',    -- '수강생' 또는 관리자 아이디
  seen_by VARCHAR(50) NOT NULL DEFAULT '',  -- 확인한 사람
  seen_at DATETIME NULL DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_exam_date (exam_date),
  INDEX idx_seen (seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- 공휴일 표. 설·추석 등은 계산으로 안 나와서 표로 관리합니다. 달력에 빨간 표시 +
-- 일괄 회차 생성에서 자동 제외하는 데 씁니다.
CREATE TABLE IF NOT EXISTS {prefix}holidays (
  holiday_date VARCHAR(10) PRIMARY KEY,   -- 'YYYY-MM-DD'
  name VARCHAR(50) NOT NULL DEFAULT ''    -- '추석', '대체공휴일'
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
