/* 셔틀/일정/강사 조회 앱 전용 오프라인 캐시입니다.
   lookup-app.html에서 scope:"lookup-app.html"로 좁혀서 등록하므로
   이 파일이 사이트의 다른 페이지(관리자 화면 포함)에 영향을 주지 않습니다.
   개인정보(셔틀·일정·로그인)가 오가는 API 요청은 절대 캐시하지 않습니다 —
   항상 네트워크로만 처리하고, 실패하면 그냥 실패로 둡니다. */

var CACHE_NAME = "yj-lookup-app-v1";
var APP_SHELL = [
  "lookup-app.html",
  "css/style.css"
];

self.addEventListener("install", function (event) {
  self.skipWaiting();
  event.waitUntil(
    caches.open(CACHE_NAME).then(function (cache) {
      return cache.addAll(APP_SHELL);
    })
  );
});

self.addEventListener("activate", function (event) {
  event.waitUntil(
    caches.keys().then(function (keys) {
      return Promise.all(
        keys.filter(function (k) { return k !== CACHE_NAME; })
            .map(function (k) { return caches.delete(k); })
      );
    }).then(function () { return self.clients.claim(); })
  );
});

self.addEventListener("fetch", function (event) {
  var req = event.request;
  if (req.method !== "GET") { return; }

  var url = new URL(req.url);

  /* /api/ 요청(개인정보 포함)은 이 서비스워커가 절대 손대지 않습니다 */
  if (url.pathname.indexOf("/api/") !== -1) { return; }

  var isAppShell = APP_SHELL.some(function (path) {
    return url.pathname.indexOf(path) !== -1;
  });
  if (!isAppShell) { return; }

  /* 화면(html)·스타일(css)은 캐시를 우선 보여주되, 뒤에서 조용히 최신본으로 갱신해둡니다 */
  event.respondWith(
    caches.match(req).then(function (cached) {
      var network = fetch(req).then(function (res) {
        caches.open(CACHE_NAME).then(function (c) { c.put(req, res.clone()); });
        return res;
      }).catch(function () { return cached; });
      return cached || network;
    })
  );
});
