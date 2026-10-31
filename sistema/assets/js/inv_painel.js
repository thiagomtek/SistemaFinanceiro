// Modal "KPIs e gráficos" de um investimento (tela Investimentos): busca api_investimento_painel.php e desenha com Chart.js.
(function () {
  var modal = document.getElementById('modal-painel');
  if (!modal) { return; }
  var charts = {};
  var pedido = 0; // so a resposta do ultimo clique pode desenhar o painel
  var $ = function (id) { return document.getElementById(id); };
  var eixoY = { ticks: { callback: function (v) { return brl(v).replace(',00', ''); } }, grid: { color: '#f1f5f9' } };
  var tip = { callbacks: { label: function (c) { return ' ' + c.dataset.label + ': ' + brl(c.parsed.y); } } };

  function dataBR(iso) { var p = String(iso).split('-'); return p[2] + '/' + p[1] + '/' + p[0]; }
  function destruir() { Object.keys(charts).forEach(function (k) { charts[k].destroy(); }); charts = {}; }
  function cor(v) { return v < -0.004 ? 'text-danger' : 'text-success'; }
  function kpi(cls, icone, rotulo, valor, detalhe, colorir) {
    return '<div class="kpi ' + cls + '"><i class="fa ' + icone + ' kpi-icone"></i><div class="kpi-label">' + escapeHtml(rotulo) + '</div>' +
      '<div class="kpi-valor ' + (colorir ? cor(valor) : '') + '">' + brl(valor) + '</div><div class="kpi-detalhe">' + escapeHtml(detalhe) + '</div></div>';
  }

  function mostrar(corpo, html) { $('pn-corpo').classList.toggle('hidden', !corpo); $('pn-msg').classList.toggle('hidden', corpo); if (!corpo) { $('pn-msg').innerHTML = html; } }

  function render(d) {
    $('pn-titulo').textContent = d.nome;
    $('pn-sub').textContent = d.rotulo + (d.instituicao ? ' · ' + d.instituicao : '') + (d.ativo ? '' : ' · inativo');
    if (d.vazio) { mostrar(false, '<span class="text-muted">Este investimento ainda não tem movimentações até hoje.</span>'); return; }
    mostrar(true);
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

    destruir();
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

  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-painel]');
    if (!b) { return; }
    var id = b.getAttribute('data-painel');
    $('pn-detalhe').setAttribute('href', 'investimentos.php?id=' + encodeURIComponent(id) + '#detalhe');
    destruir();
    $('pn-titulo').textContent = b.getAttribute('data-nome') || 'Investimento';
    $('pn-sub').textContent = '';
    if (!window.Chart) { mostrar(false, '<span class="text-danger">Não foi possível carregar a biblioteca de gráficos. Recarregue a página.</span>'); return; }
    mostrar(false, '<span class="text-muted"><i class="fa fa-spinner fa-spin"></i> Carregando…</span>');
    var meu = ++pedido;
    fetch('api_investimento_painel.php?id=' + encodeURIComponent(id), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (meu !== pedido) { return; }
        if (d.erro) { mostrar(false, '<span class="text-danger"><i class="fa fa-exclamation-triangle"></i> ' + escapeHtml(d.erro) + '</span>'); return; }
        render(d);
      })
      .catch(function () { if (meu === pedido) { mostrar(false, '<span class="text-danger">Não foi possível carregar o painel.</span>'); } });
  });
  new MutationObserver(function () { if (!modal.classList.contains('show')) { destruir(); } }).observe(modal, { attributes: true, attributeFilter: ['class'] });
})();
