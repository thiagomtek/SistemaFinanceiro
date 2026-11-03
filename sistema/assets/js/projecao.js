// Tela de projecao: busca /api_projecao.php e desenha graficos + tabela.
(function () {
  var charts = {};
  var $ = function (id) { return document.getElementById(id); };
  var eixoY = { ticks: { callback: function (v) { return brl(v).replace(',00', ''); } }, grid: { color: '#f1f5f9' } };

  function dataBR(iso) { var p = String(iso).split('-'); return p[2] + '/' + p[1] + '/' + p[0]; }
  function destruir(nome) { if (charts[nome]) { charts[nome].destroy(); } }

  function renderizar(d) {
    var s = d.serie;
    var ultimo = s[s.length - 1];
    var menor = s.reduce(function (a, b) { return b.saldo < a.saldo ? b : a; }, s[0]);

    var patHoje = d.saldo_atual + d.investido_atual;
    var patHojeLiq = d.saldo_atual + d.investido_liquido_atual;
    var delta = function (v) { return (v >= 0 ? '+ ' : '− ') + brl(Math.abs(v)); };
    $('k-pat-hoje').textContent = brl(patHoje);
    $('k-pat-hoje-d').textContent = 'Contas ' + brl(d.saldo_atual) + ' · investimentos ' + brl(d.investido_atual) + ' · líquido ' + brl(patHojeLiq);
    $('k-pat').textContent = brl(ultimo.patrimonio);
    $('k-pat-d').textContent = delta(ultimo.patrimonio - patHoje) + ' em ' + s.length + ' meses · líquido ' + brl(ultimo.patrimonio_liquido);
    $('k-inv-fim').textContent = brl(ultimo.investido);
    $('k-inv-fim-d').textContent = 'Hoje ' + brl(d.investido_atual) + ' · líquido ao fim ' + brl(ultimo.investido_liquido);
    $('k-final').textContent = brl(d.saldo_atual) + ' → ' + brl(ultimo.saldo);
    $('k-final').style.fontSize = '17px';
    $('k-final').className = 'kpi-valor ' + (menor.saldo < 0 ? 'text-danger' : '');
    $('k-final-d').textContent = 'Menor saldo no período: ' + brl(menor.saldo) + ' em ' + menor.label + ' (a sobra de cada mês é investida)';
    $('k-min-box').className = 'kpi ' + (menor.saldo < 0 ? 'vermelho' : 'azul');

    // alerta so no DIA em que a conta fica negativa (simulacao dia a dia); o total do mes nao basta, pode haver investimentos
    var al = d.alerta_negativo;
    $('p-alerta').innerHTML = al
      ? '<div class="alert alert-danger"><i class="fa fa-exclamation-triangle"></i> A conta ficará <strong>negativa em ' + escapeHtml(dataBR(al.data)) + '</strong> (saldo previsto ' + brl(al.saldo) + '). ' +
        (al.investido_liquido > 0 ? 'Você tem ' + brl(al.investido_liquido) + ' em investimentos (líquido de IR/IOF) que podem cobrir com um resgate; ' : '') +
        'ou antecipe receitas / adie despesas.</div>'
      : '';

    destruir('saldo');
    charts.saldo = new Chart($('ch-saldo'), {
      type: 'line',
      data: {
        labels: s.map(function (x) { return x.label; }),
        datasets: [
          { label: 'Saldo em contas', data: s.map(function (x) { return x.saldo; }), borderColor: '#2563eb', backgroundColor: 'rgba(37,99,235,.10)', fill: true, tension: .25, pointRadius: 3 },
          { label: 'Patrimônio bruto (contas + investimentos)', data: s.map(function (x) { return x.patrimonio; }), borderColor: '#d97706', borderDash: [6, 4], fill: false, tension: .25, pointRadius: 2 },
          { label: 'Patrimônio líquido de IR/IOF', data: s.map(function (x) { return x.patrimonio_liquido; }), borderColor: '#16a34a', borderDash: [2, 3], fill: false, tension: .25, pointRadius: 2 }
        ]
      },
      options: { maintainAspectRatio: false, interaction: { mode: 'index', intersect: false }, plugins: { tooltip: { callbacks: { label: function (c) { return ' ' + c.dataset.label + ': ' + brl(c.parsed.y); } } } }, scales: { y: eixoY, x: { grid: { display: false } } } }
    });

    destruir('fluxo');
    charts.fluxo = new Chart($('ch-fluxo'), {
      type: 'bar',
      data: {
        labels: s.map(function (x) { return x.label; }),
        datasets: [
          { label: 'Receitas', data: s.map(function (x) { return x.receitas_mes; }), backgroundColor: '#16a34a', borderRadius: 3, stack: 'in' },
          { label: 'Despesas', data: s.map(function (x) { return -x.despesas_mes; }), backgroundColor: '#dc2626', borderRadius: 3, stack: 'out' },
          { label: 'Faturas de cartão', data: s.map(function (x) { return -x.faturas_mes; }), backgroundColor: '#9333ea', borderRadius: 3, stack: 'out' }
        ]
      },
      options: { maintainAspectRatio: false, interaction: { mode: 'index', intersect: false }, plugins: { tooltip: { callbacks: { label: function (c) { return ' ' + c.dataset.label + ': ' + brl(Math.abs(c.parsed.y)); } } } }, scales: { y: eixoY, x: { grid: { display: false } } } }
    });

    destruir('inv');
    $('p-cdi-info').textContent = '(CDI ' + Number(d.cdi_anual).toLocaleString('pt-BR', { maximumFractionDigits: 2 }) + '% a.a.)';
    charts.inv = new Chart($('ch-inv'), {
      type: 'line',
      data: {
        labels: s.map(function (x) { return x.label; }),
        datasets: [
          { label: 'Investido bruto', data: s.map(function (x) { return x.investido; }), borderColor: '#d97706', backgroundColor: 'rgba(217,119,6,.10)', fill: true, tension: .25, pointRadius: 2 },
          { label: 'Investido líquido', data: s.map(function (x) { return x.investido_liquido; }), borderColor: '#16a34a', fill: false, tension: .25, pointRadius: 2 }
        ]
      },
      options: { maintainAspectRatio: false, interaction: { mode: 'index', intersect: false }, plugins: { tooltip: { callbacks: { label: function (c) { return ' ' + c.dataset.label + ': ' + brl(c.parsed.y); } } } }, scales: { y: eixoY, x: { grid: { display: false } } } }
    });
    $('t-inv').querySelector('tbody').innerHTML = (d.investimentos || []).map(function (i) {
      var u = i.serie[i.serie.length - 1];
      return '<tr><td><strong>' + escapeHtml(i.nome) + '</strong><br><small class="text-muted">' + escapeHtml(i.rotulo) + '</small></td><td class="num">' + brl(i.saldo_atual) + '</td><td class="num">' + brl(u.bruto) + '</td><td class="num"><strong>' + brl(u.liquido) + '</strong></td></tr>';
    }).join('') || '<tr><td colspan="4" class="text-muted">Sem investimentos ativos.</td></tr>';

    var tb = $('t-proj').querySelector('tbody');
    tb.innerHTML = s.map(function (x) {
      var cls = function (v) { return v < 0 ? ' text-danger' : ''; };
      var jaFeito = function (v) { return v > 0 ? ' title="Já realizado neste mês: ' + brl(v) + '"' : ''; };
      var feitoInv = function (r) {
        var t = [];
        if (r.aplicacoes > 0) { t.push('aplicado ' + brl(r.aplicacoes)); }
        if (r.resgates > 0) { t.push('resgatado ' + brl(r.resgates)); }
        return t.length ? ' title="Já realizado neste mês: ' + t.join(', ') + '"' : '';
      };
      return '<tr><td><strong>' + escapeHtml(x.label) + '</strong></td>' +
        '<td class="num text-success"' + jaFeito(x.realizado.receitas) + '>' + brl(x.receitas_mes) + '</td>' +
        '<td class="num"' + jaFeito(x.realizado.despesas) + '>' + brl(x.despesas_mes) + '</td>' +
        '<td class="num"' + jaFeito(x.realizado.faturas) + '>' + brl(x.faturas_mes) + '</td>' +
        '<td class="num"' + feitoInv(x.realizado) + '>' + (Math.abs(x.invest_mes) > 0.004 ? brl(x.invest_mes) : '—') + '</td>' +
        '<td class="num' + cls(x.resultado_mes) + '">' + brl(x.resultado_mes) + '</td>' +
        '<td class="num">' + (x.aporte > 0 ? brl(x.aporte) : '—') + '</td>' +
        '<td class="num' + cls(x.saldo) + '"><strong>' + brl(x.saldo) + '</strong></td>' +
        '<td class="num">' + brl(x.investido) + '</td>' +
        '<td class="num">' + brl(x.patrimonio) + '</td>' +
        '<td class="num">' + brl(x.patrimonio_liquido) + '</td></tr>';
    }).join('');
  }

  function carregar() {
    var btn = $('p-ok');
    btn.disabled = true;
    $('p-carregando').classList.remove('hidden');
    $('p-conteudo').classList.add('hidden');
    var qs = 'meses=' + encodeURIComponent($('p-meses').value) +
      '&media=' + ($('p-media').checked ? '1' : '0') +
      '&aporte=' + encodeURIComponent($('p-aporte').value || '0') +
      '&pct=' + encodeURIComponent($('p-pct').value || '100') +
      '&cdi=' + encodeURIComponent($('p-cdi').value || '');
    fetch('api_projecao.php?' + qs, { credentials: 'same-origin' })
      .then(function (r) { if (!r.ok) { throw new Error('HTTP ' + r.status); } return r.json(); })
      .then(function (d) { renderizar(d); $('p-carregando').classList.add('hidden'); $('p-conteudo').classList.remove('hidden'); })
      .catch(function (e) { $('p-carregando').innerHTML = '<div class="alert alert-danger">Não foi possível calcular a projeção (' + escapeHtml(e.message) + ').</div>'; })
      .then(function () { btn.disabled = false; });
  }

  $('p-ok').addEventListener('click', carregar);
  $('p-meses').addEventListener('change', carregar);
  $('p-media').addEventListener('change', carregar);
  carregar();
})();
