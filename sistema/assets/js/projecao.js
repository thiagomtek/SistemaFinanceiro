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

    $('p-media-valores').textContent = $('p-media').checked
      ? 'despesas ' + brl(d.media_despesas) + ' por mês · receitas ' + brl(d.media_receitas) + ' por mês'
      : 'desligada (a projeção usa só o que está agendado)';
    $('p-media-meses').textContent = (d.media_meses || []).join(', ');

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
      var tipInv = function (v) {
        return Math.abs(v) > 0.004 ? ' title="Fora do resultado (só muda o dinheiro de lugar): ' + (v < 0 ? 'aplicado ' : 'resgatado ') + brl(Math.abs(v)) + ' em investimentos neste mês"' : '';
      };
      return '<tr><td><strong>' + escapeHtml(x.label) + '</strong></td>' +
        '<td class="num text-success"' + jaFeito(x.realizado.receitas) + '>' + brl(x.receitas_mes) + '</td>' +
        '<td class="num"' + jaFeito(x.realizado.despesas) + '>' + brl(x.despesas_mes) + '</td>' +
        '<td class="num"' + jaFeito(x.realizado.faturas) + '>' + brl(x.faturas_mes) + '</td>' +
        '<td class="num' + cls(x.resultado_mes) + '"' + tipInv(x.invest_mes) + '>' + brl(x.resultado_mes) + '</td>' +
        '<td class="num text-success"' + (x.rendimento_aportes > 0.004 ? ' title="Dos aportes da sobra já feitos: ' + brl(x.rendimento_aportes) + '"' : '') + '>' + (Math.abs(x.rendimento_mes) > 0.004 ? brl(x.rendimento_mes) : '—') + '</td>' +
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
      .then(function (d) { renderizar(d); $('p-carregando').classList.add('hidden'); $('p-conteudo').classList.remove('hidden'); carregarRisco(qs); })
      .catch(function (e) { $('p-carregando').innerHTML = '<div class="alert alert-danger">Não foi possível calcular a projeção (' + escapeHtml(e.message) + ').</div>'; })
      .then(function () { btn.disabled = false; });
  }

  // risco do patrimonio frente a Selic: calculo a parte (5 projecoes), carregado depois da tela principal
  function carregarRisco(qs) {
    var pct = function (v) { return Number(v).toFixed(1).replace('.', ',') + '%'; };
    var sinal = function (v) { return (v > 0.004 ? '+ ' : (v < -0.004 ? '− ' : '')) + brl(Math.abs(v)); };
    var cor = function (v) { return v > 0.004 ? ' text-success' : (v < -0.004 ? ' text-danger' : ''); };
    $('p-risco-corpo').classList.add('hidden');
    $('p-risco-carregando').classList.remove('hidden');
    $('p-risco-carregando').innerHTML = '<i class="fa fa-spinner fa-spin"></i> Calculando os cenários…';
    fetch('api_projecao_risco.php?' + qs, { credentials: 'same-origin' })
      .then(function (r) { if (!r.ok) { throw new Error('HTTP ' + r.status); } return r.json(); })
      .then(function (d) {
        var b = d.base;
        var baixo = d.cenarios[1], alto = d.cenarios[3];
        $('p-risco-info').textContent = '· horizonte de ' + b.meses + ' meses, CDI base de ' + String(b.cdi).replace('.', ',') + '% a.a.';
        if (Math.abs(d.sensibilidade_pp) < 0.005) {
          $('p-risco-resumo').innerHTML = 'Com os investimentos de hoje, mudanças na Selic <strong>não alteram</strong> o patrimônio ao fim do horizonte: nada do que está investido rende por CDI (veja o tipo de rendimento abaixo).';
        } else {
          $('p-risco-resumo').innerHTML = 'Cada <strong>1 p.p.</strong> de Selic muda o patrimônio ao fim do horizonte em cerca de <strong>' + brl(Math.abs(d.sensibilidade_pp)) + '</strong>. ' +
            'Se a Selic cair 2 p.p., o patrimônio ao fim fica em <strong>' + brl(baixo.patrimonio_fim) + '</strong> (' + sinal(baixo.dif_patrimonio) + ', ' + pct(baixo.dif_pct) + ' em relação ao cenário base); ' +
            'se subir 2 p.p., fica em <strong>' + brl(alto.patrimonio_fim) + '</strong> (' + sinal(alto.dif_patrimonio) + ').';
        }
        $('t-risco').querySelector('tbody').innerHTML = d.cenarios.map(function (c) {
          var base = c.delta === 0;
          return '<tr' + (base ? ' style="font-weight:700"' : '') + '><td>' + escapeHtml(c.rotulo) + '</td><td class="num">' + String(c.selic).replace('.', ',') + '%</td><td class="num">' + String(c.cdi).replace('.', ',') + '%</td>' +
            '<td class="num">' + brl(c.patrimonio_fim) + '</td><td class="num' + cor(c.dif_patrimonio) + '">' + (base ? '—' : sinal(c.dif_patrimonio)) + '</td>' +
            '<td class="num' + cor(c.dif_pct) + '">' + (base ? '—' : pct(c.dif_pct)) + '</td><td class="num">' + brl(c.rendimento_total) + '</td></tr>';
        }).join('');
        var e = d.exposicao;
        $('t-risco-idx').querySelector('tbody').innerHTML = e.por_indexador.map(function (x) {
          return '<tr><td>' + escapeHtml(x.rotulo) + '</td><td class="num">' + brl(x.valor) + '</td><td class="num">' + pct(x.pct) + '</td><td>' + escapeHtml(x.efeito) + '</td></tr>';
        }).join('') || '<tr><td colspan="4" class="text-muted">Sem investimentos ativos.</td></tr>';
        $('t-risco-inst').querySelector('tbody').innerHTML = e.por_instituicao.map(function (x) {
          var alto = x.uso_fgc_pct >= 80;
          return '<tr><td>' + escapeHtml(x.instituicao) + '</td><td class="num">' + brl(x.saldo) + '</td><td class="num">' + pct(x.pct) + '</td><td class="num">' + brl(x.renda_fixa) + '</td>' +
            '<td class="num' + (alto ? ' text-danger' : '') + '">' + (x.renda_fixa > 0 ? pct(x.uso_fgc_pct) : '—') + '</td></tr>';
        }).join('') || '<tr><td colspan="5" class="text-muted">Sem investimentos ativos.</td></tr>';
        $('p-risco-carregando').classList.add('hidden');
        $('p-risco-corpo').classList.remove('hidden');
      })
      .catch(function (e) { $('p-risco-carregando').innerHTML = '<span class="text-danger">Não foi possível calcular os cenários (' + escapeHtml(e.message) + ').</span>'; });
  }

  $('p-ok').addEventListener('click', carregar);
  $('p-meses').addEventListener('change', carregar);
  $('p-media').addEventListener('change', carregar);
  carregar();
})();
