# CLAUDE.md — 영진자동차운전전문학원 홈페이지 (YJ_Homepage)

이 파일은 새 작업을 시작할 때 **가장 먼저 읽는 프로젝트 설명서**입니다. 규칙이 바뀌면 이 파일도 같이 고치세요.

## 1. 이 프로젝트가 뭔가
광주 영진자동차운전전문학원의 **홈페이지 + 수강생 조회·필기시험 예약 + 직원 관리자 화면**입니다.
정적 HTML/CSS/JS + PHP API + MySQL. 학사 프로그램(Neo)의 데이터는 **학사서버가 홈페이지로 보내 주는 방식**으로만 들어옵니다.
앞으로 이것을 **"영진 통합 앱"(홈 화면에 설치하는 웹앱)** 으로 확장합니다 — 기획은 별도 저장소 `xfile119/yj-academy-messaging`의 `app-plan/`과 `docs-20-integrated-app-plan.md`에 있습니다(없으면 `add_repo`로 추가).

## 2. 사람과 일하는 방식 (중요)
- **원장님(사용자)은 개발자가 아닙니다.** 한국어로, 쉬운 말로, 단계별로 설명하세요. 어려운 용어는 풀어 쓰고 "왜 그런지"를 한 줄 붙이세요.
- **승인 후 구현**: 큰 기능은 설계서를 먼저 보여 주고 승인을 받은 뒤 코드를 씁니다. 되돌리기 어렵거나 외부에 영향을 주는 일(메인 푸시, 배포 안내, 권한 변경)은 확인하고 진행합니다.
- **모르는 것은 추측하지 말고 "확인 필요"로 적습니다.** 학사DB 컬럼 의미를 이름만 보고 단정하지 않습니다(실제로 여러 번 틀렸음).
- 결과 보고는 **솔직하게**: 시험했는지, 못 한 것은 무엇인지, 확인하지 못한 가정은 무엇인지 구분해서 씁니다.
- 원장님이 "보류"라고 하면 `yj-academy-messaging/docs-16-homepage-backlog.md`(보류 목록)에 기록합니다.
- **GPT와의 분업**: GPT는 문서 작성·교차 검토·일부 개발을 합니다. GPT 결과물은 **받으면 실제로 돌려서 검토**하고(말로만 검토하지 않기), 수정이 필요하면 **GPT에게 줄 수정 요청서**(재현 근거 + 수정 방향)를 써서 원장님께 드립니다. GPT가 만든 것을 임의로 대신 고치지 않습니다(원장님 결정, 2026-10-01).

## 3. 서버·기술 환경 (깨지면 운영이 멈춥니다)
- **호스팅은 카페24**: **PHP 5.5 이상 호환 문법만** 씁니다. 쓰지 말 것: `??`, 반환 타입 선언, 스칼라 타입 힌트, `random_bytes`(필요하면 `openssl_random_pseudo_bytes` 폴백), `hash_equals` 직접 호출(→ `api/_db.php`의 `yj_require_sync_key` 패턴처럼 `function_exists` 확인). 짧은 배열 `[]`은 가능.
- DB: MySQL(MariaDB 호환), 테이블 접두사 `yj_`, 문자셋 utf8. 새 테이블/컬럼은 `db/schema.sql`·`db/setup.php`·`db/CHANGELOG-DB.md`를 **같이** 고칩니다(`setup.php`는 원장님이 한 번 실행).
- 배포는 **FTP로 바뀐 파일만 올립니다.** 그래서 변경할 때마다 `CHANGELOG.md`에 **"FTP로 올릴 파일" 목록**과 DB 변경 여부를 적습니다(최신 항목이 위).
- **비밀값은 저장소에 없습니다**: `config.php`(gitignore)에만 있고 `config.example.php`에는 자리표시자만. 비밀번호·키·전화번호·수강생 정보를 코드·문서·로그에 쓰지 않습니다.
- 공통 헬퍼는 `api/_db.php`: `yj_json`, `yj_input`, `yj_db`, `yj_table`, `yj_require_login`, `yj_require_admin`(최고관리자), `yj_require_content_admin`(최고+사무실), `yj_require_shuttle_admin`(최고+사무실+셔틀), `yj_require_sync_key($configKey)`(학사서버↔홈페이지 키), `yj_has_role`. **역할은 쉼표로 이어진 목록**(예: `instructor,admin`)이라 `yj_has_role`로만 검사합니다.
- 서버↔서버 키는 **용도마다 따로**(`schedule_sync_key`, `written_exam_sync_key`, `guide_sso_key` …). 하나를 재사용하지 않습니다.

## 4. 학사DB와 연동 규칙
- 학사DB(`neoinfo`, SQL Server 2008 R2)는 **읽기만**. INSERT/UPDATE/DELETE/새 테이블 금지. 읽기 계정은 컬럼 단위 최소권한.
- 구조·코드 해독·확인된 규칙은 `yj-academy-messaging/REFERENCE-학사DB.md`가 **기준 문서**입니다. 코드 쓰기 전에 읽으세요.
- 학사DB의 날짜는 **`varchar(8)` `YYYYMMDD` 문자열**입니다(날짜형 아님 → 변환하지 말고 문자열로 비교). 홈페이지 쪽 날짜는 `YYYY-MM-DD`.
- 이 환경에서는 학사DB에 접속할 수 없습니다. 조회가 필요하면 **SQL을 써서 원장님이 SSMS에서 실행하고 결과를 붙여넣는 방식**으로 진행합니다(개인정보 컬럼은 요청하지 않음).

## 5. 업무 규칙 (코드에 반영돼 있음 — 함부로 바꾸지 말 것)
- **필기시험 예약** (`api/written-exam-booking.php`): 수강생 신청·날짜 변경은 시험 **2일 전까지**, 취소는 **전날까지**. 학원출발 회차만(개인방문은 안 받음). 같은 날 수업이 있으면 수강생은 신청 불가. 직원 입력(관리자 화면·안내장 앱)은 이 제한 없이 경고만.
- 본인 확인: 이름 + 생년월일(+ 연락처 입력). **연락처를 저장값과 대조하지 않는 약점이 알려져 있음**(`docs-19-written-exam-rules.md` §8).
- 안내장 앱(학사서버, Flask)은 `yj-academy-messaging/guide-print/`에 있고 홈페이지의 "안내장 작성" 버튼(`api/guide-auth.php`)으로 들어옵니다.
- 앞으로 구현 예정: 필기시험 신청 자격 확인(`docs-18`), 일일 입학현황, 알림톡 전환 — 상태는 `docs-18`, `docs-16` 참고.

## 6. 작업 습관
- **시험은 로컬에서**: `mariadbd --user=root --datadir=/tmp/<새폴더> --socket=/tmp/<이름>.sock --port=3307 --bind-address=127.0.0.1`로 DB를 띄우고, 임시 `config.php`를 만들어 `php -S 127.0.0.1:<포트>`로 실행(임시 `config.php`는 끝나면 삭제, **커밋 금지**). 화면은 Playwright(Chromium, `/opt/pw-browsers/chromium`)로 확인.
- 프로세스 종료는 `ps aux | awk '/패턴/ && !/awk/ {print $2}' | xargs -r kill` — **`pkill -f`는 자기 셸을 죽이니 쓰지 않습니다.** `rm -rf *` 같은 광범위 삭제도 쓰지 않습니다.
- 커밋 메시지는 한국어로 "무엇을/왜". 끝에 지정된 공동 작성자 줄을 붙입니다.
- 변경 후 확인: `php -l`(문법), 관련 시나리오를 실제로 호출, 권한(최고관리자/사무실/비로그인) 확인, 개인정보가 새지 않는지.

## 7. 브랜치
- 운영 수정·소규모 개선: `main`.
- **통합 앱 개발**: 별도 기능 브랜치(예: `feature/integrated-app`)에서 만들고, **원장님이 승인한 단위만** `main`에 합칩니다. 두 곳에서 같은 파일을 동시에 고치지 않도록 먼저 `git pull`로 최신을 받습니다.

## 8. 문서 위치
| 문서 | 위치 |
|---|---|
| 통합 앱 기획서 v0.1 | `yj-academy-messaging/docs-20-integrated-app-plan.md` |
| 통합 앱 상세 기획 문서 세트 | `yj-academy-messaging/app-plan/` |
| 학사DB 기준 문서 | `yj-academy-messaging/REFERENCE-학사DB.md` |
| 개발 전체 문서 | `yj-academy-messaging/docs-13-development-spec.md` |
| 보류 목록 | `yj-academy-messaging/docs-16-homepage-backlog.md` |
| 필기시험 규칙·신청자격 설계 | `docs-19-written-exam-rules.md`, `docs-18-written-exam-eligibility.md` |
| 이 저장소의 변경 기록 | `CHANGELOG.md`, `db/CHANGELOG-DB.md`, `PENDING.md` |
