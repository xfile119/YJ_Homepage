/* 실제 사이트(FTP 업로드본)가 지금 어느 작업 시점인지 확인하기 위한 버전 표시입니다.
   커밋할 때마다 이 값을 그 커밋 해시/시각으로 갱신해두면, 화면 맨 아래 작은 글씨로
   나오는 버전과 git 기록을 대조해서 "어디까지 실제로 반영됐는지" 확인할 수 있습니다. */
var YJ_SITE_VERSION = { commit: "95ab296", date: "2026-09-24 00:40 KST", note: "광고·유입 성과 측정" };

document.addEventListener("DOMContentLoaded", function () {
  var header = document.querySelector(".site-header");
  var navToggle = document.querySelector(".nav-toggle");
  var mainNav = document.querySelector(".main-nav");
  var backToTop = document.querySelector(".fa-top");

  // 스크롤 시 헤더 그림자 + 맨 위로 버튼 노출
  function onScroll() {
    var scrolled = window.scrollY > 10;
    if (header) header.classList.toggle("is-scrolled", scrolled);
    if (backToTop) backToTop.classList.toggle("is-visible", window.scrollY > 400);
  }
  window.addEventListener("scroll", onScroll, { passive: true });
  onScroll();

  // 모바일 메뉴 토글
  if (navToggle && mainNav) {
    // 메뉴 패널이 항상 헤더(상단 유틸리티바 포함) 바로 아래에서 시작하도록,
    // 열 때마다 헤더의 실제 화면상 하단 위치를 측정해 top 값으로 지정합니다.
    // (top: var(--header-h) 고정값만 쓰면 스크롤 전에는 상단바 높이만큼
    // 메뉴가 헤더 위로 파고들어 로고·버튼을 가리는 문제가 있었습니다.)
    function positionMobileNav() {
      if (header) mainNav.style.top = header.getBoundingClientRect().bottom + "px";
    }

    navToggle.addEventListener("click", function () {
      var isOpen = mainNav.classList.toggle("is-open");
      if (isOpen) positionMobileNav();
      navToggle.classList.toggle("is-open", isOpen);
      navToggle.setAttribute("aria-expanded", isOpen ? "true" : "false");
      document.body.style.overflow = isOpen ? "hidden" : "";
    });

    window.addEventListener("resize", function () {
      if (mainNav.classList.contains("is-open")) positionMobileNav();
    });

    mainNav.querySelectorAll("a").forEach(function (link) {
      link.addEventListener("click", function () {
        mainNav.classList.remove("is-open");
        navToggle.classList.remove("is-open");
        document.body.style.overflow = "";
      });
    });
  }

  // 맨 위로 버튼
  if (backToTop) {
    backToTop.addEventListener("click", function () {
      window.scrollTo({ top: 0, behavior: "smooth" });
    });
  }

  // 현재 페이지 메뉴 강조
  var currentPage = (location.pathname.split("/").pop() || "index.html");
  document.querySelectorAll(".main-nav a[data-page]").forEach(function (link) {
    if (link.getAttribute("data-page") === currentPage) {
      link.classList.add("is-active");
    }
  });

  // 버전 표시 (footer-legal 옆에 작게). FTP 업로드 후 실제 사이트에서
  // 이 값을 보고 어느 시점까지 반영됐는지 확인할 수 있습니다.
  var footerLegal = document.querySelector(".footer-legal");
  if (footerLegal) {
    var v = document.createElement("span");
    v.className = "footer-version";
    v.title = YJ_SITE_VERSION.note;
    v.textContent = "build " + YJ_SITE_VERSION.date + " · " + YJ_SITE_VERSION.commit;
    footerLegal.appendChild(v);
  }
});

/* =======================================================================
   유입 경로 측정 — "어디서 들어온 사람이 상담·전화로 이어졌는지"
   - 광고 링크 끝에 ?src=이름 을 붙이면 그 이름으로 기록합니다
     (예: license-picker.html?src=naver_powerlink). 네이버 검색광고의 자동 추적
     파라미터(n_keyword 등)가 붙어 오면 키워드도 함께 남깁니다. 전단·현수막 QR처럼
     한글 이름을 붙이고 싶으면 &c=이름 (예: ?src=flyer&c=수완OO아파트)을 씁니다.
   - src가 없으면 이전 페이지(referrer)로 네이버 검색·플레이스·구글 등을 구분합니다.
   - 마지막으로 확인된 경로를 이 브라우저에 30일간 기억해 두고, 상담 신청·문의
     남기기·전화(학원 대표번호)·카카오톡·네이버 톡톡 클릭 때 함께 보냅니다.
   - 이름·전화번호 같은 개인정보는 여기서 절대 보내지 않습니다(경로·키워드·페이지만).
   ======================================================================= */
(function () {
  "use strict";
  var KEY = "yj_attr";
  var TTL = 30 * 24 * 60 * 60 * 1000;
  var ACADEMY_TEL = "0629515100";

  function clean(v, max) {
    return String(v == null ? "" : v).replace(/[\u0000-\u001f<>"'`]/g, "").trim().slice(0, max);
  }
  function load() {
    try {
      var a = JSON.parse(localStorage.getItem(KEY) || "null");
      if (a && a.src && Date.now() - a.ts < TTL) return a;
    } catch (e) {}
    return null;
  }
  function save(a) {
    try { localStorage.setItem(KEY, JSON.stringify(a)); } catch (e) {}
  }
  function page() {
    return clean(location.pathname.split("/").pop() || "index.html", 60);
  }
  /* 이번 방문이 어디서 왔는지. 우리 사이트 안에서 옮겨 다닌 경우는 null(기존 기억 유지) */
  function detect() {
    var q = {};
    try { new URLSearchParams(location.search).forEach(function (v, k) { q[k] = v; }); } catch (e) {}
    var kw = clean(q.n_keyword || q.n_query || q.utm_term || q.c || q.utm_campaign || "", 60);
    var src = clean(q.src || q.utm_source || "", 40).toLowerCase().replace(/[^a-z0-9_.:-]/g, "");
    if (!src && (q.n_media || q.n_keyword || q.n_query)) src = "naver_ad";
    if (src) return { src: src, kw: kw };

    var ref = "";
    try { ref = document.referrer ? new URL(document.referrer).hostname.toLowerCase() : ""; } catch (e) {}
    if (!ref) return null;
    if (/(^|\.)yjcdrive\.co\.kr$/.test(ref) || ref === location.hostname) return null;
    if (/place\.naver\.com$|(^|\.)map\.naver\.com$/.test(ref)) return { src: "naver_place", kw: "" };
    if (/(^|\.)search\.naver\.com$/.test(ref)) return { src: "naver_search", kw: "" };
    if (/(^|\.)naver\.com$/.test(ref)) return { src: "naver_etc", kw: "" };
    if (/(^|\.)google\.[a-z.]+$/.test(ref)) return { src: "google_search", kw: "" };
    if (/(^|\.)daum\.net$/.test(ref)) return { src: "daum_search", kw: "" };
    if (/daangn\.com$/.test(ref)) return { src: "daangn", kw: "" };
    if (/(^|\.)kakao\.com$/.test(ref)) return { src: "kakao", kw: "" };
    if (/instagram\.com$|facebook\.com$/.test(ref)) return { src: "sns", kw: "" };
    return { src: clean("ref:" + ref.replace(/^www\./, ""), 40), kw: "" };
  }

  var found = detect();
  var attr = load();
  if (found) {
    attr = { src: found.src, kw: found.kw, landing: page(), ts: Date.now() };
    save(attr);
  }
  function current() {
    return attr || { src: "direct", kw: "", landing: page() };
  }
  window.YJ_ATTR = { get: current };

  function send(event) {
    var a = current();
    var body = JSON.stringify({ event: event, src: a.src, kw: a.kw || "", page: page() });
    try {
      if (navigator.sendBeacon && navigator.sendBeacon("api/track.php", body)) return;
    } catch (e) {}
    try { fetch("api/track.php", { method: "POST", body: body, keepalive: true }); } catch (e) {}
  }

  /* 방문은 브라우저 창(세션)마다 한 번만 셉니다 */
  var isBot = /bot|crawl|spider|slurp|yeti|daumoa|bingpreview/i.test(navigator.userAgent || "");
  if (!isBot) {
    var seen = false;
    try { seen = sessionStorage.getItem("yj_visit") === "1"; sessionStorage.setItem("yj_visit", "1"); } catch (e) {}
    if (!seen) send("visit");
  }

  document.addEventListener("click", function (e) {
    var a = e.target && e.target.closest ? e.target.closest("a[href]") : null;
    if (!a) return;
    var href = a.getAttribute("href") || "";
    if (/^tel:/i.test(href)) {
      if (href.replace(/[^0-9]/g, "") === ACADEMY_TEL) send("call");
    } else if (/pf\.kakao\.com/i.test(href)) {
      send("kakao");
    } else if (/naver-talktalk|talk\.naver\.com/i.test(href)) {
      send("talk");
    }
  }, true);
})();
