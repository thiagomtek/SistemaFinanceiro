<?php
require_once __DIR__ . '/auth_check.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_exigir();
    $acao = $_POST['acao'] ?? '';
    $id = (int) ($_POST['id'] ?? 0);
    $volta = 'lancamentos.php' . (!empty($_POST['volta']) && preg_match('/^[\w=&%.\-]*$/', $_POST['volta']) ? '?' . $_POST['volta'] : '');
    try {
        if (!empty($_POST['anexo_excluir'])) {
            anexo_excluir((int) $_POST['anexo_excluir']);
            flash('Anexo removido.');
        } elseif ($acao === 'salvar') {
            if ($id) {
                atualizar_lancamento($id, $_POST);
                $ids = [$id];
                flash('Lançamento atualizado.');
            } else {
                $ids = criar_lancamento_ids($_POST);
                flash(count($ids) > 1 ? count($ids) . ' lançamentos criados.' : 'Lançamento criado.');
            }
            $arq = $_FILES['anexo'] ?? null;
            if ($arq && $arq['error'] !== UPLOAD_ERR_NO_FILE) {
                try {
                    anexar_arquivo((int) $ids[0], $arq);
                } catch (InvalidArgumentException | RuntimeException $e) {
                    flash('Lançamento salvo, mas o anexo não foi gravado: ' . $e->getMessage(), 'warning');
                }
            }
            if (!empty($_POST['nova'])) {
                $volta .= (strpos($volta, '?') === false ? '?' : '&') . 'novo=1';
            }
        } elseif ($acao === 'excluir') {
            $n = excluir_lancamento($id, (string) ($_POST['escopo'] ?? 'um'));
            flash($n > 1 ? "$n lançamentos excluídos." : 'Lançamento excluído.');
        } elseif ($acao === 'alternar') {
            alternar_efetivado($id);
            flash('Situação alterada.');
        } elseif ($acao === 'lote') {
            $n = lote_aplicar((array) ($_POST['ids'] ?? []), (string) ($_POST['op'] ?? ''), $_POST['valor'] ?? null);
            flash($n . ' lançamento(s) alterado(s).' . ($n === 0 ? ' (Itens de cartão e pagamentos de fatura não aceitam esta ação, e a categoria precisa ser do mesmo tipo do lançamento.)' : ''), $n ? 'success' : 'warning');
        }
    } catch (InvalidArgumentException $e) {
        flash($e->getMessage(), 'danger');
    }
    redirect($volta);
}

// ------------------------------------------------------------------ filtros
$mes = mes_valido($_GET['mes'] ?? null, date('Y-m'), ['todos']);
$fatura = mes_valido($_GET['fatura'] ?? null, '');
$tipo = in_array($_GET['tipo'] ?? '', ['receita', 'despesa', 'transferencia'], true) ? $_GET['tipo'] : '';
$contaF = (int) ($_GET['conta'] ?? 0);
$cartaoF = (int) ($_GET['cartao'] ?? 0);
$catF = (int) ($_GET['categoria'] ?? 0);
$sit = in_array($_GET['sit'] ?? '', ['pendente', 'efetivado'], true) ? $_GET['sit'] : '';
$busca = trim($_GET['q'] ?? '');
$pagina = max(1, (int) ($_GET['p'] ?? 1));
$ppOpcoes = [10, 20, 25, 50, 100, 'todos'];
$ppReq = (string) ($_GET['pp'] ?? '25');
$pp = in_array($ppReq, array_map('strval', $ppOpcoes), true) ? $ppReq : '25';
$porPagina = $pp === 'todos' ? 5000 : (int) $pp;

$where = ['l.perfil_id = ?'];
$par = [perfil_id()];
if ($fatura && $cartaoF) {
    $where[] = 'l.cartao_id = ? AND l.fatura_mes = ?';
    array_push($par, $cartaoF, $fatura);
} else {
    if ($mes !== 'todos') {
        $where[] = 'l.data >= ? AND l.data < ?';
        array_push($par, $mes . '-01', add_meses($mes . '-01', 1));
    }
    if ($cartaoF) {
        $where[] = 'l.cartao_id = ?';
        $par[] = $cartaoF;
    }
}
if ($tipo) {
    $where[] = 'l.tipo = ?';
    $par[] = $tipo;
}
if ($contaF) {
    $where[] = '(l.conta_id = ? OR l.conta_destino_id = ?)';
    array_push($par, $contaF, $contaF);
}
if ($catF) {
    $where[] = '(l.categoria_id = ? OR l.categoria_id IN (SELECT id FROM categorias WHERE pai_id = ?))';
    array_push($par, $catF, $catF);
}
if ($sit === 'efetivado') {
    $where[] = 'l.efetivado = 1';
} elseif ($sit === 'pendente') {
    $where[] = 'l.efetivado = 0';
}
if ($busca !== '') {
    $where[] = '(l.descricao LIKE ? OR l.tags LIKE ? OR l.observacao LIKE ?)';
    array_push($par, "%$busca%", "%$busca%", "%$busca%");
}
$W = implode(' AND ', $where);

// RN-17 e pagamento de fatura: ver lancamentos_totais (LancamentoService)
$tot = lancamentos_totais($W, $par, $contaF);
// Opcao de visualizacao "agrupar lancamentos do cartao": cada fatura vira UMA linha (soma dos itens do periodo filtrado)
$agrupar = (($_COOKIE['finAgruparCartao'] ?? '') === '1') && !($fatura && $cartaoF);
$grupos = [];
if ($agrupar) {
    $cartoesPorId = [];
    foreach (cartoes_do_perfil() as $kc) {
        $cartoesPorId[(int) $kc['id']] = $kc;
    }
    $pagasPorChave = [];
    foreach (q_all('SELECT f.cartao_id, f.mes FROM faturas f JOIN cartoes c ON c.id = f.cartao_id WHERE c.perfil_id = ?', [perfil_id()]) as $fp) {
        $pagasPorChave[$fp['cartao_id'] . '|' . $fp['mes']] = true;
    }
    foreach (q_all(
        "SELECT l.cartao_id, l.fatura_mes, SUM(l.valor) AS total, COUNT(*) AS n FROM lancamentos l
         WHERE $W AND l.cartao_id IS NOT NULL AND l.fatura_id IS NULL AND l.fatura_mes IS NOT NULL GROUP BY l.cartao_id, l.fatura_mes",
        $par
    ) as $g) {
        $kc = $cartoesPorId[(int) $g['cartao_id']] ?? null;
        if (!$kc) {
            continue;
        }
        $grupos[] = ['_grupo' => true, 'id' => 0, 'cartao_id' => (int) $g['cartao_id'], 'cartao_nome' => $kc['nome'], 'fatura_mes' => $g['fatura_mes'],
            'total' => (float) $g['total'], 'n' => (int) $g['n'], 'data' => fatura_vencimento($kc, $g['fatura_mes']),
            'paga' => isset($pagasPorChave[$g['cartao_id'] . '|' . $g['fatura_mes']])];
    }
    $W .= ' AND l.cartao_id IS NULL';
    $tot['n'] = (int) q_val("SELECT COUNT(*) FROM lancamentos l WHERE $W", $par) + count($grupos);
}
$paginas = max(1, (int) ceil($tot['n'] / $porPagina));
$pagina = min($pagina, $paginas);
$linhas = $grupos ? [] : q_all(
    "SELECT l.*, c.nome AS conta_nome, d.nome AS destino_nome, k.nome AS cartao_nome, cat.nome AS cat_nome, cat.cor AS cat_cor, pai.nome AS pai_nome, iv.nome AS inv_nome
     FROM lancamentos l LEFT JOIN investimentos iv ON iv.id = l.investimento_id
     LEFT JOIN contas c ON c.id = l.conta_id LEFT JOIN contas d ON d.id = l.conta_destino_id
     LEFT JOIN cartoes k ON k.id = l.cartao_id
     LEFT JOIN categorias cat ON cat.id = l.categoria_id LEFT JOIN categorias pai ON pai.id = cat.pai_id
     WHERE $W ORDER BY l.data DESC, l.id DESC LIMIT $porPagina OFFSET " . (($pagina - 1) * $porPagina),
    $par
);
if ($grupos) {
    // junta as linhas normais com as faturas agrupadas, em ordem de data, e pagina
    $todas = q_all(
        "SELECT l.*, c.nome AS conta_nome, d.nome AS destino_nome, k.nome AS cartao_nome, cat.nome AS cat_nome, cat.cor AS cat_cor, pai.nome AS pai_nome, iv.nome AS inv_nome
         FROM lancamentos l LEFT JOIN investimentos iv ON iv.id = l.investimento_id
         LEFT JOIN contas c ON c.id = l.conta_id LEFT JOIN contas d ON d.id = l.conta_destino_id
         LEFT JOIN cartoes k ON k.id = l.cartao_id
         LEFT JOIN categorias cat ON cat.id = l.categoria_id LEFT JOIN categorias pai ON pai.id = cat.pai_id
         WHERE $W ORDER BY l.data DESC, l.id DESC LIMIT 5000",
        $par
    );
    $linhas = array_merge($todas, $grupos);
    usort($linhas, function ($a, $b) {
        return strcmp($b['data'], $a['data']);
    });
    $linhas = array_slice($linhas, ($pagina - 1) * $porPagina, $porPagina);
}
$faturasPagas = [];
foreach (q_all('SELECT f.cartao_id, f.mes FROM faturas f JOIN cartoes c ON c.id = f.cartao_id WHERE c.perfil_id = ?', [perfil_id()]) as $fp) {
    $faturasPagas[$fp['cartao_id'] . '|' . $fp['mes']] = true;
}
$anexMap = anexos_de_lancamentos(array_column($linhas, 'id'));

$contas = contas_do_perfil(true);
$cartoes = cartoes_do_perfil(true);
$cats = categorias_do_perfil();

// Saldo previsto do dia (RN-03): so quando a lista mostra o fluxo inteiro do periodo (sem filtros que escondam itens)
$saldoDia = [];
if ($mes !== 'todos' && !$tipo && !$catF && !$cartaoF && !$fatura && $busca === '' && $sit === '') {
    $saldoDia = saldo_previsto_por_dia($mes . '-01', date('Y-m-t', strtotime($mes . '-01')), $contaF ?: null);
}
// investimentos nao pertencem a uma conta: so entram quando a lista nao esta filtrada por conta
$invDia = ($saldoDia && !$contaF) ? saldo_investimentos_por_dia(array_keys($saldoDia)) : [];

function url_filtro(array $sobrescrever = []): string
{
    $q = array_merge($_GET, $sobrescrever);
    foreach ($q as $k => $v) {
        if ($v === '' || $v === null || $v === 0) {
            unset($q[$k]);
        }
    }
    return 'lancamentos.php' . ($q ? '?' . http_build_query($q) : '');
}
$volta = http_build_query(array_filter($_GET, 'is_scalar'));
$mesBase = $mes === 'todos' ? date('Y-m') : $mes;

$PAGINA = ['Lançamentos', 'fa-exchange', 'lancamentos.php', 'Receitas, despesas e transferências', 'success'];
require __DIR__ . '/header.php';
?>
<div class="kpi-grid">
  <div class="kpi verde"><i class="fa fa-arrow-circle-up kpi-icone"></i><div class="kpi-label">Receitas no filtro</div><div class="kpi-valor text-success"><?= brl($tot['rec']) ?></div></div>
  <div class="kpi vermelho"><i class="fa fa-arrow-circle-down kpi-icone"></i><div class="kpi-label">Despesas no filtro</div><div class="kpi-valor text-danger"><?= brl($tot['desp']) ?></div><div class="kpi-detalhe"><?= $contaF ? 'Inclui o pagamento de fatura que saiu da conta' : 'Sem pagamentos de fatura: as compras do cartão já contam' ?></div></div>
  <div class="kpi azul"><i class="fa fa-balance-scale kpi-icone"></i><div class="kpi-label">Balanço</div><div class="kpi-valor <?= $tot['rec'] - $tot['desp'] < 0 ? 'text-danger' : 'text-success' ?>"><?= brl($tot['rec'] - $tot['desp']) ?></div><div class="kpi-detalhe"><?= (int) $tot['n'] ?> lançamento(s)</div></div>
</div>

<form method="get" class="filtros">
  <div class="campo"><label>Período</label>
    <div class="d-flex gap-2 align-center">
      <a class="btn btn-outline-secondary btn-sm" href="<?= e(url_filtro(['mes' => substr(add_meses($mesBase . '-01', -1), 0, 7), 'p' => '', 'fatura' => ''])) ?>"><i class="fa fa-chevron-left"></i></a>
      <input type="month" class="form-control" name="mes" value="<?= $mes === 'todos' ? '' : e($mes) ?>" onchange="this.form.submit()" <?= $mes === 'todos' ? 'placeholder="Todos"' : '' ?>>
      <a class="btn btn-outline-secondary btn-sm" href="<?= e(url_filtro(['mes' => substr(add_meses($mesBase . '-01', 1), 0, 7), 'p' => '', 'fatura' => ''])) ?>"><i class="fa fa-chevron-right"></i></a>
      <a class="btn btn-outline-secondary btn-sm" href="<?= e(url_filtro(['mes' => 'todos', 'p' => '', 'fatura' => ''])) ?>">Todos</a>
    </div></div>
  <div class="campo"><label>Tipo</label><select class="form-control" name="tipo" onchange="this.form.submit()"><option value="">Todos</option><option value="receita" <?= $tipo === 'receita' ? 'selected' : '' ?>>Receitas</option><option value="despesa" <?= $tipo === 'despesa' ? 'selected' : '' ?>>Despesas</option><option value="transferencia" <?= $tipo === 'transferencia' ? 'selected' : '' ?>>Transferências</option></select></div>
  <div class="campo"><label>Conta</label><select class="form-control" name="conta" onchange="this.form.submit()"><?= opcoes($contas, 'id', 'nome', $contaF, true, 'Todas') ?></select></div>
  <div class="campo"><label>Cartão</label><select class="form-control" name="cartao" onchange="this.form.submit()"><?= opcoes($cartoes, 'id', 'nome', $cartaoF, true, 'Todos') ?></select></div>
  <div class="campo"><label>Categoria</label><select class="form-control" name="categoria" onchange="this.form.submit()"><?= opcoes(array_filter($cats, function ($c) { return !$c['pai_id']; }), 'id', 'nome', $catF, true, 'Todas') ?></select></div>
  <div class="campo"><label>Situação</label><select class="form-control" name="sit" onchange="this.form.submit()"><option value="">Todas</option><option value="efetivado" <?= $sit === 'efetivado' ? 'selected' : '' ?>>Efetivadas</option><option value="pendente" <?= $sit === 'pendente' ? 'selected' : '' ?>>Pendentes</option></select></div>
  <div class="campo" style="flex:1;min-width:180px"><label>Buscar</label><input class="form-control" name="q" value="<?= e($busca) ?>" placeholder="Descrição, tag ou observação"></div>
  <?php if ($fatura): ?><input type="hidden" name="fatura" value="<?= e($fatura) ?>"><?php endif; ?>
  <div class="campo"><label>&nbsp;</label><button class="btn btn-primary btn-sm" style="height:36px"><i class="fa fa-search"></i> Filtrar</button></div>
</form>

<?php if ($fatura && $cartaoF): ?>
  <div class="alert alert-info">Mostrando as compras da fatura <strong><?= e(mes_label($fatura)) ?></strong>. <a href="<?= e(url_filtro(['fatura' => '', 'cartao' => 0])) ?>">Limpar</a></div>
<?php endif; ?>

<div class="card card-outline card-success">
  <div class="card-header">
    <h3 class="card-title"><i class="fa fa-list text-success"></i> Lançamentos do período <small class="text-muted" style="font-weight:400">(<?= (int) $tot['n'] ?>)</small></h3>
    <div class="card-tools"><a href="#" class="btn btn-outline-secondary btn-sm" data-modal="modal-viz" title="Colunas, saldo do dia e agrupar lançamentos do cartão"><i class="fa fa-sliders"></i> Visualização</a> <a href="importar_extrato.php" class="btn btn-outline-success btn-sm"><i class="fa fa-upload"></i> Importar extrato</a> <a href="#" class="btn btn-primary btn-sm" data-modal="modal-lanc" data-novo><i class="fa fa-plus"></i> Novo lançamento</a></div>
  </div>
  <div class="card-body p-0">
<?php if (!$linhas): ?>
  <div class="erp-empty-state"><div class="erp-empty-state-icon"><i class="fa fa-inbox"></i></div><div class="erp-empty-state-title">Nenhum lançamento encontrado</div><div class="erp-empty-state-desc">Ajuste o período ou os filtros, ou crie um novo lançamento.</div></div>
<?php else: ?>

<form method="post" id="form-alternar" style="display:none"><?= csrf_field() ?><input type="hidden" name="acao" value="alternar"><input type="hidden" name="id"><input type="hidden" name="volta" value="<?= e($volta) ?>"></form>

<form method="post" id="form-lote">
  <?= csrf_field() ?><input type="hidden" name="acao" value="lote"><input type="hidden" name="volta" value="<?= e($volta) ?>">
  <div class="barra-lote hidden" id="barra-lote">
    <strong id="lote-n"></strong>
    <button class="btn btn-success btn-sm" name="op" value="efetivar"><i class="fa fa-check"></i> Efetivar</button>
    <button class="btn btn-outline-warning btn-sm" name="op" value="pendente"><i class="fa fa-clock-o"></i> Marcar pendente</button>
    <select class="form-control" name="valor" id="lote-valor" style="width:auto;height:30px;padding:2px 8px;font-size:12px">
      <option value="">— categoria / conta —</option>
      <optgroup label="Categoria"><?php foreach ($cats as $c): ?><option value="cat:<?= (int) $c['id'] ?>"><?= e(($c['pai_nome'] ? $c['pai_nome'] . ' › ' : '') . $c['nome']) ?> (<?= e($c['tipo']) ?>)</option><?php endforeach; ?></optgroup>
      <optgroup label="Conta"><?php foreach ($contas as $c): ?><option value="conta:<?= (int) $c['id'] ?>"><?= e($c['nome']) ?></option><?php endforeach; ?></optgroup>
    </select>
    <button class="btn btn-outline-primary btn-sm" type="button" id="lote-aplicar-valor"><i class="fa fa-tags"></i> Aplicar</button>
    <button class="btn btn-danger btn-sm" name="op" value="excluir" data-confirmar-click="Excluir os lançamentos selecionados? Esta ação não pode ser desfeita."><i class="fa fa-trash"></i> Excluir</button>
    <input type="hidden" name="op_valor_alvo" value="">
  </div>

<div class="table-responsive">
<table class="table table-striped table-hover m-0" id="t-lanc">
  <thead><tr><th style="width:30px"><input type="checkbox" id="lote-todos" title="Selecionar todos da página"></th><th class="text-center">Situação</th><th>Data</th><th>Descrição</th><th class="col-cat">Categoria</th><th class="col-conta">Conta / Cartão</th><th class="num">Valor</th><th class="text-center">Ações</th></tr></thead>
  <tbody>
  <?php
  $pillDia = function (array $l, ?string $proxData) use ($saldoDia, $invDia): void {
      if (!($saldoDia && isset($saldoDia[$l['data']]) && $proxData !== $l['data'])) {
          return;
      }
      ?>
    <tr class="linha-saldo-dia"><td colspan="8" class="text-center"><span class="saldo-dia">Saldo previsto final do dia <?= e(date('d/m', strtotime($l['data']))) ?>:
      contas <strong class="<?= $saldoDia[$l['data']] < 0 ? 'text-danger' : '' ?>"><?= brl($saldoDia[$l['data']]) ?></strong>
      <?php if (isset($invDia[$l['data']]) && $invDia[$l['data']]['bruto'] > 0): $iv = $invDia[$l['data']]; $totB = $saldoDia[$l['data']] + $iv['bruto']; $totL = $saldoDia[$l['data']] + $iv['liquido']; ?>
        · investimentos <strong><?= brl($iv['bruto']) ?></strong> <span class="text-muted" title="Se resgatasse tudo neste dia, depois de IR e IOF">(líquido <?= brl($iv['liquido']) ?>)</span>
        · total <strong><?= brl($totB) ?></strong> <span class="text-muted" title="Contas + investimentos líquidos de IR e IOF">(líquido <?= brl($totL) ?>)</span>
      <?php endif; ?></span></td></tr>
    <?php
  };
  foreach ($linhas as $idx => $l):
      if (!empty($l['_grupo'])): ?>
    <tr>
      <td></td>
      <td class="text-center"><span class="badge badge-<?= $l['paga'] ? 'success' : 'warning' ?>"><?= $l['paga'] ? 'Paga' : 'Pendente' ?></span></td>
      <td class="nowrap"><?= data_br($l['data']) ?></td>
      <td><span class="erp-badge erp-badge-neutral">Agrupada cartão</span> <a href="fatura.php?cartao=<?= (int) $l['cartao_id'] ?>&amp;mes=<?= e($l['fatura_mes']) ?>"><strong>Fatura do cartão <?= e($l['cartao_nome']) ?></strong> · <?= e(mes_label($l['fatura_mes'])) ?></a> <small class="text-muted"><?= (int) $l['n'] ?> item(ns)</small></td>
      <td class="col-cat"><span class="text-muted">—</span></td>
      <td class="col-conta"><i class="fa fa-credit-card text-info"></i> <?= e($l['cartao_nome']) ?></td>
      <td class="num text-danger"><strong>− <?= brl($l['total']) ?></strong></td>
      <td class="acoes"><a href="fatura.php?cartao=<?= (int) $l['cartao_id'] ?>&amp;mes=<?= e($l['fatura_mes']) ?>" class="cli-btn-acao btn-acao-ver" title="Abrir a fatura"><i class="fa fa-eye"></i></a></td>
    </tr>
    <?php $pillDia($l, $linhas[$idx + 1]['data'] ?? null);
      continue;
      endif;
      $cartaoItem = (bool) $l['cartao_id'];
      $anex = $anexMap[(int) $l['id']] ?? [];
      $json = json_encode([
          'id' => $l['id'], 'tipo' => $l['tipo'], 'descricao' => $l['descricao'], 'valor' => number_format(abs($l['valor']), 2, ',', ''),
          'data' => $l['data'], 'efetivado' => $l['efetivado'], 'conta_id' => $l['conta_id'], 'conta_destino_id' => $l['conta_destino_id'],
          'cartao_id' => $l['cartao_id'], 'fatura_mes' => $l['fatura_mes'], 'categoria_id' => $l['categoria_id'], 'tags' => $l['tags'],
          'observacao' => $l['observacao'], 'anexos' => $anex,
      ]);
  ?>
    <tr class="<?= $l['efetivado'] || $cartaoItem ? '' : 'pendente' ?>">
      <td><input type="checkbox" class="chk-lote" name="ids[]" value="<?= (int) $l['id'] ?>"></td>
      <td class="text-center">
        <?php if ($cartaoItem): $faturaPaga = isset($faturasPagas[$l['cartao_id'] . '|' . $l['fatura_mes']]); ?><span class="badge badge-<?= $faturaPaga ? 'success' : 'warning' ?>"><?= $faturaPaga ? 'Paga' : 'Pendente' ?></span>
        <?php elseif ($l['fatura_id']): ?><span class="badge badge-success">Paga</span>
        <?php else: ?><span class="badge badge-<?= $l['efetivado'] ? 'success' : 'warning' ?>"><?= $l['efetivado'] ? ($l['tipo'] === 'receita' ? 'Recebida' : ($l['tipo'] === 'despesa' ? 'Paga' : 'Realizada')) : 'Pendente' ?></span>
        <?php endif; ?>
      </td>
      <td class="nowrap"><?= data_br($l['data']) ?></td>
      <td><?= e($l['descricao']) ?>
        <?php if ($l['parcela_total'] && !preg_match('/\(\d+\s*\/\s*\d+\)\s*$/', $l['descricao'])): ?> <span class="erp-badge erp-badge-info"><?= (int) $l['parcela_num'] ?>/<?= (int) $l['parcela_total'] ?></span>
        <?php elseif ($l['grupo_id'] && !$l['parcela_total']): ?> <span class="erp-badge erp-badge-neutral" title="Recorrente"><i class="fa fa-repeat"></i></span><?php endif; ?>
        <?php if ($l['observacao'] && !$l['fatura_id']): ?> <i class="fa fa-comment-o text-muted" title="<?= e($l['observacao']) ?>"></i><?php endif; ?>
        <?php foreach ($anex as $a): ?> <a href="anexo.php?id=<?= (int) $a['id'] ?>" target="_blank" rel="noopener" title="<?= e($a['nome']) ?>"><i class="fa fa-paperclip"></i></a><?php endforeach; ?>
        <?php if ($l['tags']): ?><br><small class="text-muted col-tags"><i class="fa fa-tag"></i> <?= e($l['tags']) ?></small><?php endif; ?></td>
      <td class="col-cat"><?php if ($l['cat_nome']): ?><span class="cor-bolinha" style="background:<?= e($l['cat_cor']) ?>"></span><?= e(($l['pai_nome'] ? $l['pai_nome'] . ' › ' : '') . $l['cat_nome']) ?><?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
      <td class="col-conta"><?php if ($l['investimento_id']): ?><?= $l['sentido_invest'] === 'resgate' ? '<i class="fa fa-bar-chart text-warning"></i> <a href="investimentos.php?abrir=' . (int) $l['investimento_id'] . '&amp;aba=mov">' . e($l['inv_nome']) . '</a> <i class="fa fa-long-arrow-right"></i> ' . e($l['conta_nome']) : e($l['conta_nome']) . ' <i class="fa fa-long-arrow-right"></i> <i class="fa fa-bar-chart text-warning"></i> <a href="investimentos.php?abrir=' . (int) $l['investimento_id'] . '&amp;aba=mov">' . e($l['inv_nome']) . '</a>' ?>
          <?php elseif ($l['tipo'] === 'transferencia'): ?><?= e($l['conta_nome']) ?> <i class="fa fa-long-arrow-right"></i> <?= e($l['destino_nome']) ?>
          <?php elseif ($cartaoItem): ?><i class="fa fa-credit-card text-info"></i> <?= e($l['cartao_nome']) ?> <small class="text-muted">· <a href="fatura.php?cartao=<?= (int) $l['cartao_id'] ?>&amp;mes=<?= e($l['fatura_mes']) ?>">fatura <?= e(mes_label($l['fatura_mes'])) ?></a></small>
          <?php else: ?><?= e($l['conta_nome']) ?><?php endif; ?></td>
      <td class="num <?= classe_valor($l['tipo']) ?>"><strong><?= $l['tipo'] === 'despesa' ? ($l['valor'] < 0 ? '+' : '−') : ($l['tipo'] === 'receita' ? '+' : '') ?> <?= brl(abs($l['valor'])) ?></strong></td>
      <td class="acoes">
        <?php if (!$cartaoItem && !$l['fatura_id']): $verbo = $l['tipo'] === 'receita' ? 'recebimento' : ($l['tipo'] === 'despesa' ? 'pagamento' : 'realização'); ?>
          <a href="#" class="cli-btn-acao <?= $l['efetivado'] ? 'btn-acao-reativar' : 'btn-acao-sucesso' ?> btn-confirmar" title="<?= $l['efetivado'] ? 'Reverter a confirmação' : 'Confirmar ' . $verbo ?>" data-id="<?= (int) $l['id'] ?>" data-efetivado="<?= (int) $l['efetivado'] ?>" data-verbo="<?= e($verbo) ?>" data-desc="<?= e($l['descricao'] . ' · ' . brl(abs($l['valor']))) ?>"><i class="fa fa-<?= $l['efetivado'] ? 'undo' : 'check' ?>"></i></a>
        <?php endif; ?>
        <?php if (!$l['fatura_id'] && !$l['investimento_id'] && $l['valor'] >= 0): ?>
          <a href="#" class="cli-btn-acao btn-acao-editar" title="Editar" data-modal="modal-lanc" data-preencher="<?= e($json) ?>"><i class="fa fa-pencil"></i></a>
        <?php endif; ?>
        <a href="#" class="cli-btn-acao btn-acao-excluir" title="Excluir" data-modal="modal-excluir" data-preencher="<?= e(json_encode(['id' => $l['id'], 'tem_grupo' => $l['grupo_id'] ? 1 : 0, 'fatura' => $l['fatura_id'] ? 1 : 0, 'descricao_ex' => $l['descricao']])) ?>"><i class="fa fa-trash"></i></a>
      </td>
    </tr>
    <?php $pillDia($l, $linhas[$idx + 1]['data'] ?? null); ?>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
</form>
<div class="card-footer paginacao-tri">
  <span class="text-muted">Mostrando <?= $tot['n'] ? (($pagina - 1) * $porPagina + 1) : 0 ?>–<?= min($tot['n'], $pagina * $porPagina) ?> de <?= (int) $tot['n'] ?></span>
  <nav class="erp-pagination"><ul class="pagination">
    <li class="page-item <?= $pagina <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="<?= e(url_filtro(['p' => max(1, $pagina - 1)])) ?>">‹</a></li>
    <?php for ($i = max(1, $pagina - 2); $i <= min($paginas, $pagina + 2); $i++): ?>
      <li class="page-item <?= $i === $pagina ? 'active' : '' ?>"><a class="page-link" href="<?= e(url_filtro(['p' => $i])) ?>"><?= $i ?></a></li>
    <?php endfor; ?>
    <li class="page-item <?= $pagina >= $paginas ? 'disabled' : '' ?>"><a class="page-link" href="<?= e(url_filtro(['p' => min($paginas, $pagina + 1)])) ?>">›</a></li>
  </ul></nav>
  <span class="erp-pagination">Itens por página
    <select onchange="location.href=this.value">
      <?php foreach ($ppOpcoes as $o): ?><option value="<?= e(url_filtro(['pp' => (string) $o, 'p' => ''])) ?>" <?= (string) $o === $pp ? 'selected' : '' ?>><?= $o === 'todos' ? 'Todos' : (int) $o ?></option><?php endforeach; ?>
    </select></span>
</div>
<?php endif; ?>
  </div>
</div>

<div class="modal fin-modal erp-modal-lg" id="modal-lanc">
  <form class="modal-dialog" method="post" id="form-lanc" enctype="multipart/form-data">
    <?= csrf_field() ?><input type="hidden" name="acao" value="salvar"><input type="hidden" name="id"><input type="hidden" name="volta" value="<?= e($volta) ?>">
    <div class="modal-header"><h4 class="modal-title"><i class="fa fa-exchange"></i> <span id="lanc-titulo">Lançamento</span></h4><button type="button" class="close" data-fechar>&times;</button></div>
    <div class="modal-body">
      <div class="form-grid">
        <div class="form-group"><label>Tipo</label>
          <select class="form-control" name="tipo" id="lanc-tipo"><option value="despesa">Despesa</option><option value="receita">Receita</option><option value="transferencia">Transferência</option></select></div>
        <div class="form-group"><label>Valor</label><div class="fin-field-prefix"><span class="fin-prefix">R$</span><input class="form-control" name="valor" inputmode="decimal" placeholder="0,00" required></div></div>
        <div class="form-group span-2"><label>Descrição</label><input class="form-control" name="descricao" maxlength="160" required></div>
        <div class="form-group"><label>Data</label><input class="form-control" type="date" name="data" value="<?= date('Y-m-d') ?>" required></div>
        <div class="form-group" data-campo="categoria"><label>Categoria</label>
          <select class="form-control" name="categoria_id" id="lanc-categoria"><option value="">— sem categoria —</option>
            <?php foreach ($cats as $c): ?><option value="<?= (int) $c['id'] ?>" data-tipo="<?= e($c['tipo']) ?>"><?= e(($c['pai_nome'] ? $c['pai_nome'] . ' › ' : '') . $c['nome']) ?></option><?php endforeach; ?>
          </select></div>
        <div class="form-group" data-campo="origem"><label id="lbl-conta">Conta</label>
          <select class="form-control" name="conta_id"><option value="">— selecione —</option><?= opcoes($contas) ?></select></div>
        <div class="form-group" data-campo="destino"><label>Conta de destino</label>
          <select class="form-control" name="conta_destino_id"><option value="">— selecione —</option><?= opcoes($contas) ?></select></div>
        <div class="form-group" data-campo="cartao"><label>…ou Cartão de crédito</label>
          <select class="form-control" name="cartao_id" id="lanc-cartao"><option value="">— não —</option><?= opcoes($cartoes) ?></select></div>
        <div class="form-group" data-campo="fatura"><label>Fatura do cartão</label>
          <select class="form-control" name="fatura_mes" id="lanc-fatura"><option value="">Automática (pelo fechamento)</option>
            <?php for ($i = -12; $i <= 36; $i++): $ym = substr(add_meses(date('Y-m-01'), $i), 0, 7); ?><option value="<?= e($ym) ?>"><?= e(mes_label($ym)) ?></option><?php endfor; ?>
          </select></div>
        <div class="form-group span-2"><label>Tags (separadas por vírgula)</label><input class="form-control" name="tags" maxlength="200"></div>
        <div class="form-group span-2"><label>Observação</label><input class="form-control" name="observacao" maxlength="255"></div>
        <div class="form-group" data-campo="efetivado" style="display:flex;align-items:center;gap:8px;padding-top:24px"><input type="checkbox" name="efetivado" value="1" id="lanc-efetivado" style="width:auto"><label for="lanc-efetivado" style="margin:0" id="lbl-efetivado">Já foi pago</label></div>
        <div class="form-group" data-campo="repeticao"><label>Repetição</label>
          <select class="form-control" name="repeticao" id="lanc-repeticao"><option value="unico">Não repete</option><option value="fixa">Fixa (sem fim, até você excluir)</option><option value="parcelado">Parcelado (divide o valor)</option></select></div>
        <div class="form-group" data-campo="qtd"><label>Quantidade</label><input class="form-control" type="number" min="2" max="480" name="qtd" value="2"></div>
        <div class="form-group" data-campo="periodo"><label>Periodicidade</label>
          <select class="form-control" name="periodo"><option value="mes">Meses</option><option value="semana">Semanas</option><option value="dia">Dias</option><option value="ano">Anos</option></select></div>
        <div class="form-group span-2"><label>Anexar comprovante (PDF, imagem, TXT, CSV, XLSX ou DOCX, até 5 MB)</label><input class="form-control" type="file" name="anexo" accept=".pdf,.jpg,.jpeg,.png,.gif,.webp,.txt,.csv,.xlsx,.docx">
          <div id="lanc-anexos" style="margin-top:6px"></div></div>
      </div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-fechar>Cancelar</button>
      <button class="btn btn-outline-success" name="nova" value="1" id="btn-salvar-nova"><i class="fa fa-plus"></i> Salvar e criar nova</button>
      <button class="btn btn-success"><i class="fa fa-check"></i> Salvar</button></div>
  </form>
</div>

<div class="modal fin-modal erp-modal-md" id="modal-excluir">
  <form class="modal-dialog" method="post">
    <?= csrf_field() ?><input type="hidden" name="acao" value="excluir"><input type="hidden" name="id"><input type="hidden" name="volta" value="<?= e($volta) ?>">
    <input type="hidden" name="tem_grupo"><input type="hidden" name="fatura">
    <div class="modal-header" style="background:#dc2626"><h4 class="modal-title"><i class="fa fa-trash"></i> Excluir lançamento</h4><button type="button" class="close" data-fechar>&times;</button></div>
    <div class="modal-body">
      <p>Excluir <strong id="ex-desc"></strong>?</p>
      <p class="text-muted hidden" id="ex-fatura">Este é o pagamento de uma fatura: ao excluir, a fatura será reaberta.</p>
      <div class="form-group hidden" id="ex-escopo"><label>Este lançamento faz parte de uma série</label>
        <select class="form-control" name="escopo"><option value="um">Excluir somente este</option><option value="futuras">Excluir este e os próximos ainda não confirmados</option><option value="todas">Excluir todos da série, inclusive os confirmados</option></select></div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-fechar>Cancelar</button><button class="btn btn-danger"><i class="fa fa-trash"></i> Excluir</button></div>
  </form>
</div>

<div class="modal fin-modal erp-modal-md" id="modal-viz">
  <div class="modal-dialog">
    <div class="modal-header"><h4 class="modal-title"><i class="fa fa-sliders"></i> Opções de visualização</h4><button type="button" class="close" data-fechar>&times;</button></div>
    <div class="modal-body">
      <label style="display:block"><input type="checkbox" data-viz="cat" checked style="width:auto"> Exibir categoria</label>
      <label style="display:block"><input type="checkbox" data-viz="conta" checked style="width:auto"> Exibir contas</label>
      <label style="display:block"><input type="checkbox" data-viz="tags" checked style="width:auto"> Exibir tags</label>
      <label style="display:block"><input type="checkbox" data-viz="saldo" checked style="width:auto"> Exibir saldo do dia</label>
      <label style="display:block" title="Mostra uma linha por fatura (Fatura do cartão X) com a soma dos itens do período, em vez de cada compra"><input type="checkbox" id="viz-agrupar" <?= $agrupar ? 'checked' : '' ?> style="width:auto"> Agrupar lançamentos do cartão (uma linha por fatura)</label>
      <p class="text-muted" style="font-size:12px;margin:8px 0 0">Guardado neste navegador. "Agrupar" recarrega a lista.</p>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-primary" data-fechar>Fechar</button></div>
  </div>
</div>
<?php $PAGINA_SCRIPTS = ['assets/js/lancamentos.js'];
require __DIR__ . '/footer.php'; ?>
