/* 유입 경로(src) 이름표 — 관리자 화면(문의 관리·광고/유입 성과)에서 같이 씁니다.
   js/main.js가 기록하는 값과, 광고 주소에 붙이는 ?src= 값을 사람이 읽는 이름으로 바꿉니다.
   새 광고 채널을 추가하면 여기와 admin-stats.html의 "광고에 넣을 주소"를 같이 늘려주세요. */
(function () {
  "use strict";
  var LABEL = {
    naver_powerlink: "네이버 파워링크 (광고)",
    naver_ad: "네이버 광고 (자동 추적)",
    naver_place_ad: "네이버 플레이스 광고",
    naver_place: "네이버 플레이스·지도",
    naver_search: "네이버 검색",
    naver_etc: "네이버 기타 (블로그·카페 등)",
    google_search: "구글 검색",
    google_business: "구글 비즈니스 프로필",
    daum_search: "다음 검색",
    daangn: "당근",
    daangn_ad: "당근 광고",
    kakao: "카카오",
    kakao_channel: "카카오톡 채널",
    sns: "인스타그램·페이스북",
    direct: "직접 방문·알 수 없음"
  };
  window.YJ_CHANNEL = {
    label: function (src) {
      src = String(src || "direct");
      if (LABEL[src]) return LABEL[src];
      if (src.indexOf("ref:") === 0) return "다른 사이트 (" + src.slice(4) + ")";
      return src;
    },
    /* 돈을 내는 광고 경로인지 (광고비 입력칸을 보여줄지 판단) */
    isPaid: function (src) {
      return /(_ad|powerlink)$/.test(String(src || "")) || src === "naver_ad";
    }
  };
})();
