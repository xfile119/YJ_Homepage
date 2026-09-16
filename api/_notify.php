<?php
/* 카카오 알림톡 발송 — 지금은 대행업체 API 키가 없어서 항상 "설정 필요"로
   실패 처리만 하는 자리표시자입니다. 대행업체(예: 알리고, 솔라피) 가입과
   채널 연결, 템플릿 승인까지 끝나면 이 함수 안쪽만 실제 HTTP 요청으로
   바꾸면 됩니다 — 이 파일을 호출하는 api/shuttle.php 쪽은 고칠 필요 없습니다.

   $vars 에는 메시지 문구에 채워 넣을 값들이 들어옵니다: name, date, time, place.
   반환값: ['ok' => true] 성공 / ['ok' => false, 'error' => '사유'] 실패. */
function yj_send_shuttle_alimtalk($phone, $vars) {
    /* TODO: 대행업체 API 키가 생기면 여기를 실제 발송 요청으로 바꿉니다.
       예시(솔라피/알리고 알림톡 발송 API 공통 흐름):
         1) 발급받은 API 키·시크릿과 승인된 템플릿 코드를 아래에 넣습니다.
         2) $phone, 템플릿 코드, $vars(치환용 변수)를 담아 대행업체 API에 POST합니다.
         3) 응답이 성공이면 ['ok' => true], 실패면 ['ok' => false, 'error' => 응답 메시지] 를 돌려줍니다. */
    return [
        'ok' => false,
        'error' => '카카오 알림톡 연동이 아직 설정되지 않았습니다 (대행업체 가입·템플릿 승인 후 api/_notify.php에 연결 필요)',
    ];
}
