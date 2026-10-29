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

/** Importacao em andamento (arquivos lidos, destinos e escolhas), guardada em JSON temporario; a senha do PDF nunca entra aqui. */
function extrato_sessao(): ?array
{
    $s = $_SESSION['import_extrato'] ?? null;
    if (!$s || !preg_match('/^[a-f0-9]{32}$/', $s['token'] ?? '') || ($s['perfil_id'] ?? 0) !== perfil_id()) {
        return null;
    }
    $arq = UPLOAD_DIR . '/tmp/' . $s['token'] . '.json';
    $dados = is_file($arq) ? json_decode((string) file_get_contents($arq), true) : null;
    return $dados && !empty($dados['arquivos']) ? $dados : null;
}

function extrato_sessao_gravar(array $dados): void
{
    $s = $_SESSION['import_extrato'] ?? null;
    if (!$s || ($s['perfil_id'] ?? 0) !== perfil_id()) {
        $s = ['token' => bin2hex(random_bytes(16)), 'perfil_id' => perfil_id()];
        $_SESSION['import_extrato'] = $s;
    }
    file_put_contents(UPLOAD_DIR . '/tmp/' . $s['token'] . '.json', json_encode($dados, JSON_UNESCAPED_UNICODE));
}

function extrato_sessao_descartar(): void
{
    if ($s = $_SESSION['import_extrato'] ?? null) {
        if (preg_match('/^[a-f0-9]{32}$/', $s['token'] ?? '')) {
            @unlink(UPLOAD_DIR . '/tmp/' . $s['token'] . '.json');
        }
    }
    unset($_SESSION['import_extrato']);
}

/** Escolhas do usuario enviadas pelo formulario de revisao. @return array{destinos: array, over: array, sel: array, vistos: array} */
function extrato_ler_escolhas(array $post, int $nArquivos): array
{
    $destinos = [];
    foreach ($nArquivos ? range(0, $nArquivos - 1) : [] as $f) {
        $destinos[$f] = (string) ($post['dest'][$f] ?? '');
    }
    $over = [];
    foreach ((array) ($post['ac'] ?? []) as $k => $acao) {
        if (!preg_match('/^\d+_\d+$/', (string) $k)) {
            continue;
        }
        $over[$k] = ['acao' => (string) $acao, 'cp' => (string) ($post['cp'][$k] ?? '')];
        if (isset($post['cat'][$k])) {
            $over[$k]['cat'] = (int) $post['cat'][$k];
        }
    }
    $pos = [];
    foreach ((array) ($post['pos_inv'] ?? []) as $k => $inv) {
        if (preg_match('/^\d+_\d+$/', (string) $k)) {
            $pos[$k] = ['inv' => (string) $inv, 'pct' => (string) ($post['pos_pct'][$k] ?? ''), 'sel' => in_array((string) $k, array_map('strval', (array) ($post['pos_sel'] ?? [])), true)];
        }
    }
    $corte = parse_data($post['corte'] ?? '') ?: '';
    return ['destinos' => $destinos, 'over' => $over, 'sel' => array_map('strval', (array) ($post['sel'] ?? [])), 'vistos' => array_map('strval', array_keys((array) ($post['ac'] ?? []))), 'pos' => $pos, 'corte' => $corte];
}

function extrato_pre_marcada(array $l): bool
{
    return $l['status'] === 'novo' && $l['loc'] !== '' && !isset($l['op']['erro']) && ($l['op']['op'] ?? '') !== 'ignorar';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_exigir();
    $acao = $_POST['acao'] ?? '';
    try {
        if ($acao === 'enviar') {
            set_time_limit(300); // a leitura de PDF por IA pode levar de 1 a 2 minutos por arquivo
            $env = $_FILES['arquivos'] ?? null;
            $nomes = $env ? (array) $env['name'] : [];
            if (!$nomes || ($nomes[0] ?? '') === '') {
                throw new InvalidArgumentException('Selecione ao menos um arquivo de extrato.');
            }
            $dados = extrato_sessao() ?? ['arquivos' => [], 'destinos' => [], 'over' => [], 'sel' => [], 'vistos' => []];
            $senha = (string) ($_POST['senha'] ?? ''); // usada so nesta requisicao; nunca gravada
            $lidos = 0;
            $falhas = [];
            foreach ($nomes as $n => $nome) {
                if (($env['error'][$n] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    $falhas[] = $nome . ': erro no envio.';
                    continue;
                }
                if ($env['size'][$n] > 10 * 1024 * 1024) {
                    $falhas[] = $nome . ': arquivo muito grande (limite de 10 MB).';
                    continue;
                }
                if (count($dados['arquivos']) >= 12) {
                    $falhas[] = $nome . ': limite de 12 extratos por importação.';
                    continue;
                }
                try {
                    $r = extrato_ler_arquivo($env['tmp_name'][$n], $nome, $senha);
                    $dados['arquivos'][] = $r;
                    $dados['destinos'][count($dados['arquivos']) - 1] = extrato_sugerir_destino($r['meta']);
                    $lidos++;
                } catch (InvalidArgumentException | RuntimeException $e) {
                    $falhas[] = $nome . ': ' . $e->getMessage();
                }
            }
            foreach ($falhas as $f) {
                flash($f, 'danger');
            }
            if (!$lidos) {
                redirect('importar_extrato.php' . (extrato_sessao() ? '?etapa=revisar' : ''));
            }
            extrato_sessao_gravar($dados);
            redirect('importar_extrato.php?etapa=revisar');
        } elseif ($acao === 'cancelar') {
            extrato_sessao_descartar();
            flash('Importação cancelada.', 'info');
        } elseif ($acao === 'remover') {
            $dados = extrato_sessao();
            $idx = (int) ($_POST['arquivo'] ?? -1);
            if ($dados && isset($dados['arquivos'][$idx])) {
                array_splice($dados['arquivos'], $idx, 1);
                $dest = array_values($dados['destinos'] ?? []);
                array_splice($dest, $idx, 1);
                $dados['destinos'] = $dest;
                $dados['over'] = [];
                $dados['sel'] = $dados['vistos'] = [];
                if ($dados['arquivos']) {
                    extrato_sessao_gravar($dados);
                    redirect('importar_extrato.php?etapa=revisar');
                }
                extrato_sessao_descartar();
            }
        } elseif ($acao === 'recalcular') {
            $dados = extrato_sessao();
            if (!$dados) {
                throw new InvalidArgumentException('A importação expirou. Envie os arquivos novamente.');
            }
            $dados = array_merge($dados, extrato_ler_escolhas($_POST, count($dados['arquivos'])));
            extrato_sessao_gravar($dados);
            redirect('importar_extrato.php?etapa=revisar');
        } elseif ($acao === 'confirmar') {
            $dados = extrato_sessao();
            if (!$dados) {
                throw new InvalidArgumentException('A importação expirou. Envie os arquivos novamente.');
            }
            $esc = extrato_ler_escolhas($_POST, count($dados['arquivos']));
            // o servidor recalcula tudo (situacao, pares, operacao): a tela pode estar desatualizada
            $linhas = extrato_montar($dados['arquivos'], $esc['destinos'], $esc['over'], $esc['corte']);
            $todosNovos = ($_POST['modo'] ?? '') === 'todos_novos';
            $marcados = array_flip($esc['sel']);
            $aprovadas = [];
            foreach ($linhas as $l) {
                if ($l['loc'] === '') {
                    continue;
                }
                if ($todosNovos ? extrato_pre_marcada($l) : isset($marcados[$l['k']])) {
                    $aprovadas[] = $l;
                }
            }
            $posAprov = [];
            foreach (extrato_posicoes($dados['arquivos']) as $p) {
                $e = $esc['pos'][$p['k']] ?? null;
                if ($e && $e['sel'] && ($e['inv'] === 'novo' || (int) $e['inv'] > 0)) {
                    $posAprov[] = $p + ['inv' => $e['inv'] === 'novo' ? 'novo' : (int) $e['inv'], 'pct' => parse_valor($e['pct'], false)];
                }
            }
            if (!$aprovadas && !$posAprov) {
                throw new InvalidArgumentException('Nenhum item aprovado para importar.');
            }
            $r = $aprovadas ? extrato_aplicar($aprovadas) : ['feitos' => 0, 'erros' => [], 'resumo' => []];
            $rp = $posAprov ? extrato_aplicar_posicoes($posAprov) : ['feitos' => 0, 'erros' => []];
            foreach (array_slice(array_merge($r['erros'], $rp['erros']), 0, 8) as $e) {
                flash($e, 'danger');
            }
            if ($r['feitos'] + $rp['feitos'] === 0) {
                $dados = array_merge($dados, $esc);
                extrato_sessao_gravar($dados);
                redirect('importar_extrato.php?etapa=revisar');
            }
            extrato_sessao_descartar();
            $partes = [];
            foreach ($r['resumo'] as $rot => $q) {
                $partes[] = $q . ' ' . mb_strtolower($rot) . ($q > 1 ? 's' : '');
            }
            if ($rp['feitos']) {
                $partes[] = $rp['feitos'] . ' posição(ões) de investimento';
            }
            $nErros = count($r['erros']) + count($rp['erros']);
            flash(($r['feitos'] + $rp['feitos']) . ' item(ns) importado(s): ' . implode(', ', $partes) . '.' . ($nErros ? ' ' . $nErros . ' não foram importados (veja os avisos).' : ''));
            redirect('lancamentos.php');
        }
    } catch (InvalidArgumentException | RuntimeException $e) {
        flash($e->getMessage(), 'danger');
        redirect('importar_extrato.php' . (extrato_sessao() ? '?etapa=revisar' : ''));
    }
    redirect('importar_extrato.php');
}

$sessao = ($_GET['etapa'] ?? '') === 'revisar' ? extrato_sessao() : null;
if (($_GET['etapa'] ?? '') === 'revisar' && !$sessao) {
    flash('Nenhuma importação pendente.', 'warning');
    redirect('importar_extrato.php');
}

$PAGINA = ['Importar extrato', 'fa-file-text-o', 'lancamentos.php', 'Extratos de conta corrente ou investimento lidos por IA: você aprova cada item antes de gravar', 'info'];
require __DIR__ . '/header.php';

$formEnvio = function (string $titulo, string $botao) {
    ?>
    <form method="post" enctype="multipart/form-data" class="form-envio">
      <?= csrf_field() ?><input type="hidden" name="acao" value="enviar">
      <div class="form-group"><label><?= e($titulo) ?></label><input class="form-control" type="file" name="arquivos[]" accept=".pdf,.ofx,.csv,.txt,.xlsx" multiple required></div>
      <div class="form-group"><label>Senha do PDF (se houver)</label><input class="form-control" type="password" name="senha" autocomplete="off" placeholder="Usada só para abrir o arquivo; não é gravada. Vale para todos os PDFs desta seleção"></div>
      <button class="btn btn-success btn-ler"><i class="fa fa-search"></i> <?= e($botao) ?></button>
      <span class="text-muted hidden lendo"><i class="fa fa-spinner fa-spin"></i> Lendo… cada PDF pode levar até 2 minutos.</span>
    </form>
    <?php
};

if (!$sessao):
?>
<div class="grid-2">
  <div class="painel">
    <h4><i class="fa fa-file-text-o text-info"></i> Enviar extratos</h4>
    <?php $formEnvio('Extratos (PDF, OFX, CSV ou Excel .xlsx, até 10 MB cada; pode escolher vários)', 'Ler extratos'); ?>
  </div>
  <div class="painel">
    <h4><i class="fa fa-info-circle text-info"></i> Como funciona</h4>
    <ol style="padding-left:18px;line-height:1.7">
      <li>Envie um ou mais extratos, de <strong>conta corrente</strong> ou de <strong>investimento</strong>. A IA (Gemini) lê PDF, CSV e Excel; OFX é lido direto.</li>
      <li>Para cada arquivo, confirme a <strong>conta ou investimento</strong> a que ele pertence (o sistema sugere pelo banco).</li>
      <li>A IA entende cada linha: receita, despesa, transferência, aplicação, resgate, rendimento, tarifa ou pagamento de fatura.</li>
      <li>Quando uma <strong>saída de um extrato</strong> aparece como <strong>entrada em outro</strong> (mesmo valor, datas próximas), vira <strong>uma única transferência</strong>. Ex.: sai R$ 51,20 do CDB do BV e entra no Itaú.</li>
      <li>Cada item é comparado com o que já existe: <span class="erp-badge erp-badge-success">Novo</span> <span class="erp-badge erp-badge-warning">Possível duplicado</span> <span class="erp-badge erp-badge-neutral">Já existe</span>. Você ajusta o que for preciso e <strong>aprova item por item</strong> ou todos os novos.</li>
    </ol>
    <p class="text-muted" style="font-size:12px"><i class="fa fa-lock"></i> O arquivo é enviado ao Google (Gemini) apenas para extrair e classificar as linhas; ele e a senha não ficam guardados aqui. No resgate, o extrato da conta mostra o valor líquido: o sistema calcula o bruto e o imposto.</p>
  </div>
</div>
<?php else:
    $arquivos = $sessao['arquivos'];
    $destinos = $sessao['destinos'] ?? [];
    $over = $sessao['over'] ?? [];
    $selSalva = array_flip($sessao['sel'] ?? []);
    $vistos = array_flip($sessao['vistos'] ?? []);
    $corte = (string) ($sessao['corte'] ?? '');
    $posSel = $sessao['pos'] ?? [];
    $linhas = extrato_montar($arquivos, $destinos, $over, $corte);
    $posicoes = extrato_posicoes($arquivos);
    $nDescartadas = 0;
    foreach ($arquivos as $a) {
        foreach ($a['itens'] as $x) {
            $nDescartadas += ($corte !== '' && $x['data'] < $corte) ? 1 : 0;
        }
    }
    $listaDest = extrato_destinos();
    $catsDesp = categorias_do_perfil('despesa');
    $catsRec = categorias_do_perfil('receita');
    $cont = ['novo' => 0, 'provavel' => 0, 'existe' => 0];
    foreach ($linhas as $l) {
        $cont[$l['status']]++;
    }
    $semDestino = array_filter(array_keys($arquivos), function ($f) use ($destinos, $arquivos) { return $arquivos[$f]['itens'] && ($destinos[$f] ?? '') === ''; });
    $totEnt = array_sum(array_map(function ($a) { return $a['meta']['entradas']; }, $arquivos));
    $totSai = array_sum(array_map(function ($a) { return $a['meta']['saidas']; }, $arquivos));
    $nMov = array_sum(array_map(function ($a) { return count($a['itens']); }, $arquivos));
    $nPares = count(array_filter($linhas, function ($l) { return $l['par_loc'] !== ''; }));
    $nPre = count(array_filter($linhas, 'extrato_pre_marcada'));
    $badges = ['novo' => ['success', 'Novo'], 'provavel' => ['warning', 'Possível duplicado'], 'existe' => ['secondary', 'Já existe']];
?>
<div class="kpi-grid">
  <div class="kpi azul"><div class="kpi-label">Extratos lidos</div><div class="kpi-valor"><?= count($arquivos) ?> arquivo(s)</div>
    <div class="kpi-detalhe"><?= (int) $nMov ?> movimentações · <?= count($linhas) ?> linhas na revisão<?= $nPares ? ' · ' . $nPares . ' transferência(s) pareada(s)' : '' ?></div></div>
  <div class="kpi verde"><div class="kpi-label">Entradas / Saídas nos arquivos</div><div class="kpi-valor" style="font-size:17px"><span class="text-success"><?= brl($totEnt) ?></span> / <span class="text-danger"><?= brl($totSai) ?></span></div>
    <div class="kpi-detalhe">Somando todos os extratos (transferências entre eles aparecem nos dois)</div></div>
  <div class="kpi roxo"><div class="kpi-label">Situação das <?= count($linhas) ?> linhas</div><div class="kpi-valor" style="font-size:16px"><?= $cont['novo'] ?> novas · <?= $cont['provavel'] ?> duplicadas? · <?= $cont['existe'] ?> já existem</div><div class="kpi-detalhe">Comparadas com o banco</div></div>
</div>

<div class="painel">
  <h4><i class="fa fa-files-o text-info"></i> Extratos e seus destinos</h4>
  <form method="post" id="form-rev">
  <?= csrf_field() ?><input type="hidden" name="acao" value="confirmar">
  <div class="table-responsive">
    <table class="table table-striped table-hover m-0">
      <thead><tr><th>Arquivo</th><th>Lido como</th><th>Período</th><th class="num">Linhas</th><th class="num">Entradas</th><th class="num">Saídas</th><th style="min-width:230px">Pertence a</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($arquivos as $f => $a): $m = $a['meta']; ?>
        <tr>
          <td><strong><?= e($a['nome']) ?></strong><?php foreach ($a['avisos'] as $av): ?><br><small class="text-warning"><i class="fa fa-exclamation-triangle"></i> <?= e($av) ?></small><?php endforeach; ?></td>
          <td><?= e(trim($m['instituicao'] . ' ' . ($m['produto'] ?? ''))) ?><br><small class="text-muted"><?= $m['tipo_extrato'] === 'investimento' ? 'Investimento' : 'Conta corrente' ?><?= $m['conta'] ? ' · ' . e($m['conta']) : '' ?></small></td>
          <td class="nowrap"><?= $m['periodo_inicio'] ? e(data_br($m['periodo_inicio'])) . ' a ' . e(data_br($m['periodo_fim'])) : '—' ?></td>
          <td class="num"><?= count($a['itens']) ?></td>
          <td class="num text-success"><?= brl($m['entradas']) ?></td>
          <td class="num text-danger"><?= brl($m['saidas']) ?></td>
          <td><?php if ($a['itens']): ?><select class="form-control js-recalc" name="dest[<?= (int) $f ?>]" style="height:32px;padding:2px 8px;font-size:13px"><?= extrato_opcoes_locais($destinos[$f] ?? '', 'Escolha a conta ou investimento…') ?></select>
            <?php else: ?><input type="hidden" name="dest[<?= (int) $f ?>]" value=""><span class="text-muted">Posição de investimentos (veja abaixo)</span><?php endif; ?></td>
          <td class="nowrap"><button type="submit" name="acao" value="remover" class="cli-btn-acao" title="Remover este extrato" formnovalidate onclick="this.form.querySelector('[name=arquivo]').value=<?= (int) $f ?>"><i class="fa fa-trash"></i></button></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <input type="hidden" name="arquivo" value="">
  <div class="d-flex flex-wrap gap-2 align-center" style="margin-top:10px;font-size:13px">
    <label style="margin:0">Ignorar movimentações anteriores a</label>
    <input class="form-control js-recalc" type="date" name="corte" value="<?= e($corte) ?>" style="width:auto;height:32px;padding:2px 10px">
    <span class="text-muted"><?= $corte !== '' ? $nDescartadas . ' movimentação(ões) descartada(s) pela data de corte.' : 'Em branco: importa tudo que está nos extratos.' ?></span>
  </div>
  <button type="submit" name="acao" value="recalcular" id="btn-recalc" class="hidden" formnovalidate></button>

  <?php if ($semDestino): ?><div class="alert alert-warning" style="margin-top:10px"><i class="fa fa-exclamation-triangle"></i> Escolha a conta ou o investimento de cada extrato para comparar com o banco e liberar a importação.</div><?php endif; ?>

<?php if ($posicoes):
    $listaInv = q_all('SELECT id, nome FROM investimentos WHERE perfil_id = ? AND ativo = 1 ORDER BY nome', [perfil_id()]); ?>
  <h4 style="margin-top:18px"><i class="fa fa-bar-chart text-warning"></i> Posições de investimentos encontradas</h4>
  <p class="text-muted" style="font-size:12px;margin-top:0">Saldo <strong>bruto</strong> de cada produto no dia do extrato. Em investimento já cadastrado, o saldo é reajustado para o valor do extrato. Em <em>novo</em>, o investimento é criado e a posição inicial é montada para o saldo bruto bater e o imposto estimado ficar próximo ao do banco.</p>
  <div class="table-responsive"><table class="table table-striped table-hover m-0">
    <thead><tr><th style="width:34px"></th><th>Produto</th><th class="num">Saldo bruto</th><th class="num">IR estimado</th><th class="num">Líquido</th><th style="min-width:230px">Investimento no sistema</th><th style="min-width:110px">% do CDI (novo)</th></tr></thead>
    <tbody>
    <?php foreach ($posicoes as $p): $e = $posSel[$p['k']] ?? null; $invSel = $e ? $e['inv'] : ($p['sugestao'] ? (string) $p['sugestao'] : 'novo'); $marcado = $e ? $e['sel'] : true; ?>
      <tr>
        <td><input type="checkbox" name="pos_sel[]" value="<?= e($p['k']) ?>" <?= $marcado ? 'checked' : '' ?>></td>
        <td><strong><?= e($p['nome']) ?></strong><br><small class="text-muted"><?= e(trim($p['emissor'] . ' ' . $p['tipo_ativo'])) ?> · <?= e(data_br($p['data'])) ?></small></td>
        <td class="num"><?= brl($p['bruto']) ?></td>
        <td class="num"><?= $p['ir'] !== null ? brl($p['ir']) : '—' ?></td>
        <td class="num"><?= $p['liquido'] !== null ? brl($p['liquido']) : '—' ?></td>
        <td><select class="form-control" name="pos_inv[<?= e($p['k']) ?>]" style="height:32px;padding:2px 8px;font-size:12px">
          <option value="novo" <?= $invSel === 'novo' ? 'selected' : '' ?>>Criar novo investimento</option>
          <?php foreach ($listaInv as $iv): ?><option value="<?= (int) $iv['id'] ?>" <?= (string) $iv['id'] === $invSel ? 'selected' : '' ?>>Reajustar: <?= e($iv['nome']) ?></option><?php endforeach; ?>
        </select></td>
        <td><div class="fin-field-suffix"><input class="form-control" name="pos_pct[<?= e($p['k']) ?>]" inputmode="decimal" value="<?= e($e ? $e['pct'] : '100') ?>" style="height:32px;padding:2px 8px"><span class="fin-suffix">%</span></div></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>
<?php endif; ?>

<?php if (!$semDestino && count($linhas) > 0): ?>
  <div class="d-flex justify-between flex-wrap gap-2 mb-2 align-center" style="margin-top:14px">
    <span class="pilulas">
      <a href="#" class="pilula" data-sel="novos">Marcar só os novos</a>
      <a href="#" class="pilula" data-sel="todos">Marcar todos</a>
      <a href="#" class="pilula" data-sel="nenhum">Limpar</a>
    </span>
    <strong id="resumo-sel"></strong>
  </div>
  <div class="table-responsive">
  <table class="table table-striped table-hover m-0" id="t-itens">
    <thead><tr><th style="width:34px"><input type="checkbox" id="sel-todos" title="Marcar/limpar todos"></th><th>Data</th><th>Descrição</th><th style="min-width:170px">O que é</th><th style="min-width:160px">Conta / investimento / fatura</th><th style="min-width:130px">Categoria</th><th class="num">Valor</th><th>Situação</th></tr></thead>
    <tbody>
    <?php foreach ($linhas as $l):
        $k = $l['k'];
        $erro = $l['op']['erro'] ?? null;
        $pre = isset($vistos[$k]) ? isset($selSalva[$k]) : extrato_pre_marcada($l);
        $habil = $l['loc'] !== '';
        [$bc, $br] = $badges[$l['status']];
        $cats = $l['acao'] === 'receita' ? $catsRec : $catsDesp;
        $pareado = $l['par_loc'] !== '';
    ?>
      <tr class="<?= $l['status'] === 'existe' ? 'pendente' : '' ?>">
        <td><input type="checkbox" class="chk-item" name="sel[]" value="<?= e($k) ?>" data-status="<?= e($l['status']) ?>" data-pre="<?= extrato_pre_marcada($l) ? '1' : '0' ?>" data-valor="<?= e($l['sentido'] === 'in' ? $l['valor'] : -$l['valor']) ?>" <?= $pre && $habil ? 'checked' : '' ?> <?= $habil ? '' : 'disabled' ?>></td>
        <td class="nowrap"><?= e(data_br($l['data'])) ?></td>
        <td><?= e($l['descricao']) ?>
          <?php if ($pareado): ?><br><small class="text-info"><i class="fa fa-exchange"></i> <?= e($l['loc_nome']) ?> <i class="fa fa-long-arrow-right"></i> <?= e($l['par_nome']) ?> (pareado entre extratos<?= abs($l['valor_in'] - $l['valor_out']) >= 0.005 ? ': saiu ' . brl($l['valor_out']) . ', entrou ' . brl($l['valor_in']) . ' após imposto' : '' ?>)</small>
          <?php else: ?><br><small class="text-muted"><?= e($l['loc_nome']) ?><?= $l['contraparte_txt'] !== '' ? ' · ' . e($l['contraparte_txt']) : '' ?></small><?php endif; ?></td>
        <td><select class="form-control js-recalc" name="ac[<?= e($k) ?>]" style="height:32px;padding:2px 8px;font-size:12px">
          <?php foreach (EXTRATO_ACOES as $v => $rot): ?><option value="<?= e($v) ?>" <?= $v === $l['acao'] ? 'selected' : '' ?>><?= e($rot) ?></option><?php endforeach; ?>
        </select></td>
        <td><?php if (in_array($l['acao'], ['mover', 'fatura'], true)): ?>
          <select class="form-control js-recalc" name="cp[<?= e($k) ?>]" style="height:32px;padding:2px 8px;font-size:12px"><?= extrato_opcoes_locais($l['cp'], $l['acao'] === 'fatura' ? 'Escolha a fatura…' : ($l['sentido'] === 'out' ? 'Para onde foi…' : 'De onde veio…'), $l['acao'] === 'fatura') ?></select>
        <?php else: ?><input type="hidden" name="cp[<?= e($k) ?>]" value=""><span class="text-muted">—</span><?php endif; ?></td>
        <td><?php if (in_array($l['acao'], ['receita', 'despesa'], true)): ?>
          <select class="form-control" name="cat[<?= e($k) ?>]" style="height:32px;padding:2px 8px;font-size:12px"><option value="">— sem categoria —</option>
            <?php foreach ($cats as $c): ?><option value="<?= (int) $c['id'] ?>" <?= (int) $l['categoria_id'] === (int) $c['id'] ? 'selected' : '' ?>><?= e(($c['pai_nome'] ? $c['pai_nome'] . ' › ' : '') . $c['nome']) ?></option><?php endforeach; ?>
          </select><?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
        <td class="num nowrap <?= $pareado ? 'text-info' : ($l['sentido'] === 'in' ? 'text-success' : 'text-danger') ?>"><strong><?= $pareado ? '⇄' : ($l['sentido'] === 'in' ? '+' : '−') ?> <?= brl($l['valor']) ?></strong></td>
        <td><span class="badge badge-<?= $bc ?>"><?= e($br) ?></span><?= $l['parecido'] !== '' ? '<br><small class="text-muted">' . e($l['parecido']) . '</small>' : '' ?>
          <?= $erro && $habil && $l['acao'] !== 'ignorar' ? '<br><small class="text-danger"><i class="fa fa-exclamation-circle"></i> ' . e($erro) . '</small>' : '' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <p class="text-muted" style="font-size:12px;margin-top:8px">Resgate: o valor do extrato da conta é o líquido; o sistema calcula o bruto, o IR e o IOF e credita na conta exatamente o valor do extrato. Rendimentos de investimento indexado ao CDI já são calculados pelo sistema, por isso aparecem como "Ignorar".</p>

<?php endif; ?>

  <div class="d-flex gap-2 flex-wrap" style="margin-top:12px">
    <button class="btn btn-success" name="acao" value="confirmar" type="submit" <?= $semDestino ? 'disabled' : '' ?>><i class="fa fa-check"></i> Importar selecionados <span id="n-sel"></span></button>
    <button class="btn btn-outline-success" name="modo" value="todos_novos" type="submit" <?= !$semDestino && $nPre ? '' : 'disabled' ?> data-confirmar-click="Importar todas as <?= (int) $nPre ?> linhas novas e válidas?"><i class="fa fa-check-double"></i> Importar todas as novas (<?= (int) $nPre ?>)</button>
    <button class="btn btn-secondary" type="submit" name="acao" value="cancelar" formnovalidate>Cancelar</button>
  </div>
  </form>
</div>

<div class="painel">
  <h4><i class="fa fa-plus text-success"></i> Adicionar outro extrato a esta importação</h4>
  <?php $formEnvio('Mais extratos (PDF, OFX, CSV ou Excel)', 'Ler e adicionar'); ?>
</div>
<script>
(function () {
  var form = document.getElementById('form-rev');
  var chks = Array.prototype.slice.call(document.querySelectorAll('.chk-item'));
  function brlTxt(v) { return Number(v).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' }); }
  function atualizar() {
    var n = 0, soma = 0;
    chks.forEach(function (c) { if (c.checked) { n++; soma += parseFloat(c.dataset.valor); } });
    var r = document.getElementById('resumo-sel'); if (r) { r.textContent = n + ' item(ns) selecionado(s) · saldo ' + brlTxt(soma); }
    var ns = document.getElementById('n-sel'); if (ns) { ns.textContent = '(' + n + ')'; }
    var st = document.getElementById('sel-todos'); if (st) { st.checked = n > 0 && n === chks.length; }
  }
  document.addEventListener('click', function (e) {
    var a = e.target.closest('[data-sel]');
    if (!a) { return; }
    e.preventDefault();
    chks.forEach(function (c) { if (!c.disabled) { c.checked = a.dataset.sel === 'todos' || (a.dataset.sel === 'novos' && c.dataset.pre === '1'); } });
    atualizar();
  });
  var st = document.getElementById('sel-todos');
  if (st) { st.addEventListener('change', function () { var v = this.checked; chks.forEach(function (c) { if (!c.disabled) { c.checked = v; } }); atualizar(); }); }
  chks.forEach(function (c) { c.addEventListener('change', atualizar); });
  // mudar destino, "o que é" ou conta/investimento recalcula pares, situação e operação no servidor
  document.querySelectorAll('.js-recalc').forEach(function (s) {
    s.addEventListener('change', function () { form.requestSubmit(document.getElementById('btn-recalc')); });
  });
  var bt = document.querySelector('[data-confirmar-click]');
  if (bt) {
    bt.addEventListener('click', function (e) {
      if (bt.dataset.confirmado === '1') { return; }
      e.preventDefault();
      var enviar = function () { bt.dataset.confirmado = '1'; form.requestSubmit(bt); };
      if (window.ERP && ERP.confirm) { ERP.confirm(bt.getAttribute('data-confirmar-click'), enviar, 'Importar linhas novas', { perigo: false, btnConfirmar: 'Importar' }); } else if (window.confirm(bt.getAttribute('data-confirmar-click'))) { enviar(); }
    });
  }
  atualizar();
})();
</script>
<?php endif; ?>
<script>
document.querySelectorAll('.form-envio').forEach(function (f) {
  f.addEventListener('submit', function () {
    f.querySelector('.btn-ler').disabled = true;
    f.querySelector('.lendo').classList.remove('hidden');
  });
});
</script>
<?php require __DIR__ . '/footer.php'; ?>
