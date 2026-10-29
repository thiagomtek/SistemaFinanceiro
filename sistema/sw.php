<?php
// Service worker do Sistema Financeiro (publico). Escopo = /sistema/ (pasta deste arquivo), cache proprio com prefixo
// "sistema-financeiro-": nao toca em caches de outros servicos. Nunca guarda paginas autenticadas nem respostas de API.
define('FIN_SEM_SESSAO', true); // sem sessao: nao cria arquivo de sessao nem cookie a cada consulta anonima
require_once __DIR__ . '/bootstrap.php';
header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: no-cache');
header('Service-Worker-Allowed: ' . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/');
$cache = 'sistema-financeiro-' . SISTEMA_BUILD;
?>
const CACHE = <?= json_encode($cache) ?>;
const PREFIXO = 'sistema-financeiro-';
const BASE = self.registration.scope; // .../sistema/
const PRE = ['offline.html', 'assets/icons/icon-192.png'];

self.addEventListener('install', function (e) {
  e.waitUntil(caches.open(CACHE).then(function (c) { return c.addAll(PRE.map(function (u) { return new Request(BASE + u, { cache: 'reload' }); })); }).then(function () { return self.skipWaiting(); }));
});

self.addEventListener('activate', function (e) {
  e.waitUntil(caches.keys().then(function (ks) {
    return Promise.all(ks.filter(function (k) { return k.indexOf(PREFIXO) === 0 && k !== CACHE; }).map(function (k) { return caches.delete(k); }));
  }).then(function () { return self.clients.claim(); }));
});

self.addEventListener('fetch', function (e) {
  var req = e.request;
  if (req.method !== 'GET') { return; }
  var url = new URL(req.url);
  if (url.origin !== location.origin || url.pathname.indexOf(new URL(BASE).pathname) !== 0) { return; }
  // paginas: sempre da rede (dados financeiros); sem rede, mostra a pagina offline
  if (req.mode === 'navigate') {
    e.respondWith(fetch(req).catch(function () { return caches.match(BASE + 'offline.html'); }));
    return;
  }
  // estaticos versionados (css, js, fontes, icones): cache primeiro com atualizacao em segundo plano
  if (/\/assets\//.test(url.pathname)) {
    e.respondWith(caches.open(CACHE).then(function (c) {
      return c.match(req).then(function (hit) {
        var rede = fetch(req).then(function (r) { if (r && r.ok) { c.put(req, r.clone()); } return r; }).catch(function () { return hit; });
        return hit || rede;
      });
    }));
  }
  // demais (api_*.php, anexo.php etc.): direto da rede, sem cache
});

// Web Push: o servidor envia {titulo, mensagem, url (relativa a /sistema/), tag}. Origem e escopo proprios (porta 8453): nao se mistura com o ERP.
self.addEventListener('push', function (e) {
  var d = {};
  try { d = e.data ? e.data.json() : {}; } catch (x) { d = { mensagem: e.data ? e.data.text() : '' }; }
  var alvo = new URL(BASE + 'index.php');
  try {
    var u = new URL(String(d.url || ''), BASE);
    if (u.origin === alvo.origin && u.pathname.indexOf(new URL(BASE).pathname) === 0) { alvo = u; }
  } catch (x) {}
  e.waitUntil(self.registration.showNotification(String(d.titulo || 'Sistema Financeiro'), {
    body: String(d.mensagem || 'Há um novo aviso no Sistema Financeiro.'),
    icon: BASE + 'assets/icons/icon-192.png',
    badge: BASE + 'assets/icons/icon-192.png',
    tag: String(d.tag || 'fin-aviso'),
    data: { url: alvo.href }
  }));
});

self.addEventListener('notificationclick', function (e) {
  e.notification.close();
  var alvo = (e.notification.data && e.notification.data.url) || (BASE + 'index.php');
  e.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (cs) {
    for (var i = 0; i < cs.length; i++) { if (cs[i].url.indexOf(BASE) === 0 && 'focus' in cs[i]) { cs[i].navigate(alvo); return cs[i].focus(); } }
    return self.clients.openWindow(alvo);
  }));
});
