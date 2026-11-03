<?php
require_once __DIR__ . '/auth_check.php';

$tmpDir = UPLOAD_DIR . '/tmp';
if (!is_dir($tmpDir)) {
    mkdir($tmpDir, 0775, true);
}
foreach (glob($tmpDir . '/*.json') ?: [] as $velho) {
    if (filemtime($velho) < time() - 86400) {
        @unlink($velho);
    }
}

function fatura_sessao(): ?array
{
    $s = $_SESSION['import_fatura'] ?? null;
    if (!$s || !preg_match('/^[a-f0-9]{32}$/', $s['token'] ?? '') || ($s['perfil_id'] ?? 0) !== perfil_id()) {
        return null;
    }
    $arq = UPLOAD_DIR . '/tmp/' . $s['token'] . '.json';
    $dados = is_file($arq) ? json_decode((string) file_get_contents($arq), true) : null;
    if (!$dados) {
        return null;
    }
    $s['dados'] = $dados;
    return $s;
}

function fatura_descartar_sessao(): void
{
    if ($s = fatura_sessao()) {
        @unlink(UPLOAD_DIR . '/tmp/' . $s['token'] . '.json');
    }
    unset($_SESSION['import_fatura']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_exigir();
    $acao = $_POST['acao'] ?? '';
    try {
        if ($acao === 'enviar') {
            set_time_limit(240); // a leitura de PDF por IA pode levar de 1 a 2 minutos
            $f = $_FILES['arquivo'] ?? null;
            if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
                throw new InvalidArgumentException('Selecione o arquivo da fatura (erro no envio).');
            }
            if ($f['size'] > 10 * 1024 * 1024) {
                throw new InvalidArgumentException('Arquivo muito grande (limite de 10 MB).');
            }
            $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['pdf', 'csv', 'txt', 'xlsx', 'xls'], true)) {
                throw new InvalidArgumentException('Formato não suportado. Envie PDF, CSV ou Excel (.xlsx).');
            }
            $cartaoId = (int) ($_POST['cartao_id'] ?? 0);
            if ($cartaoId && !pertence_ao_perfil('cartoes', $cartaoId)) {
                throw new InvalidArgumentException('Cartão inválido.');
            }
            // a senha do PDF so existe nesta requisicao: nao e gravada em sessao, arquivo ou banco
            $lido = fatura_arquivo_ler($f['tmp_name'], $f['name'], (string) ($_POST['senha'] ?? ''), ($_POST['sinal'] ?? '') === 'negativo');
            fatura_descartar_sessao();
            $token = bin2hex(random_bytes(16));
            file_put_contents($tmpDir . '/' . $token . '.json', json_encode($lido, JSON_UNESCAPED_UNICODE));
            $_SESSION['import_fatura'] = ['token' => $token, 'perfil_id' => perfil_id(), 'arquivo' => basename($f['name']), 'cartao_id' => $cartaoId ?: null];
            redirect('importar_fatura.php?etapa=revisar');
        } elseif ($acao === 'cancelar') {
            fatura_descartar_sessao();
            flash('Importação cancelada.', 'info');
        } elseif ($acao === 'confirmar') {
            $s = fatura_sessao();
            if (!$s) {
                throw new InvalidArgumentException('A importação expirou. Envie o arquivo novamente.');
            }
            $cartaoId = (int) ($_POST['cartao_id'] ?? 0);
            $ym = (string) ($_POST['mes'] ?? '');
            if (!pertence_ao_perfil('cartoes', $cartaoId) || !preg_match('/^\d{4}-\d{2}$/', $ym)) {
                throw new InvalidArgumentException('Escolha o cartão e a fatura de destino.');
            }
            $meta = $s['dados']['fatura'];
            // o servidor recalcula o status de cada item (a tela pode estar desatualizada)
            $linhas = fatura_marcar_existentes($cartaoId, $s['dados']['linhas']);
            $todosNovos = ($_POST['modo'] ?? '') === 'todos_novos';
            $marcados = array_flip(array_map('intval', (array) ($_POST['sel'] ?? [])));
            $aprovados = [];
            foreach ($linhas as $i => $l) {
                if ($todosNovos ? $l['status'] === 'novo' : isset($marcados[$i])) {
                    $l['categoria_id'] = (int) ($_POST['cat'][$i] ?? 0) ?: null;
                    $l['restantes'] = !empty($_POST['rest'][$i]) && ($l['restantes_qtd'] ?? 0) > 0;
                    $aprovados[] = $l;
                }
            }
            if (!$aprovados) {
                throw new InvalidArgumentException('Nenhum item aprovado para importar.');
            }
            $total = (!empty($_POST['ajustar']) && $meta['total'] !== null) ? (float) $meta['total'] : null;
            $st = fatura_importar_itens($cartaoId, $ym, $aprovados, $s['arquivo'], $total, $linhas);
            fatura_descartar_sessao();
            $msg = $st['importados'] . ' item(ns) importado(s)';
            if ($st['projetadas']) { $msg .= ', ' . $st['projetadas'] . ' parcela(s) futura(s) lançada(s) nas faturas seguintes'; }
            if ($st['parcelas_removidas']) { $msg .= ', ' . $st['parcelas_removidas'] . ' parcela(s) projetada(s) removida(s) por não constarem mais na fatura'; }
            if ($st['reajustes_removidos']) { $msg .= ' · ' . $st['reajustes_removidos'] . ' reajuste(s) manual(is) das faturas seguintes removido(s) (' . brl($st['reajustes_removidos_total']) . ')'; }
            if ($st['puladas']) { $msg .= ' (' . $st['puladas'] . ' já existiam ou caíram em fatura paga)'; }
            if ($st['duplicados']) { $msg .= ', ' . $st['duplicados'] . ' já existiam e foram ignorados'; }
            if ($st['ajuste'] !== null && abs($st['ajuste']) >= 0.005) { $msg .= '; fatura ajustada ao total com reajuste de ' . brl($st['ajuste']); }
            flash($msg . '.');
            redirect('fatura.php?cartao=' . $cartaoId . '&mes=' . $ym);
        }
    } catch (InvalidArgumentException | RuntimeException $e) {
        flash($e->getMessage(), 'danger');
        redirect($acao === 'confirmar' ? 'importar_fatura.php?etapa=revisar' : 'importar_fatura.php');
    }
    redirect('importar_fatura.php');
}

$cartoes = cartoes_do_perfil(true);
$sessao = ($_GET['etapa'] ?? '') === 'revisar' ? fatura_sessao() : null;
if (($_GET['etapa'] ?? '') === 'revisar' && !$sessao) {
    flash('Nenhuma importação pendente.', 'warning');
    redirect('importar_fatura.php');
}

$PAGINA = ['Importar fatura de cartão', 'fa-upload', 'cartoes.php', 'PDF (lido por IA), CSV ou Excel: você aprova cada item antes de gravar', 'warning'];
require __DIR__ . '/header.php';

if (!$sessao):
    $cartaoPre = (int) ($_GET['cartao'] ?? 0);
?>
<div class="grid-2">
  <div class="painel">
    <h4><i class="fa fa-file-pdf-o text-danger"></i> Enviar fatura</h4>
    <form method="post" enctype="multipart/form-data" id="form-envio">
      <?= csrf_field() ?><input type="hidden" name="acao" value="enviar">
      <div class="form-group"><label>Cartão (opcional: a IA sugere pelo PDF)</label>
        <select class="form-control" name="cartao_id"><?= opcoes($cartoes, 'id', 'nome', $cartaoPre, true, 'Detectar / escolher depois') ?></select></div>
      <div class="form-group"><label>Arquivo da fatura (PDF, CSV ou Excel .xlsx, até 10 MB)</label><input class="form-control" type="file" name="arquivo" accept=".pdf,.csv,.txt,.xlsx,.xls" required></div>
      <div class="form-group"><label>Senha do PDF (se houver)</label><input class="form-control" type="password" name="senha" autocomplete="off" placeholder="Usada só para abrir o arquivo; não é gravada"></div>
      <div class="form-group"><label>Em CSV/Excel, as compras vêm com valor…</label>
        <select class="form-control" name="sinal"><option value="positivo">Positivo (faturas de banco)</option><option value="negativo">Negativo (padrão Mobills)</option></select></div>
      <button class="btn btn-success" id="btn-ler"><i class="fa fa-search"></i> Ler fatura</button>
      <span class="text-muted hidden" id="lendo"><i class="fa fa-spinner fa-spin"></i> Lendo… PDFs podem levar até 2 minutos.</span>
    </form>
  </div>
  <div class="painel">
    <h4><i class="fa fa-info-circle text-info"></i> Como funciona</h4>
    <ol style="padding-left:18px;line-height:1.7">
      <li>O PDF é lido por IA (Gemini); CSV e Excel são lidos direto. Nada é gravado ainda.</li>
      <li>O sistema compara cada item com o que já existe no banco: <span class="erp-badge erp-badge-success">Novo</span> <span class="erp-badge erp-badge-warning">Possível duplicado</span> <span class="erp-badge erp-badge-neutral">Já existe</span>.</li>
      <li>Você <strong>aprova item por item</strong> (ou todos os novos de uma vez) e escolhe a categoria de cada um.</li>
      <li>Compras <strong>parceladas</strong> podem ter as parcelas restantes lançadas de uma vez nas faturas dos meses seguintes.</li>
      <li>Opcionalmente a fatura é ajustada ao total do PDF, criando o <em>Reajuste de fatura</em> com a diferença.</li>
      <li>As faturas seguintes passam a espelhar o banco: os <em>Reajustes de fatura</em> manuais delas são removidos e as parcelas projetadas de compras que não constam mais (estorno, cancelamento) saem.</li>
    </ol>
    <p class="text-muted" style="font-size:12px"><i class="fa fa-lock"></i> O PDF é enviado ao Google (Gemini) apenas para extrair os itens; o arquivo e a senha não ficam guardados neste sistema.</p>
  </div>
</div>
<script>
document.getElementById('form-envio').addEventListener('submit', function () {
  document.getElementById('btn-ler').disabled = true;
  document.getElementById('lendo').classList.remove('hidden');
});
</script>
<?php else:
    $linhas = $sessao['dados']['linhas'];
    $meta = $sessao['dados']['fatura'];
    $avisos = $sessao['dados']['avisos'] ?? [];
    $cartaoId = (int) ($_GET['cartao'] ?? 0) ?: (int) ($sessao['cartao_id'] ?? 0);
    if (!$cartaoId && ($meta['origem'] ?? '') === 'pdf') {
        $cartaoId = (int) fatura_pdf_sugerir_cartao($meta);
    }
    $cartaoRow = null;
    foreach ($cartoes as $c) {
        if ((int) $c['id'] === $cartaoId) {
            $cartaoRow = $c;
        }
    }
    $cartaoId = $cartaoRow ? (int) $cartaoRow['id'] : 0;
    $ym = $_GET['mes'] ?? '';
    if (!preg_match('/^\d{4}-\d{2}$/', $ym)) {
        $ym = $meta['vencimento'] ? substr($meta['vencimento'], 0, 7) : ($cartaoRow ? fatura_mes_da_compra($cartaoRow, max(array_column($linhas, 'data'))) : '');
    }
    foreach ($linhas as $i => $l) {
        $linhas[$i]['status'] = 'novo';
        $linhas[$i]['parecido'] = '';
    }
    if ($cartaoId) {
        $linhas = fatura_marcar_existentes($cartaoId, $linhas);
    }
    $catsDb = categorias_do_perfil('despesa');
    $cont = ['novo' => 0, 'provavel' => 0, 'existe' => 0];
    foreach ($linhas as $l) {
        $cont[$l['status']]++;
    }
    $faturaTotalEx = ($cartaoId && $ym) ? fatura_total($cartaoId, $ym) : 0.0;
    $diff = $meta['total'] !== null ? round($meta['total'] - $meta['soma_itens'], 2) : null;
?>
<div class="kpi-grid">
  <div class="kpi azul"><div class="kpi-label">Arquivo</div><div class="kpi-valor" style="font-size:15px;word-break:break-all"><?= e($sessao['arquivo']) ?></div>
    <div class="kpi-detalhe"><?= ($meta['origem'] ?? '') === 'pdf' ? 'PDF lido por IA' . ($meta['modelo'] ? ' (' . e($meta['modelo']) . ')' : '') : 'Planilha' ?><?= $meta['emissor'] ? ' · ' . e(trim($meta['emissor'] . ' ' . $meta['produto'] . ' ' . ($meta['final_cartao'] ? '•••• ' . $meta['final_cartao'] : ''))) : '' ?></div></div>
  <div class="kpi roxo"><div class="kpi-label">Total da fatura no arquivo</div><div class="kpi-valor"><?= $meta['total'] !== null ? brl($meta['total']) : '—' ?></div>
    <div class="kpi-detalhe">Soma dos itens lidos: <?= brl($meta['soma_itens']) ?><?= $diff !== null && abs($diff) >= 0.005 ? ' · <span class="text-danger">diferença ' . brl($diff) . '</span>' : '' ?></div></div>
  <div class="kpi verde"><div class="kpi-label">Situação dos <?= count($linhas) ?> itens</div><div class="kpi-valor" style="font-size:16px"><?= $cont['novo'] ?> novos · <?= $cont['provavel'] ?> duplicados? · <?= $cont['existe'] ?> já existem</div><div class="kpi-detalhe">Comparados com o banco<?= $cartaoRow ? ' (cartão ' . e($cartaoRow['nome']) . ')' : '' ?></div></div>
</div>
<?php foreach ($avisos as $a): ?><div class="alert alert-warning"><?= e($a) ?></div><?php endforeach; ?>
<?php if ($diff !== null && abs($diff) >= 0.005): ?>
  <div class="alert alert-warning"><i class="fa fa-exclamation-triangle"></i> A soma dos itens lidos difere do total da fatura em <strong><?= brl($diff) ?></strong>. Confira os valores abaixo; a diferença pode ser corrigida com o reajuste da fatura.</div>
<?php endif; ?>

<form method="get" class="filtros">
  <input type="hidden" name="etapa" value="revisar">
  <div class="campo"><label>Cartão da fatura</label><select class="form-control" name="cartao" onchange="this.form.submit()"><?= opcoes($cartoes, 'id', 'nome', $cartaoId, true, 'Selecione…') ?></select></div>
  <div class="campo"><label>Fatura (mês do vencimento)</label>
    <select class="form-control" name="mes" onchange="this.form.submit()">
      <?php $opts = []; for ($i = -12; $i <= 12; $i++) { $opts[] = substr(add_meses(date('Y-m-01'), $i), 0, 7); } if ($ym && !in_array($ym, $opts, true)) { $opts[] = $ym; } sort($opts); ?>
      <?php foreach ($opts as $o): ?><option value="<?= e($o) ?>" <?= $o === $ym ? 'selected' : '' ?>><?= e(mes_label($o)) ?></option><?php endforeach; ?>
    </select></div>
  <?php if ($cartaoRow && $ym): ?><div class="campo"><label>&nbsp;</label><span class="text-muted">Vence em <?= e(data_br(fatura_vencimento($cartaoRow, $ym))) ?><?= $faturaTotalEx != 0.0 ? ' · já tem ' . brl($faturaTotalEx) . ' lançados' : '' ?></span></div><?php endif; ?>
</form>

<form method="post" id="form-aprovar">
  <?= csrf_field() ?><input type="hidden" name="acao" value="confirmar"><input type="hidden" name="cartao_id" value="<?= (int) $cartaoId ?>"><input type="hidden" name="mes" value="<?= e($ym) ?>">
  <div class="d-flex justify-between flex-wrap gap-2 mb-2 align-center">
    <span class="pilulas">
      <a href="#" class="pilula" data-sel="novos">Marcar só os novos</a>
      <a href="#" class="pilula" data-sel="todos">Marcar todos</a>
      <a href="#" class="pilula" data-sel="nenhum">Limpar</a>
      <a href="#" class="pilula" data-rest="1" title="Marca o lançamento das parcelas restantes em todos os itens parcelados">Todas as parcelas restantes</a>
      <a href="#" class="pilula" data-rest="0">Nenhuma parcela restante</a>
    </span>
    <strong id="resumo-sel"></strong>
  </div>
  <div class="table-responsive">
  <table class="table table-striped table-hover m-0" id="t-itens">
    <thead><tr><th style="width:34px"><input type="checkbox" id="sel-todos" title="Marcar/limpar todos"></th><th>Data</th><th>Descrição</th><th class="text-center" title="Lança as parcelas que faltam nas faturas dos meses seguintes">Parcelas<br>restantes</th><th style="min-width:170px">Categoria</th><th class="num">Valor</th><th>Situação</th></tr></thead>
    <tbody>
    <?php foreach ($linhas as $i => $l):
        $catSel = fatura_categoria_por_nome($l['categoria'] ?? '', $catsDb);
        $cls = $l['status'] === 'novo' ? 'success' : ($l['status'] === 'provavel' ? 'warning' : 'secondary');
        $rot = $l['status'] === 'novo' ? 'Novo' : ($l['status'] === 'provavel' ? 'Possível duplicado' : 'Já existe');
    ?>
      <tr class="<?= $l['status'] === 'existe' ? 'pendente' : '' ?>">
        <td><input type="checkbox" class="chk-item" name="sel[]" value="<?= (int) $i ?>" data-status="<?= e($l['status']) ?>" data-valor="<?= e($l['valor']) ?>" <?= $l['status'] === 'novo' && $cartaoId ? 'checked' : '' ?> <?= $cartaoId ? '' : 'disabled' ?>></td>
        <td class="nowrap"><?= e(data_br($l['data'])) ?></td>
        <td><?= e($l['descricao']) ?>
          <?php if (($l['tags'] ?? '') === 'credito'): ?> <span class="erp-badge erp-badge-success">crédito</span><?php elseif (($l['tags'] ?? '') === 'encargo'): ?> <span class="erp-badge erp-badge-danger">encargo</span><?php endif; ?></td>
        <td class="text-center nowrap"><?php if (($l['restantes_qtd'] ?? 0) > 0): ?><label style="margin:0;font-weight:400" title="Lançar as <?= (int) $l['restantes_qtd'] ?> parcela(s) restante(s) nas faturas seguintes"><input type="checkbox" class="chk-rest" name="rest[<?= (int) $i ?>]" value="1" style="width:auto" <?= $l['status'] === 'novo' ? 'checked' : '' ?>> +<?= (int) $l['restantes_qtd'] ?></label><?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
        <td><select class="form-control" name="cat[<?= (int) $i ?>]" style="height:32px;padding:2px 8px;font-size:12px">
          <option value="">— sem categoria —</option>
          <?php foreach ($catsDb as $c): ?><option value="<?= (int) $c['id'] ?>" <?= $catSel === (int) $c['id'] ? 'selected' : '' ?>><?= e(($c['pai_nome'] ? $c['pai_nome'] . ' › ' : '') . $c['nome']) ?></option><?php endforeach; ?>
        </select></td>
        <td class="num <?= $l['valor'] < 0 ? 'text-success' : 'text-danger' ?>"><strong><?= $l['valor'] < 0 ? '+' : '−' ?> <?= brl(abs($l['valor'])) ?></strong></td>
        <td><span class="badge badge-<?= $cls ?>"><?= $rot ?></span><?= $l['parecido'] !== '' ? '<br><small class="text-muted">' . e($l['parecido']) . '</small>' : '' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>

  <?php if ($meta['total'] !== null): ?>
  <div class="painel" style="margin-top:12px">
    <label style="display:flex;gap:8px;align-items:center;margin:0"><input type="checkbox" name="ajustar" value="1" checked style="width:auto">
      Ajustar a fatura ao total do arquivo (<?= brl($meta['total']) ?>): cria ou atualiza o item <em>Reajuste de fatura</em> com a diferença.</label>
  </div>
  <?php endif; ?>

  <div class="d-flex gap-2 flex-wrap" style="margin-top:12px">
    <button class="btn btn-success" name="modo" value="selecionados" <?= $cartaoId ? '' : 'disabled' ?>><i class="fa fa-check"></i> Importar selecionados <span id="n-sel"></span></button>
    <button class="btn btn-outline-success" name="modo" value="todos_novos" <?= $cartaoId && $cont['novo'] ? '' : 'disabled' ?> data-confirmar-click="Importar todos os <?= (int) $cont['novo'] ?> itens novos?"><i class="fa fa-check-double"></i> Importar todos os novos (<?= (int) $cont['novo'] ?>)</button>
    <button class="btn btn-secondary" type="submit" name="acao" value="cancelar" formnovalidate>Cancelar</button>
  </div>
  <?php if (!$cartaoId): ?><p class="text-muted" style="margin-top:8px">Escolha o cartão acima para comparar com o banco e liberar a importação.</p><?php endif; ?>
</form>
<script>
(function () {
  var chks = Array.prototype.slice.call(document.querySelectorAll('.chk-item'));
  function brlTxt(v) { return Number(v).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' }); }
  function atualizar() {
    var n = 0, soma = 0;
    chks.forEach(function (c) { if (c.checked) { n++; soma += parseFloat(c.dataset.valor); } });
    document.getElementById('resumo-sel').textContent = n + ' item(ns) selecionado(s) · ' + brlTxt(soma);
    document.getElementById('n-sel').textContent = '(' + n + ')';
    document.getElementById('sel-todos').checked = n > 0 && n === chks.length;
  }
  document.addEventListener('click', function (e) {
    var a = e.target.closest('[data-sel]');
    if (!a) { return; }
    e.preventDefault();
    chks.forEach(function (c) { c.checked = a.dataset.sel === 'todos' || (a.dataset.sel === 'novos' && c.dataset.status === 'novo'); });
    atualizar();
  });
  document.addEventListener('click', function (e) {
    var r = e.target.closest('[data-rest]');
    if (!r) { return; }
    e.preventDefault();
    document.querySelectorAll('.chk-rest').forEach(function (c) { c.checked = r.dataset.rest === '1'; });
  });
  document.getElementById('sel-todos').addEventListener('change', function () { var v = this.checked; chks.forEach(function (c) { c.checked = v; }); atualizar(); });
  chks.forEach(function (c) { c.addEventListener('change', atualizar); });
  var bt = document.querySelector('[data-confirmar-click]');
  if (bt) {
    bt.addEventListener('click', function (e) {
      if (bt.dataset.confirmado === '1') { return; }
      e.preventDefault();
      var enviar = function () { bt.dataset.confirmado = '1'; bt.form.requestSubmit(bt); };
      if (window.ERP && ERP.confirm) { ERP.confirm(bt.getAttribute('data-confirmar-click'), enviar, 'Importar itens novos', { perigo: false, btnConfirmar: 'Importar' }); } else if (window.confirm(bt.getAttribute('data-confirmar-click'))) { enviar(); }
    });
  }
  atualizar();
})();
</script>
<?php endif; ?>
<?php require __DIR__ . '/footer.php'; ?>
