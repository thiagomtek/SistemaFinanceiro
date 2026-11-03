<?php
require_once __DIR__ . '/auth_check.php';

$PAGINA = ['Cenário e risco', 'fa-globe', 'cenario.php', 'Risco do patrimônio frente a juros, inflação, dólar e notícias, com histórico para comparar passado, presente e futuro', 'warning'];
require __DIR__ . '/header.php';
?>
<div class="filtros" style="justify-content:space-between;align-items:center">
  <div style="font-size:13px;color:#475569"><i class="fa fa-info-circle text-primary"></i> Indicadores oficiais (Banco Central e IBGE) e manchetes públicas, lidas por IA uma vez por dia. <strong>Apoio à decisão, não recomendação de investimento.</strong>
    <span class="text-muted" id="c-atualizado"></span></div>
  <?php if (usuario_admin_sistema()): ?><div class="campo" style="min-width:0"><button class="btn btn-primary btn-sm" id="c-atualizar" style="height:36px"><i class="fa fa-refresh"></i> Atualizar agora</button></div><?php endif; ?>
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

  <div class="painel"><h4><i class="fa fa-pie-chart text-primary"></i> Sugestões por classe de ativo <a href="#" class="btn btn-outline-secondary btn-sm" data-modal="modal-perfil" style="margin-left:auto"><i class="fa fa-sliders"></i> Perfil e faixas</a></h4>
    <div id="c-aloc"></div>
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
<div class="modal fin-modal erp-modal-md" id="modal-perfil">
  <form class="modal-dialog" id="form-perfil" onsubmit="return false">
    <div class="modal-header"><h4 class="modal-title"><i class="fa fa-sliders"></i> Perfil e faixas de alocação</h4><button type="button" class="close" data-fechar>&times;</button></div>
    <div class="modal-body">
      <div class="form-grid">
        <div class="form-group"><label>Tolerância a risco</label><select class="form-control" id="pf-tol"><option value="conservador">Conservador</option><option value="moderado">Moderado</option><option value="arrojado">Arrojado</option></select></div>
        <div class="form-group"><label title="Quantos meses de despesas devem estar em liquidez diária (contas e renda fixa pós-fixada de resgate diário)">Reserva de emergência (meses)</label><input class="form-control" id="pf-reserva" type="number" min="1" max="36" step="1"></div>
      </div>
      <label style="display:flex;gap:8px;align-items:center;font-weight:600;margin:6px 0 10px"><input type="checkbox" id="pf-padrao" style="width:auto"> Usar as faixas padrão da tolerância escolhida</label>
      <div class="table-responsive"><table class="table m-0" id="pf-tab"><thead><tr><th>Classe</th><th class="num">Mínimo (%)</th><th class="num">Máximo (%)</th></tr></thead><tbody></tbody></table></div>
      <p class="text-muted" style="font-size:12px;margin:10px 0 0">As faixas são pontos de partida genéricos para conversar com um planejador, e não uma recomendação: ajuste à sua realidade, objetivos e prazo. Elas definem o que a tela chama de "acima" ou "abaixo da faixa".</p>
      <div class="text-danger" id="pf-erro" style="font-size:13px;margin-top:8px"></div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-fechar>Cancelar</button><button type="button" class="btn btn-primary" id="pf-salvar"><i class="fa fa-check"></i> Salvar</button></div>
  </form>
</div>
<?php $PAGINA_SCRIPTS = ['assets/js/cenario.js'];
require __DIR__ . '/footer.php'; ?>
