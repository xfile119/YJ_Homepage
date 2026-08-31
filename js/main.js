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
    navToggle.addEventListener("click", function () {
      var isOpen = mainNav.classList.toggle("is-open");
      navToggle.classList.toggle("is-open", isOpen);
      navToggle.setAttribute("aria-expanded", isOpen ? "true" : "false");
      document.body.style.overflow = isOpen ? "hidden" : "";
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

  // 상담 신청 폼 (백엔드 미연동 상태의 클라이언트 측 검증 + 안내 메시지)
  var consultForm = document.querySelector("#consult-form");
  if (consultForm) {
    consultForm.addEventListener("submit", function (e) {
      e.preventDefault();
      var status = consultForm.querySelector(".form-status");
      var name = consultForm.querySelector("#name");
      var phone = consultForm.querySelector("#phone");
      var privacy = consultForm.querySelector("#privacy");

      var phonePattern = /^0\d{1,2}-?\d{3,4}-?\d{4}$/;

      if (!name.value.trim()) {
        showStatus(status, "이름을 입력해주세요.", false);
        name.focus();
        return;
      }
      if (!phonePattern.test(phone.value.trim())) {
        showStatus(status, "연락처를 정확히 입력해주세요. (예: 010-1234-5678)", false);
        phone.focus();
        return;
      }
      if (privacy && !privacy.checked) {
        showStatus(status, "개인정보 수집 및 이용에 동의해주세요.", false);
        return;
      }

      // TODO: 실제 서비스 시에는 이 부분을 서버/이메일 발송 API 연동으로 교체하세요.
      showStatus(status, "상담 신청이 접수되었습니다. 빠른 시간 내에 연락드리겠습니다.", true);
      consultForm.reset();
    });
  }

  function showStatus(el, message, success) {
    if (!el) return;
    el.textContent = message;
    el.className = "form-status " + (success ? "is-success" : "is-error");
  }
});
