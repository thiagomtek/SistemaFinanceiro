// Tela "Cenario e risco": busca /api_cenario.php (indicadores, risco consolidado, dolarizacao, leitura do dia, inflacao percebida e historico).
(function () {
  var $ = function (id) { return document.getElementById(id); };
  var NV = { 1: 'success', 2: 'success', 3: 'warning', 4: 'danger', 5: 'danger' };
  var KPI_COR = { 1: 'verde', 2: 'verde', 3: 'laranja', 4: 'vermelho', 5: 'vermelho' };
  var rotulos = {};

  function num(v, c) { return v === null || v === undefined ? '—' : Number(v).toLocaleString('pt-BR', { minimumFractionDigits: c, maximumFractionDigits: c }); }
  function pc(v, c) { return v === null || v === undefined ? '—' : num(v, c === undefined ? 2 : c) + '%'; }
  function sinal(v, c) { return v === null || v === undefined ? '—' : (v > 0 ? '+' : '') + num(v, c === undefined ? 2 : c) + '%'; }
  function dataBR(iso) { var p = String(iso).slice(0, 10).split('-'); return p[2] + '/' + p[1] + '/' + p[0]; }
  function badge(n) {
    if (n === null || n === undefined) { return '<span class="erp-badge erp-badge-info">Sem dado</span>'; }
    return '<span class="erp-badge erp-badge-' + NV[n] + '">' + n + ' · ' + escapeHtml(rotulos[n] || '') + '</span>';
  }
  function link(url) { return /^https?:\/\//.test(url) ? url : '#'; }

  function kpi(cls, icone, rotulo, valor, detalhe) {
    return '<div class="kpi ' + cls + '"><i class="fa ' + icone + ' kpi-icone"></i><div class="kpi-label">' + escapeHtml(rotulo) + '</div><div class="kpi-valor">' + valor + '</div><div class="kpi-detalhe">' + escapeHtml(detalhe) + '</div></div>';
  }

  function renderKpis(d) {
    var i = d.indicadores, r = d.risco, f = i.focus;
    $('c-kpis').innerHTML =
      kpi(KPI_COR[r.nivel_geral] || 'azul', 'fa-shield', 'Risco geral do patrimônio', escapeHtml(rotulos[r.nivel_geral] || '—'), 'Nível ' + r.nivel_geral + ' de 5 · patrimônio ' + brl(r.exposicao.patrimonio)) +
      kpi('azul', 'fa-percent', 'Selic · CDI', pc(i.selic) + ' · ' + pc(i.cdi), 'Focus para a Selic: ' + pc(f.selic_ano) + ' este ano, ' + pc(f.selic_ano_seguinte) + ' no seguinte') +
      kpi('laranja', 'fa-line-chart', 'IPCA em 12 meses', pc(i.ipca_12m && i.ipca_12m.valor), 'Mês: ' + pc(i.ipca_mes && i.ipca_mes.valor) + ' · esperado em 12 meses: ' + pc(i.ipca_esperado_12m)) +
      kpi('verde', 'fa-balance-scale', 'Juro real (CDI − IPCA)', pc(i.juro_real_12m, 1) + ' a.a.', 'Com o IPCA esperado: ' + pc(i.juro_real_esperado, 1) + ' a.a.') +
      kpi('azul', 'fa-usd', 'Dólar', i.dolar ? brl(i.dolar.valor).replace('R$', 'R$') : '—', '12 meses: ' + sinal(i.dolar_var_12m, 1) + ' · Focus no fim do ano: ' + (f.cambio_ano ? brl(f.cambio_ano) : '—'));
    $('c-atualizado').textContent = i.atualizado_em ? ' Indicadores atualizados em ' + dataBR(i.atualizado_em) + ' ' + String(i.atualizado_em).slice(11, 16) + '.' : '';
  }

  function renderDimensoes(r) {
    $('c-risco-info').textContent = '· ' + r.dimensoes.filter(function (x) { return x.nivel !== null; }).length + ' de ' + r.dimensoes.length + ' dimensões com dado';
    $('c-dimensoes').innerHTML = r.dimensoes.map(function (x) {
      return '<div class="dim-card nv-' + (x.nivel || 0) + '"><div class="dim-topo"><strong>' + escapeHtml(x.rotulo) + '</strong>' + badge(x.nivel) + '</div><div class="dim-motivo">' + escapeHtml(x.motivo) + '</div></div>';
    }).join('');
  }

  function renderDolar(r, indic) {
    var d = r.dolarizacao;
    var cor = d.sinal === 'avaliar_aumentar' ? 'warning' : (d.sinal === 'sem_pressa' ? 'success' : 'info');
    $('c-dolar').innerHTML =
      '<p style="margin:0 0 8px;font-size:14px"><span class="erp-badge erp-badge-' + cor + '">' + escapeHtml(d.titulo) + '</span> <span class="text-muted" style="font-size:12px">saldo dos fatores: ' + (d.pontos > 0 ? '+' : '') + d.pontos + '</span></p>' +
      '<p style="margin:0 0 8px;font-size:13px">Hoje <strong>' + num(d.exposicao_pct, 1) + '%</strong> do seu patrimônio está em dólar (investimentos marcados com exposição cambial em dólar).</p>' +
      '<ul class="lista-fatores">' + (d.fatores.length ? d.fatores.map(function (f) {
        return '<li><i class="fa ' + (f.pontos > 0 ? 'fa-plus-circle text-warning' : 'fa-minus-circle text-success') + '"></i> ' + escapeHtml(f.texto) + '</li>';
      }).join('') : '<li class="text-muted">Nenhum fator pesou a favor ou contra hoje.</li>') + '</ul>' +
      '<p style="margin:10px 0 4px;font-size:13px"><strong>Como dolarizar:</strong></p><ul class="lista-fatores">' + d.como.map(function (c) { return '<li>' + escapeHtml(c) + '</li>'; }).join('') + '</ul>' +
      '<p class="text-muted" style="font-size:12px;margin:10px 0 0">Cada fator vale +1 (a favor de aumentar a parcela em dólar) ou −1 (contra); a regra é simples e aberta. Quanto colocar em dólar depende do seu objetivo, prazo e tolerância a oscilação, e esta tela não é recomendação de investimento. Para dizer que um investimento está em dólar, edite-o em Investimentos e marque a exposição cambial.</p>';
  }

  var SINAL = { aumentar: ['info', 'Avaliar aumentar'], manter: ['success', 'Manter'], reduzir: ['warning', 'Avaliar reduzir'], aguardar: ['secondary', 'Aguardar'], priorizar_reserva: ['danger', 'Reforçar a reserva antes'] };
  var TOL = { conservador: 'Conservador', moderado: 'Moderado', arrojado: 'Arrojado' };
  var aloc = null;

  function renderAloc(a) {
    aloc = a;
    var r = a.reserva;
    var h = '<p style="font-size:13.5px;margin:0 0 10px">Perfil <strong>' + escapeHtml(TOL[a.perfil.tolerancia]) + '</strong>' + (a.perfil.personalizado ? '' : ' (padrão: revise em "Perfil e faixas")') + '. Total investido: <strong>' + brl(a.total_investido) + '</strong>. Cada classe é comparada com a faixa de referência que você definiu e com os fatores do momento.</p>';
    h += '<div class="reserva-box ' + (r.baixa ? 'baixa' : 'ok') + '"><i class="fa ' + (r.baixa ? 'fa-exclamation-triangle' : 'fa-check-circle') + '"></i> <strong>Reserva de emergência:</strong> ' +
      (r.meses === null ? 'sem histórico de despesas para calcular' : num(r.meses, 1) + ' meses de despesas (' + brl(r.liquido) + ' em liquidez diária ÷ média de ' + brl(r.media_despesas) + ' por mês), alvo de ' + r.alvo + ' meses') +
      (r.baixa ? '. <strong>Enquanto estiver abaixo do alvo, a tela não sugere aumentar risco.</strong>' : '.') + '</div>';
    h += '<div class="dim-grid">' + a.classes.map(function (c) {
      var s = SINAL[c.sinal] || SINAL.manter;
      var barra = '<div class="faixa-barra" title="Faixa de referência ' + c.faixa[0] + '% a ' + c.faixa[1] + '% · hoje ' + num(c.pct, 1) + '%"><div class="faixa-ref" style="left:' + Math.min(100, c.faixa[0]) + '%;width:' + Math.max(1, Math.min(100, c.faixa[1]) - Math.min(100, c.faixa[0])) + '%"></div><div class="faixa-atual" style="left:' + Math.min(99.2, c.pct) + '%"></div></div>';
      return '<div class="dim-card nv-' + (c.sinal === 'reduzir' || c.sinal === 'priorizar_reserva' ? 4 : (c.sinal === 'aumentar' ? 2 : (c.sinal === 'aguardar' ? 3 : 1))) + '"><div class="dim-topo"><strong>' + escapeHtml(c.rotulo) + '</strong><span class="erp-badge erp-badge-' + s[0] + '">' + escapeHtml(s[1]) + '</span></div>' +
        '<div class="dim-motivo"><strong>' + num(c.pct, 1) + '%</strong> (' + brl(c.valor) + ') · faixa ' + c.faixa[0] + '% a ' + c.faixa[1] + '%' + barra + '<span class="text-muted">' + escapeHtml(c.indicacao) + '</span>' +
        (c.fatores.length ? '<ul class="lista-fatores" style="margin-top:6px">' + c.fatores.map(function (f) { return '<li><i class="fa ' + (f.pontos > 0 ? 'fa-plus-circle text-warning' : 'fa-minus-circle text-success') + '"></i> ' + escapeHtml(f.texto) + '</li>'; }).join('') + '</ul>' : '<div class="text-muted" style="margin-top:6px;font-size:12px">Nenhum fator do momento pesa a favor ou contra.</div>') +
        '<div class="text-muted" style="font-size:11.5px;margin-top:4px">' + escapeHtml(c.descricao) + '</div></div></div>';
    }).join('') + '</div>';

    var t = a.tesouro;
    if (t && (t.ipca_6 || t.pre_6)) {
      var linha = function (rot, x, suf) { return x ? '<tr><td>' + rot + '</td><td class="num">' + (x.vencimento ? dataBR(x.vencimento).slice(3) : '—') + '</td><td class="num"><strong>' + num(x.taxa, 2) + '%</strong> ' + suf + '</td></tr>' : ''; };
      h += '<h5 style="font-size:13.5px;margin:18px 0 8px">Renda fixa de prazo: o que o Tesouro paga hoje' + (t.data_base ? ' <small class="text-muted" style="font-weight:400">(dados de ' + dataBR(t.data_base) + ')</small>' : '') + '</h5>' +
        '<div class="table-responsive"><table class="table table-striped table-hover m-0"><thead><tr><th>Título</th><th class="num">Vencimento</th><th class="num">Taxa de compra</th></tr></thead><tbody>' +
        linha('Tesouro IPCA+ (curto)', t.ipca_3, 'acima do IPCA') + linha('Tesouro IPCA+ (longo)', t.ipca_6, 'acima do IPCA') + linha('Tesouro Prefixado (curto)', t.pre_3, 'ao ano') + linha('Tesouro Prefixado (longo)', t.pre_6, 'ao ano') + '</tbody></table></div>';
      if (t.breakeven_6 !== null) {
        h += '<p style="font-size:13px;margin:8px 0 0">A inflação <strong>implícita</strong> nos prazos longos (prefixado contra IPCA+) é de <strong>' + pc(t.breakeven_6) + '</strong> ao ano' + (a.indicadores.ipca_esperado !== null ? ', contra IPCA esperado de ' + pc(a.indicadores.ipca_esperado) + ' no próximo ano' : '') +
          '. Se a inflação ficar abaixo da implícita, o prefixado rende mais que o IPCA+; se ficar acima, o IPCA+ rende mais. Títulos de prazo podem ter marcação a mercado negativa se vendidos antes do vencimento.</p>';
      }
    }
    var im = a.imovel;
    if (im) {
      h += '<h5 style="font-size:13.5px;margin:18px 0 8px">Imóvel para aluguel: quanto o aluguel precisa render</h5>' +
        '<p style="font-size:13px;margin:0">Para um imóvel empatar com a renda fixa, o retorno total precisa passar do CDI líquido de IR (<strong>' + pc(im.cdi_liquido) + '</strong> ao ano, com IR de 15%). Se o imóvel valorizar só com a inflação esperada (' + pc(im.valorizacao) + '), o <strong>aluguel líquido</strong> (depois de IR, vacância, condomínio e IPTU) precisa render pelo menos <strong>' + pc(im.aluguel_minimo_ano) + ' ao ano</strong> do valor do imóvel (cerca de ' + pc(im.aluguel_minimo_mes) + ' ao mês). Compare com o aluguel que você consegue de fato.</p>';
    }
    h += '<p class="text-muted" style="font-size:12px;margin:12px 0 0">Indicações por <strong>classe</strong>, nunca por produto ou papel. A regra é aberta (cada fator vale +1 ou −1 e aparece acima) e usa dados oficiais e a leitura das manchetes; ela não conhece seus objetivos, prazos, impostos e outros bens. <strong>Não é recomendação de investimento:</strong> use como ponto de partida para decidir, de preferência com um profissional habilitado.</p>';
    $('c-aloc').innerHTML = h;
  }

  function abrirPerfil() {
    if (!aloc) { return; }
    var p = aloc.perfil;
    $('pf-tol').value = p.tolerancia;
    $('pf-reserva').value = p.reserva_meses;
    $('pf-padrao').checked = p.faixas_editadas.length === 0;
    $('pf-erro').textContent = '';
    $('pf-tab').querySelector('tbody').innerHTML = aloc.classes.map(function (c) {
      return '<tr><td>' + escapeHtml(c.rotulo) + '</td><td class="num"><input class="form-control" style="width:90px;display:inline-block" type="number" min="0" max="100" step="1" data-k="' + c.chave + '" data-t="min" value="' + c.faixa[0] + '"></td>' +
        '<td class="num"><input class="form-control" style="width:90px;display:inline-block" type="number" min="0" max="100" step="1" data-k="' + c.chave + '" data-t="max" value="' + c.faixa[1] + '"></td></tr>';
    }).join('');
    aplicarPadrao();
  }
  function aplicarPadrao() {
    var usar = $('pf-padrao').checked;
    var pad = aloc.padroes[$('pf-tol').value];
    $('pf-tab').querySelectorAll('input').forEach(function (i) {
      if (usar) { i.value = pad[i.dataset.k][i.dataset.t === 'min' ? 0 : 1]; }
      i.disabled = usar;
    });
  }
  $('pf-tol').addEventListener('change', aplicarPadrao);
  $('pf-padrao').addEventListener('change', aplicarPadrao);
  document.addEventListener('click', function (e) { if (e.target.closest('[data-modal="modal-perfil"]')) { abrirPerfil(); } });
  $('pf-salvar').addEventListener('click', function () {
    var faixas = {};
    if (!$('pf-padrao').checked) {
      $('pf-tab').querySelectorAll('input').forEach(function (i) {
        faixas[i.dataset.k] = faixas[i.dataset.k] || [0, 0];
        faixas[i.dataset.k][i.dataset.t === 'min' ? 0 : 1] = Number(i.value);
      });
    }
    var fd = new FormData();
    fd.append('csrf', window.CSRF);
    fd.append('dados', JSON.stringify({ tolerancia: $('pf-tol').value, reserva_meses: Number($('pf-reserva').value), faixas: faixas }));
    fetch('api_cenario_perfil.php', { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (x) {
        if (!x.ok) { $('pf-erro').textContent = x.j.erro || 'Não foi possível salvar.'; return; }
        $('modal-perfil').classList.remove('show');
        ERP.toast.show('Perfil salvo.', 'success');
        carregar();
      })
      .catch(function (e) { $('pf-erro').textContent = 'Falha ao salvar: ' + e.message; });
  });

  function renderDia(dia) {
    if (!dia) {
      $('c-dia-info').textContent = '';
      $('c-dia').innerHTML = '<p class="text-muted" style="font-size:13px;margin:0">Ainda não há leitura de notícias. Clique em <strong>Atualizar agora</strong> ou aguarde a rotina diária.</p>';
      return;
    }
    $('c-dia-info').textContent = '· ' + dataBR(dia.data) + (dia.origem === 'ia' ? ' · resumo por IA (Gemini)' : ' · sem resumo por IA');
    var h = '<p style="font-size:14px;margin:0 0 8px">' + escapeHtml(dia.resumo) + '</p>';
    if (dia.origem !== 'ia') { h += '<p class="text-warning" style="font-size:12.5px">A IA não respondeu hoje; abaixo estão só as manchetes coletadas.</p>'; }
    if (dia.atencao && dia.atencao.length) {
      h += '<p style="margin:8px 0 4px;font-size:13px"><strong>Para acompanhar:</strong></p><ul class="lista-fatores">' + dia.atencao.map(function (a) { return '<li>' + escapeHtml(a) + '</li>'; }).join('') + '</ul>';
    }
    Object.keys(dia.temas).forEach(function (t) {
      var x = dia.temas[t];
      var lista = (dia.noticias[t] || []).slice(0, 6);
      var tomCor = x.tom === 'negativo' ? 'danger' : (x.tom === 'positivo' ? 'success' : 'info');
      h += '<details class="tema-dia"' + (x.nivel_risco >= 4 ? ' open' : '') + '><summary><strong>' + escapeHtml(x.rotulo) + '</strong> ' +
        (x.tom ? '<span class="erp-badge erp-badge-' + tomCor + '">' + escapeHtml(x.tom) + '</span> ' : '') + (x.nivel_risco ? badge(x.nivel_risco) : '') + '</summary>' +
        (x.resumo ? '<p style="margin:6px 0;font-size:13px">' + escapeHtml(x.resumo) + '</p>' : '') +
        '<ul class="lista-manchetes">' + lista.map(function (n) {
          return '<li>' + (n.destaque ? '<i class="fa fa-star text-warning" title="Manchete de destaque na leitura"></i> ' : '') + '<a href="' + escapeHtml(link(n.url)) + '" target="_blank" rel="noopener noreferrer">' + escapeHtml(n.titulo) + '</a> <span class="text-muted">' +
            escapeHtml(n.fonte || '') + (n.publicada_em ? ' · ' + dataBR(n.publicada_em) : '') + '</span></li>';
        }).join('') + '</ul></details>';
    });
    var ds = dia.descartadas || {};
    var totalDesc = (ds.fonte || 0) + (ds.ruido || 0) + (ds.fora_do_pais || 0) + (ds.fora_do_tema || 0) + (ds.ia || 0);
    if (totalDesc > 0) {
      h += '<p class="text-muted" style="font-size:12px;margin:10px 0 0"><i class="fa fa-filter"></i> Filtro de qualidade: ' + totalDesc + ' manchetes descartadas (' + [
        ds.fonte ? ds.fonte + ' de fonte fora da lista de veículos confiáveis' : '', ds.fora_do_pais ? ds.fora_do_pais + ' sobre outro país em tema do Brasil' : '', ds.ruido ? ds.ruido + ' de entretenimento, celebridades e afins' : '',
        ds.fora_do_tema ? ds.fora_do_tema + ' fora de economia, finanças e governo' : '', ds.ia ? ds.ia + ' pela IA, por não tratar do tema' : ''].filter(Boolean).join('; ') + ').</p>';
    }
    h += '<p class="text-muted" style="font-size:12px;margin:10px 0 0">A IA recebe só as manchetes e os indicadores públicos (nenhum dado seu) e cita as manchetes pelo número; os links vêm da coleta. A leitura se baseia em títulos, não nas matérias completas: abra os links para conferir.</p>';
    $('c-dia').innerHTML = h;
  }

  function renderPercebida(p) {
    var h = '';
    var g = p.itens.geral;
    h += '<p style="font-size:13.5px;margin:0 0 8px">' + (g ? 'IPCA geral em 12 meses até ' + dataBR(g.data).slice(3) + ': <strong>' + pc(g.valor) + '</strong>. Veja o que sobe mais (ou menos) que o índice:' : 'Sem dados do IPCA do IBGE ainda: clique em Atualizar agora.') + '</p>';
    if (p.itens.itens.length) {
      h += '<div class="table-responsive"><table class="table table-striped table-hover m-0"><thead><tr><th>Item do IPCA</th><th class="num">12 meses</th><th class="num">Diferença para o IPCA geral</th></tr></thead><tbody>' +
        p.itens.itens.map(function (x) {
          return '<tr><td>' + escapeHtml(x.rotulo) + ' <small class="text-muted">' + (x.tipo === 'grupo' ? 'grupo' : 'item') + '</small></td><td class="num">' + pc(x.var_12m) + '</td><td class="num ' + (x.spread > 0.5 ? 'text-danger' : (x.spread < -0.5 ? 'text-success' : '')) + '">' + (x.spread > 0 ? '+' : '') + num(x.spread, 2) + ' p.p.</td></tr>';
        }).join('') + '</tbody></table></div>';
    }
    h += '<p class="text-muted" style="font-size:12px;margin:8px 0 14px">Fonte: IBGE (IPCA). O preço de <strong>venda</strong> de imóveis não faz parte do IPCA (só o aluguel); veja as manchetes do tema Imóveis na leitura do dia. Carro novo e usado aparecem pelo que o IBGE mede, que pode diferir do que se percebe nas concessionárias.</p>';

    h += '<h5 style="font-size:13.5px;margin:6px 0 8px">Reajustes dos seus gastos recorrentes</h5>';
    h += p.recorrentes.length ? '<div class="table-responsive"><table class="table table-striped table-hover m-0"><thead><tr><th>Gasto</th><th class="num">Desde</th><th class="num">Valor inicial</th><th class="num">Valor atual</th><th class="num">Variação</th><th class="num">Equivale a (ao ano)</th></tr></thead><tbody>' +
      p.recorrentes.map(function (x) {
        return '<tr><td>' + escapeHtml(x.descricao) + '</td><td class="num">' + dataBR(x.desde) + '</td><td class="num">' + brl(x.valor_inicial) + '</td><td class="num">' + brl(x.valor_atual) + '</td><td class="num ' + (x.var_pct > 0 ? 'text-danger' : 'text-success') + '">' + sinal(x.var_pct) + '</td><td class="num">' + (x.anualizada === null ? '<span class="text-muted">poucos meses</span>' : sinal(x.anualizada)) + '</td></tr>';
      }).join('') + '</tbody></table></div>'
      : '<p class="text-muted" style="font-size:13px;margin:0">Nenhum gasto recorrente seu mudou de valor no histórico ainda.</p>';

    var c = p.cesta;
    h += '<h5 style="font-size:13.5px;margin:18px 0 8px">Inflação da sua cesta</h5>';
    if (c.ipca_cesta === null) {
      h += '<p class="text-muted" style="font-size:13px;margin:0">Ainda não dá para calcular: nenhuma categoria sua está ligada a um grupo do IPCA.</p>';
    } else {
      h += '<p style="font-size:13.5px;margin:0 0 6px">Com o peso dos seus gastos por categoria, o IPCA da sua cesta em 12 meses é <strong>' + pc(c.ipca_cesta) + '</strong>' + (c.ipca_geral !== null ? ' (IPCA geral: ' + pc(c.ipca_geral) + ')' : '') + '. Cobertura: ' + num(c.cobertura_pct, 0) + '% dos seus gastos de 12 meses.</p>';
      if (!c.confiavel) { h += '<p class="text-warning" style="font-size:12.5px;margin:0 0 6px">Cobertura abaixo de 50%: a categoria "Outros" (ou sem categoria) concentra muito do gasto, então esse número é pouco confiável. Recategorizar os lançamentos melhora o cálculo, e o risco de inflação não o usa enquanto estiver assim.</p>'; }
      h += '<div class="table-responsive"><table class="table table-striped table-hover m-0"><thead><tr><th>Grupo do IPCA</th><th class="num">Seu gasto (12 meses)</th><th class="num">IPCA do grupo</th></tr></thead><tbody>' +
        c.grupos.map(function (x) { return '<tr><td>' + escapeHtml(x.rotulo) + '</td><td class="num">' + brl(x.gasto) + '</td><td class="num">' + pc(x.var_12m) + '</td></tr>'; }).join('') + '</tbody></table></div>';
    }
    $('c-percebida').innerHTML = h;
  }

  function renderHistorico(d) {
    var h = '';
    if (d.mensais.length) {
      h += d.mensais.map(function (m) {
        var i = m.indicadores.inicio || {}, f = m.indicadores.fim || {};
        var ind = ['selic:Selic', 'ipca_12m:IPCA 12m', 'juro_real_12m:Juro real', 'dolar:Dólar'].map(function (k) {
          var p = k.split(':');
          return i[p[0]] !== undefined && f[p[0]] !== undefined ? p[1] + ' ' + num(i[p[0]], 2) + ' → ' + num(f[p[0]], 2) : null;
        }).filter(Boolean).join(' · ');
        return '<div class="mes-card"><div class="mes-titulo"><strong>' + escapeHtml(m.label) + '</strong> <small class="text-muted">' + escapeHtml(ind) + '</small></div>' +
          '<p><span class="rot-tempo">Passado e presente</span> ' + escapeHtml(m.resumo) + '</p><p><span class="rot-tempo futuro">Futuro (o que se esperava)</span> ' + escapeHtml(m.perspectiva) + '</p></div>';
      }).join('');
    } else {
      h += '<p class="text-muted" style="font-size:13px">O primeiro fechamento mensal aparece no começo do próximo mês, a partir das leituras diárias deste mês. No mês seguinte você verá aqui o que aconteceu e o que se esperava.</p>';
    }
    h += '<h5 style="font-size:13.5px;margin:16px 0 8px">Risco do seu patrimônio ao longo do tempo</h5>';
    h += d.historico.length ? '<div class="table-responsive"><table class="table table-striped table-hover m-0"><thead><tr><th>Data</th><th class="num">Risco geral</th><th class="num">Selic</th><th class="num">IPCA 12m</th><th class="num">Juro real</th><th class="num">Dólar</th></tr></thead><tbody>' +
      d.historico.map(function (x) {
        var i = x.indicadores || {};
        return '<tr><td>' + dataBR(x.data) + '</td><td class="num">' + badge(x.nivel_geral) + '</td><td class="num">' + pc(i.selic) + '</td><td class="num">' + pc(i.ipca_12m) + '</td><td class="num">' + pc(i.juro_real_12m, 1) + '</td><td class="num">' + (i.dolar ? 'R$ ' + num(i.dolar, 2) : '—') + '</td></tr>';
      }).join('') + '</tbody></table></div><p class="text-muted" style="font-size:12px;margin:8px 0 0">Uma linha por mês (a foto mais recente de cada mês). A rotina grava uma foto por dia.</p>'
      : '<p class="text-muted" style="font-size:13px;margin:0">Ainda não há fotos de risco guardadas. A primeira é gravada agora, ao abrir "Atualizar agora", e depois uma por dia.</p>';
    $('c-historico').innerHTML = h;
  }

  function carregar() {
    $('c-carregando').classList.remove('hidden');
    $('c-carregando').innerHTML = '<i class="fa fa-spinner fa-spin"></i>Calculando o risco…';
    $('c-conteudo').classList.add('hidden');
    return fetch('api_cenario.php', { credentials: 'same-origin' })
      .then(function (r) { if (!r.ok) { throw new Error('HTTP ' + r.status); } return r.json(); })
      .then(function (d) {
        rotulos = d.rotulos;
        renderKpis(d); renderDimensoes(d.risco); renderDolar(d.risco, d.indicadores); renderAloc(d.alocacao); renderDia(d.dia); renderPercebida(d.percebida); renderHistorico(d);
        $('c-carregando').classList.add('hidden');
        $('c-conteudo').classList.remove('hidden');
      })
      .catch(function (e) { $('c-carregando').innerHTML = '<div class="alert alert-danger">Não foi possível carregar o cenário (' + escapeHtml(e.message) + ').</div>'; });
  }

  $('c-atualizar').addEventListener('click', function () {
    var b = this;
    b.disabled = true;
    b.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Atualizando…';
    var fd = new FormData();
    fd.append('csrf', window.CSRF);
    fetch('api_cenario_atualizar.php', { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (x) {
        if (!x.ok) { ERP.toast.show(x.j.erro || 'Não foi possível atualizar.', 'warning'); return; }
        var erros = (x.j.erros || []).length;
        ERP.toast.show(erros ? 'Atualizado com ' + erros + ' aviso(s): ' + x.j.erros.join(' | ') : 'Cenário e risco atualizados.', erros ? 'warning' : 'success');
        return carregar();
      })
      .catch(function (e) { ERP.toast.show('Falha ao atualizar: ' + e.message, 'error'); })
      .then(function () { b.disabled = false; b.innerHTML = '<i class="fa fa-refresh"></i> Atualizar agora'; });
  });

  carregar();
})();
