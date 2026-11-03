// Modal de um investimento (tela Investimentos): busca api_investimento_painel.php e mostra duas visoes:
// "Resumo" (KPIs e graficos, Chart.js) e "Lotes e movimentacoes" (lotes, projecao de 12 meses e historico, com exclusao).
(function () {
  var modal = document.getElementById('modal-painel');
  if (!modal) { return; }
  var charts = {};
  var pedido = 0; // so a resposta do ultimo clique pode desenhar o painel
  var atual = null; // dados do investimento aberto
  var abaInicial = null; // aba a mostrar quando os dados chegarem (reabertura depois de uma acao)
  var $ = function (id) { return document.getElementById(id); };
  var eixoY = { ticks: { callback: function (v) { return brl(v).replace(',00', ''); } }, grid: { color: '#f1f5f9' } };
  var tip = { callbacks: { label: function (c) { return ' ' + c.dataset.label + ': ' + brl(c.parsed.y); } } };
  var ROTULO = { aporte: 'Aplicação', resgate: 'Resgate', rendimento: 'Rendimento', reajuste: 'Reajuste' };

  function dataBR(iso) { var p = String(iso).split('-'); return p[2] + '/' + p[1] + '/' + p[0]; }
  function destruir() { Object.keys(charts).forEach(function (k) { charts[k].destroy(); }); charts = {}; }
  function cor(v) { return v < -0.004 ? 'text-danger' : 'text-success'; }
  function kpi(cls, icone, rotulo, valor, detalhe, colorir) {
    return '<div class="kpi ' + cls + '"><i class="fa ' + icone + ' kpi-icone"></i><div class="kpi-label">' + escapeHtml(rotulo) + '</div>' +
      '<div class="kpi-valor ' + (colorir ? cor(valor) : '') + '">' + brl(valor) + '</div><div class="kpi-detalhe">' + escapeHtml(detalhe) + '</div></div>';
  }

  // mensagem (carregando/erro) ou uma das visoes: 'resumo' | 'mov'
  function tela(qual, html) {
    $('pn-msg').classList.toggle('hidden', qual !== 'msg');
    $('pn-corpo').classList.toggle('hidden', qual !== 'resumo');
    $('pn-mov').classList.toggle('hidden', qual !== 'mov');
    if (qual === 'msg') { $('pn-msg').innerHTML = html; }
    var botao = $('pn-alterna');
    var temResumo = !!(atual && !atual.vazio);
    botao.classList.toggle('hidden', qual === 'msg' || !temResumo);
    botao.querySelector('span').textContent = qual === 'mov' ? 'Voltar ao resumo' : 'Lotes e movimentações';
    botao.querySelector('i').className = 'fa ' + (qual === 'mov' ? 'fa-pie-chart' : 'fa-list');
    botao.dataset.destino = qual === 'mov' ? 'resumo' : 'mov';
    if (qual === 'resumo' && atual && atual.serie) { Object.keys(charts).forEach(function (k) { charts[k].resize(); }); }
  }

  function renderResumo(d) {
    var k = d.kpis, s = d.serie;
    var isento = d.tributacao !== 'ir_regressivo';
    $('pn-kpis').innerHTML =
      kpi('laranja', 'fa-bar-chart', 'Saldo bruto', k.saldo_bruto, 'Como aparece no app do banco', false) +
      kpi('verde', 'fa-money', 'Saldo líquido', k.saldo_liquido, isento ? 'Sem imposto neste investimento' : 'Após IR e IOF estimados: ' + brl(k.impostos), false) +
      kpi('azul', 'fa-line-chart', 'Rendimento bruto acumulado', k.acum_bruto, 'Desde ' + dataBR(d.primeira_data) + ' · aplicado ' + brl(k.aportes) + ' · resgatado ' + brl(k.resgates), true) +
      kpi('verde', 'fa-line-chart', 'Rendimento líquido acumulado', k.acum_liquido, 'Descontado o IR e o IOF pagos e estimados', true) +
      kpi('azul', 'fa-calendar', 'Rendimento bruto do mês', k.mes_bruto, 'Do dia 1 até hoje', true) +
      kpi('verde', 'fa-calendar', 'Rendimento líquido do mês', k.mes_liquido, 'Do dia 1 até hoje', true) +
      kpi('azul', 'fa-calendar-o', 'Rendimento bruto do ano', k.ano_bruto, 'De 1º de janeiro até hoje', true) +
      kpi('verde', 'fa-calendar-o', 'Rendimento líquido do ano', k.ano_liquido, 'De 1º de janeiro até hoje', true);

    charts.saldo = new Chart($('pn-ch-saldo'), {
      type: 'line',
      data: { labels: s.labels, datasets: [
        { label: 'Saldo bruto', data: s.bruto, borderColor: '#d97706', backgroundColor: 'rgba(217,119,6,.10)', fill: true, tension: .25, pointRadius: 2 },
        { label: 'Saldo líquido', data: s.liquido, borderColor: '#16a34a', fill: false, tension: .25, pointRadius: 2 },
        { label: 'Aplicado (aplicações − resgates)', data: s.aplicado, borderColor: '#64748b', borderDash: [5, 4], fill: false, stepped: true, pointRadius: 0 }
      ] },
      options: { maintainAspectRatio: false, interaction: { mode: 'index', intersect: false }, plugins: { tooltip: tip }, scales: { y: eixoY } }
    });
    charts.rend = new Chart($('pn-ch-rend'), {
      type: 'bar',
      data: { labels: s.labels, datasets: [
        { label: 'Rendimento bruto', data: s.rend_bruto, backgroundColor: '#2563eb' },
        { label: 'Rendimento líquido', data: s.rend_liquido, backgroundColor: '#16a34a' }
      ] },
      options: { maintainAspectRatio: false, interaction: { mode: 'index', intersect: false }, plugins: { tooltip: tip }, scales: { y: eixoY } }
    });
    var c = d.composicao;
    var temComp = c.principal > 0 && c.rendimento_liquido >= 0 && c.impostos >= 0;
    $('pn-comp-box').classList.toggle('hidden', !temComp);
    $('pn-comp-vazio').classList.toggle('hidden', temComp);
    if (temComp) {
      charts.comp = new Chart($('pn-ch-comp'), {
        type: 'doughnut',
        data: { labels: ['Valor aplicado', 'Rendimento (líquido de imposto)', 'IR + IOF estimados'], datasets: [{ data: [c.principal, c.rendimento_liquido, c.impostos], backgroundColor: ['#64748b', '#16a34a', '#dc2626'] }] },
        options: { maintainAspectRatio: false, plugins: { tooltip: { callbacks: { label: function (x) { return ' ' + x.label + ': ' + brl(x.parsed); } } } } }
      });
    }
    $('pn-nota').textContent = (s.labels.length >= 36 ? 'Últimos 36 meses. ' : '') + 'Tudo até hoje, sem projeção. O imposto é o que seria retido se resgatasse tudo em cada data.';
  }

  function renderMov(d) {
    $('pn-regras').innerHTML = '<strong>' + escapeHtml(d.rotulo) + '</strong> · ' + escapeHtml(d.regras) + ' · liquidez ' +
      (d.liquidez === 'vencimento' ? 'no vencimento' + (d.vencimento ? ' (' + escapeHtml(dataBR(d.vencimento)) + ')' : '') : 'diária') + '.';

    var ir = d.tributacao === 'ir_regressivo';
    $('pn-lotes-box').classList.toggle('hidden', !d.lotes.length);
    $('pn-lotes').innerHTML = d.lotes.map(function (l) {
      return '<tr><td>' + escapeHtml(dataBR(l.data)) + '</td><td class="num">' + l.dias + '</td><td class="num">' + brl(l.principal) + '</td><td class="num"><strong>' + brl(l.valor) + '</strong></td>' +
        '<td class="num text-success">' + brl(l.ganho) + '</td>' +
        '<td class="num">' + brl(l.iof) + (l.iof_pct > 0 ? ' <small class="text-muted">(' + Math.round(l.iof_pct) + '%)</small>' : '') + '</td>' +
        '<td class="num">' + brl(l.ir) + (l.aliquota_ir > 0 ? ' <small class="text-muted">(' + String(l.aliquota_ir).replace('.', ',') + '%)</small>' : '') + '</td>' +
        '<td class="num"><strong>' + brl(l.liquido) + '</strong></td></tr>';
    }).join('');

    $('pn-proj-box').classList.toggle('hidden', !d.projecao.length);
    $('pn-proj-info').textContent = d.cdi_anual !== null ? '(CDI de hoje: ' + Number(d.cdi_anual).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + '% a.a., incluindo aplicações já agendadas)' : '';
    $('pn-proj').innerHTML = d.projecao.map(function (x) {
      return '<tr><td>' + escapeHtml(x.label) + '</td><td class="num">' + brl(x.bruto) + '</td><td class="num">' + brl(x.impostos) + '</td><td class="num"><strong>' + brl(x.liquido) + '</strong></td></tr>';
    }).join('');

    $('pn-movs-vazio').classList.toggle('hidden', d.movs.length > 0);
    $('pn-movs').innerHTML = d.movs.map(function (m) {
      var res = m.tipo === 'resgate', neg = res || m.valor < 0;
      var badge = m.tipo === 'aporte' ? 'info' : (res ? 'warning' : 'success');
      var aviso = 'Excluir esta movimentação' + (m.ligado ? ' e a transferência de conta ligada a ela' : '') + '?';
      return '<tr><td>' + escapeHtml(dataBR(m.data)) + (m.futuro ? ' <small class="text-muted">(agendado)</small>' : '') + '</td>' +
        '<td><span class="erp-badge erp-badge-' + badge + '">' + escapeHtml(ROTULO[m.tipo] || m.tipo) + '</span>' + (m.ligado ? ' <i class="fa fa-exchange text-muted" title="Ligado a uma transferência de conta"></i>' : '') + '</td>' +
        '<td>' + escapeHtml(m.obs) + '</td>' +
        '<td class="num ' + (neg ? 'text-danger' : 'text-success') + '">' + (neg ? '−' : '+') + ' ' + brl(Math.abs(m.valor)) + '</td>' +
        '<td class="num">' + (res ? brl(m.ir) : '') + '</td><td class="num">' + (res ? brl(m.iof) : '') + '</td>' +
        '<td class="num">' + (res ? '<strong>' + brl(m.valor - m.ir - m.iof) + '</strong>' : '') + '</td>' +
        '<td class="acoes"><form method="post" action="investimentos.php" data-confirmar="' + escapeHtml(aviso) + '"><input type="hidden" name="csrf" value="' + escapeHtml(window.CSRF || '') + '">' +
        '<input type="hidden" name="acao" value="excluir_mov"><input type="hidden" name="mov_id" value="' + m.id + '"><button class="cli-btn-acao btn-acao-excluir" title="Excluir"><i class="fa fa-trash"></i></button></form></td></tr>';
    }).join('');

    var manual = $('pn-manual');
    manual.classList.toggle('hidden', !(d.manual && d.ativo));
    manual.setAttribute('data-preencher', JSON.stringify({ investimento_id: d.id, data: d.hoje }));
  }

  function render(d, aba) {
    atual = d;
    $('pn-titulo').textContent = d.nome;
    $('pn-sub').textContent = d.rotulo + (d.instituicao ? ' · ' + d.instituicao : '') + (d.ativo ? '' : ' · inativo');
    destruir();
    renderMov(d);
    if (d.vazio) { tela('mov'); return; }
    // desenha os graficos com o resumo visivel (canvas oculto nao tem tamanho) e so depois troca para a aba pedida
    tela('resumo');
    renderResumo(d);
    if (aba === 'mov') { tela('mov'); }
  }

  function abrir(id, nome, aba) {
    atual = null;
    destruir();
    $('pn-titulo').textContent = nome || 'Investimento';
    $('pn-sub').textContent = '';
    if (!window.Chart) { tela('msg', '<span class="text-danger">Não foi possível carregar a biblioteca de gráficos. Recarregue a página.</span>'); return; }
    tela('msg', '<span class="text-muted"><i class="fa fa-spinner fa-spin"></i> Carregando…</span>');
    var meu = ++pedido;
    fetch('api_investimento_painel.php?id=' + encodeURIComponent(id), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (meu !== pedido) { return; }
        if (d.erro) { tela('msg', '<span class="text-danger"><i class="fa fa-exclamation-triangle"></i> ' + escapeHtml(d.erro) + '</span>'); return; }
        d.id = Number(id);
        render(d, aba);
      })
      .catch(function () { if (meu === pedido) { tela('msg', '<span class="text-danger">Não foi possível carregar o painel.</span>'); } });
  }

  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-painel]');
    if (b) { abrir(b.getAttribute('data-painel'), b.getAttribute('data-nome'), abaInicial || 'resumo'); abaInicial = null; return; }
    var alterna = e.target.closest('#pn-alterna');
    if (alterna) { tela(alterna.dataset.destino === 'mov' ? 'mov' : 'resumo'); }
  });
  new MutationObserver(function () { if (!modal.classList.contains('show')) { destruir(); } }).observe(modal, { attributes: true, attributeFilter: ['class'] });

  // depois de uma acao (aplicar, resgatar, excluir movimento...) a tela recarrega com ?abrir=ID[&aba=mov]: reabre o painel
  var q = new URLSearchParams(location.search);
  var reabrir = (q.get('abrir') || '').replace(/[^0-9]/g, '');
  var gatilho = reabrir ? document.querySelector('[data-painel="' + reabrir + '"]') : null;
  if (gatilho) {
    // abre direto (sem gatilho.click()): este script roda antes do DOMContentLoaded, quando o handler de [data-modal] do app.js ainda
    // nao existe e o clique no <a href="?abrir=ID"> seria seguido pelo navegador, recarregando a pagina em loop
    if (window.history && history.replaceState) { history.replaceState(null, '', location.pathname); }
    modal.classList.add('show');
    abrir(gatilho.getAttribute('data-painel'), gatilho.getAttribute('data-nome'), q.get('aba') === 'mov' ? 'mov' : 'resumo');
  }
})();
