/* 면허 탐색기 전용 오프라인 캐시입니다.
   license-guide.html에서 scope:"license-guide.html"로 좁혀서 등록하므로
   이 파일이 사이트의 다른 페이지(관리자 화면 포함)에 영향을 주지 않습니다.
   PHP 서버 없이도 마지막으로 받은 화면·데이터로 켤 수 있게 하는 게 목적입니다. */

var CACHE_NAME = "yj-license-guide-v1";
var APP_SHELL = [
  "license-guide.html",
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

  /* 수강료·시험일정 데이터는 최신 값이 중요하므로 네트워크를 먼저 시도하고,
     오프라인일 때만 마지막으로 받아둔 값을 보여줍니다. */
  if (url.pathname.indexOf("/api/license-data.php") !== -1) {
    event.respondWith(
      fetch(req).then(function (res) {
        var copy = res.clone();
        caches.open(CACHE_NAME).then(function (c) { c.put(req, copy); });
        return res;
      }).catch(function () {
        return caches.match(req).then(function (cached) {
          return cached || new Response(
            JSON.stringify({ error: "오프라인 상태입니다. 인터넷에 연결되면 최신 요금으로 갱신됩니다." }),
            { status: 503, headers: { "Content-Type": "application/json" } }
          );
        });
      })
    );
    return;
  }

  /* 화면(html)·스타일(css)은 캐시를 우선 보여주되, 뒤에서 조용히 최신본으로 갱신해둡니다 */
  var isAppShell = APP_SHELL.some(function (path) {
    return url.pathname.indexOf(path) !== -1;
  });
  if (isAppShell) {
    event.respondWith(
      caches.match(req).then(function (cached) {
        var network = fetch(req).then(function (res) {
          caches.open(CACHE_NAME).then(function (c) { c.put(req, res.clone()); });
          return res;
        }).catch(function () { return cached; });
        return cached || network;
      })
    );
  }
  /* 그 외 요청(폰트, 다른 페이지 등)은 이 서비스워커가 손대지 않고 그대로 흘려보냅니다 */
});
