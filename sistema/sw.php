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

// A identidade inclui a porta. Payloads e janelas de outros servicos sao rejeitados.
const PWA = {"id": "fin", "name": "Sistema Financeiro", "origin": "https://servidor.tail074692.ts.net:8453", "scope": "/sistema/", "home": "/sistema/index.php", "icon": "/sistema/assets/icons/icon-192.png", "badge": "/sistema/assets/icons/notification-badge.png", "titleKey": "titulo", "bodyKey": "mensagem"};
function pwaTarget(value) {
  try {
    const u = new URL(typeof value === 'string' && value ? value : PWA.home, PWA.origin + PWA.scope);
    if (u.origin === PWA.origin && !u.username && !u.password && u.pathname.startsWith(PWA.scope)) return u.href;
  } catch (_) {}
  return PWA.origin + PWA.home;
}
function pwaTitle(value) {
  let title = String(value || '').trim();
  if (PWA.id === 'fin') title = title.replace(/^Financeiro\s*[·:-]\s*/i, '');
  return !title || title === PWA.name ? PWA.name : (title.startsWith(PWA.name + ' · ') ? title : PWA.name + ' · ' + title);
}
function pwaOwnClient(client) {
  try { const u = new URL(client.url); return u.origin === PWA.origin && u.pathname.startsWith(PWA.scope); } catch (_) { return false; }
}
self.addEventListener('push', (event) => {
  if (self.location.origin !== PWA.origin) return;
  let data = {};
  try { data = event.data ? event.data.json() : {}; } catch (_) { data = {body: event.data ? event.data.text() : ''}; }
  event.waitUntil((async () => {
    if (PWA.id === 'hub' && !data.test) {
      const windows = await self.clients.matchAll({type: 'window', includeUncontrolled: true});
      if (windows.some(w => pwaOwnClient(w) && w.visibilityState === 'visible' && w.focused)) return;
    }
    await self.registration.showNotification(pwaTitle(data[PWA.titleKey]), {
      body: String(data[PWA.bodyKey] || data.body || 'Há um novo aviso neste aplicativo.'),
      icon: PWA.origin + PWA.icon,
      badge: PWA.origin + PWA.badge,
      tag: PWA.id + ':' + String(data.tag || (PWA.id === 'erp' ? 'pdv-autorizacao-' + String(data.id || 'pendente') : 'aviso')),
      ...(PWA.id === 'erp' ? {renotify: true} : {}),
      ...(PWA.id === 'hub' ? {renotify: true, requireInteraction: true, vibrate: [500,200,500,200,500,200,500]} : {}),
      data: {url: pwaTarget(data.url), app: PWA.origin, clientId: data.clientId || ''}
    });
  })());
});
self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  if (self.location.origin !== PWA.origin) return;
  const target = pwaTarget(event.notification.data && event.notification.data.url);
  event.waitUntil((async () => {
    const windows = (await self.clients.matchAll({type: 'window', includeUncontrolled: true})).filter(pwaOwnClient);
    windows.sort((a,b) => Number(b.focused) - Number(a.focused));
    for (const client of windows) {
      if (!('navigate' in client)) continue;
      try { const navigated = await client.navigate(target); if (navigated) return await navigated.focus(); } catch (_) {}
    }
    return self.clients.openWindow(target);
  })());
});
