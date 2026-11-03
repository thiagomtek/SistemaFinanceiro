<?php
require_once __DIR__ . '/auth_check.php';

$PAGINA = ['Cenário e risco', 'fa-globe', 'cenario.php', 'Risco do patrimônio frente a juros, inflação, dólar e notícias, com histórico para comparar passado, presente e futuro', 'warning'];
require __DIR__ . '/header.php';
?>
<div class="filtros" style="justify-content:space-between;align-items:center">
  <div style="font-size:13px;color:#475569"><i class="fa fa-info-circle text-primary"></i> Indicadores oficiais (Banco Central e IBGE) e manchetes públicas, lidas por IA uma vez por dia. <strong>Apoio à decisão, não recomendação de investimento.</strong>
    <span class="text-muted" id="c-atualizado"></span></div>
  <div class="campo" style="min-width:0"><button class="btn btn-primary btn-sm" id="c-atualizar" style="height:36px"><i class="fa fa-refresh"></i> Atualizar agora</button></div>
</div>

<div id="c-carregando" class="vazio"><i class="fa fa-spinner fa-spin"></i>Calculando o risco…</div>
<div id="c-conteudo" class="hidden">
  <div class="kpi-grid" id="c-kpis"></div>

  <div class="painel"><h4><i class="fa fa-shield text-primary"></i> Dimensões do risco <small class="text-muted" id="c-risco-info" style="font-weight:400"></small></h4>
    <div class="dim-grid" id="c-dimensoes"></div>
    <p class="text-muted" style="font-size:12px;margin:10px 0 0">Nível de 1 (baixo) a 5 (alto). O nível geral é a média das dimensões com dado (juros 20%, inflação 20%, moeda, concentração, liquidez e cenário externo 15% cada), e sobe para pelo menos 4 se alguma dimensão estiver em 5. A conta de cada dimensão está explicada no motivo.</p>
  </div>

  <div class="painel"><h4><i class="fa fa-usd text-success"></i> Dolarização: comprar dólar ou investir no exterior?</h4>
    <div id="c-dolar"></div>
  </div>

  <div class="painel"><h4><i class="fa fa-newspaper-o text-warning"></i> Leitura do dia <small class="text-muted" id="c-dia-info" style="font-weight:400"></small></h4>
    <div id="c-dia"></div>
  </div>

  <div class="painel"><h4><i class="fa fa-shopping-basket text-danger"></i> Inflação percebida: o que sobe acima do IPCA</h4>
    <div id="c-percebida"></div>
  </div>

  <div class="painel"><h4><i class="fa fa-history text-secondary"></i> Histórico: passado, presente e futuro</h4>
    <div id="c-historico"></div>
  </div>
</div>
<?php $PAGINA_SCRIPTS = ['assets/js/cenario.js'];
require __DIR__ . '/footer.php'; ?>
