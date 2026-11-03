<?php
declare(strict_types=1);

/* =========================================================================
   FaturaImportService: importacao de fatura de cartao a partir de PDF (IA), CSV ou Excel, com
   checagem de itens ja existentes no banco e importacao somente dos itens aprovados pelo usuario.

   Fluxo: fatura_arquivo_ler -> fatura_marcar_existentes (tela de aprovacao) -> fatura_importar_itens.
   ========================================================================= */

/**
 * Le o arquivo da fatura e devolve as linhas no formato comum.
 * @return array{linhas: array, avisos: array, fatura: array}  fatura: origem, vencimento, total (nulos para planilhas)
 */
function fatura_arquivo_ler(string $caminho, string $nomeOriginal, string $senha = '', bool $comprasNegativas = false): array
{
    $ext = strtolower(pathinfo($nomeOriginal, PATHINFO_EXTENSION));
    if ($ext === 'pdf') {
        $r = fatura_pdf_ler($caminho, $senha);
        $r['fatura']['origem'] = 'pdf';
        return $r;
    }
    if ($ext === 'xls') {
        throw new InvalidArgumentException('Arquivos .xls (Excel antigo) não são lidos. Abra no Excel e salve como .xlsx ou .csv.');
    }
    $lido = importar_ler_arquivo($caminho, $nomeOriginal); // .xlsx / .csv
    $linhas = [];
    $avisos = $lido['avisos'];
    foreach ($lido['linhas'] as $l) {
        if ($l['tipo'] === 'transferencia') {
            continue;
        }
        $bruto = (float) ($l['bruto'] ?? 0);
        $compra = $comprasNegativas ? $bruto < 0 : $bruto > 0;
        $l['tipo'] = 'despesa';
        $l['valor'] = $compra ? abs($bruto) : -abs($bruto); // sinal oposto = credito/estorno (item negativo)
        $l['bruto'] = $l['valor'];
        $l['conta'] = '';
        $l['efetivado'] = 0;
        $l['tags'] = $compra ? ($l['tags'] ?? '') : 'credito';
        $linhas[] = $l;
    }
    if (!$linhas) {
        throw new InvalidArgumentException('Nenhum lançamento encontrado no arquivo.');
    }
    return ['linhas' => $linhas, 'avisos' => $avisos, 'fatura' => [
        'origem' => 'planilha', 'emissor' => '', 'produto' => '', 'final_cartao' => '', 'vencimento' => null, 'fechamento' => null,
        'total' => null, 'soma_itens' => round(array_sum(array_column($linhas, 'valor')), 2), 'diferenca' => null, 'modelo' => '', 'tentativas' => [],
    ]];
}

/** Hash unico de cada linha (conta "cartao#ID"), com contagem de ocorrencias na ordem do arquivo. */
function fatura_hashes(int $cartaoId, array $linhas): array
{
    $ocorr = [];
    $out = [];
    foreach ($linhas as $i => $l) {
        $x = $l;
        $x['tipo'] = 'despesa';
        $x['conta'] = 'cartao#' . $cartaoId;
        $x['destino'] = '';
        $base = importar_hash($x, 0);
        $ocorr[$base] = ($ocorr[$base] ?? -1) + 1;
        $out[$i] = importar_hash($x, $ocorr[$base]);
    }
    return $out;
}

/**
 * Marca cada linha com o status frente ao banco:
 *  "existe"   = o mesmo item (hash) ja foi importado;
 *  "provavel" = ha um lancamento do cartao com o mesmo valor em data proxima (+-3 dias) que nao veio desta importacao;
 *  "novo"     = nada parecido.
 * Acrescenta 'hash', 'status' e 'parecido' (descricao e data do item encontrado).
 */
function fatura_marcar_existentes(int $cartaoId, array $linhas): array
{
    $pid = perfil_id();
    $hashes = fatura_hashes($cartaoId, $linhas);
    $series = fatura_series($cartaoId, $linhas);
    $usados = [];
    $usadosParc = [];
    $duvidas = [];
    // se a fatura ja traz varias parcelas da mesma compra, as restantes sao as que vem depois da ultima presente no arquivo
    $maxPn = [];
    foreach ($linhas as $i => $l0) {
        $g = $series[$i]['grupo'];
        if ($g && $series[$i]['pn']) {
            $maxPn[$g] = max($maxPn[$g] ?? 0, (int) $series[$i]['pn']);
        }
    }
    foreach ($linhas as $i => &$l) {
        $l['hash'] = $hashes[$i];
        $l['grupo'] = $series[$i]['grupo'];
        $l['pn'] = $series[$i]['pn'];
        $l['pt'] = $series[$i]['pt'];
        $l['restantes_qtd'] = $series[$i]['pt'] ? max(0, $series[$i]['pt'] - max((int) $series[$i]['pn'], $maxPn[$series[$i]['grupo']] ?? 0)) : 0;
        $l['status'] = 'novo';
        $l['parecido'] = '';
        if (q_val('SELECT id FROM lancamentos WHERE perfil_id = ? AND hash_unico = ?', [$pid, $l['hash']])) {
            $l['status'] = 'existe';
            $l['parecido'] = 'Já importado anteriormente';
            continue;
        }
        if ($l['grupo']) {
            $ja = q_one('SELECT fatura_mes FROM lancamentos WHERE perfil_id = ? AND cartao_id = ? AND grupo_id = ? AND parcela_num = ? LIMIT 1', [$pid, $cartaoId, $l['grupo'], $l['pn']]);
            if (!$ja) {
                // mesma compra com outra grafia (ex.: "•••• 4001" no inicio): exige cartao, parcela n/total e valor iguais; o nome decide ou vai a IA
                $cands = fatura_parcela_candidatas($cartaoId, $l, $usadosParc);
                if ($cands && $cands[0]['score'] >= FATURA_NOME_CERTO) {
                    $ja = $cands[0];
                    $usadosParc[$ja['id']] = true;
                    $l['grupo'] = $ja['grupo_id'] ?: $l['grupo'];
                } elseif ($cands) {
                    $duvidas[$i] = array_slice($cands, 0, 3);
                    continue;
                }
            }
            if ($ja) {
                $l['status'] = 'existe';
                $l['parecido'] = 'Parcela ' . $l['pn'] . '/' . $l['pt'] . ' já lançada (fatura ' . mes_label($ja['fatura_mes']) . ')';
                continue;
            }
        }
        $cand = q_all(
            'SELECT id, descricao, data, hash_unico FROM lancamentos WHERE perfil_id = ? AND cartao_id = ? AND ABS(valor - ?) < 0.005 AND data BETWEEN ? AND ? ORDER BY id',
            [$pid, $cartaoId, $l['valor'], add_dias($l['data'], -3), add_dias($l['data'], 3)]
        );
        foreach ($cand as $c) {
            // itens que vieram deste mesmo arquivo (gemeos legitimos) nao contam como parecidos
            if (!isset($usados[$c['id']]) && !in_array($c['hash_unico'], $hashes, true)) {
                $usados[$c['id']] = true;
                $l['status'] = 'provavel';
                $l['parecido'] = $c['descricao'] . ' (' . data_br($c['data']) . ')';
                break;
            }
        }
    }
    unset($l);
    if ($duvidas) {
        $veredito = fatura_ia_mesma_compra($linhas, $duvidas);
        foreach ($duvidas as $i => $cands) {
            $achou = null;
            $semDecisao = false;
            foreach ($cands as $c) {
                $v = $veredito[$i . ':' . $c['id']] ?? null;
                if ($v === true && !isset($usadosParc[$c['id']])) {
                    $achou = $c;
                    break;
                }
                $semDecisao = $semDecisao || $v === null;
            }
            $rotulo = 'Parcela ' . $linhas[$i]['pn'] . '/' . $linhas[$i]['pt'];
            if ($achou) {
                $usadosParc[$achou['id']] = true;
                $linhas[$i]['status'] = 'existe';
                $linhas[$i]['grupo'] = $achou['grupo_id'] ?: $linhas[$i]['grupo'];
                $linhas[$i]['parecido'] = $rotulo . ' já lançada como "' . $achou['descricao'] . '" (fatura ' . mes_label($achou['fatura_mes']) . ') · confirmado pela IA';
            } elseif ($semDecisao) {
                // IA indisponivel ou sem resposta: nao assume nada, so avisa
                $linhas[$i]['status'] = 'provavel';
                $linhas[$i]['parecido'] = $cands[0]['descricao'] . ' (' . $rotulo . ', fatura ' . mes_label($cands[0]['fatura_mes']) . ')';
                $linhas[$i]['grupos_duvida'] = array_column($cands, 'grupo_id'); // sem certeza: a serie gravada nao pode ser dada como cancelada
            }
        }
    }
    return $linhas;
}

const FATURA_NOME_CERTO = 0.85;

/** Palavras do nome da compra sem ruido (prefixo de cartao, "parcela", conectivos). @return string[] */
function fatura_nome_tokens(string $desc): array
{
    $ruido = ['parcela', 'pag', 'de', 'da', 'do', 'com', 'ltda', 'me', 'sa', 'nupay'];
    $t = array_filter(explode(' ', fatura_descricao_base($desc)), function ($x) use ($ruido) {
        return strlen($x) > 1 && !in_array($x, $ruido, true);
    });
    return array_values(array_unique($t));
}

/** Semelhanca 0..1 entre dois nomes de compra (palavras em comum e texto corrido, ignorando espacos). */
function fatura_nome_similaridade(string $a, string $b): float
{
    $ta = fatura_nome_tokens($a);
    $tb = fatura_nome_tokens($b);
    $ja = implode('', $ta);
    $jb = implode('', $tb);
    if ($ja === '' || $jb === '') {
        return 0.0;
    }
    if ($ja === $jb) {
        return 1.0;
    }
    $palavras = count(array_intersect($ta, $tb)) / max(1, min(count($ta), count($tb)));
    similar_text($ja, $jb, $pct);
    // uma contida na outra ("nike" em "nikestore") tambem conta
    $contem = (strpos($ja, $jb) !== false || strpos($jb, $ja) !== false) ? 0.85 : 0.0;
    return min(1.0, max($palavras * 0.9, $pct / 100, $contem));
}

/**
 * Lancamentos do cartao que podem ser a mesma parcela da linha: mesmo cartao, parcela n/total e valor, ainda nao usados
 * por outra linha. Ordenados pela semelhanca do nome. @return array<int, array<string, mixed>>
 */
function fatura_parcela_candidatas(int $cartaoId, array $l, array $usadosParc): array
{
    $out = [];
    foreach (q_all(
        'SELECT id, descricao, fatura_mes, grupo_id FROM lancamentos WHERE perfil_id = ? AND cartao_id = ? AND parcela_num = ? AND parcela_total = ? AND ABS(valor - ?) < 0.005',
        [perfil_id(), $cartaoId, $l['pn'], $l['pt'], $l['valor']]
    ) as $c) {
        if (!isset($usadosParc[$c['id']])) {
            $c['score'] = fatura_nome_similaridade($l['descricao'], $c['descricao']);
            $out[] = $c;
        }
    }
    usort($out, function ($x, $y) {
        return $y['score'] <=> $x['score'];
    });
    return $out;
}

/**
 * Pergunta a IA, numa unica chamada, se cada par (item novo x lancamento gravado) e a MESMA transacao.
 * $pares: id => ['novo' => texto, 'gravado' => texto, 'valor' => float, 'detalhe' => string]. Devolve id => bool; sem IA configurada ou
 * em falha devolve so o que souber (o resto fica a cargo de quem chamou, que marca "possivel duplicado").
 * As decisoes ficam em cache na sessao para nao repetir a chamada a cada tela.
 */
function ia_mesma_transacao(array $pares, string $contexto): array
{
    if (!$pares || !extrato_ia_configurada()) {
        return [];
    }
    $res = [];
    $pedir = [];
    foreach ($pares as $id => $p) {
        $chave = sha1($contexto . '|' . norm_texto($p['novo']) . '|' . norm_texto($p['gravado']) . '|' . $p['valor'] . '|' . $p['detalhe']);
        if (isset($_SESSION['ia_pares'][$chave])) {
            $res[$id] = $_SESSION['ia_pares'][$chave];
        } else {
            $pedir[(string) $id] = $chave;
        }
    }
    if (!$pedir) {
        return $res;
    }
    $itens = [];
    foreach ($pedir as $id => $chave) {
        $p = $pares[$id];
        $itens[] = ['id' => $id, 'novo' => $p['novo'], 'gravado' => $p['gravado'], 'valor' => $p['valor'], 'detalhe' => $p['detalhe']];
    }
    try {
        $r = ia_gemini_json([
            ['text' => $contexto . " Para cada par, diga se o item NOVO (lido do arquivo) e o item GRAVADO (já no banco) são a MESMA transação. "
                . "O valor (e o que mais estiver em 'detalhe') já é idêntico; o nome pode variar por grafia do emissor (prefixo de cartão, abreviações, espaços, intermediário como NuPay/Mercado Pago/PayPal/PIX) "
                . "ou por ter sido renomeado à mão. Responda mesma_compra=true só se for o mesmo estabelecimento/pessoa/motivo; false se forem coisas diferentes.\n"
                . json_encode($itens, JSON_UNESCAPED_UNICODE)],
        ], [
            'type' => 'OBJECT',
            'properties' => ['decisoes' => ['type' => 'ARRAY', 'items' => ['type' => 'OBJECT', 'properties' => [
                'id' => ['type' => 'STRING'], 'mesma_compra' => ['type' => 'BOOLEAN'],
            ], 'required' => ['id', 'mesma_compra']]]],
            'required' => ['decisoes'],
        ], 'decisoes', 'Não foi possível consultar a IA');
    } catch (Throwable $t) {
        return $res;
    }
    foreach ((array) $r['decisoes'] as $d) {
        $id = (string) ($d['id'] ?? '');
        if (isset($pedir[$id]) && is_bool($d['mesma_compra'] ?? null)) {
            $res[$id] = $d['mesma_compra'];
            $_SESSION['ia_pares'][$pedir[$id]] = $d['mesma_compra'];
        }
    }
    return $res;
}

/** Decisoes da IA para as parcelas em duvida da fatura. Devolve ['<linha>:<id>' => bool]. */
function fatura_ia_mesma_compra(array $linhas, array $duvidas): array
{
    $pares = [];
    foreach ($duvidas as $i => $cands) {
        foreach ($cands as $c) {
            $pares[$i . ':' . $c['id']] = ['novo' => $linhas[$i]['descricao'], 'gravado' => $c['descricao'], 'valor' => (float) $linhas[$i]['valor'],
                'detalhe' => 'parcela ' . $linhas[$i]['pn'] . '/' . $linhas[$i]['pt'] . ' do mesmo cartão'];
        }
    }
    return ia_mesma_transacao($pares, 'Fatura de cartão de crédito.');
}

/**
 * Identifica a serie de parcelas de cada linha: mesma descricao (sem a parcela), valor, cartao e total de parcelas.
 * Linhas identicas no mesmo arquivo (compras gemeas) recebem series diferentes pela ordem de ocorrencia.
 * @return array<int, array{grupo: ?string, pn: ?int, pt: ?int}>
 */
function fatura_series(int $cartaoId, array $linhas): array
{
    $pid = perfil_id();
    $ocorr = [];
    $out = [];
    foreach ($linhas as $i => $l) {
        [$pn, $pt] = fatura_parse_parcela($l);
        if (!$pt) {
            $out[$i] = ['grupo' => null, 'pn' => null, 'pt' => null];
            continue;
        }
        $base = fatura_descricao_base($l['descricao']) . '|' . number_format((float) $l['valor'], 2, '.', '') . '|' . $cartaoId . '|' . $pt;
        // gemeas = mesma compra E mesma parcela duas vezes; parcelas diferentes da mesma compra formam uma serie so
        $chaveO = $base . '|' . $pn;
        $ocorr[$chaveO] = ($ocorr[$chaveO] ?? -1) + 1;
        $out[$i] = ['grupo' => substr(sha1($pid . '|' . $base . '|' . $ocorr[$chaveO]), 0, 16), 'pn' => $pn, 'pt' => $pt];
    }
    return $out;
}

/** Descricao normalizada da compra: sem o "•••• 1234" inicial (final do cartao) e sem a parcela "n/m" do fim. */
function fatura_descricao_base(string $desc): string
{
    $d = preg_replace('/^\s*[•*·.]{2,}\s*\d{4}\s+/u', '', $desc) ?? $desc;
    return norm_texto(preg_replace('/\s*\(?\d{1,3}\s*\/\s*\d{1,3}\)?\s*$/', '', $d) ?? '');
}

/** Troca o numero da parcela na descricao ("... 09/21" -> "... 10/21"; sem parcela no texto, acrescenta " (n/m)"). */
function fatura_descricao_parcela(string $desc, int $novoN, int $total): string
{
    $novo = preg_replace_callback('/(\d{1,3})(\s*\/\s*)(\d{1,3})(?!.*\d\s*\/\s*\d)/', function ($m) use ($novoN) {
        return str_pad((string) $novoN, strlen($m[1]), '0', STR_PAD_LEFT) . $m[2] . $m[3];
    }, $desc, 1, $n);
    if ($n === 0 || $novo === null) {
        return rtrim($desc) . ' (' . $novoN . '/' . $total . ')';
    }
    return $novo;
}

/** Parcela "n/m" do campo parcela ou do fim da descricao. @return array{0: ?int, 1: ?int} */
function fatura_parse_parcela(array $l): array
{
    foreach ([(string) ($l['parcela'] ?? ''), (string) $l['descricao']] as $i => $txt) {
        $rx = $i === 0 ? '/^\s*(\d{1,3})\s*\/\s*(\d{1,3})\s*$/' : '/(\d{1,3})\s*\/\s*(\d{1,3})\)?\s*$/';
        if (preg_match($rx, $txt, $m) && (int) $m[1] >= 1 && (int) $m[2] >= (int) $m[1]) {
            return [(int) $m[1], (int) $m[2]];
        }
    }
    return [null, null];
}

/** Resolve o nome de categoria sugerido para um ID de categoria de despesa do perfil (ou null). */
function fatura_categoria_por_nome(string $nome, array $categorias): ?int
{
    $n = norm_texto($nome);
    if ($n === '') {
        return null;
    }
    foreach ($categorias as $c) {
        if (norm_texto($c['nome']) === $n) {
            return (int) $c['id'];
        }
    }
    return null;
}

/** Hash do lancamento de parcela projetada (lancada antes de a fatura chegar): distingue a projecao do que veio do banco. */
function fatura_hash_parcela(int $pid, int $cartaoId, string $grupo, int $n): string
{
    return sha1('parcela|' . $pid . '|' . $cartaoId . '|' . $grupo . '|' . $n);
}

/** Series de parcelas que o arquivo da fatura traz (grupo => true), incluindo as que ficaram em duvida. */
function fatura_grupos_do_arquivo(array $linhas): array
{
    $g = [];
    foreach ($linhas as $l) {
        if (!empty($l['grupo']) && !empty($l['pn'])) {
            $g[(string) $l['grupo']] = true;
        }
        foreach ((array) ($l['grupos_duvida'] ?? []) as $d) {
            if ($d) {
                $g[(string) $d] = true;
            }
        }
    }
    return $g;
}

/**
 * Lanca as parcelas $pn+1..$pt de uma compra nas faturas seguintes a $ymBase. Pula fatura paga e parcela ja lancada.
 * $l: descricao, valor, tags, grupo, pn, pt. $contaLancadas = conta no "puladas" tambem as parcelas que ja existiam.
 */
function fatura_projetar_parcelas(array $cartaoRow, string $ymBase, array $l, ?int $cat, int $impId, array &$st, bool $contaLancadas): void
{
    $pid = perfil_id();
    $cartaoId = (int) $cartaoRow['id'];
    $pn = (int) $l['pn'];
    $pt = (int) $l['pt'];
    for ($k = 1; $k <= min($pt - $pn, 120); $k++) {
        $ymk = substr(add_meses($ymBase . '-01', $k), 0, 7);
        if (q_val('SELECT id FROM faturas WHERE cartao_id = ? AND mes = ?', [$cartaoId, $ymk])) {
            $st['puladas']++;
            continue;
        }
        if (q_val('SELECT id FROM lancamentos WHERE perfil_id = ? AND cartao_id = ? AND grupo_id = ? AND parcela_num = ?', [$pid, $cartaoId, $l['grupo'], $pn + $k])) {
            $st['puladas'] += $contaLancadas ? 1 : 0;
            continue;
        }
        q_insert(
            'INSERT INTO lancamentos (perfil_id, tipo, descricao, valor, data, efetivado, cartao_id, fatura_mes, categoria_id, tags, grupo_id,
                parcela_num, parcela_total, importacao_id, hash_unico)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [$pid, 'despesa', mb_substr(fatura_descricao_parcela($l['descricao'], $pn + $k, $pt), 0, 160), $l['valor'],
                fatura_vencimento($cartaoRow, $ymk), 0, $cartaoId, $ymk, $cat, ($l['tags'] ?? '') ?: null, $l['grupo'], $pn + $k, $pt, $impId,
                fatura_hash_parcela($pid, $cartaoId, (string) $l['grupo'], $pn + $k)]
        );
        $st['projetadas']++;
    }
}

/**
 * Remove as parcelas projetadas (lancadas por uma importacao anterior) das compras que a fatura $ym nao traz mais: estorno,
 * cancelamento ou parcelamento encerrado. So mexe em fatura nao paga, a partir de $ym, e em serie que ja deveria constar em $ym
 * (tem lancamento nessa fatura ou antes). Parcela que veio de uma fatura real ou foi lancada a mao nunca e removida.
 * @return int quantidade removida
 */
function fatura_remover_projecoes_obsoletas(int $cartaoId, string $ym, array $gruposNoArquivo): int
{
    $pid = perfil_id();
    $pagas = array_column(q_all('SELECT mes FROM faturas WHERE cartao_id = ?', [$cartaoId]), 'mes');
    $ancora = [];
    $ids = [];
    foreach (q_all(
        'SELECT id, grupo_id, parcela_num, fatura_mes, hash_unico FROM lancamentos
         WHERE perfil_id = ? AND cartao_id = ? AND grupo_id IS NOT NULL AND parcela_num IS NOT NULL AND importacao_id IS NOT NULL AND fatura_mes >= ?',
        [$pid, $cartaoId, $ym]
    ) as $r) {
        $g = (string) $r['grupo_id'];
        if (isset($gruposNoArquivo[$g]) || in_array($r['fatura_mes'], $pagas, true)
            || $r['hash_unico'] !== fatura_hash_parcela($pid, $cartaoId, $g, (int) $r['parcela_num'])) {
            continue;
        }
        if (!isset($ancora[$g])) {
            $ancora[$g] = (bool) q_val('SELECT id FROM lancamentos WHERE perfil_id = ? AND cartao_id = ? AND grupo_id = ? AND fatura_mes <= ? LIMIT 1', [$pid, $cartaoId, $g, $ym]);
        }
        if ($ancora[$g]) {
            $ids[] = (int) $r['id'];
        }
    }
    foreach (array_chunk($ids, 400) as $lote) {
        anexos_remover_arquivos($lote);
        q('DELETE FROM lancamentos WHERE perfil_id = ? AND id IN (' . implode(',', array_fill(0, count($lote), '?')) . ')', array_merge([$pid], $lote));
    }
    return count($ids);
}

/**
 * Grava os itens aprovados na fatura $ym do cartao. Itens ja importados (hash) sao ignorados.
 * $itens: linhas com 'hash' e opcional 'categoria_id'. Se $totalFatura for informado, ajusta a fatura a esse total
 * (cria/atualiza o item "Reajuste de fatura" com a diferenca) depois de gravar.
 *
 * A fatura do banco manda sobre a projecao: nas faturas seguintes (nao pagas) os "Reajuste de fatura" manuais sao removidos e,
 * com $linhasArquivo (todas as linhas lidas, ja marcadas por fatura_marcar_existentes), as parcelas projetadas de compras que o
 * arquivo nao traz mais saem, e as parcelas restantes das series que ele traz passam a constar (RN-39).
 * @return array{importados: int, duplicados: int, ajuste: ?float}
 */
function fatura_importar_itens(int $cartaoId, string $ym, array $itens, string $arquivo, ?float $totalFatura = null, ?array $linhasArquivo = null): array
{
    $pid = perfil_id();
    if (!pertence_ao_perfil('cartoes', $cartaoId) || mes_valido($ym, '') === '') {
        throw new InvalidArgumentException('Cartão ou fatura inválidos.');
    }
    if (q_val('SELECT id FROM faturas WHERE cartao_id = ? AND mes = ?', [$cartaoId, $ym])) {
        throw new InvalidArgumentException('Esta fatura já está paga. Reabra-a antes de importar itens.');
    }
    $cartaoRow = q_one('SELECT * FROM cartoes WHERE id = ? AND perfil_id = ?', [$cartaoId, $pid]);
    $st = ['importados' => 0, 'duplicados' => 0, 'ajuste' => null, 'projetadas' => 0, 'puladas' => 0,
        'reajustes_removidos' => 0, 'reajustes_removidos_total' => 0.0, 'parcelas_removidas' => 0];
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $impId = q_insert('INSERT INTO importacoes (perfil_id, arquivo, tipo) VALUES (?,?,?)', [$pid, mb_substr($arquivo, 0, 200), 'fatura_cartao']);
        $rj = remover_reajustes_futuros_fatura($cartaoId, $ym);
        $st['reajustes_removidos'] = $rj['qtd'];
        $st['reajustes_removidos_total'] = $rj['total'];
        if ($linhasArquivo !== null) {
            $st['parcelas_removidas'] = fatura_remover_projecoes_obsoletas($cartaoId, $ym, fatura_grupos_do_arquivo($linhasArquivo));
        }
        foreach ($itens as $l) {
            if (q_val('SELECT id FROM lancamentos WHERE perfil_id = ? AND hash_unico = ?', [$pid, $l['hash']])) {
                $st['duplicados']++;
                continue;
            }
            $cat = !empty($l['categoria_id']) && pertence_ao_perfil('categorias', (int) $l['categoria_id']) ? (int) $l['categoria_id'] : null;
            $pn = $l['pn'] ?? null;
            $pt = $l['pt'] ?? null;
            $grupo = $l['grupo'] ?? null;
            if ($pt === null) {
                [$pn, $pt] = fatura_parse_parcela($l);
            }
            q_insert(
                'INSERT INTO lancamentos (perfil_id, tipo, descricao, valor, data, efetivado, cartao_id, fatura_mes, categoria_id, tags, grupo_id,
                    parcela_num, parcela_total, importacao_id, hash_unico)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [$pid, 'despesa', mb_substr($l['descricao'], 0, 160), $l['valor'], $l['data'], 0, $cartaoId, $ym, $cat, ($l['tags'] ?? '') ?: null,
                    $grupo, $pn, $pt, $impId, $l['hash']]
            );
            // parcelas restantes: lanca as proximas parcelas nas faturas dos meses seguintes (RF: compra parcelada em lote)
            if (!empty($l['restantes']) && $grupo && $pn && $pt && $pt > $pn) {
                fatura_projetar_parcelas($cartaoRow, $ym, ['descricao' => $l['descricao'], 'valor' => $l['valor'], 'tags' => $l['tags'] ?? '', 'grupo' => $grupo, 'pn' => $pn, 'pt' => $pt],
                    $cat, $impId, $st, true);
            }
            $st['importados']++;
        }
        // series que o arquivo traz e ja estavam gravadas: as parcelas que faltam nas faturas seguintes passam a constar
        foreach ((array) $linhasArquivo as $l) {
            if (($l['status'] ?? '') !== 'existe' || empty($l['grupo']) || empty($l['pn']) || empty($l['pt']) || $l['pt'] <= $l['pn']) {
                continue;
            }
            $a = q_one(
                'SELECT descricao, valor, tags, categoria_id, fatura_mes FROM lancamentos WHERE perfil_id = ? AND cartao_id = ? AND grupo_id = ? AND parcela_num = ? ORDER BY id LIMIT 1',
                [$pid, $cartaoId, $l['grupo'], $l['pn']]
            );
            if ($a) {
                fatura_projetar_parcelas($cartaoRow, (string) $a['fatura_mes'], ['descricao' => $a['descricao'], 'valor' => $a['valor'], 'tags' => $a['tags'] ?? '',
                    'grupo' => $l['grupo'], 'pn' => $l['pn'], 'pt' => $l['pt']], $a['categoria_id'] ? (int) $a['categoria_id'] : null, $impId, $st, false);
            }
        }
        q('UPDATE importacoes SET qtd_importados = ?, qtd_duplicados = ? WHERE id = ?', [$st['importados'], $st['duplicados'], $impId]);
        $pdo->commit();
    } catch (Throwable $t) {
        $pdo->rollBack();
        throw $t;
    }
    if ($totalFatura !== null) {
        $st['ajuste'] = ajustar_fatura($cartaoId, $ym, $totalFatura)['ajuste'];
    }
    return $st;
}
