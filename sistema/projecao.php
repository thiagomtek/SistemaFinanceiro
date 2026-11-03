<?php
require_once __DIR__ . '/auth_check.php';

$PAGINA = ['Projeção', 'fa-line-chart', 'projecao.php', 'Saldo futuro a partir de lançamentos agendados, parcelas, faturas e média de gastos', 'success'];
require __DIR__ . '/header.php';
?>
<form class="filtros" id="form-proj" onsubmit="return false">
  <div class="campo"><label>Horizonte</label>
    <select class="form-control" id="p-meses"><option value="6">6 meses</option><option value="12" selected>12 meses</option><option value="24">24 meses</option><option value="36">36 meses</option><option value="60">60 meses</option></select></div>
  <div class="campo"><label title="Tudo que sobra no mês (receitas − despesas − faturas) é investido no fim do mês e rende a este % do CDI. 100% é o cenário conservador.">Sobra do mês rende</label><div class="fin-field-suffix"><input class="form-control" id="p-pct" inputmode="decimal" value="100"><span class="fin-suffix">% do CDI</span></div></div>
  <div class="campo"><label title="Aporte fixo além da sobra do mês (opcional)">Aporte extra mensal</label><div class="fin-field-prefix"><span class="fin-prefix">R$</span><input class="form-control" id="p-aporte" inputmode="decimal" value="0"></div></div>
  <div class="campo" style="flex-direction:row;align-items:center;gap:8px;padding-top:18px">
    <input type="checkbox" id="p-media" checked style="width:auto"><label for="p-media" style="margin:0;text-transform:none;font-size:13px" title="Soma a média dos últimos 3 meses de receitas e despesas avulsas (sem parcela/recorrência) ao que ainda não está agendado">Incluir média histórica de gastos avulsos</label></div>
  <div class="campo"><label title="Deixe em branco para usar o CDI de hoje (Banco Central)">CDI do cenário</label><div class="fin-field-suffix"><input class="form-control" id="p-cdi" inputmode="decimal" placeholder="CDI de hoje"><span class="fin-suffix">% a.a.</span></div></div>
  <div class="campo"><label>&nbsp;</label><button class="btn btn-primary btn-sm" id="p-ok" style="height:36px"><i class="fa fa-refresh"></i> Recalcular</button></div>
</form>

<div id="p-carregando" class="vazio"><i class="fa fa-spinner fa-spin"></i>Calculando projeção…</div>
<div id="p-conteudo" class="hidden">
  <div class="kpi-grid">
    <div class="kpi azul"><i class="fa fa-balance-scale kpi-icone"></i><div class="kpi-label">Patrimônio hoje (contas + investimentos)</div><div class="kpi-valor" id="k-pat-hoje"></div><div class="kpi-detalhe" id="k-pat-hoje-d"></div></div>
    <div class="kpi laranja" id="k-pat-fim-box"><i class="fa fa-line-chart kpi-icone"></i><div class="kpi-label">Patrimônio ao fim do horizonte</div><div class="kpi-valor" id="k-pat"></div><div class="kpi-detalhe" id="k-pat-d"></div></div>
    <div class="kpi verde"><i class="fa fa-bar-chart kpi-icone"></i><div class="kpi-label">Investimentos ao fim</div><div class="kpi-valor" id="k-inv-fim"></div><div class="kpi-detalhe" id="k-inv-fim-d"></div></div>
    <div class="kpi" id="k-min-box"><i class="fa fa-university kpi-icone"></i><div class="kpi-label">Saldo em conta corrente</div><div class="kpi-valor" id="k-final"></div><div class="kpi-detalhe" id="k-final-d"></div></div>
  </div>
  <div id="p-alerta"></div>
  <div class="grid-2">
    <div class="painel"><h4><i class="fa fa-line-chart text-success"></i> Patrimônio e saldo em contas projetados</h4><div class="chart-box"><canvas id="ch-saldo"></canvas></div><p class="text-muted" style="font-size:12px;margin:6px 0 0">O saldo em contas fica perto de zero porque tudo que sobra em cada mês é investido: o patrimônio é que cresce.</p></div>
    <div class="painel"><h4><i class="fa fa-bar-chart text-primary"></i> Entradas e saídas por mês</h4><div class="chart-box"><canvas id="ch-fluxo"></canvas></div></div>
  </div>
  <div class="grid-2">
    <div class="painel"><h4><i class="fa fa-bar-chart text-warning"></i> Investimentos: bruto × líquido de impostos <small class="text-muted" id="p-cdi-info" style="font-weight:400"></small></h4><div class="chart-box"><canvas id="ch-inv"></canvas></div></div>
    <div class="painel"><h4><i class="fa fa-list text-warning"></i> Investimentos ao fim do horizonte</h4>
      <div class="table-responsive"><table class="table table-striped table-hover m-0" id="t-inv"><thead><tr><th>Investimento</th><th class="num">Hoje (bruto)</th><th class="num">Fim (bruto)</th><th class="num">Fim (líquido)</th></tr></thead><tbody></tbody></table></div>
      <p class="text-muted" style="font-size:12px">Bruto = como aparece no app do banco. Líquido = depois do IR regressivo e do IOF, se resgatasse tudo no fim do período. Considera aplicações e resgates já agendados.</p></div>
  </div>
  <div class="painel"><h4><i class="fa fa-table"></i> Detalhe mês a mês</h4>
    <div class="table-responsive"><table class="table table-striped table-hover m-0" id="t-proj"><thead><tr><th>Mês</th><th class="num">Receitas</th><th class="num">Despesas</th><th class="num">Faturas</th><th class="num" title="Receitas − despesas − faturas. Aplicar ou resgatar investimento só muda o dinheiro de lugar e não entra aqui">Resultado</th><th class="num" title="Sobra do mês investida no fim do mês">Investido na sobra</th><th class="num">Saldo em contas</th><th class="num">Investido</th><th class="num">Patrimônio</th><th class="num" title="Contas + investimentos líquidos de IR e IOF">Patr. líquido</th></tr></thead><tbody></tbody></table></div>
    <p class="text-muted" style="font-size:12px;margin-top:8px"><strong>Saldo em contas</strong> é só a conta corrente; <strong>patrimônio</strong> soma contas e investimentos (bruto, e líquido de IR/IOF). No mês atual, receitas, despesas e faturas somam o que já foi recebido/pago (passe o mouse sobre o valor) e o que ainda falta; o saldo parte do saldo de hoje e só considera o que falta. <strong>Resultado = receitas − despesas − faturas</strong>: o que sobrou ou faltou no mês, somando conta e investimentos. Aplicar ou resgatar investimento só muda o dinheiro de lugar (aparece em <em>Saldo em contas</em> e <em>Investido</em>) e não conta como gasto. Faturas em aberto entram no dia do vencimento. Lançamentos pendentes atrasados são considerados hoje. <strong>O que sobra em cada mês (receitas − despesas − faturas) é investido no fim do mês</strong> e rende ao % do CDI escolhido (100% é o cenário conservador); os aportes também rendem nos meses seguintes. Mês de falta sai do saldo da conta, e a sobra só é investida depois de a conta voltar a ficar positiva. Os investimentos atuais rendem pelo CDI e pelo % de cada um.</p>
  </div>
</div>
<?php $PAGINA_SCRIPTS = ['assets/js/chart.umd.min.js', 'assets/js/projecao.js'];
require __DIR__ . '/footer.php'; ?>
