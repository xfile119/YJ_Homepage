<?php
/**
 * 이 파일을 복사해서 config.php 로 저장한 뒤, 실제 DB 접속 정보를 입력하세요.
 * config.php는 절대 git 저장소에 올리지 마세요 (.gitignore에 이미 등록되어 있습니다).
 *
 * table_prefix: 기존 홈페이지가 쓰던 DB를 그대로 공유해서 쓰는 경우,
 * 테이블 이름이 기존 것과 겹치지 않도록 접두사를 붙입니다.
 * 새 DB를 따로 만들었다면 굳이 바꾸지 않아도 됩니다.
 */
return [
    'db_host' => 'localhost',
    'db_name' => '여기에_DB이름을_입력하세요',
    'db_user' => '여기에_DB_사용자명을_입력하세요',
    'db_pass' => '여기에_DB_비밀번호를_입력하세요',
    'table_prefix' => 'yj_',
];
