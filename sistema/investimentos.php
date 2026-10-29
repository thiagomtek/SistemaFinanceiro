<?php
require_once __DIR__ . '/auth_check.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_exigir();
    $acao = $_POST['acao'] ?? '';
    $id = (int) ($_POST['id'] ?? 0);
    $destino = 'investimentos.php';
    try {
        if ($acao === 'salvar') {
            $novo = investimento_salvar($_POST, $id);
            flash($id ? 'Investimento atualizado.' : 'Investimento cadastrado. Registre a aplicação (ou a aplicação já existente) para começar.');
        } elseif ($acao === 'aplicar') {
            $contaId = (int) ($_POST['conta_id'] ?? 0);
            rf_aplicar($contaId, (int) ($_POST['investimento_id'] ?? 0), $_POST['valor'] ?? '', (string) ($_POST['data'] ?? ''), !empty($_POST['efetivada']), (string) ($_POST['obs'] ?? ''));
            flash($contaId ? 'Aplicação registrada e debitada da conta.' : 'Aplicação registrada (sem débito em conta).');
            $destino = 'investimentos.php?id=' . (int) ($_POST['investimento_id'] ?? 0);
        } elseif ($acao === 'resgatar') {
            $contaId = (int) ($_POST['conta_id'] ?? 0);
            $liquido = ($_POST['modo'] ?? 'valor') === 'total' ? null : ($_POST['valor'] ?? '');
            $r = rf_resgatar_liquido((int) ($_POST['investimento_id'] ?? 0), $contaId, $liquido, (string) ($_POST['data'] ?? ''), !empty($_POST['efetivada']), (string) ($_POST['obs'] ?? ''), $_POST['ir_manual'] ?? null, $_POST['iof_manual'] ?? null);
            flash('Resgate registrado: ' . brl($r['liquido']) . ($contaId ? ' creditado na conta' : ' (sem crédito em conta)') . '; debitado do investimento ' . brl($r['bruto']) . ' (IR ' . brl($r['ir']) . ', IOF ' . brl($r['iof']) . ($r['editado'] ? ', impostos informados por você' : '') . ').');
            $destino = 'investimentos.php?id=' . (int) ($_POST['investimento_id'] ?? 0);
        } elseif ($acao === 'reajustar') {
            $r = rf_reajustar((int) ($_POST['investimento_id'] ?? 0), $_POST['saldo'] ?? '', (string) ($_POST['data'] ?? ''), (string) ($_POST['obs'] ?? ''), (string) ($_POST['base'] ?? 'bruto'));
            flash('Saldo bruto reajustado: de ' . brl($r['anterior']) . ' para ' . brl($r['novo']) . ' (diferença de ' . brl($r['diferenca']) . ').' . ($r['base'] === 'liquido' ? ' Calculado a partir do líquido informado.' : ''));
            $destino = 'investimentos.php?id=' . (int) ($_POST['investimento_id'] ?? 0);
        } elseif ($acao === 'mov_manual') {
            // rendimento informado a mao (investimentos manuais, como acoes e FII)
            $invM = rf_investimento((int) ($_POST['investimento_id'] ?? 0), true);
            $valor = round(parse_valor($_POST['valor'] ?? 0), 2);
            $data = parse_data($_POST['data'] ?? '');
            if (!$data || abs($valor) < 0.005) {
                throw new InvalidArgumentException('Informe valor e data válidos.');
            }
            q_insert('INSERT INTO investimento_movs (investimento_id, data, tipo, valor, obs) VALUES (?,?,?,?,?)', [$invM['id'], $data, 'rendimento', $valor, trim($_POST['obs'] ?? '') ?: null]);
            flash('Rendimento registrado.');
            $destino = 'investimentos.php?id=' . (int) $invM['id'];
        } elseif ($acao === 'excluir_mov') {
            $m = q_one('SELECT m.investimento_id FROM investimento_movs m JOIN investimentos i ON i.id = m.investimento_id WHERE m.id = ? AND i.perfil_id = ?', [(int) ($_POST['mov_id'] ?? 0), perfil_id()]);
            if ($m) {
                rf_excluir_mov((int) $_POST['mov_id']);
                flash('Movimento excluído (e a transferência ligada a ele, se houver).');
                $destino = 'investimentos.php?id=' . $m['investimento_id'];
            }
        } elseif ($acao === 'alternar' && pertence_ao_perfil('investimentos', $id)) {
            q('UPDATE investimentos SET ativo = 1 - ativo WHERE id = ? AND perfil_id = ?', [$id, perfil_id()]);
            flash('Situação alterada.');
        } elseif ($acao === 'excluir' && pertence_ao_perfil('investimentos', $id)) {
            $vinc = (int) q_val('SELECT COUNT(*) FROM lancamentos WHERE investimento_id = ? AND perfil_id = ?', [$id, perfil_id()]);
            if ($vinc > 0) {
                throw new InvalidArgumentException("Este investimento tem $vinc transferência(s) ligada(s) a contas. Exclua-as primeiro (ou inative o investimento).");
            }
            q('DELETE FROM investimentos WHERE id = ? AND perfil_id = ?', [$id, perfil_id()]);
            flash('Investimento e suas movimentações foram excluídos.');
        } elseif ($acao === 'atualizar_indices') {
            $r = indices_atualizar(true);
            flash($r['mensagem'], $r['atualizado'] ? 'success' : 'warning');
        }
    } catch (InvalidArgumentException $e) {
        flash($e->getMessage(), 'danger');
    }
    redirect($destino);
}

indices_garantir();
$invs = investimentos_com_saldo();
$ativos = array_filter($invs, function ($i) { return $i['ativo']; });
$totBruto = $totLiq = $totRend = $totIR = $totPrincipal = 0.0;
foreach ($ativos as $i) {
    $totBruto += $i['saldo'];
    $totLiq += $i['liquido'];
    $totRend += $i['saldo'] - $i['principal'];
    $totIR += $i['ir'] + $i['iof'];
    $totPrincipal += $i['principal'];
}
$idx = indices_resumo();
$contas = array_values(array_filter(contas_do_perfil(true), function ($c) { return $c['tipo'] !== 'investimento'; }));
$detalheId = (int) ($_GET['id'] ?? 0);
$detalhe = null;
foreach ($invs as $i) {
    if ((int) $i['id'] === $detalheId) {
        $detalhe = $i;
    }
}
$movs = $detalhe ? array_reverse(rf_movs((int) $detalhe['id'])) : [];
$serieDet = [];
if ($detalhe && $detalhe['ativo']) {
    $datas = [];
    for ($m = 1; $m <= 12; $m++) {
        $datas[] = date('Y-m-t', strtotime("+$m month", strtotime(date('Y-m-01'))));
    }
    $serieDet = rf_serie($detalhe, rf_movs((int) $detalhe['id']), $datas);
}
$produtoDe = function (array $i): string {
    if ($i['indexador'] === 'poupanca') { return 'poupanca'; }
    if ($i['tipo'] === 'renda_fixa') { return $i['indexador'] === 'pre' ? 'tesouro' : ($i['tributacao'] === 'isento' ? 'lci' : 'cdb'); }
    return in_array($i['tipo'], ['acoes', 'fii', 'fundos', 'cripto', 'previdencia'], true) ? $i['tipo'] : 'outro';
};

$PAGINA = ['Investimentos', 'fa-bar-chart', 'investimentos.php', 'Aplicações, resgates e rendimentos (CDI do Banco Central); saldo bruto como nos bancos, imposto só no resgate', 'warning'];
require __DIR__ . '/header.php';
?>
<div class="kpi-grid">
  <div class="kpi laranja"><i class="fa fa-bar-chart kpi-icone"></i><div class="kpi-label">Patrimônio investido (bruto)</div><div class="kpi-valor"><?= brl($totBruto) ?></div><div class="kpi-detalhe">Como aparece no app do banco, sem desconto de imposto</div></div>
  <div class="kpi verde"><i class="fa fa-money kpi-icone"></i><div class="kpi-label">Líquido se resgatar hoje</div><div class="kpi-valor"><?= brl($totLiq) ?></div><div class="kpi-detalhe">Após IR e IOF estimados: <?= brl($totIR) ?></div></div>
  <div class="kpi azul"><i class="fa fa-line-chart kpi-icone"></i><div class="kpi-label">Rendimento bruto não resgatado</div><div class="kpi-valor text-success"><?= brl($totRend) ?></div><div class="kpi-detalhe">Ganho sobre o que ainda está aplicado (<?= brl($totPrincipal) ?>)</div></div>
  <div class="kpi roxo"><i class="fa fa-percent kpi-icone"></i><div class="kpi-label">CDI hoje</div><div class="kpi-valor"><?= number_format($idx['cdi_anual'], 2, ',', '.') ?>% a.a.</div>
    <div class="kpi-detalhe"><?= $idx['cdi_dia'] !== null ? number_format($idx['cdi_dia'], 4, ',', '.') . '% ao dia útil (' . e(data_br($idx['data'])) . ')' : 'sem dado ainda' ?> · Selic <?= number_format($idx['selic'], 2, ',', '.') ?>%</div></div>
</div>

<?php $invsAtivos = array_values(array_filter($invs, function ($x) { return (int) $x['ativo'] === 1; }));
$invsInativos = array_values(array_filter($invs, function ($x) { return (int) $x['ativo'] !== 1; }));
foreach ([[$invsAtivos, false], [$invsInativos, true]] as [$lista, $ina]):
    if ($ina && !$lista) { continue; }
    if ($ina): ?>
<details style="margin-top:18px"><summary style="cursor:pointer;font-weight:700;color:#475569;margin-bottom:10px"><i class="fa fa-archive"></i> Investimentos inativos (<?= count($lista) ?>)</summary>
<?php endif; ?>
<div class="card card-outline card-warning">
  <div class="card-header">
    <h3 class="card-title"><i class="fa fa-list text-warning"></i> <?= $ina ? 'Investimentos inativos' : 'Carteira' ?> <small class="text-muted" style="font-weight:400">(<?= count($lista) ?>)</small></h3>
    <?php if (!$ina): ?><div class="card-tools">
      <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="acao" value="atualizar_indices"><button class="btn btn-outline-secondary btn-sm" title="Busca o CDI e a Selic no Banco Central. Isso também acontece automaticamente."><i class="fa fa-refresh"></i> Atualizar CDI</button></form>
      <a href="#" class="btn btn-success btn-sm" data-modal="modal-aplicar" data-preencher="<?= e(json_encode(['data' => date('Y-m-d'), 'efetivada' => 1])) ?>"><i class="fa fa-arrow-circle-right"></i> Aplicar</a>
      <a href="#" class="btn btn-primary btn-sm" data-modal="modal-inv" data-novo><i class="fa fa-plus"></i> Novo investimento</a>
    </div><?php endif; ?>
  </div>
  <div class="card-body p-0">
<?php if (!$lista): ?>
  <div class="erp-empty-state"><div class="erp-empty-state-icon"><i class="fa fa-bar-chart"></i></div><div class="erp-empty-state-title">Nenhum investimento cadastrado</div><div class="erp-empty-state-desc">Cadastre o investimento (ex.: CDB BV 110% do CDI) e depois registre a aplicação.</div></div>
<?php else: ?>
<div class="table-responsive">
<table class="table table-striped table-hover m-0">
  <thead><tr><th>Investimento</th><th>Rentabilidade</th><th class="num">Aplicado</th><th class="num">Saldo bruto</th><th class="num">Rendimento</th><th class="num" title="IR e IOF que seriam retidos se resgatasse tudo hoje">IR + IOF</th><th class="num">Saldo líquido</th><th class="text-center">Ações</th></tr></thead>
  <tbody>
  <?php foreach ($lista as $i): $rend = $i['saldo'] - $i['principal']; ?>
    <tr style="<?= $i['ativo'] ? '' : 'opacity:.55' ?>">
      <td><a href="investimentos.php?id=<?= (int) $i['id'] ?>"><strong><?= e($i['nome']) ?></strong></a><?= $i['instituicao'] ? '<br><small class="text-muted">' . e($i['instituicao']) . '</small>' : '' ?>
        <?php if ($i['tributacao'] === 'isento'): ?> <span class="erp-badge erp-badge-success" title="Sem imposto de renda">isento</span><?php endif; ?></td>
      <td><?= e($i['rotulo']) ?><?= $i['liquidez'] === 'vencimento' && $i['data_vencimento'] ? '<br><small class="text-muted">vence ' . e(data_br($i['data_vencimento'])) . '</small>' : '' ?></td>
      <td class="num"><?= brl($i['principal']) ?></td>
      <td class="num"><strong><?= brl($i['saldo']) ?></strong></td>
      <td class="num text-success"><?= brl($rend) ?></td>
      <td class="num"><?= brl($i['ir'] + $i['iof']) ?></td>
      <td class="num"><strong><?= brl($i['liquido']) ?></strong></td>
      <td class="acoes">
        <?php if ($i['ativo']): ?>
          <a href="#" class="erp-btn-acao btn-acao-sucesso" title="Aplicar (conta → investimento)" data-modal="modal-aplicar" data-preencher="<?= e(json_encode(['investimento_id' => $i['id'], 'data' => date('Y-m-d'), 'efetivada' => 1])) ?>"><i class="fa fa-arrow-circle-right"></i></a>
          <a href="#" class="cli-btn-acao btn-acao-ver" title="Resgatar (investimento → conta)" data-modal="modal-resgatar" data-preencher="<?= e(json_encode(['investimento_id' => $i['id'], 'data' => date('Y-m-d'), 'efetivada' => 1, 'modo' => 'total'])) ?>"><i class="fa fa-arrow-circle-left"></i></a>
          <a href="#" class="cli-btn-acao btn-acao-reativar" title="Reajustar saldo" data-modal="modal-reajustar" data-preencher="<?= e(json_encode(['investimento_id' => $i['id'], 'inv_nome' => $i['nome'], 'calculado' => number_format($i['saldo'], 2, ',', '.'), 'calculado_liq' => number_format($i['liquido'], 2, ',', '.'), 'base' => 'bruto', 'data' => date('Y-m-d')])) ?>"><i class="fa fa-sliders"></i></a>
        <?php endif; ?>
        <a href="#" class="cli-btn-acao btn-acao-editar" title="Editar" data-modal="modal-inv" data-preencher="<?= e(json_encode(['id' => $i['id'], 'nome' => $i['nome'], 'instituicao' => $i['instituicao'], 'produto' => $produtoDe($i), 'indexador' => $i['indexador'], 'percentual_cdi' => $i['percentual_cdi'], 'taxa_anual' => $i['taxa_anual'], 'tributacao' => $i['tributacao'], 'liquidez' => $i['liquidez'], 'data_vencimento' => $i['data_vencimento']])) ?>"><i class="fa fa-pencil"></i></a>
        <form method="post" data-confirmar="<?= $i['ativo'] ? 'Inativar &quot;' . e($i['nome']) . '&quot;? Ele sai da carteira e das projeções, mas o histórico é mantido.' : 'Reativar &quot;' . e($i['nome']) . '&quot;?' ?>"><?= csrf_field() ?><input type="hidden" name="acao" value="alternar"><input type="hidden" name="id" value="<?= (int) $i['id'] ?>"><button class="cli-btn-acao btn-acao-reativar" title="<?= $i['ativo'] ? 'Inativar' : 'Reativar' ?>"><i class="fa fa-<?= $i['ativo'] ? 'eye-slash' : 'eye' ?>"></i></button></form>
        <form method="post" data-confirmar="Excluir &quot;<?= e($i['nome']) ?>&quot; e todas as movimentações?"><?= csrf_field() ?><input type="hidden" name="acao" value="excluir"><input type="hidden" name="id" value="<?= (int) $i['id'] ?>"><button class="cli-btn-acao btn-acao-excluir" title="Excluir"><i class="fa fa-trash"></i></button></form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>
  </div>
</div>

<?php if ($ina): ?></details><?php endif;
endforeach; ?>

<?php if ($detalhe): ?>
<div class="card card-outline card-info">
  <div class="card-header"><h3 class="card-title"><i class="fa fa-search text-info"></i> <?= e($detalhe['nome']) ?> — lotes e movimentações</h3>
    <div class="card-tools"><a href="investimentos.php" class="btn btn-outline-secondary btn-sm">Fechar</a></div></div>
  <div class="card-body p-0">
    <div style="padding:14px 20px;font-size:13px;color:#475569">
      <strong><?= e($detalhe['rotulo']) ?></strong> · <?= $detalhe['tributacao'] === 'ir_regressivo' ? 'IR regressivo (22,5% até 180 dias, 20% até 360, 17,5% até 720, 15% acima) + IOF nos primeiros 30 dias' : ($detalhe['tributacao'] === 'isento' ? 'isento de IR' : 'sem imposto calculado') ?>
      · liquidez <?= $detalhe['liquidez'] === 'vencimento' ? 'no vencimento' : 'diária' ?>.
      Cada aplicação é um lote com a própria data; resgates consomem o lote mais antigo primeiro.
    </div>
    <?php if ($detalhe['lotes']): ?>
    <div class="table-responsive"><table class="table table-striped table-hover m-0">
      <thead><tr><th>Aplicado em</th><th class="num">Dias</th><th class="num">Principal</th><th class="num">Valor bruto</th><th class="num">Ganho</th><th class="num">IOF</th><th class="num">IR</th><th class="num">Líquido</th></tr></thead>
      <tbody>
      <?php foreach ($detalhe['lotes'] as $l):
          $iofL = $detalhe['tributacao'] === 'ir_regressivo' ? $l['ganho'] * $l['iof_pct'] / 100 : 0;
          $irL = $detalhe['tributacao'] === 'ir_regressivo' ? ($l['ganho'] - $iofL) * $l['aliquota_ir'] / 100 : 0; ?>
        <tr><td><?= e(data_br($l['data'])) ?></td><td class="num"><?= (int) $l['dias'] ?></td><td class="num"><?= brl($l['principal']) ?></td><td class="num"><strong><?= brl($l['valor']) ?></strong></td>
          <td class="num text-success"><?= brl($l['ganho']) ?></td><td class="num"><?= brl($iofL) ?><?= $l['iof_pct'] > 0 ? ' <small class="text-muted">(' . number_format($l['iof_pct'], 0) . '%)</small>' : '' ?></td>
          <td class="num"><?= brl($irL) ?><?= $l['aliquota_ir'] > 0 ? ' <small class="text-muted">(' . number_format($l['aliquota_ir'], 1, ',', '.') . '%)</small>' : '' ?></td><td class="num"><strong><?= brl($l['valor'] - $iofL - $irL) ?></strong></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </div>
</div>

<?php if ($serieDet): ?>
<div class="card card-outline card-success">
  <div class="card-header"><h3 class="card-title"><i class="fa fa-line-chart text-success"></i> Projeção dos próximos 12 meses <small class="text-muted" style="font-weight:400">(CDI de hoje: <?= number_format($idx['cdi_anual'], 2, ',', '.') ?>% a.a., incluindo aplicações já agendadas)</small></h3>
    <div class="card-tools"><a href="projecao.php" class="btn btn-outline-secondary btn-sm">Cenários na tela de Projeção</a></div></div>
  <div class="card-body p-0"><div class="table-responsive"><table class="table table-striped table-hover m-0">
    <thead><tr><th>Fim de</th><th class="num">Saldo bruto</th><th class="num">IR + IOF se resgatar</th><th class="num">Saldo líquido</th></tr></thead>
    <tbody><?php foreach ($serieDet as $d => $x): ?>
      <tr><td><?= e(mes_label(substr($d, 0, 7))) ?></td><td class="num"><?= brl($x['bruto']) ?></td><td class="num"><?= brl($x['ir'] + $x['iof']) ?></td><td class="num"><strong><?= brl($x['liquido']) ?></strong></td></tr>
    <?php endforeach; ?></tbody></table></div></div>
</div>
<?php endif; ?>

<div class="card card-outline card-secondary">
  <div class="card-header"><h3 class="card-title"><i class="fa fa-history text-secondary"></i> Movimentações</h3>
    <?php if ($detalhe['indexador'] === 'manual'): ?><div class="card-tools"><a href="#" class="btn btn-outline-primary btn-sm" data-modal="modal-manual" data-preencher="<?= e(json_encode(['investimento_id' => $detalhe['id'], 'data' => date('Y-m-d')])) ?>"><i class="fa fa-plus"></i> Registrar rendimento</a></div><?php endif; ?></div>
  <div class="card-body p-0">
  <?php if (!$movs): ?><div class="erp-empty-state"><div class="erp-empty-state-icon"><i class="fa fa-inbox"></i></div><div class="erp-empty-state-title">Sem movimentações</div></div><?php else: ?>
  <div class="table-responsive"><table class="table table-striped table-hover m-0">
    <thead><tr><th>Data</th><th>Tipo</th><th>Observação</th><th class="num">Valor bruto</th><th class="num">IR</th><th class="num">IOF</th><th class="num">Líquido</th><th class="text-center">Ações</th></tr></thead>
    <tbody>
    <?php foreach ($movs as $m): $rot = ['aporte' => 'Aplicação', 'resgate' => 'Resgate', 'rendimento' => 'Rendimento', 'reajuste' => 'Reajuste'][$m['tipo']]; ?>
      <tr><td><?= e(data_br($m['data'])) ?></td>
        <td><span class="erp-badge erp-badge-<?= $m['tipo'] === 'aporte' ? 'info' : ($m['tipo'] === 'resgate' ? 'warning' : 'success') ?>"><?= e($rot) ?></span><?= $m['lancamento_id'] ? ' <i class="fa fa-exchange text-muted" title="Ligado a uma transferência de conta"></i>' : '' ?></td>
        <td><?= e($m['obs']) ?></td>
        <td class="num <?= $m['tipo'] === 'resgate' ? 'text-danger' : ($m['valor'] < 0 ? 'text-danger' : 'text-success') ?>"><?= $m['tipo'] === 'resgate' ? '−' : ($m['valor'] < 0 ? '−' : '+') ?> <?= brl(abs($m['valor'])) ?></td>
        <td class="num"><?= $m['tipo'] === 'resgate' ? brl($m['ir']) : '' ?></td><td class="num"><?= $m['tipo'] === 'resgate' ? brl($m['iof']) : '' ?></td>
        <td class="num"><?= $m['tipo'] === 'resgate' ? '<strong>' . brl($m['valor'] - $m['ir'] - $m['iof']) . '</strong>' : '' ?></td>
        <td class="acoes"><form method="post" data-confirmar="Excluir esta movimentação<?= $m['lancamento_id'] ? ' e a transferência de conta ligada a ela' : '' ?>?"><?= csrf_field() ?><input type="hidden" name="acao" value="excluir_mov"><input type="hidden" name="mov_id" value="<?= (int) $m['id'] ?>"><button class="cli-btn-acao btn-acao-excluir"><i class="fa fa-trash"></i></button></form></td></tr>
    <?php endforeach; ?></tbody></table></div><?php endif; ?>
  </div>
</div>
<?php endif; ?>

<div class="modal fin-modal erp-modal-md" id="modal-inv">
  <form class="modal-dialog" method="post" id="form-inv">
    <?= csrf_field() ?><input type="hidden" name="acao" value="salvar"><input type="hidden" name="id">
    <div class="modal-header"><h4 class="modal-title"><i class="fa fa-bar-chart"></i> Investimento</h4><button type="button" class="close" data-fechar>&times;</button></div>
    <div class="modal-body">
      <div class="form-group"><label>Nome</label><input class="form-control" name="nome" maxlength="120" required placeholder="Ex.: CDB BV 110% do CDI, Nubank 120% CDI"></div>
      <div class="form-grid">
        <div class="form-group"><label>Instituição</label><input class="form-control" name="instituicao" maxlength="80" placeholder="BV, Nubank, PicPay, Sicredi, Itaú…"></div>
        <div class="form-group"><label>Produto</label><select class="form-control" name="produto" id="inv-produto"><?php foreach (INV_PRODUTOS as $k => $v): ?><option value="<?= e($k) ?>"><?= e($v[0]) ?></option><?php endforeach; ?></select></div>
        <div class="form-group"><label>Rende como</label><select class="form-control" name="indexador" id="inv-indexador"><option value="cdi">% do CDI</option><option value="pre">Pré-fixado (% a.a.)</option><option value="poupanca">Poupança</option><option value="manual">Manual (informo os rendimentos)</option></select></div>
        <div class="form-group" id="g-pct"><label>Percentual do CDI</label><div class="fin-field-suffix"><input class="form-control" name="percentual_cdi" inputmode="decimal" value="100"><span class="fin-suffix">%</span></div></div>
        <div class="form-group" id="g-taxa"><label>Taxa a.a. (pré-fixada ou estimada)</label><div class="fin-field-suffix"><input class="form-control" name="taxa_anual" inputmode="decimal" value="0"><span class="fin-suffix">%</span></div></div>
        <div class="form-group"><label>Imposto</label><select class="form-control" name="tributacao" id="inv-trib"><option value="ir_regressivo">IR regressivo + IOF (CDB, RDB, cofrinho)</option><option value="isento">Isento (LCI, LCA, poupança)</option><option value="nenhuma">Não calcular</option></select></div>
        <div class="form-group"><label>Liquidez</label><select class="form-control" name="liquidez" id="inv-liq"><option value="diaria">Diária (resgata quando quiser)</option><option value="vencimento">No vencimento</option></select></div>
        <div class="form-group" id="g-venc"><label>Vencimento</label><input class="form-control" type="date" name="data_vencimento"></div>
      </div>
      <p class="text-muted" style="font-size:12px;margin:10px 0 0">Os bancos mostram o saldo <strong>bruto</strong> (sem imposto). O sistema calcula o rendimento nos dias úteis com o CDI do Banco Central e estima o imposto que seria retido no resgate.</p>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-fechar>Cancelar</button><button class="btn btn-primary"><i class="fa fa-check"></i> Salvar</button></div>
  </form>
</div>

<div class="modal fin-modal erp-modal-md" id="modal-aplicar">
  <form class="modal-dialog" method="post">
    <?= csrf_field() ?><input type="hidden" name="acao" value="aplicar">
    <div class="modal-header"><h4 class="modal-title"><i class="fa fa-arrow-circle-right"></i> Aplicar (conta → investimento)</h4><button type="button" class="close" data-fechar>&times;</button></div>
    <div class="modal-body">
      <div class="form-grid">
        <div class="form-group"><label>Conta de origem</label><select class="form-control" name="conta_id"><option value="0">— sem débito em conta (aplicação que já existia) —</option><?= opcoes($contas) ?></select></div>
        <div class="form-group"><label>Investimento</label><select class="form-control" name="investimento_id" required><option value="">Selecione…</option><?= opcoes(array_values($ativos)) ?></select></div>
        <div class="form-group"><label>Valor</label><div class="fin-field-prefix"><span class="fin-prefix">R$</span><input class="form-control" name="valor" inputmode="decimal" required placeholder="0,00"></div></div>
        <div class="form-group"><label>Data da aplicação</label><input class="form-control" type="date" name="data" required></div>
        <div class="form-group span-2"><label>Observação</label><input class="form-control" name="obs" maxlength="160"></div>
        <div class="form-group" style="display:flex;align-items:center;gap:8px"><input type="checkbox" name="efetivada" value="1" id="ap-ef" style="width:auto" checked><label for="ap-ef" style="margin:0">Já foi realizada</label></div>
      </div>
      <p class="text-muted" style="font-size:12px;margin:10px 0 0">Com conta de origem, a aplicação é uma <strong>transferência</strong>: debita a conta e abre um lote no investimento. Sem conta, só registra o lote (use para o que você já tinha aplicado antes de usar o sistema; depois acerte o saldo em “Reajustar”).</p>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-fechar>Cancelar</button><button class="btn btn-success"><i class="fa fa-check"></i> Aplicar</button></div>
  </form>
</div>

<div class="modal fin-modal erp-modal-md" id="modal-resgatar">
  <form class="modal-dialog" method="post" id="form-resgate">
    <?= csrf_field() ?><input type="hidden" name="acao" value="resgatar">
    <div class="modal-header"><h4 class="modal-title"><i class="fa fa-arrow-circle-left"></i> Resgatar (investimento → conta)</h4><button type="button" class="close" data-fechar>&times;</button></div>
    <div class="modal-body">
      <div class="form-grid">
        <div class="form-group"><label>Investimento</label><select class="form-control" name="investimento_id" required><option value="">Selecione…</option><?= opcoes(array_values($ativos)) ?></select></div>
        <div class="form-group"><label>Conta de destino</label><select class="form-control" name="conta_id"><option value="0">— sem crédito em conta —</option><?= opcoes($contas) ?></select></div>
        <div class="form-group"><label>Quanto resgatar</label><select class="form-control" name="modo"><option value="total">Tudo (resgate total)</option><option value="valor">Um valor (líquido na conta)</option></select></div>
        <div class="form-group" id="rs-valor"><label>Valor líquido na conta</label><div class="fin-field-prefix"><span class="fin-prefix">R$</span><input class="form-control" name="valor" inputmode="decimal" placeholder="0,00"></div></div>
        <div class="form-group"><label>Data do resgate</label><input class="form-control" type="date" name="data" required></div>
        <div class="form-group" style="display:flex;align-items:center;gap:8px;padding-top:22px"><input type="checkbox" name="efetivada" value="1" id="rs-ef" style="width:auto" checked><label for="rs-ef" style="margin:0">Já foi realizado</label></div>
        <div class="form-group span-2"><label>Observação</label><input class="form-control" name="obs" maxlength="160"></div>
      </div>
      <input type="hidden" name="ir_manual"><input type="hidden" name="iof_manual">
      <div class="painel" id="rs-previa" style="margin:12px 0 0;padding:12px 16px">
        <div id="rs-msg" class="text-muted">Escolha o investimento e a data para ver o imposto.</div>
        <div id="rs-detalhe" class="hidden">
          <div class="form-grid">
            <div class="form-group"><label>Saldo bruto na data</label><div class="fin-field-prefix"><span class="fin-prefix">R$</span><input class="form-control" id="rs-saldo" readonly></div></div>
            <div class="form-group"><label>Líquido na conta</label><div class="fin-field-prefix"><span class="fin-prefix">R$</span><input class="form-control" id="rs-liq" readonly style="font-weight:700;color:#16a34a"></div></div>
            <div class="form-group"><label>IR (editável)</label><div class="fin-field-prefix"><span class="fin-prefix">R$</span><input class="form-control" id="rs-ir" inputmode="decimal"></div></div>
            <div class="form-group"><label>IOF (editável)</label><div class="fin-field-prefix"><span class="fin-prefix">R$</span><input class="form-control" id="rs-iof" inputmode="decimal"></div></div>
            <div class="form-group span-2"><label>Debitado do investimento (bruto)</label><div class="fin-field-prefix"><span class="fin-prefix">R$</span><input class="form-control" id="rs-bruto" readonly></div></div>
          </div>
          <div id="rs-av"></div>
          <p class="text-muted" style="font-size:12px;margin:6px 0 0">A conta recebe exatamente o valor líquido; o imposto sai do próprio investimento. Se o banco cobrar IR ou IOF diferentes do calculado, corrija os campos acima.</p>
        </div>
      </div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-fechar>Cancelar</button><button class="btn btn-primary"><i class="fa fa-check"></i> Resgatar</button></div>
  </form>
</div>

<div class="modal fin-modal erp-modal-md" id="modal-reajustar">
  <form class="modal-dialog" method="post">
    <?= csrf_field() ?><input type="hidden" name="acao" value="reajustar"><input type="hidden" name="investimento_id">
    <div class="modal-header"><h4 class="modal-title"><i class="fa fa-sliders"></i> Reajustar saldo do investimento</h4><button type="button" class="close" data-fechar>&times;</button></div>
    <div class="modal-body">
      <p style="margin-top:0"><strong id="rj-inv"></strong></p>
      <div class="form-grid">
        <div class="form-group"><label>Saldo bruto calculado hoje</label><div class="fin-field-prefix"><span class="fin-prefix">R$</span><input class="form-control" name="calculado" readonly></div></div>
        <div class="form-group"><label>Saldo líquido calculado hoje</label><div class="fin-field-prefix"><span class="fin-prefix">R$</span><input class="form-control" name="calculado_liq" readonly></div></div>
        <div class="form-group"><label>O saldo que vou informar é</label><select class="form-control" name="base"><option value="bruto">Bruto (como o app do banco mostra)</option><option value="liquido">Líquido (após IR e IOF)</option></select></div>
        <div class="form-group"><label id="rj-rotulo">Saldo bruto no app do banco</label><div class="fin-field-prefix"><span class="fin-prefix">R$</span><input class="form-control" name="saldo" inputmode="decimal" required placeholder="0,00" style="font-weight:700"></div></div>
        <div class="form-group"><label>Data do saldo</label><input class="form-control" type="date" name="data" required></div>
        <div class="form-group"><label>Observação</label><input class="form-control" name="obs" maxlength="160" placeholder="Reajuste de saldo"></div>
      </div>
      <p class="text-muted" style="font-size:12px;margin:10px 0 0">Bancos como BV e Nubank mostram o saldo <strong>bruto</strong> (o imposto só sai no resgate): use <em>Bruto</em>. Se informar o <em>líquido</em>, o sistema calcula o bruto equivalente. O ajuste é lançado sempre sobre o saldo bruto, que passa a render a partir dele.</p>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-fechar>Cancelar</button><button class="btn btn-primary"><i class="fa fa-check"></i> Reajustar</button></div>
  </form>
</div>

<div class="modal fin-modal erp-modal-md" id="modal-manual">
  <form class="modal-dialog" method="post">
    <?= csrf_field() ?><input type="hidden" name="acao" value="mov_manual"><input type="hidden" name="investimento_id">
    <div class="modal-header"><h4 class="modal-title"><i class="fa fa-plus-circle"></i> Registrar rendimento</h4><button type="button" class="close" data-fechar>&times;</button></div>
    <div class="modal-body"><div class="form-grid">
      <div class="form-group"><label>Valor (negativo = perda)</label><div class="fin-field-prefix"><span class="fin-prefix">R$</span><input class="form-control" name="valor" inputmode="decimal" required></div></div>
      <div class="form-group"><label>Data</label><input class="form-control" type="date" name="data" required></div>
      <div class="form-group span-2"><label>Observação</label><input class="form-control" name="obs" maxlength="160"></div>
    </div></div>
    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-fechar>Cancelar</button><button class="btn btn-primary"><i class="fa fa-check"></i> Registrar</button></div>
  </form>
</div>

<script>
(function () {
  // presets por produto (indexador e imposto), como nos bancos
  var PRESETS = <?= json_encode(array_map(function ($v) { return ['indexador' => $v[1], 'tributacao' => $v[2]]; }, INV_PRODUTOS)) ?>;
  var f = document.getElementById('form-inv');
  var prod = document.getElementById('inv-produto'), idx = document.getElementById('inv-indexador'), trib = document.getElementById('inv-trib'), liq = document.getElementById('inv-liq');
  function campos() {
    document.getElementById('g-pct').classList.toggle('hidden', idx.value !== 'cdi');
    document.getElementById('g-taxa').classList.toggle('hidden', idx.value !== 'pre' && idx.value !== 'manual');
    document.getElementById('g-venc').classList.toggle('hidden', liq.value !== 'vencimento');
  }
  // so quando a pessoa troca o produto: ao abrir um investimento para editar, o modal dispara 'change' por script
  // (isTrusted = false) e nao pode trocar o indexador e o imposto ja salvos
  prod.addEventListener('change', function (ev) { if (!ev.isTrusted) { return; } var p = PRESETS[prod.value]; if (p) { idx.value = p.indexador; trib.value = p.tributacao; campos(); } });
  [idx, liq].forEach(function (el) { el.addEventListener('change', campos); });
  new MutationObserver(campos).observe(document.getElementById('modal-inv'), { attributes: true, attributeFilter: ['class'] });
  campos();

  // modal de reajuste: nome do investimento
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-modal="modal-reajustar"]');
    if (b) { try { document.getElementById('rj-inv').textContent = JSON.parse(b.dataset.preencher).inv_nome; } catch (err) {} }
  });
  var rjBase = document.querySelector('#modal-reajustar [name=base]');
  if (rjBase) { rjBase.addEventListener('change', function () { document.getElementById('rj-rotulo').textContent = this.value === 'liquido' ? 'Saldo líquido (após IR e IOF)' : 'Saldo bruto no app do banco'; }); }

  // resgate pelo valor liquido: previa no servidor; IR e IOF editaveis (o bruto = liquido + IR + IOF)
  var fr = document.getElementById('form-resgate');
  var el = function (id) { return document.getElementById(id); };
  var fmt = function (v) { return Number(v).toFixed(2).replace('.', ','); };
  function previa(editado) {
    var modo = fr.elements.modo.value;
    el('rs-valor').classList.toggle('hidden', modo === 'total');
    var inv = fr.elements.investimento_id.value, data = fr.elements.data.value;
    if (!inv || !data || (modo === 'valor' && !fr.elements.valor.value)) { el('rs-msg').classList.remove('hidden'); el('rs-msg').textContent = 'Escolha o investimento, a data e o valor para ver o imposto.'; el('rs-detalhe').classList.add('hidden'); return; }
    var qs = 'inv=' + encodeURIComponent(inv) + '&data=' + encodeURIComponent(data) + (modo === 'valor' ? '&valor=' + encodeURIComponent(fr.elements.valor.value) : '');
    if (editado === true) { qs += '&ir=' + encodeURIComponent(el('rs-ir').value) + '&iof=' + encodeURIComponent(el('rs-iof').value); }
    fetch('api_resgate_previa.php?' + qs, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
      if (d.erro) { el('rs-msg').classList.remove('hidden'); el('rs-msg').innerHTML = '<span class="text-danger"><i class="fa fa-exclamation-triangle"></i> ' + escapeHtml(d.erro) + '</span>'; el('rs-detalhe').classList.add('hidden'); return; }
      el('rs-msg').classList.add('hidden'); el('rs-detalhe').classList.remove('hidden');
      el('rs-saldo').value = fmt(d.saldo_bruto); el('rs-liq').value = fmt(d.liquido); el('rs-bruto').value = fmt(d.bruto);
      if (editado !== true) { el('rs-ir').value = fmt(d.ir); el('rs-iof').value = fmt(d.iof); fr.elements.ir_manual.value = ''; fr.elements.iof_manual.value = ''; }
      el('rs-av').innerHTML = (d.avisos || []).map(function (a) { return '<div class="text-warning" style="font-size:12px">⚠ ' + escapeHtml(a) + '</div>'; }).join('');
    }).catch(function () { el('rs-msg').classList.remove('hidden'); el('rs-msg').innerHTML = '<span class="text-danger">Não foi possível calcular a prévia.</span>'; });
  }
  ['investimento_id', 'modo', 'valor', 'data'].forEach(function (n) { fr.elements[n].addEventListener('change', function () { previa(false); }); fr.elements[n].addEventListener('input', function () { previa(false); }); });
  ['rs-ir', 'rs-iof'].forEach(function (id) {
    el(id).addEventListener('input', function () { fr.elements.ir_manual.value = el('rs-ir').value; fr.elements.iof_manual.value = el('rs-iof').value; previa(true); });
  });
  new MutationObserver(function () { setTimeout(function () { previa(false); }, 60); }).observe(document.getElementById('modal-resgatar'), { attributes: true, attributeFilter: ['class'] });
})();
</script>
<?php require __DIR__ . '/footer.php'; ?>
