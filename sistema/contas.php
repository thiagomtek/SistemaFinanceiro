<?php
require_once __DIR__ . '/auth_check.php';

const TIPOS_CONTA = ['corrente' => 'Conta corrente', 'poupanca' => 'Poupança', 'carteira' => 'Carteira / dinheiro', 'investimento' => 'Conta investimento', 'outro' => 'Outra'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_exigir();
    $acao = $_POST['acao'] ?? '';
    $id = (int) ($_POST['id'] ?? 0);
    try {
        if ($acao === 'salvar') {
            $nome = trim($_POST['nome'] ?? '');
            $tipo = array_key_exists($_POST['tipo'] ?? '', TIPOS_CONTA) ? $_POST['tipo'] : 'corrente';
            $saldo = parse_valor($_POST['saldo_inicial'] ?? 0);
            if ($nome === '') {
                throw new InvalidArgumentException('Informe o nome da conta.');
            }
            $dup = q_val('SELECT id FROM contas WHERE perfil_id = ? AND nome = ? AND id <> ?', [perfil_id(), $nome, $id]);
            if ($dup) {
                throw new InvalidArgumentException('Já existe uma conta com esse nome.');
            }
            if ($id && pertence_ao_perfil('contas', $id)) {
                q('UPDATE contas SET nome=?, tipo=?, saldo_inicial=? WHERE id=? AND perfil_id=?', [$nome, $tipo, $saldo, $id, perfil_id()]);
                flash('Conta atualizada.');
            } else {
                limite_entidade_exigir('contas', 'contas');
                q_insert('INSERT INTO contas (perfil_id, nome, tipo, saldo_inicial) VALUES (?,?,?,?)', [perfil_id(), $nome, $tipo, $saldo]);
                flash('Conta cadastrada.');
            }
        } elseif ($acao === 'transferir') {
            criar_lancamento(['tipo' => 'transferencia', 'descricao' => trim($_POST['descricao'] ?? '') !== '' ? $_POST['descricao'] : 'Transferência entre contas',
                'valor' => $_POST['valor'] ?? '', 'data' => $_POST['data'] ?? '', 'efetivado' => !empty($_POST['efetivado']) ? 1 : 0,
                'conta_id' => (int) ($_POST['conta_origem'] ?? 0), 'conta_destino_id' => (int) ($_POST['conta_destino'] ?? 0)]);
            flash('Transferência registrada.');
        } elseif ($acao === 'reajustar') {
            $r = reajustar_saldo($id, $_POST['novo_saldo'] ?? '', $_POST['modo'] ?? 'transacao', $_POST['descricao'] ?? '', $_POST['data'] ?? null);
            flash($r['modo'] === 'saldo_inicial'
                ? 'Saldo reajustado alterando o saldo inicial (diferença de ' . brl($r['diferenca']) . ').'
                : 'Saldo reajustado com uma transação de ajuste de ' . brl(abs($r['diferenca'])) . ' (' . ($r['diferenca'] > 0 ? 'receita' : 'despesa') . ').');
        } elseif ($acao === 'alternar' && pertence_ao_perfil('contas', $id)) {
            q('UPDATE contas SET ativa = 1 - ativa WHERE id = ? AND perfil_id = ?', [$id, perfil_id()]);
            flash('Situação da conta alterada.');
        } elseif ($acao === 'excluir' && pertence_ao_perfil('contas', $id)) {
            $usos = (int) q_val('SELECT COUNT(*) FROM lancamentos WHERE conta_id = ? OR conta_destino_id = ?', [$id, $id])
                + (int) q_val('SELECT COUNT(*) FROM faturas WHERE conta_id = ?', [$id]);
            if ($usos > 0) {
                throw new InvalidArgumentException("Esta conta possui $usos lançamento(s) e não pode ser excluída. Inative-a para ocultá-la.");
            }
            q('UPDATE cartoes SET conta_pagamento_id = NULL WHERE conta_pagamento_id = ?', [$id]);
            q('DELETE FROM contas WHERE id = ? AND perfil_id = ?', [$id, perfil_id()]);
            flash('Conta excluída.');
        }
    } catch (InvalidArgumentException $e) {
        flash($e->getMessage(), 'danger');
    }
    redirect('contas.php');
}

$contas = contas_do_perfil();
$saldos = saldos_por_conta();
$previstos = saldos_previstos_por_conta();
$totalPrevisto = saldo_total_previsto();
$total = 0.0;
foreach ($contas as $c) {
    if ($c['ativa']) {
        $total += $saldos[$c['id']] ?? 0;
    }
}

$PAGINA = ['Contas', 'fa-university', 'contas.php', 'Onde seu dinheiro está: bancos, carteira e poupança', 'primary'];
require __DIR__ . '/header.php';
?>
<div class="kpi-grid">
  <div class="kpi azul"><i class="fa fa-university kpi-icone"></i><div class="kpi-label">Saldo total (contas ativas)</div><div class="kpi-valor <?= $total < 0 ? 'text-danger' : '' ?>"><?= brl($total) ?></div><div class="kpi-detalhe">Considera apenas lançamentos efetivados</div></div>
  <div class="kpi <?= $totalPrevisto < 0 ? 'vermelho' : 'verde' ?>"><i class="fa fa-clock-o kpi-icone"></i><div class="kpi-label">Saldo previsto (até <?= e(date('t/m')) ?>)</div><div class="kpi-valor <?= $totalPrevisto < 0 ? 'text-danger' : '' ?>"><?= brl($totalPrevisto) ?></div><div class="kpi-detalhe">Atual + pendentes e faturas do mês</div></div>
</div>
<?php $contasAtivos = array_values(array_filter($contas, function ($x) { return (int) $x['ativa'] === 1; }));
$contasInativos = array_values(array_filter($contas, function ($x) { return (int) $x['ativa'] !== 1; }));
foreach ([[$contasAtivos, false], [$contasInativos, true]] as [$lista, $ina]):
    if ($ina && !$lista) { continue; }
    if ($ina): ?>
<details style="margin-top:18px"><summary style="cursor:pointer;font-weight:700;color:#475569;margin-bottom:10px"><i class="fa fa-archive"></i> Contas inativos (<?= count($lista) ?>)</summary>
<?php endif; ?>
<div class="card card-outline card-primary">
  <div class="card-header">
    <h3 class="card-title"><i class="fa fa-list text-primary"></i> <?= $ina ? 'Contas inativas' : 'Contas cadastradas' ?> <small class="text-muted" style="font-weight:400">(<?= count($lista) ?>)</small></h3>
    <?php if (!$ina): ?><div class="card-tools">
      <a href="#" class="btn btn-success btn-sm" data-modal="modal-transferir" data-preencher="<?= e(json_encode(['data' => date('Y-m-d'), 'efetivado' => 1])) ?>"><i class="fa fa-exchange"></i> Transferir entre contas</a>
      <a href="investimentos.php" class="btn btn-outline-secondary btn-sm"><i class="fa fa-bar-chart"></i> Aplicar em investimento</a>
      <a href="#" class="btn btn-primary btn-sm" data-modal="modal-conta" data-novo><i class="fa fa-plus"></i> Nova conta</a>
    </div><?php endif; ?>
  </div>
  <div class="card-body p-0">

<?php if (!$lista): ?>
  <div class="vazio"><i class="fa fa-university"></i>Nenhuma conta cadastrada. Crie a primeira para começar a lançar receitas e despesas.</div>
<?php else: ?>
<div class="grid-cards" style="padding:16px">
  <?php foreach ($lista as $c): $s = $saldos[$c['id']] ?? 0; $pv = $previstos[$c['id']] ?? 0; ?>
  <div class="card card-outline <?= $c['ativa'] ? 'card-primary' : 'card-secondary' ?>" style="margin:0">
    <div class="card-header">
      <h3 class="card-title"><i class="fa fa-university text-primary"></i> <?= e($c['nome']) ?> <small class="text-muted" style="font-weight:400;margin-left:6px"><?= e(TIPOS_CONTA[$c['tipo']] ?? $c['tipo']) ?></small></h3>
      <div class="card-tools">
        <a href="lancamentos.php?conta=<?= (int) $c['id'] ?>" class="cli-btn-acao btn-acao-ver" title="Ver lançamentos"><i class="fa fa-list"></i></a>
        <a href="#" class="erp-btn-acao btn-acao-sucesso" title="Reajustar saldo" data-modal="modal-reajuste" data-preencher="<?= e(json_encode(['id' => $c['id'], 'atual_txt' => number_format($s, 2, ',', '.'), 'data' => date('Y-m-d'), 'conta_nome' => $c['nome']])) ?>"><i class="fa fa-sliders"></i></a>
        <a href="#" class="cli-btn-acao btn-acao-editar" title="Editar" data-modal="modal-conta" data-preencher="<?= e(json_encode(['id' => $c['id'], 'nome' => $c['nome'], 'tipo' => $c['tipo'], 'saldo_inicial' => $c['saldo_inicial']])) ?>"><i class="fa fa-pencil"></i></a>
        <form method="post" style="display:inline" data-confirmar="<?= $c['ativa'] ? 'Inativar a conta &quot;' . e($c['nome']) . '&quot;? Ela deixa de aparecer nas listas e nos saldos, mas o histórico é mantido.' : 'Reativar a conta &quot;' . e($c['nome']) . '&quot;?' ?>"><?= csrf_field() ?><input type="hidden" name="acao" value="alternar"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
          <button class="cli-btn-acao btn-acao-reativar" title="<?= $c['ativa'] ? 'Inativar' : 'Reativar' ?>"><i class="fa fa-<?= $c['ativa'] ? 'eye-slash' : 'eye' ?>"></i></button></form>
        <form method="post" style="display:inline" data-confirmar="Excluir a conta &quot;<?= e($c['nome']) ?>&quot;?"><?= csrf_field() ?><input type="hidden" name="acao" value="excluir"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
          <button class="cli-btn-acao btn-acao-excluir" title="Excluir"><i class="fa fa-trash"></i></button></form>
      </div>
    </div>
    <div class="card-body">
      <div class="text-muted" style="font-size:12px;text-transform:uppercase">Saldo atual</div>
      <div style="font-size:24px;font-weight:700" class="<?= $s < 0 ? 'text-danger' : 'text-success' ?>"><?= brl($s) ?></div>
      <div class="d-flex justify-between" style="font-size:13px;margin-top:8px"><span class="text-muted">Saldo previsto (até o fim do mês)</span><strong class="<?= $pv < 0 ? 'text-danger' : '' ?>"><?= brl($pv) ?></strong></div>
      <div class="d-flex justify-between" style="font-size:13px"><span class="text-muted">Saldo inicial</span><span><?= brl($c['saldo_inicial']) ?></span></div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
  </div>
</div>

<?php if ($ina): ?></details><?php endif;
endforeach; ?>

<div class="modal fin-modal erp-modal-md" id="modal-transferir">
  <form class="modal-dialog" method="post">
    <?= csrf_field() ?><input type="hidden" name="acao" value="transferir">
    <div class="modal-header"><h4 class="modal-title"><i class="fa fa-exchange"></i> Transferência entre contas</h4><button type="button" class="close" data-fechar>&times;</button></div>
    <div class="modal-body"><div class="form-grid">
      <div class="form-group"><label>Conta de origem</label><select class="form-control" name="conta_origem" required><option value="">Selecione…</option><?= opcoes($contas) ?></select></div>
      <div class="form-group"><label>Conta de destino</label><select class="form-control" name="conta_destino" required><option value="">Selecione…</option><?= opcoes($contas) ?></select></div>
      <div class="form-group"><label>Valor</label><div class="fin-field-prefix"><span class="fin-prefix">R$</span><input class="form-control" name="valor" inputmode="decimal" required placeholder="0,00"></div></div>
      <div class="form-group"><label>Data</label><input class="form-control" type="date" name="data" required></div>
      <div class="form-group span-2"><label>Descrição (opcional)</label><input class="form-control" name="descricao" maxlength="160" placeholder="Transferência entre contas"></div>
      <div class="form-group" style="display:flex;align-items:center;gap:8px"><input type="checkbox" name="efetivado" value="1" id="tr-ef" style="width:auto" checked><label for="tr-ef" style="margin:0">Já foi realizada</label></div>
    </div>
    <p class="text-muted" style="font-size:12px;margin:10px 0 0">Não conta como receita nem despesa: apenas move o saldo de uma conta para a outra.</p></div>
    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-fechar>Cancelar</button><button class="btn btn-success"><i class="fa fa-check"></i> Transferir</button></div>
  </form>
</div>

<div class="modal fin-modal erp-modal-md" id="modal-reajuste">
  <form class="modal-dialog" method="post">
    <?= csrf_field() ?><input type="hidden" name="acao" value="reajustar"><input type="hidden" name="id">
    <div class="modal-header"><h4 class="modal-title"><i class="fa fa-sliders"></i> Reajuste de saldo</h4><button type="button" class="close" data-fechar>&times;</button></div>
    <div class="modal-body">
      <p style="margin-top:0"><strong id="rj-conta"></strong></p>
      <div class="form-grid">
        <div class="form-group"><label>Saldo atual</label><div class="fin-field-prefix"><span class="fin-prefix">R$</span><input class="form-control" name="atual_txt" readonly></div></div>
        <div class="form-group"><label>Novo saldo</label><div class="fin-field-prefix"><span class="fin-prefix">R$</span><input class="form-control" name="novo_saldo" inputmode="decimal" required placeholder="0,00" style="font-size:18px"></div></div>
        <div class="form-group span-2"><label>Você gostaria de…</label>
          <select class="form-control" name="modo">
            <option value="transacao">Criar transação de ajuste (receita ou despesa de reajuste, mantém o histórico)</option>
            <option value="saldo_inicial">Modificar saldo inicial (não cria transação; altera saldos de meses anteriores)</option>
          </select></div>
        <div class="form-group"><label>Data do ajuste</label><input class="form-control" type="date" name="data"></div>
        <div class="form-group"><label>Descrição (opcional)</label><input class="form-control" name="descricao" maxlength="160" placeholder="Reajuste*"></div>
      </div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-fechar>Cancelar</button><button class="btn btn-success"><i class="fa fa-check"></i> Salvar</button></div>
  </form>
</div>
<script>
document.addEventListener('click', function (e) {
  var b = e.target.closest('[data-modal="modal-reajuste"]');
  if (b) { try { document.getElementById('rj-conta').textContent = 'Conta: ' + JSON.parse(b.dataset.preencher).conta_nome; } catch (err) {} }
});
</script>

<div class="modal fin-modal erp-modal-md" id="modal-conta">
  <form class="modal-dialog" method="post">
    <?= csrf_field() ?><input type="hidden" name="acao" value="salvar"><input type="hidden" name="id">
    <div class="modal-header"><h4 class="modal-title"><i class="fa fa-university"></i> Conta</h4><button type="button" class="close" data-fechar>&times;</button></div>
    <div class="modal-body">
      <div class="form-group"><label>Nome</label><input class="form-control" name="nome" maxlength="80" required placeholder="Ex.: Itaú, Nubank, Carteira"></div>
      <div class="form-group"><label>Tipo</label><select class="form-control" name="tipo"><?php foreach (TIPOS_CONTA as $k => $v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?></select></div>
      <div class="form-group"><label>Saldo inicial</label><div class="fin-field-prefix"><span class="fin-prefix">R$</span><input class="form-control" name="saldo_inicial" inputmode="decimal" value="0" placeholder="0,00"></div></div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-fechar>Cancelar</button><button class="btn btn-primary"><i class="fa fa-check"></i> Salvar</button></div>
  </form>
</div>
<?php require __DIR__ . '/footer.php'; ?>
