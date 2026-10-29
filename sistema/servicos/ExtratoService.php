<?php
declare(strict_types=1);

/* =========================================================================
   ExtratoService: importacao de extratos (conta corrente ou investimento) em PDF (IA), OFX, CSV ou Excel.

   Fluxo: extrato_ler_arquivo (cada arquivo) -> extrato_montar (junta os arquivos, pareia transferencias entre extratos,
   compara com o banco e sugere o que cada linha e) -> usuario aprova -> extrato_aplicar.

   - Cada arquivo tem um DESTINO: uma conta ("c:ID") ou um investimento ("i:ID").
   - Valor do extrato: entrada (+) ou saida (-) do destino. A IA classifica a natureza de cada linha.
   - Uma saida de um destino e uma entrada em outro, de mesmo valor e datas proximas, viram UMA transferencia
     (conta -> conta, aplicacao conta -> investimento ou resgate investimento -> conta).
   - Resgate: o extrato da conta mostra o valor LIQUIDO; o bruto e calculado pelo motor de renda fixa.
   - O arquivo e a senha do PDF nunca sao guardados; so o JSON da revisao fica em uploads/tmp ate confirmar ou expirar.
   ========================================================================= */

const EXTRATO_NATUREZAS = ['receita', 'despesa', 'transferencia', 'aplicacao', 'resgate', 'rendimento', 'imposto_tarifa', 'pagamento_fatura'];
const EXTRATO_MAX_ITENS = 1500;
const EXTRATO_ACOES = [
    'receita' => 'Receita', 'despesa' => 'Despesa', 'mover' => 'Transferência / aplicação / resgate',
    'rendimento' => 'Rendimento do investimento', 'fatura' => 'Pagamento de fatura', 'ignorar' => 'Ignorar',
];

// ------------------------------------------------------------------------------------------------ leitura

function extrato_esquema(): array
{
    $texto = ['type' => 'STRING'];
    $textoNull = ['type' => 'STRING', 'nullable' => true];
    $numNull = ['type' => 'NUMBER', 'nullable' => true];
    return [
        'type' => 'OBJECT',
        'properties' => [
            'instituicao' => $texto, 'tipo_extrato' => ['type' => 'STRING', 'enum' => ['conta_corrente', 'investimento']],
            'produto' => $textoNull, 'agencia' => $textoNull, 'conta' => $textoNull,
            'periodo_inicio' => $textoNull, 'periodo_fim' => $textoNull, 'saldo_inicial' => $numNull, 'saldo_final' => $numNull,
            'observacoes' => $textoNull, 'data_posicao' => $textoNull,
            'posicoes' => ['type' => 'ARRAY', 'items' => [
                'type' => 'OBJECT',
                'properties' => ['nome' => $texto, 'tipo_ativo' => $textoNull, 'emissor' => $textoNull, 'saldo_bruto' => ['type' => 'NUMBER'],
                    'ir' => $numNull, 'iof' => $numNull, 'saldo_liquido' => $numNull],
                'required' => ['nome', 'saldo_bruto'],
            ]],
            'lancamentos' => [
                'type' => 'ARRAY',
                'items' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'data' => $texto, 'descricao' => $texto, 'valor' => ['type' => 'NUMBER'],
                        'natureza' => ['type' => 'STRING', 'enum' => EXTRATO_NATUREZAS],
                        'contraparte' => $textoNull, 'categoria' => $textoNull,
                    ],
                    'required' => ['data', 'descricao', 'valor', 'natureza'],
                ],
            ],
        ],
        'required' => ['instituicao', 'tipo_extrato', 'lancamentos'],
    ];
}

function extrato_esquema_classificacao(): array
{
    return [
        'type' => 'OBJECT',
        'properties' => ['itens' => ['type' => 'ARRAY', 'items' => [
            'type' => 'OBJECT',
            'properties' => [
                'i' => ['type' => 'INTEGER'], 'natureza' => ['type' => 'STRING', 'enum' => EXTRATO_NATUREZAS],
                'contraparte' => ['type' => 'STRING', 'nullable' => true], 'categoria' => ['type' => 'STRING', 'nullable' => true],
            ],
            'required' => ['i', 'natureza'],
        ]]],
        'required' => ['itens'],
    ];
}

/** Nomes das contas, investimentos e categorias do usuario, para a IA usar exatamente os mesmos nomes. */
function extrato_contexto_ia(): array
{
    return [
        'contas' => array_column(contas_do_perfil(true), 'nome'),
        'investimentos' => array_column(q_all('SELECT nome FROM investimentos WHERE perfil_id = ? AND ativo = 1 ORDER BY nome', [perfil_id()]), 'nome'),
        'cartoes' => array_column(cartoes_do_perfil(true), 'nome'),
        'cat_despesa' => array_values(array_unique(array_column(categorias_do_perfil('despesa'), 'nome'))),
        'cat_receita' => array_values(array_unique(array_column(categorias_do_perfil('receita'), 'nome'))),
    ];
}

function extrato_regras_ia(array $ctx): string
{
    return "Regras de classificação (natureza) de cada movimentação:\n"
        . "- receita: dinheiro que entrou de terceiros (salário, pix recebido, depósito, reembolso, venda).\n"
        . "- despesa: dinheiro que saiu para terceiros (compra no débito, pix enviado, boleto, saque, pagamento de conta).\n"
        . "- transferencia: movimentação entre contas do próprio usuário (TED/DOC/PIX para si mesmo, ou entre bancos dele). Se a descrição traz o NOME DO PRÓPRIO TITULAR do extrato, é transferência entre contas dele; contraparte só deve ser preenchida se o banco do outro lado estiver explícito (nunca o mesmo banco do extrato); senão null.\n"
        . "- aplicacao: dinheiro que sai da conta para um investimento (CDB, RDB, cofrinho, caixinha, fundo, poupança, tesouro) ou, no extrato do investimento, o aporte recebido.\n"
        . "- resgate: dinheiro que volta de um investimento para a conta, ou, no extrato do investimento, a saída do resgate.\n"
        . "- rendimento: juros/rendimento creditados (conta remunerada, cofrinho, CDB). No extrato de investimento é o ganho diário/mensal.\n"
        . "- imposto_tarifa: IR, IOF e tarifas bancárias (inclusive IR/IOF retidos em resgates).\n"
        . "- pagamento_fatura: pagamento da fatura do cartão de crédito debitado da conta.\n"
        . "- contraparte: quem está do outro lado, quando identificável (nome da pessoa, empresa ou banco, por exemplo 'Itaú', 'Nubank', 'BV'). "
        . "Se o outro lado for uma conta, investimento ou cartão do usuário, use EXATAMENTE o nome da lista abaixo.\n"
        . 'Contas do usuário: ' . ($ctx['contas'] ? implode(', ', $ctx['contas']) : '(nenhuma)') . "\n"
        . 'Investimentos do usuário: ' . ($ctx['investimentos'] ? implode(', ', $ctx['investimentos']) : '(nenhum)') . "\n"
        . 'Cartões do usuário: ' . ($ctx['cartoes'] ? implode(', ', $ctx['cartoes']) : '(nenhum)') . "\n"
        . "- categoria: escolha UMA categoria da lista que melhor descreve a movimentação (despesas e receitas comuns), ou null.\n"
        . 'Categorias de despesa: ' . ($ctx['cat_despesa'] ? implode(', ', $ctx['cat_despesa']) : '(nenhuma)') . "\n"
        . 'Categorias de receita: ' . ($ctx['cat_receita'] ? implode(', ', $ctx['cat_receita']) : '(nenhuma)') . "\n";
}

function extrato_prompt_leitura(array $ctx): string
{
    return "Você extrai dados de extratos bancários brasileiros (conta corrente ou investimento) e responde SOMENTE com JSON no esquema pedido.\n"
        . "- instituicao: banco/instituição (ex.: Itaú, Nubank, BV, PicPay, Sicredi). tipo_extrato: conta_corrente ou investimento (CDB, RDB, cofrinho, caixinha, fundos, renda fixa). produto: nome do produto, se houver. agencia e conta: como aparecem, se houver.\n"
        . "- periodo_inicio, periodo_fim, saldo_inicial e saldo_final do período, quando o extrato informar (datas em AAAA-MM-DD).\n"
        . "- posicoes: SOMENTE para extratos de custódia/posição de investimentos (saldo por produto, sem movimentações): uma entrada por caixinha/ativo com nome (ex.: 'Turbo Croma'), tipo_ativo (ex.: RDB, CDB), emissor, saldo_bruto, ir e iof estimados e saldo_liquido; data_posicao em AAAA-MM-DD. Nesse caso lancamentos fica vazio. Em extratos com movimentações, posicoes fica vazio.\n"
        . "- saldo_inicial e saldo_final: use null quando o extrato não informar; não invente 0.\n"
        . "- lancamentos: UMA linha por movimentação. NÃO inclua linhas de saldo (saldo anterior, saldo do dia, saldo final), totais ou resumos.\n"
        . "- data: AAAA-MM-DD; se o extrato mostra só dia/mês, infira o ano pelo período.\n"
        . "- valor: número decimal com ponto, em reais, COM SINAL: entrada no destino = positivo, saída = negativo. Em extrato de investimento, aplicação/aporte e rendimento são positivos; resgate e impostos são negativos.\n"
        . "- descricao: o texto da movimentação como aparece, sem inventar.\n"
        . "- Os valores podem estar em colunas separadas da descrição: associe cada valor à sua linha pela posição visual.\n"
        . "- AUTOCONFERÊNCIA: saldo_inicial + soma dos valores deve ser igual ao saldo_final; releia e corrija antes de responder se não bater.\n"
        . extrato_regras_ia($ctx)
        . '- observacoes: avisos relevantes (ex.: itens ilegíveis); senão nulo.';
}

function extrato_ia_configurada(): bool
{
    return isset($GLOBALS['FIN_IA_MOCK']) || trim((string) getenv('GEMINI_API_KEY')) !== '';
}

/** Le um extrato em PDF com a IA. */
function extrato_ia_pdf(string $pdfPath, array $ctx): array
{
    fatura_pdf_chave_ia_ou_mock();
    $conteudo = (string) file_get_contents($pdfPath);
    if ($conteudo === '' || strncmp($conteudo, '%PDF', 4) !== 0) {
        throw new InvalidArgumentException('O arquivo enviado não é um PDF válido.');
    }
    return ia_gemini_json([
        ['text' => extrato_prompt_leitura($ctx)],
        ['inline_data' => ['mime_type' => 'application/pdf', 'data' => base64_encode($conteudo)]],
    ], extrato_esquema(), 'lancamentos', 'Não foi possível ler o extrato com a IA');
}

/** Le um extrato em formato tabular (CSV/Excel) enviado como texto. */
function extrato_ia_texto(string $texto, array $ctx): array
{
    fatura_pdf_chave_ia_ou_mock();
    return ia_gemini_json([
        ['text' => extrato_prompt_leitura($ctx)],
        ['text' => "Conteúdo do arquivo (colunas separadas por ' | '):\n" . $texto],
    ], extrato_esquema(), 'lancamentos', 'Não foi possível ler o extrato com a IA');
}

/** Converte CSV/Excel em texto tabular para a IA (limitado). */
function extrato_tabela_como_texto(string $caminho, string $ext): string
{
    if ($ext === 'xlsx') {
        $linhas = [];
        foreach (XlsxReader::ler($caminho) as $aba => $rows) {
            foreach ($rows as $r) {
                $linhas[] = $r;
            }
        }
    } else {
        $linhas = importar_ler_csv($caminho);
    }
    $out = [];
    foreach (array_slice($linhas, 0, 2500) as $r) {
        $l = trim(implode(' | ', array_map('trim', $r)), " |\t");
        if ($l !== '') {
            $out[] = $l;
        }
    }
    $txt = implode("\n", $out);
    if ($txt === '') {
        throw new InvalidArgumentException('O arquivo está vazio.');
    }
    return mb_substr($txt, 0, 250000);
}

/** Le um OFX (SGML ou XML): conta, saldo e transacoes. Sem IA. @return array{meta: array, itens: array} */
function extrato_parse_ofx(string $texto): array
{
    if (!mb_check_encoding($texto, 'UTF-8')) {
        $texto = mb_convert_encoding($texto, 'UTF-8', 'Windows-1252');
    }
    $campo = function (string $bloco, string $tag): string {
        return preg_match('/<' . $tag . '>\s*([^<\r\n]*)/i', $bloco, $m) ? trim(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8')) : '';
    };
    $meta = ['instituicao' => $campo($texto, 'ORG'), 'tipo_extrato' => stripos($texto, '<INVSTMTMSGSRSV1') !== false ? 'investimento' : 'conta_corrente',
        'produto' => '', 'agencia' => $campo($texto, 'BRANCHID'), 'conta' => $campo($texto, 'ACCTID'),
        'periodo_inicio' => null, 'periodo_fim' => null, 'saldo_inicial' => null, 'saldo_final' => null];
    if ($meta['instituicao'] === '') {
        $meta['instituicao'] = $campo($texto, 'BANKID');
    }
    if (preg_match('/<LEDGERBAL>(.*?)(<\/LEDGERBAL>|$)/is', $texto, $m) && $campo($m[1], 'BALAMT') !== '') {
        $meta['saldo_final'] = (float) str_replace(',', '.', $campo($m[1], 'BALAMT'));
    }
    $itens = [];
    if (preg_match_all('/<STMTTRN>(.*?)<\/STMTTRN>/is', $texto, $blocos)) {
        foreach ($blocos[1] as $b) {
            $dt = preg_replace('/\D/', '', $campo($b, 'DTPOSTED'));
            $data = strlen($dt) >= 8 ? substr($dt, 0, 4) . '-' . substr($dt, 4, 2) . '-' . substr($dt, 6, 2) : '';
            $valor = round((float) str_replace(',', '.', $campo($b, 'TRNAMT')), 2);
            $desc = $campo($b, 'MEMO') !== '' ? $campo($b, 'MEMO') : $campo($b, 'NAME');
            if ($data === '' || $valor == 0.0) {
                continue;
            }
            $itens[] = ['data' => $data, 'descricao' => $desc, 'valor' => $valor, 'natureza' => $valor > 0 ? 'receita' : 'despesa', 'contraparte' => null, 'categoria' => null];
        }
    }
    if (!$itens) {
        throw new InvalidArgumentException('Nenhuma transação encontrada no arquivo OFX.');
    }
    $datas = array_column($itens, 'data');
    $meta['periodo_inicio'] = min($datas);
    $meta['periodo_fim'] = max($datas);
    return ['meta' => $meta, 'itens' => $itens];
}

/** Classifica itens ja lidos (OFX) com a IA; sem IA, mantem a natureza pelo sinal. Devolve o aviso, se houver. */
function extrato_classificar(array &$itens, array $meta, array $ctx): ?string
{
    if (!extrato_ia_configurada()) {
        return 'IA não configurada: as linhas foram classificadas só pelo sinal (entrada/saída). Ajuste na revisão.';
    }
    $lista = [];
    foreach ($itens as $i => $x) {
        $lista[] = ['i' => $i, 'data' => $x['data'], 'descricao' => $x['descricao'], 'valor' => $x['valor']];
    }
    try {
        $r = ia_gemini_json([
            ['text' => 'Classifique cada movimentação de um extrato de ' . ($meta['tipo_extrato'] === 'investimento' ? 'investimento' : 'conta corrente')
                . ' (' . $meta['instituicao'] . "). Valor positivo = entrada, negativo = saída. Responda SOMENTE com JSON no esquema.\n" . extrato_regras_ia($ctx)],
            ['text' => json_encode($lista, JSON_UNESCAPED_UNICODE)],
        ], extrato_esquema_classificacao(), 'itens', 'Não foi possível classificar as linhas com a IA');
    } catch (RuntimeException $e) {
        return $e->getMessage() . ' As linhas foram classificadas só pelo sinal.';
    }
    foreach ((array) $r['itens'] as $c) {
        $i = (int) ($c['i'] ?? -1);
        if (isset($itens[$i]) && in_array($c['natureza'] ?? '', EXTRATO_NATUREZAS, true)) {
            $itens[$i]['natureza'] = $c['natureza'];
            $itens[$i]['contraparte'] = isset($c['contraparte']) ? trim((string) $c['contraparte']) : null;
            $itens[$i]['categoria'] = isset($c['categoria']) ? trim((string) $c['categoria']) : null;
        }
    }
    return null;
}

/**
 * Valida e normaliza a resposta da IA (ou do OFX). @return array{meta: array, itens: array, avisos: array}
 */
function extrato_normalizar(array $d): array
{
    $avisos = [];
    $itens = [];
    foreach (array_slice((array) ($d['lancamentos'] ?? $d['itens'] ?? []), 0, EXTRATO_MAX_ITENS) as $n => $x) {
        $data = parse_data($x['data'] ?? '');
        $valor = round((float) ($x['valor'] ?? 0), 2);
        $desc = trim((string) ($x['descricao'] ?? ''));
        if (!$data || $desc === '' || $valor == 0.0) {
            $avisos[] = 'Linha ' . ($n + 1) . ' ignorada (dados incompletos).';
            continue;
        }
        if (preg_match('/^\s*(saldo|sdo)\b/iu', $desc) || preg_match('/^\s*total\b/iu', $desc)) {
            continue; // linhas de saldo/total nao sao movimentacao
        }
        $nat = in_array($x['natureza'] ?? '', EXTRATO_NATUREZAS, true) ? $x['natureza'] : ($valor > 0 ? 'receita' : 'despesa');
        // IR/IOF retidos no resgate: o sistema calcula o imposto; nao e um resgate nem uma despesa a mais
        if ($valor < 0 && in_array($nat, ['resgate', 'despesa', 'transferencia'], true) && preg_match('/^\s*(ir|irrf|iof|imposto)(?![\p{L}\d])/iu', $desc)) {
            $nat = 'imposto_tarifa';
        }
        $itens[] = ['data' => $data, 'descricao' => mb_substr($desc, 0, 160), 'valor' => $valor, 'natureza' => $nat,
            'contraparte' => ($c = trim((string) ($x['contraparte'] ?? ''))) !== '' ? mb_substr($c, 0, 80) : null,
            'categoria' => ($c = trim((string) ($x['categoria'] ?? ''))) !== '' ? mb_substr($c, 0, 80) : null];
    }
    $posicoes = [];
    foreach (array_slice((array) ($d['posicoes'] ?? []), 0, 100) as $x) {
        $nome = trim((string) ($x['nome'] ?? ''));
        $bruto = round((float) ($x['saldo_bruto'] ?? 0), 2);
        if ($nome === '' || $bruto <= 0) {
            continue;
        }
        $posicoes[] = ['nome' => mb_substr($nome, 0, 100), 'tipo_ativo' => trim((string) ($x['tipo_ativo'] ?? '')), 'emissor' => trim((string) ($x['emissor'] ?? '')),
            'bruto' => $bruto, 'ir' => isset($x['ir']) && $x['ir'] !== null ? round((float) $x['ir'], 2) : null,
            'iof' => isset($x['iof']) && $x['iof'] !== null ? round((float) $x['iof'], 2) : null,
            'liquido' => isset($x['saldo_liquido']) && $x['saldo_liquido'] !== null ? round((float) $x['saldo_liquido'], 2) : null];
    }
    if (!$itens && !$posicoes) {
        throw new RuntimeException('Nenhuma movimentação nem posição de investimento foi encontrada no extrato.');
    }
    $meta = $d['meta'] ?? [
        'instituicao' => trim((string) ($d['instituicao'] ?? '')), 'tipo_extrato' => ($d['tipo_extrato'] ?? '') === 'investimento' ? 'investimento' : 'conta_corrente',
        'produto' => trim((string) ($d['produto'] ?? '')), 'agencia' => trim((string) ($d['agencia'] ?? '')), 'conta' => trim((string) ($d['conta'] ?? '')),
        'periodo_inicio' => parse_data($d['periodo_inicio'] ?? ''), 'periodo_fim' => parse_data($d['periodo_fim'] ?? ''),
        'saldo_inicial' => isset($d['saldo_inicial']) && $d['saldo_inicial'] !== null ? round((float) $d['saldo_inicial'], 2) : null,
        'saldo_final' => isset($d['saldo_final']) && $d['saldo_final'] !== null ? round((float) $d['saldo_final'], 2) : null,
    ];
    $meta['posicoes'] = $posicoes;
    $meta['data_posicao'] = parse_data($d['data_posicao'] ?? '') ?: ($meta['periodo_fim'] ?? null);
    $soma = round(array_sum(array_column($itens, 'valor')), 2);
    $meta['soma'] = $soma;
    $meta['entradas'] = round(array_sum(array_filter(array_column($itens, 'valor'), function ($v) { return $v > 0; })), 2);
    $meta['saidas'] = round(abs(array_sum(array_filter(array_column($itens, 'valor'), function ($v) { return $v < 0; }))), 2);
    $meta['modelo'] = $d['_modelo'] ?? '';
    if ($itens && $meta['saldo_inicial'] !== null && $meta['saldo_final'] !== null) {
        $dif = round($meta['saldo_final'] - $meta['saldo_inicial'] - $soma, 2);
        $meta['diferenca'] = $dif;
        if (abs($dif) >= 0.01) {
            $avisos[] = 'A soma das movimentações (' . brl($soma) . ') difere da variação de saldo do extrato (' . brl($meta['saldo_final'] - $meta['saldo_inicial']) . ') em ' . brl($dif) . '. Confira os valores.';
        }
    }
    if (!empty($d['observacoes'])) {
        $avisos[] = 'IA: ' . mb_substr((string) $d['observacoes'], 0, 200);
    }
    usort($itens, function ($a, $b) { return strcmp($a['data'], $b['data']); });
    if (!$itens) {
        $meta['periodo_inicio'] = $meta['periodo_fim'] = $meta['data_posicao'];
    }
    return ['meta' => $meta, 'itens' => $itens, 'avisos' => $avisos];
}

/** Fluxo completo de um arquivo: desprotege, le (IA/OFX) e normaliza. @return array{meta, itens, avisos, nome} */
function extrato_ler_arquivo(string $caminho, string $nomeOriginal, string $senha = ''): array
{
    $ext = strtolower(pathinfo($nomeOriginal, PATHINFO_EXTENSION));
    $ctx = extrato_contexto_ia();
    $avisoIa = null;
    if ($ext === 'pdf') {
        $tmp = [];
        try {
            $legivel = fatura_pdf_desproteger($caminho, $senha, $tmp);
            $r = extrato_normalizar(extrato_ia_pdf($legivel, $ctx));
        } finally {
            foreach ($tmp as $t) {
                @unlink($t);
            }
        }
    } elseif ($ext === 'ofx') {
        $o = extrato_parse_ofx((string) file_get_contents($caminho));
        $avisoIa = extrato_classificar($o['itens'], $o['meta'], $ctx);
        $r = extrato_normalizar(['meta' => $o['meta'], 'itens' => $o['itens']]);
    } elseif (in_array($ext, ['csv', 'txt', 'xlsx'], true)) {
        $r = extrato_normalizar(extrato_ia_texto(extrato_tabela_como_texto($caminho, $ext), $ctx));
    } elseif ($ext === 'xls') {
        throw new InvalidArgumentException('Arquivos .xls (Excel antigo) não são lidos. Salve como .xlsx ou .csv.');
    } else {
        throw new InvalidArgumentException('Formato não suportado. Envie PDF, OFX, CSV ou Excel (.xlsx).');
    }
    if ($avisoIa) {
        $r['avisos'][] = $avisoIa;
    }
    $r['nome'] = basename($nomeOriginal);
    return $r;
}

// ------------------------------------------------------------------------------------------------ destinos

/**
 * Durante extrato_montar, resultados que nao mudam entre as linhas (destinos, faturas em aberto) sao calculados uma vez
 * em vez de uma consulta por linha. Fora da montagem nao ha cache.
 */
function extrato_memo(string $chave, callable $fn)
{
    if (!isset($GLOBALS['FIN_EXTRATO_MEMO'])) {
        return $fn();
    }
    if (!array_key_exists($chave, $GLOBALS['FIN_EXTRATO_MEMO'])) {
        $GLOBALS['FIN_EXTRATO_MEMO'][$chave] = $fn();
    }
    return $GLOBALS['FIN_EXTRATO_MEMO'][$chave];
}

/** Destinos possiveis do extrato: contas e investimentos ativos. @return array<string, array{grupo: string, nome: string, instituicao: string}> */
function extrato_destinos(): array
{
    return extrato_memo('destinos', function () {
        return extrato_destinos_consultar();
    });
}

function extrato_destinos_consultar(): array
{
    $o = [];
    foreach (contas_do_perfil(true) as $c) {
        $o['c:' . $c['id']] = ['grupo' => 'Contas', 'nome' => (string) $c['nome'], 'instituicao' => ''];
    }
    foreach (q_all('SELECT id, nome, instituicao FROM investimentos WHERE perfil_id = ? AND ativo = 1 ORDER BY nome', [perfil_id()]) as $i) {
        $o['i:' . $i['id']] = ['grupo' => 'Investimentos', 'nome' => (string) $i['nome'], 'instituicao' => (string) ($i['instituicao'] ?? '')];
    }
    return $o;
}

/** HTML de <option> com os destinos agrupados (contas e investimentos) e, opcionalmente, as faturas em aberto. */
function extrato_opcoes_locais(?string $sel, string $vazio, bool $comFaturas = false): string
{
    $h = '<option value="">' . e($vazio) . '</option>';
    $grupos = [];
    foreach (extrato_destinos() as $k => $d) {
        $grupos[$d['grupo']][] = [$k, $d['nome']];
    }
    if ($comFaturas) {
        foreach (extrato_faturas_abertas() as $f) {
            $grupos['Faturas em aberto'][] = [$f['cp'], $f['rotulo']];
        }
    }
    foreach ($grupos as $g => $lista) {
        $h .= '<optgroup label="' . e($g) . '">';
        foreach ($lista as [$k, $n]) {
            $h .= '<option value="' . e($k) . '"' . ((string) $k === (string) $sel ? ' selected' : '') . '>' . e($n) . '</option>';
        }
        $h .= '</optgroup>';
    }
    return $h;
}

function extrato_loc(string $loc): array
{
    if (preg_match('/^([ci]):(\d+)$/', $loc, $m)) {
        return [$m[1], (int) $m[2]];
    }
    return ['', 0];
}

/** Nome do destino (sem rotulo) e do banco, usado para entender de qual banco e o extrato. */
function extrato_loc_nome_puro(string $loc): string
{
    $d = extrato_destinos();
    return isset($d[$loc]) ? $d[$loc]['nome'] . ' ' . $d[$loc]['instituicao'] : '';
}

function extrato_loc_nome(string $loc, ?array $destinos = null): string
{
    $destinos = $destinos ?? extrato_destinos();
    return isset($destinos[$loc]) ? ($loc[0] === 'i' ? 'Invest. ' : '') . $destinos[$loc]['nome'] : '—';
}

/** Palavras que identificam um cadastro (nome e instituicao), sem termos genericos nem numeros. */
function extrato_palavras(string $nome): array
{
    static $stop = ['conta', 'corrente', 'investimento', 'investimentos', 'cdb', 'rdb', 'cdi', 'banco', 'cofrinho', 'caixinha', 'poupanca',
        'fundo', 'fundos', 'lci', 'lca', 'renda', 'fixa', 'pagamento', 'de', 'do', 'da', 'dos', 'das', 'em', 'para', 'sa', 'ltda', 'cartao', 'fatura'];
    $p = [];
    foreach (explode(' ', norm_texto($nome)) as $w) {
        if (strlen($w) >= 2 && (!ctype_digit($w) || strlen($w) >= 3) && !in_array($w, $stop, true)) {
            $p[] = $w;
        }
    }
    return array_values(array_unique($p));
}

/**
 * Procura, no texto, uma conta/investimento do usuario (excluindo $excluir). Devolve a chave ('c:1'/'i:2') ou null se
 * nao houver correspondencia clara (nenhuma ou empate).
 * @param string $preferir 'c' | 'i' | '' : tipo preferido quando houver candidatos dos dois tipos
 */
function extrato_casar_local(string $texto, ?string $excluir, string $preferir = '', string $contexto = '', bool $contextoBasta = false): ?string
{
    $alvo = ' ' . norm_texto($texto) . ' ';
    $ctx = ' ' . norm_texto($contexto) . ' ';
    if (trim($alvo) === '' && trim($ctx) === '') {
        return null;
    }
    $pont = [];
    foreach (extrato_destinos() as $k => $d) {
        if ($k === $excluir) {
            continue;
        }
        $st = $sc = 0;
        foreach (extrato_palavras($d['nome'] . ' ' . $d['instituicao']) as $w) {
            if (strpos($alvo, ' ' . $w . ' ') !== false) {
                $st++;
            } elseif (strpos($ctx, ' ' . $w . ' ') !== false) {
                $sc++;
            }
        }
        // o banco do proprio extrato (contexto) so decide sozinho quando permitido (aplicacao/resgate: investimento do mesmo banco)
        if ($st > 0 || ($contextoBasta && $sc > 0 && ($preferir === '' || $k[0] === $preferir))) {
            $pont[$k] = $st * 10 + $sc;
        }
    }
    if (!$pont) {
        return null;
    }
    if ($preferir !== '') {
        $pref = array_filter($pont, function ($k) use ($preferir) { return $k[0] === $preferir; }, ARRAY_FILTER_USE_KEY);
        if ($pref) {
            $pont = $pref;
        }
    }
    arsort($pont);
    $chaves = array_keys($pont);
    if (count($chaves) > 1 && $pont[$chaves[0]] === $pont[$chaves[1]]) {
        return null;
    }
    return $chaves[0];
}

/** Sugere o destino de um arquivo pela instituicao/produto do extrato. */
function extrato_sugerir_destino(array $meta): string
{
    $texto = trim($meta['instituicao'] . ' ' . ($meta['produto'] ?? ''));
    $invest = $meta['tipo_extrato'] === 'investimento';
    $melhor = extrato_casar_local($texto, null, $invest ? 'i' : 'c');
    if ($melhor === null) {
        return '';
    }
    // extrato de conta nao pode ir para investimento (e vice-versa) por sugestao automatica
    return ($invest ? $melhor[0] === 'i' : $melhor[0] === 'c') ? $melhor : '';
}

/** Faturas de cartao em aberto (nao pagas e com valor). @return array<int, array{cp: string, cartao_id: int, ym: string, total: float, rotulo: string, cartao: string}> */
function extrato_faturas_abertas(): array
{
    return extrato_memo('faturas_abertas', function () {
        return extrato_faturas_abertas_consultar();
    });
}

function extrato_faturas_abertas_consultar(): array
{
    $out = [];
    foreach (cartoes_do_perfil(true) as $c) {
        foreach (faturas_do_cartao($c) as $f) {
            if (!$f['paga'] && $f['total'] > 0.004) {
                $out[] = ['cp' => 'f:' . $c['id'] . ':' . $f['mes'], 'cartao_id' => (int) $c['id'], 'ym' => $f['mes'], 'total' => $f['total'], 'cartao' => (string) $c['nome'],
                    'rotulo' => $c['nome'] . ' · ' . mes_label($f['mes']) . ' · ' . brl($f['total'])];
            }
        }
    }
    return $out;
}

// ------------------------------------------------------------------------------------------------ montagem

function extrato_hash(string $loc, array $x, int $ocorrencia): string
{
    return sha1(implode('|', ['extrato', perfil_id(), $loc, $x['data'], norm_texto($x['descricao']), number_format($x['valor'], 2, '.', ''), $ocorrencia]));
}

function extrato_dias_entre(string $a, string $b): int
{
    return (int) round(abs(strtotime($a) - strtotime($b)) / 86400);
}

/**
 * Pareia saidas de um destino com entradas de outro (mesmo valor, ate 3 dias): sao a mesma transferencia.
 * Entre investimento (saida = valor bruto) e conta, a entrada na conta pode ser menor (IR/IOF), ate 25%.
 * @param array $it itens planos com 'loc', 'sentido', 'valor', 'data', 'natureza'
 * @return array<int,int> indice da saida => indice da entrada
 */
function extrato_parear(array $it): array
{
    // entradas indexadas por data: cada saida so olha as entradas de +-3 dias (e nao todas as linhas)
    $entradas = [];
    foreach ($it as $b => $y) {
        if ($y['sentido'] === 'in' && $y['loc'] !== '') {
            $entradas[$y['data']][] = $b;
        }
    }
    $cand = [];
    foreach ($it as $a => $x) {
        if ($x['sentido'] !== 'out' || $x['loc'] === '') {
            continue;
        }
        for ($d = -3; $d <= 3; $d++) {
            foreach ($entradas[add_dias($x['data'], $d)] ?? [] as $b) {
                $y = $it[$b];
                if ($y['loc'] === $x['loc'] || $a === $b) {
                    continue;
                }
                $dias = abs($d);
                $tx = $x['loc'][0];
                $ty = $y['loc'][0];
                // conta <-> conta: so transferencia nas duas pontas; conta -> investimento: aplicacao; investimento -> conta: resgate.
                // (resgate de uma conta nao pareia com saida de OUTRA conta: o outro lado e um investimento)
                if ($tx === 'c' && $ty === 'c' && ($x['natureza'] !== 'transferencia' || $y['natureza'] !== 'transferencia')) {
                    continue;
                }
                if ($tx === 'c' && $ty === 'i' && (!in_array($x['natureza'], ['aplicacao', 'transferencia'], true) || in_array($y['natureza'], ['rendimento', 'imposto_tarifa'], true))) {
                    continue;
                }
                if ($tx === 'i' && $ty === 'c' && (!in_array($y['natureza'], ['resgate', 'transferencia'], true) || in_array($x['natureza'], ['rendimento', 'imposto_tarifa'], true))) {
                    continue;
                }
                if ($tx === 'i' && $ty === 'i') {
                    continue;
                }
                $dif = abs($x['valor'] - $y['valor']);
                $ok = $dif < 0.005 || ($tx === 'i' && $ty === 'c' && $y['valor'] < $x['valor'] && $y['valor'] >= $x['valor'] * 0.75);
                if ($ok) {
                    $cand[] = [$dif * 1000 + $dias, $a, $b];
                }
            }
        }
    }
    // empate de nota: ordem das linhas (resultado previsivel; o usort do PHP 7 nao e estavel)
    usort($cand, function ($p, $q) {
        return [$p[0], $p[1], $p[2]] <=> [$q[0], $q[1], $q[2]];
    });
    $usadoA = $usadoB = $pares = [];
    foreach ($cand as [$s, $a, $b]) {
        if (isset($usadoA[$a]) || isset($usadoB[$b]) || isset($usadoA[$b]) || isset($usadoB[$a])) {
            continue;
        }
        $usadoA[$a] = $usadoB[$b] = true;
        $pares[$a] = $b;
    }
    return $pares;
}

/**
 * Candidatos no banco para a linha (mesmo local, sentido, valor e data +-3 dias), ainda nao usados por outra linha.
 * 'nome' e a descricao do lancamento (null para movimentos de investimento, que nao tem nome para comparar).
 * @return array<int, array{k: string, nome: ?string, texto: string}>
 */
function extrato_candidatos(string $loc, string $sentido, float $valor, string $data, bool $rendimento, array $usados): array
{
    [$t, $id] = extrato_loc($loc);
    $pid = perfil_id();
    $de = add_dias($data, -3);
    $ate = add_dias($data, 3);
    $out = [];
    if ($t === 'c') {
        $rows = q_all(
            'SELECT id, tipo, descricao, data, conta_id, conta_destino_id, sentido_invest, cartao_id FROM lancamentos
             WHERE perfil_id = ? AND ignorar = 0 AND ajuste IS NULL AND ABS(valor - ?) < 0.005 AND data BETWEEN ? AND ? ORDER BY id',
            [$pid, $valor, $de, $ate]
        );
        foreach ($rows as $l) {
            if (isset($usados['l' . $l['id']]) || $l['cartao_id']) {
                continue;
            }
            if ($l['tipo'] === 'receita') {
                $achou = $sentido === 'in' && (int) $l['conta_id'] === $id;
            } elseif ($l['tipo'] === 'despesa') {
                $achou = $sentido === 'out' && (int) $l['conta_id'] === $id;
            } elseif ($l['sentido_invest'] === 'resgate') {
                $achou = $sentido === 'in' && (int) $l['conta_id'] === $id;
            } elseif ($l['sentido_invest'] === 'aplicacao') {
                $achou = $sentido === 'out' && (int) $l['conta_id'] === $id;
            } else {
                $achou = ($sentido === 'out' && (int) $l['conta_id'] === $id) || ($sentido === 'in' && (int) $l['conta_destino_id'] === $id);
            }
            if ($achou) {
                $out[] = ['k' => 'l' . $l['id'], 'nome' => $l['descricao'], 'texto' => $l['descricao'] . ' (' . data_br($l['data']) . ')'];
            }
        }
    } elseif ($t === 'i') {
        $tipos = $rendimento ? "('rendimento','reajuste')" : ($sentido === 'in' ? "('aporte')" : "('resgate')");
        $rows = q_all(
            'SELECT m.id, m.tipo, m.data FROM investimento_movs m LEFT JOIN lancamentos l ON l.id = m.lancamento_id
             WHERE m.investimento_id = ? AND m.tipo IN ' . $tipos . ' AND m.data BETWEEN ? AND ? AND (ABS(m.valor - ?) < 0.005 OR ABS(COALESCE(l.valor, -1) - ?) < 0.005) ORDER BY m.id',
            [$id, $de, $ate, $valor, $valor]
        );
        foreach ($rows as $m) {
            if (!isset($usados['m' . $m['id']])) {
                $out[] = ['k' => 'm' . $m['id'], 'nome' => null, 'texto' => ucfirst((string) $m['tipo']) . ' já lançado (' . data_br($m['data']) . ')'];
            }
        }
    }
    return $out;
}

/** Acha a fatura de cartao em aberto cujo total e igual ao pagamento. Devolve a chave 'f:cartao:ym' ou null. */
function extrato_casar_fatura(float $valor, string $texto): ?string
{
    $iguais = array_values(array_filter(extrato_faturas_abertas(), function ($f) use ($valor) { return abs($f['total'] - $valor) < 0.01; }));
    if (count($iguais) === 1) {
        return $iguais[0]['cp'];
    }
    if (count($iguais) > 1) {
        $alvo = ' ' . norm_texto($texto) . ' ';
        $m = array_values(array_filter($iguais, function ($f) use ($alvo) {
            foreach (extrato_palavras($f['cartao']) as $w) {
                if (strpos($alvo, ' ' . $w . ' ') !== false) {
                    return true;
                }
            }
            return false;
        }));
        if (count($m) === 1) {
            return $m[0]['cp'];
        }
    }
    return null;
}

function extrato_categoria_id(?string $nome, string $tipo): ?int
{
    static $cache = [];
    if (!$nome) {
        return null;
    }
    $cache[$tipo] = $cache[$tipo] ?? categorias_do_perfil($tipo);
    return fatura_categoria_por_nome($nome, $cache[$tipo]);
}

/** Sugere o que fazer com uma linha. @return array{acao: string, cp: string} */
function extrato_sugerir_acao(array $r): array
{
    [$t, $id] = extrato_loc($r['loc']);
    $nat = $r['natureza'];
    $texto = $r['descricao'] . ' ' . ($r['contraparte_txt'] ?? '');
    if (!empty($r['par_loc'])) {
        return ['acao' => 'mover', 'cp' => $r['par_loc']];
    }
    if ($t === 'i') {
        if ($nat === 'rendimento') {
            $inv = q_one('SELECT indexador FROM investimentos WHERE id = ? AND perfil_id = ?', [$id, perfil_id()]);
            return ['acao' => ($inv && $inv['indexador'] === 'manual') ? 'rendimento' : 'ignorar', 'cp' => ''];
        }
        if ($nat === 'imposto_tarifa') {
            return ['acao' => 'ignorar', 'cp' => ''];
        }
        $cp = extrato_casar_local($texto, $r['loc'], 'c', extrato_loc_nome_puro($r['loc'])) ?? '';
        if ($cp !== '' && $cp[0] !== 'c') {
            $cp = '';
        }
        return ['acao' => 'mover', 'cp' => $cp];
    }
    if ($t !== 'c') {
        return ['acao' => 'ignorar', 'cp' => ''];
    }
    if ($nat === 'pagamento_fatura') {
        $f = $r['sentido'] === 'out' ? extrato_casar_fatura($r['valor'], $texto) : null;
        return $f ? ['acao' => 'fatura', 'cp' => $f] : ['acao' => 'ignorar', 'cp' => ''];
    }
    if (in_array($nat, ['aplicacao', 'resgate', 'transferencia'], true)) {
        $cp = extrato_casar_local($texto, $r['loc'], $nat === 'transferencia' ? 'c' : 'i', extrato_loc_nome_puro($r['loc']), $nat !== 'transferencia');
        if ($cp !== null && $nat !== 'transferencia' && $cp[0] !== 'i') {
            $cp = null; // aplicacao e resgate sao com um investimento cadastrado, nao com uma conta
        }
        if ($cp !== null) {
            return ['acao' => 'mover', 'cp' => $cp];
        }
        if ($nat !== 'transferencia') {
            return ['acao' => 'mover', 'cp' => '']; // aplicacao/resgate: o usuario escolhe o investimento
        }
    }
    if ($nat === 'receita' || $nat === 'rendimento' || ($nat === 'transferencia' && $r['sentido'] === 'in')) {
        return ['acao' => $r['sentido'] === 'in' ? 'receita' : 'despesa', 'cp' => ''];
    }
    return ['acao' => $r['sentido'] === 'out' ? 'despesa' : 'receita', 'cp' => ''];
}

/**
 * Valida a acao escolhida e devolve a operacao a executar ou o motivo de nao ser possivel.
 * @return array{op?: string, erro?: string, ...}
 */
function extrato_resolver(array $r, string $acao, string $cp): array
{
    [$tl, $il] = extrato_loc($r['loc']);
    $out = $r['sentido'] === 'out';
    switch ($acao) {
        case 'ignorar':
            return ['op' => 'ignorar'];
        case 'receita':
        case 'despesa':
            if ($tl !== 'c') {
                return ['erro' => 'Receita e despesa são lançadas em conta. Em extrato de investimento use aplicação/resgate ou rendimento.'];
            }
            if (($acao === 'despesa') !== $out) {
                return ['erro' => $out ? 'É uma saída: escolha Despesa ou Transferência.' : 'É uma entrada: escolha Receita ou Transferência.'];
            }
            return ['op' => $acao, 'conta' => $il, 'valor' => $r['valor']];
        case 'rendimento':
            if ($tl !== 'i' || $out) {
                return ['erro' => 'Rendimento é uma entrada no extrato de um investimento.'];
            }
            return ['op' => 'rendimento', 'inv' => $il, 'valor' => $r['valor']];
        case 'fatura':
            if ($tl !== 'c' || !$out || !preg_match('/^f:(\d+):(\d{4}-\d{2})$/', $cp, $m)) {
                return ['erro' => 'Pagamento de fatura: saída de uma conta; escolha a fatura em aberto.'];
            }
            return ['op' => 'fatura', 'conta' => $il, 'cartao' => (int) $m[1], 'ym' => $m[2], 'valor' => $r['valor']];
        case 'mover':
            [$tc, $ic] = extrato_loc($cp);
            if ($cp === $r['loc']) {
                return ['erro' => 'Origem e destino são o mesmo lugar.'];
            }
            $A = $out ? [$tl, $il] : [$tc, $ic];
            $B = $out ? [$tc, $ic] : [$tl, $il];
            $vOut = $r['valor_out'];
            $vIn = $r['valor_in'];
            if ($A[0] === 'c' && $B[0] === 'c') {
                return ['op' => 'transferir', 'de' => $A[1], 'para' => $B[1], 'valor' => $vOut];
            }
            if ($A[0] === 'c' && ($B[0] === 'i' || $B[0] === '')) {
                if ($B[0] === '') {
                    return ['erro' => 'Escolha o investimento de destino da aplicação.'];
                }
                return ['op' => 'aplicar', 'conta' => $A[1], 'inv' => $B[1], 'valor' => $vOut];
            }
            if ($A[0] === '' && $B[0] === 'c') {
                return ['erro' => 'Escolha a conta ou o investimento de origem.'];
            }
            if ($A[0] === 'i' && $B[0] === 'c') {
                return ['op' => 'resgatar', 'inv' => $A[1], 'conta' => $B[1], 'liquido' => $vIn];
            }
            if ($A[0] === 'i' && $B[0] === '') {
                return ['op' => 'resgatar', 'inv' => $A[1], 'conta' => 0, 'bruto' => $vOut];
            }
            if ($A[0] === '' && $B[0] === 'i') {
                return ['op' => 'aplicar', 'conta' => 0, 'inv' => $B[1], 'valor' => $vIn];
            }
            if ($A[0] === 'i' && $B[0] === 'i') {
                return ['erro' => 'Entre dois investimentos não é suportado: resgate para a conta e aplique no outro.'];
            }
            return ['erro' => 'Escolha a conta ou o investimento do outro lado.'];
    }
    return ['erro' => 'Ação inválida.'];
}

/**
 * Junta os arquivos, pareia transferencias e compara com o banco.
 * @param array $arquivos  lista de ['nome', 'meta', 'itens']
 * @param array $destinos  indice do arquivo => 'c:ID'|'i:ID'|''
 * @param array $over      chave da linha => ['acao' =>, 'cp' =>, 'cat' =>] (escolhas do usuario)
 * @param string $corte    AAAA-MM-DD: movimentacoes anteriores a esta data sao descartadas
 * @return array<int, array> linhas da revisao
 */
function extrato_montar(array $arquivos, array $destinos, array $over = [], string $corte = ''): array
{
    $GLOBALS['FIN_EXTRATO_MEMO'] = []; // destinos e faturas em aberto: uma consulta por montagem, nao por linha
    try {
        return extrato_montar_interno($arquivos, $destinos, $over, $corte);
    } finally {
        unset($GLOBALS['FIN_EXTRATO_MEMO']);
    }
}

/** Quais dos hashes ja estao gravados (uma consulta por lote, em vez de uma por linha). @return array<string,true> */
function extrato_hashes_existentes(array $hashes): array
{
    $out = [];
    foreach (array_chunk(array_values(array_unique(array_filter($hashes))), 400) as $lote) {
        $marcas = implode(',', array_fill(0, count($lote), '?'));
        foreach (q_all("SELECT hash_unico FROM lancamentos WHERE perfil_id = ? AND hash_unico IN ($marcas)", array_merge([perfil_id()], $lote)) as $r) {
            $out[$r['hash_unico']] = true;
        }
    }
    return $out;
}

function extrato_montar_interno(array $arquivos, array $destinos, array $over, string $corte): array
{
    $plano = [];
    foreach ($arquivos as $f => $a) {
        $loc = (string) ($destinos[$f] ?? '');
        $ocorr = [];
        foreach ($a['itens'] as $i => $x) {
            $h0 = extrato_hash($loc, $x, 0);
            $ocorr[$h0] = ($ocorr[$h0] ?? -1) + 1;
            if ($corte !== '' && $x['data'] < $corte) {
                continue; // fica de fora, mas conta nas ocorrencias para o hash das demais linhas nao mudar
            }
            $plano[] = ['f' => $f, 'i' => $i, 'loc' => $loc, 'data' => $x['data'], 'descricao' => $x['descricao'], 'valor' => abs($x['valor']),
                'sentido' => $x['valor'] > 0 ? 'in' : 'out', 'natureza' => $x['natureza'], 'contraparte_txt' => (string) ($x['contraparte'] ?? ''),
                'categoria_txt' => (string) ($x['categoria'] ?? ''), 'hash' => extrato_hash($loc, $x, $ocorr[$h0])];
        }
    }
    $pares = extrato_parear($plano);
    $existentes = extrato_hashes_existentes(array_column($plano, 'hash'));
    $consumidos = array_flip(array_values($pares));
    $destinosLista = extrato_destinos();
    $usados = [];
    $linhas = [];
    $duvidas = [];
    $posDuvida = [];
    foreach ($plano as $n => $x) {
        if (isset($consumidos[$n])) {
            continue;
        }
        $r = $x;
        $r['k'] = $x['f'] . '_' . $x['i'];
        $r['valor_out'] = $r['valor_in'] = $x['valor'];
        $r['data_in'] = $x['data'];
        $r['par_loc'] = '';
        $r['hashes'] = [$x['hash']];
        $parecidos = [];
        if (isset($pares[$n])) {
            $y = $plano[$pares[$n]];
            $r['par_loc'] = $y['loc'];
            $r['valor_in'] = $y['valor'];
            $r['data_in'] = $y['data'];
            $r['par_descricao'] = $y['descricao'];
            $r['hashes'][] = $y['hash'];
            $r['contraparte_txt'] = trim($r['contraparte_txt'] . ' ' . $y['contraparte_txt']);
        }
        $sug = extrato_sugerir_acao($r);
        $o = $over[$r['k']] ?? [];
        $acao = isset($o['acao']) && isset(EXTRATO_ACOES[$o['acao']]) ? $o['acao'] : $sug['acao'];
        $cp = (isset($o['cp']) && $o['cp'] !== '') ? (string) $o['cp'] : ($acao === $sug['acao'] ? $sug['cp'] : '');
        $r['acao'] = $acao;
        $r['cp'] = $cp;
        $tipoCat = $acao === 'receita' ? 'receita' : 'despesa';
        $r['categoria_id'] = array_key_exists('cat', $o) ? ((int) $o['cat'] ?: null) : (in_array($acao, ['receita', 'despesa'], true) ? extrato_categoria_id($x['categoria_txt'], $tipoCat) : null);
        $r['op'] = extrato_resolver($r, $acao, $cp);
        // situacao frente ao banco
        $r['status'] = 'novo';
        $r['parecido'] = '';
        foreach ($r['hashes'] as $h) {
            if ($h && isset($existentes[$h])) {
                $r['status'] = 'existe';
                $r['parecido'] = 'Já importado anteriormente';
                break;
            }
        }
        if ($r['status'] === 'novo' && $r['loc'] !== '') {
            $cands = extrato_candidatos($r['loc'], $r['sentido'], $r['valor'], $r['data'], $acao === 'rendimento', $usados);
            if ($r['par_loc'] !== '') {
                $cands = array_merge($cands, extrato_candidatos($r['par_loc'], 'in', $r['valor_in'], $r['data_in'], false, $usados));
            }
            foreach ($cands as &$c) {
                $c['score'] = $c['nome'] === null ? null : fatura_nome_similaridade($r['descricao'], $c['nome']);
            }
            unset($c);
            usort($cands, function ($p, $q) { return ($q['score'] ?? 0.5) <=> ($p['score'] ?? 0.5); });
            if ($cands && ($cands[0]['score'] === null || $cands[0]['score'] >= FATURA_NOME_CERTO)) {
                // mesmo valor, local e data com nome igual/semelhante (ou movimento sem nome): provavel, e "existe" quando o nome confere
                $usados[$cands[0]['k']] = true;
                $r['status'] = $cands[0]['score'] === null ? 'provavel' : 'existe';
                $r['parecido'] = $cands[0]['texto'];
            } elseif ($cands) {
                $duvidas[$n] = array_slice($cands, 0, 3);
            }
        }
        $r['loc_nome'] = extrato_loc_nome($r['loc'], $destinosLista);
        $r['par_nome'] = $r['par_loc'] !== '' ? extrato_loc_nome($r['par_loc'], $destinosLista) : '';
        $linhas[] = $r;
        if (isset($duvidas[$n])) {
            $posDuvida[$n] = count($linhas) - 1;
        }
    }
    if ($duvidas) {
        extrato_resolver_duvidas($linhas, $duvidas, $posDuvida, $usados);
    }
    usort($linhas, function ($a, $b) { return [$a['data'], $a['f'], $a['i']] <=> [$b['data'], $b['f'], $b['i']]; });
    return $linhas;
}

/**
 * Linhas cujo candidato tem valor/local/data iguais mas nome diferente: a IA decide numa unica chamada se e a mesma transacao.
 * Sim = "ja existe"; nao = segue como nova; sem resposta (IA indisponivel) = "possivel duplicado" com o melhor candidato.
 */
function extrato_resolver_duvidas(array &$linhas, array $duvidas, array $pos, array &$usados): void
{
    $pares = [];
    foreach ($duvidas as $n => $cands) {
        foreach ($cands as $c) {
            $r = $linhas[$pos[$n]];
            $pares[$n . ':' . $c['k']] = ['novo' => $r['descricao'], 'gravado' => (string) $c['nome'], 'valor' => (float) $r['valor'],
                'detalhe' => ($r['sentido'] === 'in' ? 'entrada' : 'saída') . ' em ' . data_br($r['data']) . ', mesma conta'];
        }
    }
    $veredito = ia_mesma_transacao($pares, 'Extrato bancário.');
    foreach ($duvidas as $n => $cands) {
        $achou = null;
        $semDecisao = false;
        foreach ($cands as $c) {
            $v = $veredito[$n . ':' . $c['k']] ?? null;
            if ($v === true && !isset($usados[$c['k']])) {
                $achou = $c;
                break;
            }
            $semDecisao = $semDecisao || $v === null;
        }
        $i = $pos[$n];
        if ($achou) {
            $usados[$achou['k']] = true;
            $linhas[$i]['status'] = 'existe';
            $linhas[$i]['parecido'] = $achou['texto'] . ' · confirmado pela IA';
        } elseif ($semDecisao) {
            $linhas[$i]['status'] = 'provavel';
            $linhas[$i]['parecido'] = $cands[0]['texto'];
        }
    }
}

// ------------------------------------------------------------------------------------------------ aplicacao

/** Bruto a resgatar para que o LIQUIDO (apos IR/IOF) seja $liquido. Devolve null quando e o resgate total. */
function extrato_bruto_do_liquido(int $invId, float $liquido, string $data): ?float
{
    return rf_bruto_do_liquido($invId, $liquido, $data);
}

function extrato_marcar_hash(?int $lancId, string $hash): void
{
    if ($lancId && $hash !== '') {
        q('UPDATE lancamentos SET hash_unico = ? WHERE id = ? AND perfil_id = ?', [$hash, $lancId, perfil_id()]);
    }
}

/** Executa uma linha aprovada. Devolve um texto curto do que foi feito. Lanca excecao em caso de erro. */
function extrato_executar(array $r): string
{
    $op = $r['op'];
    if (isset($op['erro'])) {
        throw new InvalidArgumentException($op['erro']);
    }
    $hoje = date('Y-m-d');
    $obs = 'Extrato';
    $h = $r['hashes'][0] ?? '';
    $desc = $r['descricao'];
    switch ($op['op']) {
        case 'receita':
        case 'despesa':
            $ids = criar_lancamento_ids(['tipo' => $op['op'], 'descricao' => $desc, 'valor' => $op['valor'], 'data' => $r['data'], 'efetivado' => $r['data'] <= $hoje ? 1 : 0,
                'conta_id' => $op['conta'], 'categoria_id' => $r['categoria_id'], 'observacao' => $obs]);
            extrato_marcar_hash($ids[0], $h);
            return ucfirst($op['op']);
        case 'transferir':
            $ids = criar_lancamento_ids(['tipo' => 'transferencia', 'descricao' => $desc, 'valor' => $op['valor'], 'data' => $r['data'], 'efetivado' => $r['data'] <= $hoje ? 1 : 0,
                'conta_id' => $op['de'], 'conta_destino_id' => $op['para'], 'observacao' => $obs]);
            extrato_marcar_hash($ids[0], $h);
            return 'Transferência';
        case 'aplicar':
            $data = $op['conta'] ? $r['data'] : $r['data_in'];
            $lid = rf_aplicar((int) $op['conta'], (int) $op['inv'], $op['valor'], $data, $data <= $hoje, $obs);
            extrato_marcar_hash($lid ?: null, $h);
            return 'Aplicação';
        case 'resgatar':
            $data = $r['data_in'];
            if ($op['conta']) {
                $bruto = extrato_bruto_do_liquido((int) $op['inv'], (float) $op['liquido'], $data);
                $res = rf_resgatar((int) $op['inv'], (int) $op['conta'], $bruto, $data, $data <= $hoje, $obs);
                if (!empty($res['lancamento_id'])) {
                    // o extrato da conta e a referencia: o credito fica exatamente como o banco mostrou
                    q('UPDATE lancamentos SET valor = ? WHERE id = ? AND perfil_id = ?', [round((float) $op['liquido'], 2), $res['lancamento_id'], perfil_id()]);
                    extrato_marcar_hash((int) $res['lancamento_id'], $h);
                }
            } else {
                rf_resgatar((int) $op['inv'], 0, $op['bruto'], $r['data'], $r['data'] <= $hoje, $obs);
            }
            return 'Resgate';
        case 'rendimento':
            if (!pertence_ao_perfil('investimentos', (int) $op['inv'])) {
                throw new InvalidArgumentException('Investimento inválido.');
            }
            q_insert('INSERT INTO investimento_movs (investimento_id, data, tipo, valor, obs) VALUES (?,?,?,?,?)', [(int) $op['inv'], $r['data'], 'rendimento', $op['valor'], $obs]);
            return 'Rendimento';
        case 'fatura':
            pagar_fatura((int) $op['cartao'], (string) $op['ym'], (int) $op['conta'], $r['data']);
            return 'Pagamento de fatura';
    }
    return '';
}

/**
 * Aplica as linhas aprovadas (cada uma em sua transacao). @param array $linhas resultado de extrato_montar, ja filtrado
 * @return array{feitos: int, erros: string[], resumo: array<string,int>}
 */
function extrato_aplicar(array $linhas): array
{
    $res = ['feitos' => 0, 'erros' => [], 'resumo' => []];
    $mov = [];
    foreach ($linhas as $r) {
        $op = $r['op'] ?? [];
        if (($op['op'] ?? '') === 'ignorar') {
            continue;
        }
        try {
            if (in_array($op['op'] ?? '', ['transferir', 'aplicar', 'resgatar'], true)) {
                // a mesma movimentacao entre dois lugares aparece nos dois extratos: nao lancar duas vezes
                $chave = implode('|', [$op['op'], $op['de'] ?? $op['conta'] ?? 0, $op['para'] ?? $op['inv'] ?? 0, $op['op'] === 'resgatar' ? ($op['conta'] ?? 0) : 0, round((float) ($op['valor'] ?? $op['liquido'] ?? $op['bruto'] ?? 0), 2)]);
                foreach ($mov[$chave] ?? [] as $dt) {
                    if (extrato_dias_entre($dt, $r['data']) <= 3) {
                        throw new InvalidArgumentException('Já lançado nesta importação (a mesma movimentação aparece nos dois extratos).');
                    }
                }
                $mov[$chave][] = $r['data'];
            }
            $rot = extrato_executar($r);
            $res['feitos']++;
            $res['resumo'][$rot] = ($res['resumo'][$rot] ?? 0) + 1;
        } catch (Throwable $e) {
            $res['erros'][] = data_br($r['data']) . ' ' . mb_substr($r['descricao'], 0, 40) . ' (' . brl($r['valor']) . '): ' . $e->getMessage();
        }
    }
    return $res;
}

// ------------------------------------------------------------------------------------------------ posicoes (extrato de custodia)

/** Posicoes de investimentos dos arquivos (extrato de custodia), com o investimento sugerido pelo nome. */
function extrato_posicoes(array $arquivos): array
{
    $out = [];
    foreach ($arquivos as $f => $a) {
        foreach ($a['meta']['posicoes'] ?? [] as $i => $p) {
            // so sugere quando TODAS as palavras do produto estao no nome do investimento (ex.: "Turbo Croma")
            $pal = extrato_palavras($p['nome']);
            $cands = [];
            foreach (extrato_destinos() as $k => $dst) {
                if ($k[0] === 'i' && $pal && !array_diff($pal, extrato_palavras($dst['nome'] . ' ' . $dst['instituicao']))) {
                    $cands[] = (int) substr($k, 2);
                }
            }
            $out[] = $p + ['k' => $f . '_' . $i, 'data' => $a['meta']['data_posicao'] ?: date('Y-m-d'), 'arquivo' => $a['nome'],
                'sugestao' => count($cands) === 1 ? $cands[0] : 0];
        }
    }
    return $out;
}

/**
 * Aplica as posicoes aprovadas: investimento existente = reajuste do saldo BRUTO; "novo" = cria o investimento (CDI) com
 * a posicao inicial equivalente (IR estimado do extrato). @param array $itens cada um: posicao + 'inv' (id|'novo') + 'pct'
 * @return array{feitos: int, erros: string[]}
 */
function extrato_aplicar_posicoes(array $itens): array
{
    $res = ['feitos' => 0, 'erros' => []];
    foreach ($itens as $p) {
        try {
            if ($p['inv'] === 'novo') {
                $produto = stripos($p['tipo_ativo'], 'cdb') !== false ? 'cdb' : 'rdb';
                $nome = trim(($p['emissor'] !== '' ? $p['emissor'] . ' · ' : '') . $p['nome']);
                $id = investimento_salvar(['nome' => $nome, 'produto' => $produto, 'instituicao' => $p['emissor'], 'indexador' => 'cdi',
                    'percentual_cdi' => $p['pct'] > 0 ? $p['pct'] : 100, 'liquidez' => 'diaria']);
                rf_posicao_inicial($id, (float) $p['bruto'], $p['ir']);
            } else {
                try {
                    rf_reajustar((int) $p['inv'], $p['bruto'], $p['data'], 'Posição do extrato de custódia', 'bruto');
                } catch (InvalidArgumentException $e) {
                    if (strpos($e->getMessage(), 'já é o saldo') === false) {
                        throw $e;
                    }
                }
            }
            $res['feitos']++;
        } catch (Throwable $e) {
            $res['erros'][] = $p['nome'] . ': ' . $e->getMessage();
        }
    }
    return $res;
}
