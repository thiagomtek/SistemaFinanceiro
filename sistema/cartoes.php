<?php
require_once __DIR__ . '/auth_check.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_exigir();
    $acao = $_POST['acao'] ?? '';
    $id = (int) ($_POST['id'] ?? 0);
    try {
        if ($acao === 'salvar') {
            $nome = trim($_POST['nome'] ?? '');
            $limite = max(0, parse_valor($_POST['limite'] ?? 0));
            $fech = (int) ($_POST['dia_fechamento'] ?? 0);
            $venc = (int) ($_POST['dia_vencimento'] ?? 0);
            $conta = (int) ($_POST['conta_pagamento_id'] ?? 0) ?: null;
            if ($nome === '' || $fech < 1 || $fech > 31 || $venc < 1 || $venc > 31) {
                throw new InvalidArgumentException('Informe nome e dias de fechamento e vencimento entre 1 e 31.');
            }
            if ($conta && !pertence_ao_perfil('contas', $conta)) {
                throw new InvalidArgumentException('Conta de pagamento inválida.');
            }
            if (q_val('SELECT id FROM cartoes WHERE perfil_id = ? AND nome = ? AND id <> ?', [perfil_id(), $nome, $id])) {
                throw new InvalidArgumentException('Já existe um cartão com esse nome.');
            }
            if ($id && pertence_ao_perfil('cartoes', $id)) {
                q('UPDATE cartoes SET nome=?, limite=?, dia_fechamento=?, dia_vencimento=?, conta_pagamento_id=? WHERE id=? AND perfil_id=?', [$nome, $limite, $fech, $venc, $conta, $id, perfil_id()]);
                // reclassifica as compras comuns ainda em aberto conforme o novo fechamento/vencimento.
                // Reajustes e itens de fatura importados mantem o mes da fatura em que foram lancados.
                $c = q_one('SELECT * FROM cartoes WHERE id = ? AND perfil_id = ?', [$id, perfil_id()]);
                $pagas = array_column(q_all('SELECT mes FROM faturas WHERE cartao_id = ?', [$id]), 'mes');
                foreach (q_all('SELECT id, data, fatura_mes FROM lancamentos WHERE cartao_id = ? AND perfil_id = ? AND ajuste IS NULL AND importacao_id IS NULL', [$id, perfil_id()]) as $l) {
                    if (in_array($l['fatura_mes'], $pagas, true)) {
                        continue;
                    }
                    q('UPDATE lancamentos SET fatura_mes = ? WHERE id = ? AND perfil_id = ?', [fatura_mes_da_compra($c, $l['data']), $l['id'], perfil_id()]);
                }
                flash('Cartão atualizado. Compras comuns de faturas em aberto foram reclassificadas.');
            } else {
                q_insert('INSERT INTO cartoes (perfil_id, nome, limite, dia_fechamento, dia_vencimento, conta_pagamento_id) VALUES (?,?,?,?,?,?)', [perfil_id(), $nome, $limite, $fech, $venc, $conta]);
                flash('Cartão cadastrado.');
            }
        } elseif ($acao === 'alternar' && pertence_ao_perfil('cartoes', $id)) {
            if (q_val('SELECT ativo FROM cartoes WHERE id = ?', [$id])) {
                $n = cartao_inativar($id);
                flash('Cartão inativado.' . ($n ? ' ' . $n . ' item(ns) de faturas ainda a vencer foram excluídos.' : ''));
            } else {
                q('UPDATE cartoes SET ativo = 1 WHERE id = ? AND perfil_id = ?', [$id, perfil_id()]);
                flash('Cartão reativado.');
            }
        } elseif ($acao === 'excluir' && pertence_ao_perfil('cartoes', $id)) {
            if (cartao_tem_historico($id)) {
                throw new InvalidArgumentException('Este cartão tem compras ou faturas pagas no passado e não pode ser excluído (isso mudaria saldos e extratos). Inative-o.');
            }
            $it = cartao_itens_a_vencer($id);
            anexos_remover_arquivos($it['ids']);
            q('DELETE FROM lancamentos WHERE cartao_id = ? AND perfil_id = ?', [$id, perfil_id()]);
            q('DELETE FROM cartoes WHERE id = ? AND perfil_id = ?', [$id, perfil_id()]);
            flash('Cartão excluído.');
        } elseif ($acao === 'pagar') {
            $data = parse_data($_POST['data'] ?? '') ?: date('Y-m-d');
            pagar_fatura((int) ($_POST['cartao_id'] ?? 0), (string) ($_POST['mes'] ?? ''), (int) ($_POST['conta_id'] ?? 0), $data, $_POST['valor'] ?? null);
            flash('Pagamento registrado e debitado da conta.');
        } elseif ($acao === 'ajustar') {
            $r = ajustar_fatura((int) ($_POST['cartao_id'] ?? 0), (string) ($_POST['mes'] ?? ''), $_POST['valor_final'] ?? '');
            if ($r['negativo']) {
                flash('Fatura ajustada. Atenção: o valor é menor que a soma das compras, então o reajuste ficou negativo (' . brl($r['ajuste']) . ').', 'warning');
            } else {
                flash($r['ajuste'] == 0 ? 'Fatura ajustada: não há reajuste (o valor é igual à soma das compras).' : 'Fatura ajustada com reajuste de ' . brl($r['ajuste']) . '.');
            }
        } elseif ($acao === 'reabrir') {
            reabrir_fatura((int) ($_POST['cartao_id'] ?? 0), (string) ($_POST['mes'] ?? ''));
            flash('Fatura reaberta e pagamento estornado.');
        }
    } catch (InvalidArgumentException $e) {
        flash($e->getMessage(), 'danger');
    }
    redirect('cartoes.php');
}

$cartoes = cartoes_do_perfil();
$cartoesAtivos = array_values(array_filter($cartoes, function ($c) { return (int) $c['ativo'] === 1; }));
$cartoesInativos = array_values(array_filter($cartoes, function ($c) { return (int) $c['ativo'] !== 1; }));
$contas = contas_do_perfil(true);
$hoje = date('Y-m-d');

$PAGINA = ['Cartões e faturas', 'fa-credit-card', 'cartoes.php', 'Compras entram na fatura pelo fechamento; o pagamento debita a conta; use Ajustar fatura para informar só o total', 'purple'];
require __DIR__ . '/header.php';
?>
<?php
// resumo geral (cartoes ativos): em aberto, proximo vencimento, vencidas e limite
$totAberto = 0.0;
$vencidas = 0;
$proxVenc = null;
$limiteTotal = 0.0;
$usadoTotal = 0.0;
foreach ($cartoesAtivos as $kc) {
    $limiteTotal += (float) $kc['limite'];
    $usadoTotal += limite_utilizado($kc);
    foreach (faturas_do_cartao($kc) as $kf) {
        if ($kf['paga'] || abs($kf['total']) < 0.005) {
            continue;
        }
        $totAberto += $kf['total'];
        if ($kf['status'] === 'vencida') {
            $vencidas++;
        } elseif ($proxVenc === null || $kf['vencimento'] < $proxVenc['vencimento']) {
            $proxVenc = $kf + ['cartao' => $kc['nome']];
        }
    }
}
?>
<div class="kpi-grid">
  <div class="kpi roxo"><i class="fa fa-credit-card kpi-icone"></i><div class="kpi-label">Faturas em aberto</div><div class="kpi-valor"><?= brl($totAberto) ?></div><div class="kpi-detalhe"><?= $vencidas ? '<span class="text-danger">' . $vencidas . ' vencida(s)</span>' : 'Nenhuma vencida' ?></div></div>
  <div class="kpi azul"><i class="fa fa-calendar kpi-icone"></i><div class="kpi-label">Próximo vencimento</div><div class="kpi-valor" style="font-size:18px"><?= $proxVenc ? e(data_br($proxVenc['vencimento'])) : '—' ?></div><div class="kpi-detalhe"><?= $proxVenc ? e($proxVenc['cartao']) . ' · ' . brl($proxVenc['total']) : 'Sem faturas a vencer' ?></div></div>
  <div class="kpi laranja"><i class="fa fa-tachometer kpi-icone"></i><div class="kpi-label">Limite utilizado</div><div class="kpi-valor"><?= $limiteTotal > 0 ? number_format($usadoTotal / $limiteTotal * 100, 1, ',', '.') . '%' : '—' ?></div><div class="kpi-detalhe"><?= brl($usadoTotal) ?> de <?= brl($limiteTotal) ?></div></div>
  <div class="kpi verde"><i class="fa fa-check-circle kpi-icone"></i><div class="kpi-label">Limite disponível</div><div class="kpi-valor"><?= brl($limiteTotal - $usadoTotal) ?></div><div class="kpi-detalhe"><?= count($cartoesAtivos) ?> cartão(ões) ativo(s)</div></div>
</div>

<div class="card card-outline card-info">
  <div class="card-header">
    <h3 class="card-title"><i class="fa fa-credit-card text-info"></i> Meus cartões <small class="text-muted" style="font-weight:400">(<?= count($cartoesAtivos) ?><?= $cartoesInativos ? ' ativos · ' . count($cartoesInativos) . ' inativos' : '' ?>)</small></h3>
    <div class="card-tools">
      <a href="importar_fatura.php" class="btn btn-outline-success btn-sm"><i class="fa fa-upload"></i> Importar fatura</a>
      <a href="#" class="btn btn-primary btn-sm" data-modal="modal-cartao" data-novo><i class="fa fa-plus"></i> Novo cartão</a>
    </div>
  </div>
</div>

<?php if (!$cartoesAtivos && !$cartoesInativos): ?>
  <div class="erp-empty-state"><div class="erp-empty-state-icon"><i class="fa fa-credit-card"></i></div><div class="erp-empty-state-title">Nenhum cartão cadastrado</div><div class="erp-empty-state-desc">Cadastre um cartão para lançar compras e importar faturas.</div></div>
<?php endif; ?>

<?php foreach ([[$cartoesAtivos, false], [$cartoesInativos, true]] as [$lista, $areaInativos]):
    if ($areaInativos && $lista): ?>
<details class="cartoes-inativos" style="margin-top:18px"><summary style="cursor:pointer;font-weight:700;color:#475569;margin-bottom:10px"><i class="fa fa-archive"></i> Cartões inativos (<?= count($lista) ?>)</summary>
<?php endif;
?>
<div class="grid-cards">
<?php foreach ($lista as $c):
    $usado = limite_utilizado($c);
    $pct = $c['limite'] > 0 ? min(100, $usado / $c['limite'] * 100) : 0;
    $faturas = faturas_do_cartao($c); // ordem crescente (mais antiga primeiro)
    // lista do card: so a ultima fatura paga + as abertas (ate $mostrar linhas); o resto fica no modal "todas as faturas"
    $ultimaPaga = null;
    foreach ($faturas as $i => $f0) {
        if ($f0['paga']) {
            $ultimaPaga = $i;
        }
    }
    $visiveis = [];
    if ($ultimaPaga !== null) {
        $visiveis[$ultimaPaga] = true;
    }
    foreach ($faturas as $i => $f0) {
        if (!$f0['paga'] && count($visiveis) < 5) {
            $visiveis[$i] = true;
        }
    }
    $corr = fatura_corrente($c);
    $contaNome = '';
    foreach ($contas as $ct) { if ($ct['id'] == $c['conta_pagamento_id']) { $contaNome = $ct['nome']; } }
?>
<div class="card card-outline <?= $c['ativo'] ? 'card-primary' : 'card-secondary' ?>">
  <div class="card-header">
    <h3 class="card-title"><i class="fa fa-credit-card text-info"></i> <a href="fatura.php?cartao=<?= (int) $c['id'] ?>"><?= e($c['nome']) ?></a>
      <small class="text-muted" style="font-weight:400;margin-left:8px">fecha dia <?= (int) $c['dia_fechamento'] ?> · vence dia <?= (int) $c['dia_vencimento'] ?><?= $contaNome ? ' · paga com ' . e($contaNome) : '' ?></small></h3>
    <div class="card-tools">
      <a href="#" class="cli-btn-acao btn-acao-editar" title="Editar cartão" data-modal="modal-cartao" data-preencher="<?= e(json_encode(['id' => $c['id'], 'nome' => $c['nome'], 'limite' => $c['limite'], 'dia_fechamento' => $c['dia_fechamento'], 'dia_vencimento' => $c['dia_vencimento'], 'conta_pagamento_id' => $c['conta_pagamento_id']])) ?>"><i class="fa fa-pencil"></i></a>
      <?php $aVencer = $c['ativo'] ? cartao_itens_a_vencer((int) $c['id']) : null; ?>
      <form method="post" style="display:inline" data-confirmar="<?= $c['ativo'] ? 'Inativar o cartão &quot;' . e($c['nome']) . '&quot;?' . ($aVencer && $aVencer['ids'] ? ' Os ' . count($aVencer['ids']) . ' item(ns) de faturas ainda a vencer (' . brl($aVencer['total']) . ') serão EXCLUÍDOS.' : ' Não há itens a vencer.') : 'Reativar o cartão &quot;' . e($c['nome']) . '&quot;?' ?>"><?= csrf_field() ?><input type="hidden" name="acao" value="alternar"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><button class="cli-btn-acao btn-acao-reativar" title="<?= $c['ativo'] ? 'Inativar cartão' : 'Reativar cartão' ?>"><i class="fa fa-<?= $c['ativo'] ? 'eye-slash' : 'eye' ?>"></i></button></form>
      <form method="post" style="display:inline" data-confirmar="<?= cartao_tem_historico((int) $c['id']) ? 'Este cartão tem compras ou faturas pagas no passado: não pode ser excluído, só inativado.' : 'Excluir o cartão &quot;' . e($c['nome']) . '&quot; e seus itens futuros?' ?>"><?= csrf_field() ?><input type="hidden" name="acao" value="excluir"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><button class="cli-btn-acao btn-acao-excluir" title="Excluir cartão"><i class="fa fa-trash"></i></button></form>
    </div>
  </div>
  <div class="card-body">
    <div class="grid-2" style="align-items:center;margin-bottom:0">
      <div>
        <?php if ($corr): [$stRot, $stCls] = fatura_status_visual($corr['status']); ?>
          <div class="text-muted" style="font-size:12px;text-transform:uppercase">Fatura atual · <?= e(mes_label($corr['mes'])) ?></div>
          <div style="font-size:22px;font-weight:700" class="<?= $corr['status'] === 'vencida' ? 'text-danger' : '' ?>"><?= brl($corr['total']) ?> <span class="badge badge-<?= e($stCls) ?>" style="font-size:11px;vertical-align:middle"><?= e($stRot) ?></span></div>
          <div class="text-muted" style="font-size:13px"><?= $corr['status'] === 'vencida' ? 'Venceu em' : 'Vence em' ?> <?= e(data_br($corr['vencimento'])) ?></div>
        <?php else: ?>
          <div class="text-muted">Sem fatura em aberto.</div>
        <?php endif; ?>
      </div>
      <div>
        <div class="d-flex justify-between" style="font-size:13px"><span>Utilizado <strong><?= brl($usado) ?></strong> <span class="text-muted">(<?= number_format($pct, 1, ',', '.') ?>%)</span></span><span class="text-muted">Disponível <?= brl($c['limite'] - $usado) ?></span></div>
        <div class="barra <?= $pct > 90 ? 'perigo' : ($pct > 70 ? 'alerta' : '') ?>"><span style="width:<?= number_format($pct, 1, '.', '') ?>%"></span></div>
        <div class="text-muted" style="font-size:12px">Limite <?= brl($c['limite']) ?></div>
      </div>
    </div>
  </div>
  <?php if ($faturas): ?>
  <div class="table-responsive">
    <table class="table table-striped table-hover m-0" id="t-fat-<?= (int) $c['id'] ?>">
      <thead><tr><th>Fatura</th><th>Fechamento</th><th>Vencimento</th><th class="num">Itens</th><th class="num">Total</th><th class="text-center">Situação</th><th class="text-center">Ações</th></tr></thead>
      <tbody>
      <?php foreach ($faturas as $i => $f): [$stRot, $stCls] = fatura_status_visual($f['status']); ?>
        <tr class="<?= isset($visiveis[$i]) ? '' : 'fat-extra hidden' ?>">
          <td><strong><a href="fatura.php?cartao=<?= (int) $c['id'] ?>&amp;mes=<?= e($f['mes']) ?>"><?= e(mes_label($f['mes'])) ?></a></strong></td>
          <td><?= e(data_br($f['fechamento'])) ?></td>
          <td><?= e(data_br($f['vencimento'])) ?></td>
          <td class="num"><?= (int) $f['qtd'] ?></td>
          <td class="num"><strong><?= brl($f['total']) ?></strong></td>
          <td class="text-center"><span class="badge badge-<?= e($stCls) ?>"><?= e($stRot) ?><?= $f['paga'] ? ' em ' . e(data_br($f['pagamento']['data_pagamento'])) . (!empty($f['parcial']) ? ' (parcial)' : '') : '' ?></span></td>
          <td class="acoes">
            <a href="fatura.php?cartao=<?= (int) $c['id'] ?>&amp;mes=<?= e($f['mes']) ?>" class="cli-btn-acao btn-acao-ver" title="Ver fatura"><i class="fa fa-list"></i></a>
            <?php if ($f['paga']): ?>
              <form method="post" style="display:inline" data-confirmar="Reabrir esta fatura e estornar o pagamento?"><?= csrf_field() ?><input type="hidden" name="acao" value="reabrir"><input type="hidden" name="cartao_id" value="<?= (int) $c['id'] ?>"><input type="hidden" name="mes" value="<?= e($f['mes']) ?>"><button class="cli-btn-acao btn-acao-reativar" title="Reabrir fatura"><i class="fa fa-undo"></i></button></form>
            <?php else: ?>
              <a href="#" class="cli-btn-acao btn-acao-editar" title="Ajustar fatura" data-modal="modal-ajustar" data-preencher="<?= e(json_encode(['cartao_id' => $c['id'], 'mes' => $f['mes'], 'valor_atual' => number_format($f['total'], 2, ',', '.'), 'cartao_nome' => $c['nome'], 'vencimento_txt' => data_br($f['vencimento'])])) ?>"><i class="fa fa-pencil"></i></a>
              <a href="#" class="btn btn-success btn-sm btn-icone" data-modal="modal-pagar" data-preencher="<?= e(json_encode(['cartao_id' => $c['id'], 'mes' => $f['mes'], 'conta_id' => $c['conta_pagamento_id'], 'data' => $f['vencimento'] < $hoje ? $hoje : $f['vencimento'], 'valor' => number_format($f['total'], 2, ',', '.'), 'total_txt' => brl($f['total'])])) ?>"><i class="fa fa-check"></i> Pagar</a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php $ocultas = count($faturas) - count($visiveis); ?>
    <div class="card-footer text-center"><a href="#" class="js-todas-faturas" data-tabela="t-fat-<?= (int) $c['id'] ?>" data-cartao="<?= e($c['nome']) ?>">Ver todas as faturas (<?= count($faturas) ?>)<?= $ocultas > 0 ? ' · +' . (int) $ocultas . ' fora desta lista' : '' ?></a></div>
  <?php else: ?>
    <div class="card-body text-muted" style="padding-top:0">Sem compras neste cartão ainda.</div>
  <?php endif; ?>
</div>
<?php endforeach; ?>
</div>
<?php if ($areaInativos && $lista): ?></details><?php endif;
endforeach; ?>
<div class="modal fin-modal erp-modal-lg" id="modal-faturas">
  <div class="modal-dialog">
    <div class="modal-header"><h4 class="modal-title"><i class="fa fa-list"></i> <span id="mf-titulo">Faturas</span></h4><button type="button" class="close" data-fechar>&times;</button></div>
    <div class="modal-body" style="padding:0"><div class="table-responsive" id="mf-corpo"></div></div>
    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-fechar>Fechar</button></div>
  </div>
</div>
<script>
document.querySelectorAll('.js-todas-faturas').forEach(function (a) {
  a.addEventListener('click', function (e) {
    e.preventDefault();
    var t = document.getElementById(a.dataset.tabela).cloneNode(true);
    t.removeAttribute('id');
    t.querySelectorAll('.fat-extra').forEach(function (tr) { tr.classList.remove('hidden', 'fat-extra'); });
    document.getElementById('mf-titulo').textContent = 'Todas as faturas · ' + a.dataset.cartao;
    var corpo = document.getElementById('mf-corpo');
    corpo.innerHTML = '';
    corpo.appendChild(t);
    document.getElementById('modal-faturas').classList.add('show');
  });
});
// abrir pagar/ajustar a partir do modal: fecha a lista antes
document.getElementById('modal-faturas').addEventListener('click', function (e) {
  if (e.target.closest('[data-modal]')) { this.classList.remove('show'); }
}, true);
</script>

<div class="modal fin-modal erp-modal-md" id="modal-cartao">
  <form class="modal-dialog" method="post">
    <?= csrf_field() ?><input type="hidden" name="acao" value="salvar"><input type="hidden" name="id">
    <div class="modal-header"><h4 class="modal-title"><i class="fa fa-credit-card"></i> Cartão de crédito</h4><button type="button" class="close" data-fechar>&times;</button></div>
    <div class="modal-body">
      <div class="form-group"><label>Nome</label><input class="form-control" name="nome" maxlength="80" required placeholder="Ex.: Nubank Visa"></div>
      <div class="form-grid">
        <div class="form-group"><label>Limite</label><div class="fin-field-prefix"><span class="fin-prefix">R$</span><input class="form-control" name="limite" inputmode="decimal" value="0"></div></div>
        <div class="form-group"><label>Conta que paga a fatura</label><select class="form-control" name="conta_pagamento_id"><?= opcoes($contas, 'id', 'nome', null, true) ?></select></div>
        <div class="form-group"><label>Dia de fechamento</label><input class="form-control" type="number" min="1" max="31" name="dia_fechamento" value="1" required></div>
        <div class="form-group"><label>Dia de vencimento</label><input class="form-control" type="number" min="1" max="31" name="dia_vencimento" value="10" required></div>
      </div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-fechar>Cancelar</button><button class="btn btn-primary"><i class="fa fa-check"></i> Salvar</button></div>
  </form>
</div>

<?php require __DIR__ . '/fatura_modais.php'; ?>
<?php require __DIR__ . '/footer.php'; ?>
