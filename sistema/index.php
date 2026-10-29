<?php
require_once __DIR__ . '/auth_check.php';

$mesHoje = date('Y-m');
$mes = (isset($_GET['mes']) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $_GET['mes'])) ? (string) $_GET['mes'] : $mesHoje;
$mesAnt = substr(add_meses($mes . '-01', -1), 0, 7);
$mesProx = substr(add_meses($mes . '-01', 1), 0, 7);
$r = resumo_mes($mes);
$saldo = saldo_total_contas();
$saldoPrevisto = saldo_total_previsto();
$cartaoMes = total_faturas_do_mes($mes);
$totInv = totais_investidos();
$investido = $totInv['bruto'];
$investidoLiq = $totInv['liquido'];
$porCat = despesas_por_categoria($mes);
$serie = receitas_despesas_meses(5, 3, $mes);
$mesesAte = (int) round((strtotime($mes . '-01') - strtotime($mesHoje . '-01')) / 2629800);
$proj = projetar(min(60, max(6, $mesesAte + 1)), true);
$pm = null; // mes futuro escolhido: saldos projetados no fim dele
if ($mes > $mesHoje) {
    foreach ($proj['serie'] as $x) {
        if ($x['mes'] === $mes) {
            $pm = $x;
        }
    }
}

$faturasAbertas = 0.0;
$proximos = [];
foreach (cartoes_do_perfil() as $c) {
    foreach (faturas_do_cartao($c) as $f) {
        if (!$f['paga']) {
            $faturasAbertas += $f['total'];
            $proximos[] = ['data' => $f['vencimento'], 'descricao' => 'Fatura ' . $c['nome'] . ' ' . mes_label($f['mes']), 'valor' => $f['total'], 'tipo' => 'despesa', 'fatura' => true];
        }
    }
}
foreach (q_all(
    "SELECT data, descricao, valor, tipo FROM lancamentos WHERE perfil_id = ? AND efetivado = 0 AND cartao_id IS NULL AND tipo IN ('receita','despesa')
     ORDER BY data LIMIT 40",
    [perfil_id()]
) as $l) {
    $proximos[] = $l + ['fatura' => false];
}
usort($proximos, fn($a, $b) => strcmp($a['data'], $b['data']));
$hoje = date('Y-m-d');
// atrasados primeiro (ate 5), depois os proximos
$proximos = array_slice($proximos, 0, 8);

$contas = contas_do_perfil(true);
$saldos = saldos_por_conta();

$PAGINA = ['Dashboard', 'fa-dashboard', 'index.php', 'Visão do mês de ' . mes_label($mes) . ($mes === $mesHoje ? '' : ' (saldos e vencimentos são de hoje)'), 'success'];
require __DIR__ . '/header.php';
?>
<div class="filtros" style="align-items:center;margin-bottom:12px">
  <a class="btn btn-outline-secondary btn-sm" href="index.php?mes=<?= e($mesAnt) ?>" title="Mês anterior"><i class="fa fa-chevron-left"></i></a>
  <form method="get" style="display:inline-flex;gap:6px;align-items:center"><input class="form-control" type="month" name="mes" value="<?= e($mes) ?>" onchange="this.form.submit()" style="height:32px;padding:2px 8px;width:auto"></form>
  <a class="btn btn-outline-secondary btn-sm" href="index.php?mes=<?= e($mesProx) ?>" title="Próximo mês"><i class="fa fa-chevron-right"></i></a>
  <?php if ($mes !== $mesHoje): ?><a class="btn btn-outline-primary btn-sm" href="index.php">Mês atual</a><?php endif; ?>
  <strong style="margin-left:8px;font-size:15px"><?= e(mes_label($mes)) ?></strong>
</div>
<div class="kpi-grid">
  <?php if ($pm): ?>
  <div class="kpi azul"><i class="fa fa-university kpi-icone"></i><div class="kpi-label">Saldo em contas — fim de <?= e(mes_label($mes)) ?> (projetado)</div><div class="kpi-valor <?= $pm['saldo'] < 0 ? 'text-danger' : '' ?>"><?= brl($pm['saldo']) ?></div><div class="kpi-detalhe">Hoje: <strong><?= brl($saldo) ?></strong> · o que sobra em cada mês é investido</div></div>
  <div class="kpi laranja"><i class="fa fa-bar-chart kpi-icone"></i><div class="kpi-label">Investimentos — fim de <?= e(mes_label($mes)) ?> (projetado)</div><div class="kpi-valor"><?= brl($pm['investido']) ?></div><div class="kpi-detalhe">Líquido: <?= brl($pm['investido_liquido']) ?> · patrimônio <?= brl($pm['patrimonio']) ?> · <a href="projecao.php">Ver projeção</a></div></div>
  <?php else: ?>
  <div class="kpi azul"><i class="fa fa-university kpi-icone"></i><div class="kpi-label">Saldo em contas</div><div class="kpi-valor <?= $saldo < 0 ? 'text-danger' : '' ?>"><?= brl($saldo) ?></div><div class="kpi-detalhe">Previsto até o fim do mês: <strong><?= brl($saldoPrevisto) ?></strong> · <?= count($contas) ?> conta(s)</div></div>
  <div class="kpi laranja"><i class="fa fa-bar-chart kpi-icone"></i><div class="kpi-label">Investimentos</div><div class="kpi-valor"><?= brl($investido) ?></div><div class="kpi-detalhe">Líquido se resgatar hoje: <?= brl($investidoLiq) ?> · <a href="investimentos.php">Ver carteira</a></div></div>
  <?php endif; ?>
  <div class="kpi verde"><i class="fa fa-arrow-circle-up kpi-icone"></i><div class="kpi-label">Receitas do mês</div><div class="kpi-valor text-success"><?= brl($r['rec']) ?></div><div class="kpi-detalhe">Recebido: <?= brl($r['rec_ef']) ?></div></div>
  <div class="kpi vermelho"><i class="fa fa-arrow-circle-down kpi-icone"></i><div class="kpi-label" title="Pela data da compra ou do pagamento. O pagamento da fatura não entra: as compras do cartão já contam.">Despesas do mês</div><div class="kpi-valor text-danger"><?= brl($r['desp']) ?></div><div class="kpi-detalhe">Pago/no cartão: <?= brl($r['desp_ef']) ?></div></div>
  <div class="kpi <?= $r['rec'] - $r['desp'] < 0 ? 'vermelho' : 'verde' ?>"><i class="fa fa-balance-scale kpi-icone"></i><div class="kpi-label">Resultado previsto</div><div class="kpi-valor <?= $r['rec'] - $r['desp'] < 0 ? 'text-danger' : 'text-success' ?>"><?= brl($r['rec'] - $r['desp']) ?></div></div>
  <div class="kpi roxo"><i class="fa fa-credit-card kpi-icone"></i><div class="kpi-label" title="Soma das faturas que vencem neste mês (compras de meses anteriores incluídas).">Cartão de crédito</div><div class="kpi-valor"><?= brl($cartaoMes) ?></div><div class="kpi-detalhe">Faturas com vencimento no mês · em aberto no total: <?= brl($faturasAbertas) ?> · <a href="cartoes.php">Ver cartões</a></div></div>
</div>

<div class="grid-2">
  <div class="painel"><h4><i class="fa fa-bar-chart text-primary"></i> Receitas × despesas</h4><div class="chart-box"><canvas id="ch-rd"></canvas></div></div>
  <div class="painel"><h4><i class="fa fa-pie-chart text-warning"></i> Despesas por categoria — <?= e(mes_label($mes)) ?></h4>
    <?php if ($porCat): ?><div class="chart-box"><canvas id="ch-cat"></canvas></div><?php else: ?><div class="vazio"><i class="fa fa-pie-chart"></i>Sem despesas neste mês.</div><?php endif; ?></div>
</div>
<div class="grid-2">
  <div class="painel"><h4><i class="fa fa-line-chart text-success"></i> Patrimônio projetado — contas + investimentos <a href="projecao.php" style="margin-left:auto;font-weight:400;font-size:12px">detalhes ›</a></h4><div class="chart-box"><canvas id="ch-proj"></canvas></div></div>
  <div class="painel"><h4><i class="fa fa-calendar"></i> Próximos vencimentos</h4>
    <?php if (!$proximos): ?><div class="vazio"><i class="fa fa-check-circle"></i>Nada pendente.</div><?php else: ?>
    <table class="table table-striped table-hover m-0"><tbody>
    <?php foreach ($proximos as $p): $atrasado = $p['data'] < $hoje; ?>
      <tr><td class="nowrap"><?= e(data_br($p['data'])) ?> <?= $atrasado ? '<span class="erp-badge erp-badge-danger">atrasado</span>' : '' ?></td>
        <td><?= $p['fatura'] ? '<i class="fa fa-credit-card text-info"></i> ' : '' ?><?= e($p['descricao']) ?></td>
        <td class="num <?= classe_valor($p['tipo']) ?>"><?= $p['tipo'] === 'receita' ? '+' : '−' ?> <?= brl($p['valor']) ?></td></tr>
    <?php endforeach; ?></tbody></table><?php endif; ?>
  </div>
</div>
<?php if ($contas): ?>
<div class="painel"><h4><i class="fa fa-university"></i> Saldo por conta</h4>
  <div class="kpi-grid" style="margin:0">
  <?php foreach ($contas as $c): $s = $saldos[$c['id']] ?? 0; ?>
    <div class="kpi" style="border-left-color:<?= $s < 0 ? '#dc2626' : '#2563eb' ?>"><div class="kpi-label"><?= e($c['nome']) ?></div><div class="kpi-valor <?= $s < 0 ? 'text-danger' : '' ?>" style="font-size:18px"><?= brl($s) ?></div></div>
  <?php endforeach; ?></div></div>
<?php endif; ?>

<script>
window.DASH = <?= json_encode([
    'rd' => ['labels' => array_map('mes_label', array_keys($serie)), 'rec' => array_column($serie, 'rec'), 'desp' => array_column($serie, 'desp'), 'atual' => mes_label($mes)],
    'cat' => ['labels' => array_column($porCat, 'nome'), 'valores' => array_map('floatval', array_column($porCat, 'total')), 'cores' => array_column($porCat, 'cor')],
    'proj' => ['labels' => array_slice(array_column($proj['serie'], 'label'), 0, 12), 'saldo' => array_slice(array_column($proj['serie'], 'saldo'), 0, 12), 'pat' => array_slice(array_column($proj['serie'], 'patrimonio'), 0, 12), 'patliq' => array_slice(array_column($proj['serie'], 'patrimonio_liquido'), 0, 12)],
], JSON_UNESCAPED_UNICODE) ?>;
</script>
<?php $PAGINA_SCRIPTS = ['assets/js/chart.umd.min.js', 'assets/js/dashboard.js'];
require __DIR__ . '/footer.php'; ?>
