// Interatividade do layout (sidebar, menu do usuario, modais) sem dependencias externas.
document.addEventListener('DOMContentLoaded', function () {
  var toggleBtn = document.getElementById('btn-toggle-sidebar');
  var mobile = window.matchMedia('(max-width: 768px)');
  var KEY = 'finSidebarCollapsed';

  function aplicarEstadoSalvo() {
    var colapsado = false;
    try { colapsado = localStorage.getItem(KEY) === '1'; } catch (e) {}
    document.body.classList.toggle('sidebar-collapsed', colapsado && !mobile.matches);
    document.documentElement.classList.remove('sidebar-collapsed-initial');
  }
  aplicarEstadoSalvo();

  if (toggleBtn) {
    toggleBtn.addEventListener('click', function () {
      if (mobile.matches) {
        document.body.classList.toggle('sidebar-open');
        return;
      }
      var c = document.body.classList.toggle('sidebar-collapsed');
      try { localStorage.setItem(KEY, c ? '1' : '0'); } catch (e) {}
    });
  }
  document.addEventListener('click', function (e) {
    if (mobile.matches && document.body.classList.contains('sidebar-open') && !e.target.closest('.main-sidebar') && !e.target.closest('#btn-toggle-sidebar')) {
      document.body.classList.remove('sidebar-open');
    }
  });

  var ub = document.getElementById('btn-user-dropdown');
  var um = document.getElementById('user-dropdown-menu');
  if (ub && um) {
    ub.addEventListener('click', function (e) { e.preventDefault(); um.classList.toggle('show'); });
    document.addEventListener('click', function (e) {
      if (!ub.contains(e.target) && !um.contains(e.target)) { um.classList.remove('show'); }
    });
  }

  // Modais: [data-modal="id"] abre; [data-fechar] ou clique no fundo fecha.
  document.addEventListener('click', function (e) {
    var abrir = e.target.closest('[data-modal]');
    if (abrir) {
      e.preventDefault();
      var m = document.getElementById(abrir.getAttribute('data-modal'));
      if (m) {
        if (abrir.dataset.preencher) { try { preencherModal(m, JSON.parse(abrir.dataset.preencher)); } catch (err) {} }
        else if (abrir.hasAttribute('data-novo')) { limparModal(m); }
        m.classList.add('show');
        var primeiro = m.querySelector('input:not([type=hidden]), select');
        if (primeiro) { setTimeout(function () { primeiro.focus(); }, 50); }
      }
      return;
    }
    if (e.target.closest('[data-fechar]') || (e.target.classList && e.target.classList.contains('modal'))) {
      var alvo = e.target.closest('.modal');
      if (alvo) { alvo.classList.remove('show'); }
    }
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      document.querySelectorAll('.modal.show').forEach(function (m) { m.classList.remove('show'); });
    }
    // Tab fica preso no modal aberto mais recente (os dialogos do motor ERP ficam no fim do body)
    if (e.key === 'Tab') {
      var abertos = document.querySelectorAll('.modal.show');
      var m = abertos[abertos.length - 1];
      if (!m) { return; }
      var foco = Array.prototype.filter.call(
        m.querySelectorAll('a[href], button:not([disabled]), input:not([type=hidden]):not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'),
        function (el) { return el.offsetParent !== null; }
      );
      if (!foco.length) { return; }
      var primeiro = foco[0], ultimo = foco[foco.length - 1];
      if (!m.contains(document.activeElement)) { e.preventDefault(); primeiro.focus(); }
      else if (e.shiftKey && document.activeElement === primeiro) { e.preventDefault(); ultimo.focus(); }
      else if (!e.shiftKey && document.activeElement === ultimo) { e.preventDefault(); primeiro.focus(); }
    }
  });

  // Acessibilidade: modais como dialogos (nome, aria-modal, foco devolvido ao fechar) e acoes so com icone com nome
  document.querySelectorAll('.modal').forEach(function (m) {
    m.setAttribute('role', 'dialog');
    m.setAttribute('aria-modal', 'true');
    m.setAttribute('aria-hidden', m.classList.contains('show') ? 'false' : 'true');
    var t = m.querySelector('.modal-title');
    if (t) { if (!t.id) { t.id = (m.id || 'modal') + '-titulo'; } m.setAttribute('aria-labelledby', t.id); }
    var anterior = null;
    new MutationObserver(function () {
      var aberto = m.classList.contains('show');
      m.setAttribute('aria-hidden', aberto ? 'false' : 'true');
      if (aberto) { anterior = document.activeElement; }
      else if (anterior && document.contains(anterior)) { try { anterior.focus(); } catch (err) {} anterior = null; }
    }).observe(m, { attributes: true, attributeFilter: ['class'] });
  });
  document.querySelectorAll('.cli-btn-acao[title], .erp-btn-acao[title], .btn-icone[title], a[title][data-modal]').forEach(function (el) {
    if (!el.getAttribute('aria-label') && !el.textContent.trim()) { el.setAttribute('aria-label', el.getAttribute('title')); }
  });
  document.querySelectorAll('.modal .close').forEach(function (b) { if (!b.getAttribute('aria-label')) { b.setAttribute('aria-label', 'Fechar'); } });
  document.querySelectorAll('a[href="#"]').forEach(function (a) { if (!a.getAttribute('role')) { a.setAttribute('role', 'button'); } });
  document.querySelectorAll('canvas').forEach(function (c) {
    if (!c.getAttribute('role')) { c.setAttribute('role', 'img'); }
    if (!c.getAttribute('aria-label')) {
      var h = c.closest('.painel') && c.closest('.painel').querySelector('h4');
      c.setAttribute('aria-label', h ? 'Gráfico: ' + h.textContent.trim().replace(/\s+/g, ' ') : 'Gráfico');
    }
  });

  // Confirmacao em acoes destrutivas (dialogo padrao do ERP): <form data-confirmar="texto">
  document.addEventListener('submit', function (e) {
    var f = e.target;
    var msg = f.getAttribute && f.getAttribute('data-confirmar');
    if (!msg || f.dataset.confirmado === '1') { return; }
    e.preventDefault();
    var enviar = function () { f.dataset.confirmado = '1'; f.submit(); };
    if (window.ERP && ERP.confirm) { ERP.confirm(msg, enviar, 'Confirmar ação'); } else if (window.confirm(msg)) { enviar(); }
  });
});

function preencherModal(modal, dados) {
  limparModal(modal);
  Object.keys(dados).forEach(function (k) {
    var el = modal.querySelector('[name="' + k + '"]');
    if (!el) { return; }
    if (el.type === 'checkbox') { el.checked = !!Number(dados[k]); }
    else {
      if (el.tagName === 'SELECT' && dados[k] !== null && dados[k] !== '' && !Array.prototype.some.call(el.options, function (o) { return o.value === String(dados[k]); })) {
        el.add(new Option(dados[k], dados[k]));
      }
      el.value = dados[k] === null ? '' : dados[k];
    }
  });
  var pgt = modal.querySelector('#pg-total');
  if (pgt && dados.total_txt) { pgt.textContent = dados.total_txt; }
  modal.querySelectorAll('select').forEach(function (s) { s.dispatchEvent(new Event('change')); });
}

function limparModal(modal) {
  var f = modal.querySelector('form');
  if (!f) { return; }
  f.reset();
  f.querySelectorAll('input[type=hidden]:not([name=csrf]):not([name=acao]):not([name=volta])').forEach(function (h) { h.value = ''; });
  f.querySelectorAll('select').forEach(function (s) { s.dispatchEvent(new Event('change')); });
}

function brl(v) {
  return Number(v).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
}

function escapeHtml(s) {
  return String(s).replace(/[&<>"']/g, function (c) {
    return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
  });
}

// PWA: service worker restrito a esta pasta (nao interfere em outros servicos do mesmo servidor)
if ('serviceWorker' in navigator && (location.protocol === 'https:' || location.hostname === 'localhost' || location.hostname === '127.0.0.1')) {
  window.addEventListener('load', function () {
    navigator.serviceWorker.register('sw.php', { scope: './' }).catch(function () {});
  });
}

// Sino de notificacoes (avisos do dia) + notificacao do navegador quando permitida
(function () {
  var sino = document.getElementById('btn-notif-sino');
  if (!sino) { return; }
  var menu = document.getElementById('notif-menu'), lista = document.getElementById('notif-lista'), badge = document.getElementById('notif-badge');
  var vistas = [];
  try { vistas = JSON.parse(localStorage.getItem('finNotifVistas') || '[]'); } catch (e) {}
  function esc(t) { return String(t).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function marcar(id, depois) {
    var fd = new FormData(); fd.append('id', id); fd.append('csrf', window.CSRF);
    fetch('api_notificacoes.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
      badge.textContent = d.nao_lidas; badge.classList.toggle('hidden', !d.nao_lidas);
      if (depois) { depois(); }
    }).catch(function () { if (depois) { depois(); } });
  }
  function avisarNavegador(itens) {
    if (!('Notification' in window) || Notification.permission !== 'granted') { return; }
    itens.filter(function (n) { return !n.lida_em && vistas.indexOf(n.chave) < 0; }).slice(0, 3).forEach(function (n) {
      vistas.push(n.chave);
      var opts = { body: n.mensagem, icon: 'assets/icons/icon-192.png', tag: n.chave, data: { url: n.url || 'index.php' } };
      if (navigator.serviceWorker && navigator.serviceWorker.ready) { navigator.serviceWorker.ready.then(function (reg) { reg.showNotification(n.titulo, opts); }); }
      else { new Notification(n.titulo, opts); }
    });
    try { localStorage.setItem('finNotifVistas', JSON.stringify(vistas.slice(-100))); } catch (e) {}
  }
  function carregar() {
    fetch('api_notificacoes.php', { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
      badge.textContent = d.nao_lidas; badge.classList.toggle('hidden', !d.nao_lidas);
      lista.innerHTML = d.itens.length ? d.itens.map(function (n) {
        return '<a class="notif-item' + (n.lida_em ? '' : ' nova') + '" href="' + esc(n.url || '#') + '" data-id="' + n.id + '"><strong>' + esc(n.titulo) + '</strong><span>' + esc(n.mensagem) + '</span><small>' + esc(n.criada_em.slice(0, 16).replace('T', ' ')) + '</small></a>';
      }).join('') : '<div class="notif-vazio">Nenhum aviso por enquanto.</div>';
      avisarNavegador(d.itens);
    }).catch(function () { lista.innerHTML = '<div class="notif-vazio">Não foi possível carregar.</div>'; });
  }
  sino.addEventListener('click', function (e) { e.preventDefault(); menu.classList.toggle('show'); });
  document.addEventListener('click', function (e) { if (!e.target.closest('#notif-menu') && !e.target.closest('#btn-notif-sino')) { menu.classList.remove('show'); } });
  lista.addEventListener('click', function (e) {
    var a = e.target.closest('.notif-item'); if (!a) { return; }
    e.preventDefault(); var href = a.getAttribute('href');
    marcar(a.dataset.id, function () { window.location.href = href; });
  });
  document.getElementById('notif-ler-todas').addEventListener('click', function (e) { e.preventDefault(); marcar(0, carregar); });
  carregar();

  // Web Push: assina este aparelho (aviso com o aplicativo fechado). O servidor so aceita enderecos dos servicos de push dos navegadores.
  function b64(v) {
    var p = '='.repeat((4 - v.length % 4) % 4), r = atob((v + p).replace(/-/g, '+').replace(/_/g, '/')), o = new Uint8Array(r.length);
    for (var i = 0; i < r.length; i++) { o[i] = r.charCodeAt(i); }
    return o;
  }
  function apiPush(corpo) {
    return fetch('api_push.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.CSRF }, body: JSON.stringify(corpo) }).then(function (r) { return r.json(); });
  }
  function pushSuportado() { return 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window; }
  function registroSw() { // sem service worker (ex.: HTTP) o "ready" nunca resolve: desiste em 3 s
    if (!pushSuportado()) { return Promise.resolve(null); }
    return Promise.race([navigator.serviceWorker.ready, new Promise(function (ok) { setTimeout(function () { ok(null); }, 3000); })]);
  }
  function assinarPush() {
    if (!pushSuportado() || Notification.permission !== 'granted') { return Promise.resolve(false); }
    return fetch('api_push.php', { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (cfg) {
      if (!cfg.disponivel || !cfg.chave) { return false; }
      return registroSw().then(function (reg) {
        if (!reg) { return false; }
        return reg.pushManager.getSubscription().then(function (s) {
          return s || reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: b64(cfg.chave) });
        }).then(function (s) { return apiPush({ acao: 'assinar', assinatura: s.toJSON() }); }).then(function (d) { return !!d.ok; });
      });
    }).catch(function () { return false; });
  }
  // renova a assinatura deste aparelho no maximo 1x por dia (o navegador pode trocar o endereco sem avisar)
  try {
    if (pushSuportado() && Notification.permission === 'granted' && Date.now() - Number(localStorage.getItem('finPushSync') || 0) > 86400000) {
      assinarPush().then(function (ok) { if (ok) { try { localStorage.setItem('finPushSync', String(Date.now())); } catch (e) {} } });
    }
  } catch (e) {}

  // botoes da tela Meu perfil: ativar, enviar teste e desativar neste aparelho
  var btn = document.getElementById('btn-notif'), est = document.getElementById('notif-estado');
  var btnOff = document.getElementById('btn-notif-off'), btnTeste = document.getElementById('btn-notif-teste');
  if (btn) {
    var atualizar = function () {
      if (!('Notification' in window)) { est.textContent = 'Este navegador não suporta notificações.'; btn.disabled = true; return; }
      var perm = Notification.permission;
      registroSw().then(function (reg) { return reg ? reg.pushManager.getSubscription() : null; }).catch(function () { return null; }).then(function (s) {
        var ativo = perm === 'granted' && !!s;
        est.textContent = ativo ? 'Notificações ativadas neste aparelho, inclusive com o aplicativo fechado.'
          : perm === 'granted' ? 'Permitidas no navegador, mas este aparelho ainda não recebe avisos com o aplicativo fechado.'
          : perm === 'denied' ? 'Bloqueadas no navegador: libere nas configurações do site.' : 'Ainda não ativadas neste aparelho.';
        btn.classList.toggle('hidden', ativo || perm === 'denied');
        btnOff.classList.toggle('hidden', !ativo);
        btnTeste.classList.toggle('hidden', !ativo);
      });
    };
    btn.addEventListener('click', function () {
      btn.disabled = true;
      (Notification.permission === 'default' ? Notification.requestPermission() : Promise.resolve(Notification.permission))
        .then(function (p) { return p === 'granted' ? assinarPush() : false; })
        .catch(function () { return false; })
        .then(function () { btn.disabled = false; atualizar(); carregar(); });
    });
    btnOff.addEventListener('click', function () {
      registroSw().then(function (reg) { return reg ? reg.pushManager.getSubscription() : null; }).then(function (s) {
        if (!s) { return; }
        return apiPush({ acao: 'cancelar', endpoint: s.endpoint }).then(function () { return s.unsubscribe(); });
      }).catch(function () {}).then(atualizar);
    });
    btnTeste.addEventListener('click', function () {
      btnTeste.disabled = true;
      apiPush({ acao: 'teste' }).then(function (d) {
        est.textContent = d.erro ? d.erro : (d.ok ? 'Teste enviado: a notificação deve chegar em instantes.' : 'O servidor não conseguiu entregar o teste a este aparelho.');
      }).catch(function () { est.textContent = 'Não foi possível enviar o teste.'; })
        .then(function () { setTimeout(function () { btnTeste.disabled = false; }, 15000); });
    });
    atualizar();
  }
})();
