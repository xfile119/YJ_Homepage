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
});
