<?php
require_once __DIR__ . '/auth_check.php';

$cartoes = cartoes_do_perfil();
$cartaoId = (int) ($_GET['cartao'] ?? $_POST['cartao_id'] ?? 0);
$cartao = null;
foreach ($cartoes as $c) {
    if ((int) $c['id'] === $cartaoId) {
        $cartao = $c;
    }
}
if (!$cartao && $cartoes) {
    $cartao = $cartoes[0];
}
if (!$cartao) {
    flash('Cadastre um cartão primeiro.', 'warning');
    redirect('cartoes.php');
}
$corrente = fatura_corrente($cartao);
$mes = $_GET['mes'] ?? $_POST['mes'] ?? ($corrente ? $corrente['mes'] : date('Y-m'));
$mes = mes_valido($mes, date('Y-m'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_exigir();
    $acao = $_POST['acao'] ?? '';
    try {
        if ($acao === 'ajustar') {
            $r = ajustar_fatura((int) $cartao['id'], $mes, $_POST['valor_final'] ?? '');
            if ($r['negativo']) {
                flash('Fatura ajustada. Atenção: o valor é menor que a soma das compras, então o reajuste ficou negativo (' . brl($r['ajuste']) . ').', 'warning');
            } else {
                flash($r['ajuste'] == 0 ? 'Fatura ajustada: não há reajuste (o valor é igual à soma das compras).' : 'Fatura ajustada com reajuste de ' . brl($r['ajuste']) . '.');
            }
        } elseif ($acao === 'pagar') {
            pagar_fatura((int) $cartao['id'], $mes, (int) ($_POST['conta_id'] ?? 0), parse_data($_POST['data'] ?? '') ?: date('Y-m-d'), $_POST['valor'] ?? null);
            flash('Fatura paga e debitada da conta.');
        } elseif ($acao === 'reabrir') {
            reabrir_fatura((int) $cartao['id'], $mes);
            flash('Fatura reaberta e pagamento estornado.');
        }
    } catch (InvalidArgumentException $e) {
        flash($e->getMessage(), 'danger');
    }
    redirect('fatura.php?cartao=' . (int) $cartao['id'] . '&mes=' . $mes);
}

$contas = contas_do_perfil(true);
$total = fatura_total((int) $cartao['id'], $mes);
$pagamento = q_one('SELECT * FROM faturas WHERE cartao_id = ? AND mes = ?', [$cartao['id'], $mes]);
$status = fatura_status($cartao, $mes, (bool) $pagamento);
$parcial = $pagamento && (float) $pagamento['valor_pago'] < $total - 0.005;
[$statusRotulo, $statusCls] = fatura_status_visual($status);
$venc = fatura_vencimento($cartao, $mes);
$fech = fatura_fechamento($cartao, $mes);
$itens = q_all(
    "SELECT l.*, cat.nome AS cat_nome, cat.cor AS cat_cor FROM lancamentos l LEFT JOIN categorias cat ON cat.id = l.categoria_id
     WHERE l.perfil_id = ? AND l.cartao_id = ? AND l.fatura_mes = ? AND l.tipo = 'despesa' ORDER BY l.data, l.id",
    [perfil_id(), $cartao['id'], $mes]
);
$ajusteItem = null;
foreach ($itens as $i) {
    if ($i['ajuste'] === 'fatura') {
        $ajusteItem = $i;
    }
}
$usado = limite_utilizado($cartao);
$ant = substr(add_meses($mes . '-01', -1), 0, 7);
$prox = substr(add_meses($mes . '-01', 1), 0, 7);
$base = 'fatura.php?cartao=' . (int) $cartao['id'] . '&mes=';

$PAGINA = ['Fatura do cartão', 'fa-credit-card', 'cartoes.php', 'Itens, status e ajuste da fatura mensal', 'purple'];
require __DIR__ . '/header.php';
?>
<div class="filtros">
  <div class="campo"><label>Cartão</label>
    <select class="form-control" onchange="location.href='fatura.php?cartao='+this.value"><?= opcoes($cartoes, 'id', 'nome', $cartao['id']) ?></select></div>
  <div class="campo"><label>Fatura (vencimento)</label>
    <div class="d-flex gap-2 align-center">
      <a class="btn btn-outline-secondary btn-sm" href="<?= e($base . $ant) ?>"><i class="fa fa-chevron-left"></i></a>
      <span class="pilula ativa" style="min-width:110px;text-align:center"><?= e(mes_label($mes)) ?></span>
      <a class="btn btn-outline-secondary btn-sm" href="<?= e($base . $prox) ?>"><i class="fa fa-chevron-right"></i></a>
    </div></div>
  <div class="campo"><label>&nbsp;</label>
    <span class="d-flex gap-2">
      <a href="#" class="btn btn-primary btn-sm" style="height:36px;display:inline-flex;align-items:center" data-modal="modal-ajustar" data-preencher="<?= e(json_encode(['cartao_id' => $cartao['id'], 'mes' => $mes, 'valor_atual' => number_format($total, 2, ',', '.'), 'cartao_nome' => $cartao['nome'], 'vencimento_txt' => data_br($venc)])) ?>"><i class="fa fa-sliders"></i>&nbsp;Ajustar fatura</a>
      <?php if ($pagamento): ?>
        <form method="post" data-confirmar="Reabrir esta fatura e estornar o pagamento?" style="margin:0"><?= csrf_field() ?><input type="hidden" name="acao" value="reabrir"><input type="hidden" name="cartao_id" value="<?= (int) $cartao['id'] ?>"><input type="hidden" name="mes" value="<?= e($mes) ?>"><button class="btn btn-outline-warning btn-sm" style="height:36px"><i class="fa fa-undo"></i> Reabrir</button></form>
      <?php elseif ($total > 0): ?>
        <a href="#" class="btn btn-success btn-sm" style="height:36px;display:inline-flex;align-items:center" data-modal="modal-pagar" data-preencher="<?= e(json_encode(['cartao_id' => $cartao['id'], 'mes' => $mes, 'conta_id' => $cartao['conta_pagamento_id'], 'data' => $venc < date('Y-m-d') ? date('Y-m-d') : $venc, 'valor' => number_format($total, 2, ',', '.'), 'total_txt' => brl($total)])) ?>"><i class="fa fa-check"></i>&nbsp;Pagar fatura</a>
      <?php endif; ?>
      <a href="lancamentos.php?cartao=<?= (int) $cartao['id'] ?>&amp;fatura=<?= e($mes) ?>" class="btn btn-outline-info btn-sm" style="height:36px;display:inline-flex;align-items:center"><i class="fa fa-pencil"></i>&nbsp;Editar itens</a>
    </span></div>
</div>

<div class="kpi-grid">
  <div class="kpi roxo"><i class="fa fa-money kpi-icone"></i><div class="kpi-label">Valor da fatura</div><div class="kpi-valor"><?= brl($total) ?></div><div class="kpi-detalhe"><?= count($itens) ?> item(ns)</div></div>
  <div class="kpi <?= $status === 'paga' ? 'verde' : ($status === 'vencida' ? 'vermelho' : ($status === 'fechada' ? 'laranja' : 'azul')) ?>"><i class="fa fa-file-text-o kpi-icone"></i><div class="kpi-label">Status</div><div class="kpi-valor" style="font-size:18px">Fatura <?= e(mb_strtolower($status === 'paga' ? 'paga' : ($status === 'vencida' ? 'vencida' : ($status === 'fechada' ? 'fechada' : 'aberta')))) ?></div><div class="kpi-detalhe"><?= $pagamento ? 'Paga em ' . e(data_br($pagamento['data_pagamento'])) . ($parcial ? ' · pagou ' . brl($pagamento['valor_pago']) . ' de ' . brl($total) . '; o restante foi para a fatura seguinte' : '') : 'Em aberto' ?></div></div>
  <div class="kpi"><i class="fa fa-calendar-o kpi-icone"></i><div class="kpi-label">Dia de fechamento</div><div class="kpi-valor" style="font-size:18px"><?= e(data_br($fech)) ?></div></div>
  <div class="kpi"><i class="fa fa-calendar-check-o kpi-icone"></i><div class="kpi-label">Data de vencimento</div><div class="kpi-valor" style="font-size:18px"><?= e(data_br($venc)) ?></div></div>
  <div class="kpi azul"><i class="fa fa-credit-card kpi-icone"></i><div class="kpi-label">Limite utilizado</div><div class="kpi-valor" style="font-size:18px"><?= brl($usado) ?></div><div class="kpi-detalhe">de <?= brl($cartao['limite']) ?> · disponível <?= brl(max(0, $cartao['limite'] - $usado)) ?></div></div>
</div>

<?php if (!$itens): ?>
  <div class="vazio"><i class="fa fa-inbox"></i>Esta fatura não tem itens. Use <strong>Ajustar fatura</strong> para informar o valor total ou lance as compras do cartão.</div>
<?php else: ?>
<div class="table-responsive">
<table class="table table-striped table-hover m-0">
  <thead><tr><th>Data</th><th>Descrição</th><th>Categoria</th><th class="num">Valor</th></tr></thead>
  <tbody>
  <?php foreach ($itens as $i): ?>
    <tr>
      <td class="nowrap"><?= e(data_br($i['data'])) ?></td>
      <td><?= e($i['descricao']) ?>
        <?php if ($i['ajuste'] === 'fatura'): ?> <span class="erp-badge erp-badge-warning">Reajuste</span><?php endif; ?>
        <?php if ($i['parcela_total'] && !preg_match('/\(\d+\s*\/\s*\d+\)\s*$/', $i['descricao'])): ?> <span class="erp-badge erp-badge-info"><?= (int) $i['parcela_num'] ?>/<?= (int) $i['parcela_total'] ?></span><?php endif; ?></td>
      <td><?php if ($i['cat_nome']): ?><span class="cor-bolinha" style="background:<?= e($i['cat_cor']) ?>"></span><?= e($i['cat_nome']) ?><?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
      <td class="num <?= $i['valor'] < 0 ? 'text-success' : 'text-danger' ?>"><strong><?= $i['valor'] < 0 ? '+' : '−' ?> <?= brl(abs($i['valor'])) ?></strong></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
  <tfoot><tr><th colspan="3" class="num">Total da fatura</th><th class="num"><?= brl($total) ?></th></tr></tfoot>
</table>
</div>
<?php endif; ?>
<?php if ($ajusteItem && $ajusteItem['valor'] < 0): ?>
  <div class="alert alert-warning"><i class="fa fa-exclamation-triangle"></i> O reajuste desta fatura é negativo: o valor informado é menor que a soma das compras.</div>
<?php endif; ?>

<?php require __DIR__ . '/fatura_modais.php'; ?>
<?php require __DIR__ . '/footer.php'; ?>
