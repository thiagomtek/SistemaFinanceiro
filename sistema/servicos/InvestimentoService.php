<?php
declare(strict_types=1);

/* InvestimentoService: carteira de investimentos (saldos pelo motor de renda fixa em RendaFixaService). */

const INV_PRODUTOS = [
    'cdb' => ['CDB', 'cdi', 'ir_regressivo'], 'rdb' => ['RDB', 'cdi', 'ir_regressivo'], 'cofrinho' => ['Cofrinho / Caixinha', 'cdi', 'ir_regressivo'],
    'lci' => ['LCI', 'cdi', 'isento'], 'lca' => ['LCA', 'cdi', 'isento'], 'poupanca' => ['Poupança', 'poupanca', 'isento'],
    'tesouro' => ['Tesouro Direto', 'pre', 'ir_regressivo'], 'acoes' => ['Ações', 'manual', 'nenhuma'], 'fii' => ['Fundos imobiliários', 'manual', 'nenhuma'],
    'fundos' => ['Fundos', 'manual', 'nenhuma'], 'cripto' => ['Criptomoedas', 'manual', 'nenhuma'], 'previdencia' => ['Previdência', 'manual', 'nenhuma'],
    'outro' => ['Outro', 'manual', 'nenhuma'],
];

/** Tipo (enum legado da tabela) correspondente ao produto. */
function inv_tipo_legado(string $produto): string
{
    $m = ['cdb' => 'renda_fixa', 'rdb' => 'renda_fixa', 'cofrinho' => 'renda_fixa', 'lci' => 'renda_fixa', 'lca' => 'renda_fixa', 'poupanca' => 'renda_fixa',
        'tesouro' => 'renda_fixa', 'acoes' => 'acoes', 'fii' => 'fii', 'fundos' => 'fundos', 'cripto' => 'cripto', 'previdencia' => 'previdencia'];
    return $m[$produto] ?? 'outro';
}

function inv_rotulo_indexador(array $i): string
{
    switch ($i['indexador']) {
        case 'cdi':
            return rtrim(rtrim(number_format((float) $i['percentual_cdi'], 2, ',', '.'), '0'), ',') . '% do CDI';
        case 'pre':
            return number_format((float) $i['taxa_anual'], 2, ',', '.') . '% a.a. (pré-fixado)';
        case 'poupanca':
            return 'Poupança';
        default:
            return $i['taxa_anual'] > 0 ? 'Manual (' . number_format((float) $i['taxa_anual'], 2, ',', '.') . '% a.a. estimado)' : 'Manual';
    }
}

/**
 * Investimentos do perfil com saldo bruto/liquido calculados ate $ate (padrao hoje).
 * Mantem as chaves aportes, resgates, rendimentos e saldo (= saldo bruto) usadas pelas telas.
 */
function investimentos_com_saldo(?string $ate = null): array
{
    $ate = $ate ?: date('Y-m-d');
    $lista = q_all('SELECT * FROM investimentos WHERE perfil_id = ? ORDER BY ativo DESC, nome', [perfil_id()]);
    if (array_filter($lista, function ($i) { return $i['indexador'] === 'cdi'; })) {
        indices_garantir();
    }
    foreach ($lista as &$i) {
        $s = rf_simular($i, rf_movs((int) $i['id']), $ate);
        $i['aportes'] = $s['aportes'];
        $i['resgates'] = $s['resgates_bruto'];
        $i['saldo'] = $s['saldo_bruto'];
        $i['principal'] = $s['principal'];
        $i['rendimentos'] = $s['saldo_bruto'] - $s['aportes'] + $s['resgates_bruto'];
        $i['ir'] = $s['liquidacao']['ir'];
        $i['iof'] = $s['liquidacao']['iof'];
        $i['liquido'] = $s['liquidacao']['liquido'];
        $i['ir_resgatado'] = $s['ir_resgatado'] + $s['iof_resgatado'];
        $i['lotes'] = $s['liquidacao']['lotes'];
        $i['rotulo'] = inv_rotulo_indexador($i);
    }
    return $lista;
}

/**
 * Rendimento realizado no mes corrente (dia 1 ate hoje) e no ano corrente (1o de janeiro ate hoje), bruto e liquido, somando
 * TODOS os investimentos do perfil (inclusive os inativos: o que rendeu no periodo e rendimento de verdade). Sem projecao.
 * @return array{mes: array{de: string, ate: string, bruto: float, liquido: float}, ano: array{de: string, ate: string, bruto: float, liquido: float}}
 */
function investimentos_rendimento_periodos(?string $hoje = null): array
{
    $hoje = $hoje ?: date('Y-m-d');
    $out = [
        'mes' => ['de' => substr($hoje, 0, 7) . '-01', 'ate' => $hoje, 'bruto' => 0.0, 'liquido' => 0.0],
        'ano' => ['de' => substr($hoje, 0, 4) . '-01-01', 'ate' => $hoje, 'bruto' => 0.0, 'liquido' => 0.0],
    ];
    $lista = q_all('SELECT * FROM investimentos WHERE perfil_id = ?', [perfil_id()]);
    if (array_filter($lista, function ($i) { return $i['indexador'] === 'cdi'; })) {
        indices_garantir();
    }
    foreach ($lista as $i) {
        $movs = rf_movs((int) $i['id']);
        if (!$movs) {
            continue;
        }
        // um unico fator de crescimento por investimento, igual nas quatro simulacoes
        $cfg = ['fator' => rf_fator_fn($i, min($movs[0]['data'], add_dias($out['ano']['de'], -1)), add_dias($hoje, 2))];
        foreach (['mes', 'ano'] as $p) {
            $r = rf_rendimento_periodo($i, $movs, $out[$p]['de'], $hoje, $cfg);
            $out[$p]['bruto'] += $r['bruto'];
            $out[$p]['liquido'] += $r['liquido'];
        }
    }
    return $out;
}

/**
 * Painel de UM investimento (modal da tela Investimentos): KPIs e series para os graficos, tudo realizado ate hoje (sem projecao).
 * Serie mensal: saldo bruto/liquido no fim de cada mes (o mes corrente vai ate hoje), valor liquido aplicado (aportes - resgates)
 * e rendimento bruto/liquido de cada mes (mesma formula de rf_rendimento_periodo). Mostra no maximo os ultimos 36 meses.
 */
function investimento_painel(int $id): array
{
    $inv = rf_investimento($id);
    $hoje = date('Y-m-d');
    $r2 = function (float $v): float { return round($v, 2); };
    $todos = rf_movs($id); // inclui os agendados para depois de hoje: aparecem na lista (e podem ser excluidos), mas nao entram nos numeros
    $movs = array_values(array_filter($todos, function ($m) use ($hoje) { return $m['data'] <= $hoje; }));
    if ($inv['indexador'] === 'cdi') {
        indices_garantir();
    }
    $regras = $inv['tributacao'] === 'ir_regressivo' ? 'IR regressivo (22,5% até 180 dias, 20% até 360, 17,5% até 720, 15% acima) + IOF nos primeiros 30 dias'
        : ($inv['tributacao'] === 'isento' ? 'isento de IR' : 'sem imposto calculado');
    // projecao (unica parte que olha para frente, separada dos numeros realizados): 12 fins de mes, com os movimentos agendados
    $proj = [];
    if ($inv['ativo']) {
        $datas = [];
        for ($k = 1; $k <= 12; $k++) {
            $datas[] = date('Y-m-t', strtotime("+$k month", strtotime(date('Y-m-01'))));
        }
        foreach (rf_serie($inv, $todos, $datas) as $d => $x) {
            $proj[] = ['label' => mes_label(substr($d, 0, 7)), 'bruto' => $r2($x['bruto']), 'impostos' => $r2($x['ir'] + $x['iof']), 'liquido' => $r2($x['liquido'])];
        }
    }
    $base = ['nome' => $inv['nome'], 'rotulo' => inv_rotulo_indexador($inv), 'instituicao' => $inv['instituicao'], 'ativo' => (int) $inv['ativo'],
        'tributacao' => $inv['tributacao'], 'hoje' => $hoje, 'primeira_data' => $movs ? $movs[0]['data'] : null, 'vazio' => !$movs,
        'manual' => $inv['indexador'] === 'manual', 'liquidez' => $inv['liquidez'], 'vencimento' => $inv['data_vencimento'], 'regras' => $regras,
        'cdi_anual' => $proj ? indices_resumo()['cdi_anual'] : null, 'projecao' => $proj,
        'movs' => array_map(function ($m) use ($hoje, $r2) {
            return ['id' => (int) $m['id'], 'data' => $m['data'], 'tipo' => $m['tipo'], 'obs' => (string) $m['obs'], 'valor' => $r2((float) $m['valor']),
                'ir' => $r2((float) $m['ir']), 'iof' => $r2((float) $m['iof']), 'ligado' => !empty($m['lancamento_id']), 'futuro' => $m['data'] > $hoje];
        }, array_reverse($todos))];
    if (!$movs) {
        return $base + ['lotes' => []];
    }
    $cfg = ['fator' => rf_fator_fn($inv, $movs[0]['data'], add_dias($hoje, 2))];
    $s = rf_simular($inv, $movs, $hoje, $cfg);
    $liq = $s['liquidacao'];
    $impostos = $liq['ir'] + $liq['iof'];
    $ehIr = $inv['tributacao'] === 'ir_regressivo';
    $lotes = [];
    foreach ($liq['lotes'] as $l) {
        $iofL = $ehIr ? $l['ganho'] * $l['iof_pct'] / 100 : 0.0;
        $irL = $ehIr ? ($l['ganho'] - $iofL) * $l['aliquota_ir'] / 100 : 0.0;
        $lotes[] = ['data' => $l['data'], 'dias' => (int) $l['dias'], 'principal' => $r2($l['principal']), 'valor' => $r2($l['valor']), 'ganho' => $r2($l['ganho']),
            'iof' => $r2($iofL), 'iof_pct' => $l['iof_pct'], 'ir' => $r2($irL), 'aliquota_ir' => $l['aliquota_ir'], 'liquido' => $r2($l['valor'] - $iofL - $irL)];
    }
    $acum = rf_rendimento_periodo($inv, $movs, $movs[0]['data'], $hoje, $cfg);
    $mes = rf_rendimento_periodo($inv, $movs, substr($hoje, 0, 7) . '-01', $hoje, $cfg);
    $ano = rf_rendimento_periodo($inv, $movs, substr($hoje, 0, 4) . '-01-01', $hoje, $cfg);

    $serie = ['labels' => [], 'bruto' => [], 'liquido' => [], 'aplicado' => [], 'rend_bruto' => [], 'rend_liquido' => []];
    // estado em uma data: saldos e acumulados dos movimentos gravados (o liquido usa o imposto realmente pago nos resgates)
    $estado = function (string $ate) use ($inv, $movs, $cfg): array {
        $sim = rf_simular($inv, $movs, $ate, $cfg);
        $e = ['b' => $sim['saldo_bruto'], 'l' => $sim['liquidacao']['liquido'], 'ap' => 0.0, 'rb' => 0.0, 'rl' => 0.0];
        foreach ($movs as $x) {
            if ($x['data'] > $ate) {
                break;
            }
            if ($x['tipo'] === 'aporte') {
                $e['ap'] += (float) $x['valor'];
            } elseif ($x['tipo'] === 'resgate') {
                $e['rb'] += (float) $x['valor'];
                $e['rl'] += (float) $x['valor'] - (float) $x['ir'] - (float) $x['iof'];
            }
        }
        return $e;
    };
    $meses = [];
    for ($m = substr($movs[0]['data'], 0, 7) . '-01'; $m <= $hoje; $m = date('Y-m-d', strtotime($m . ' +1 month'))) {
        $meses[] = $m;
    }
    $meses = array_slice($meses, -36);
    $ant = $estado(add_dias($meses[0], -1));
    foreach ($meses as $ini) {
        $e = $estado(min(date('Y-m-t', strtotime($ini)), $hoje));
        $serie['labels'][] = mes_label(substr($ini, 0, 7));
        $serie['bruto'][] = $r2($e['b']);
        $serie['liquido'][] = $r2($e['l']);
        $serie['aplicado'][] = $r2($e['ap'] - $e['rb']);
        $serie['rend_bruto'][] = $r2($e['b'] - $ant['b'] - ($e['ap'] - $ant['ap']) + ($e['rb'] - $ant['rb']));
        $serie['rend_liquido'][] = $r2($e['l'] - $ant['l'] - ($e['ap'] - $ant['ap']) + ($e['rl'] - $ant['rl']));
        $ant = $e;
    }
    return $base + [
        'kpis' => [
            'saldo_bruto' => $r2($s['saldo_bruto']), 'saldo_liquido' => $r2($liq['liquido']), 'impostos' => $r2($impostos),
            'aportes' => $r2($acum['aportes']), 'resgates' => $r2($acum['resgates']),
            'acum_bruto' => $r2($acum['bruto']), 'acum_liquido' => $r2($acum['liquido']),
            'mes_bruto' => $r2($mes['bruto']), 'mes_liquido' => $r2($mes['liquido']), 'ano_bruto' => $r2($ano['bruto']), 'ano_liquido' => $r2($ano['liquido']),
        ],
        // composicao do saldo bruto: o que foi aplicado, o rendimento que fica depois de IR/IOF e o imposto estimado
        'composicao' => ['principal' => $r2($s['principal']), 'rendimento_liquido' => $r2($s['saldo_bruto'] - $s['principal'] - $impostos), 'impostos' => $r2($impostos)],
        'serie' => $serie,
        'lotes' => $lotes,
    ];
}

/** Total investido (ativos) bruto e liquido de IR/IOF, numa unica simulacao. @return array{bruto: float, liquido: float} */
function totais_investidos(?array $investimentos = null): array
{
    $t = ['bruto' => 0.0, 'liquido' => 0.0];
    foreach ($investimentos ?? investimentos_com_saldo() as $i) {
        if ($i['ativo']) {
            $t['bruto'] += $i['saldo'];
            $t['liquido'] += $i['liquido'];
        }
    }
    return $t;
}

function total_investido(bool $liquido = false): float
{
    $t = totais_investidos();
    return $liquido ? $t['liquido'] : $t['bruto'];
}

/** Valida e grava o cadastro de um investimento. Retorna o ID. */
function investimento_salvar(array $d, int $id = 0): int
{
    $nome = trim((string) ($d['nome'] ?? ''));
    if ($nome === '') {
        throw new InvalidArgumentException('Informe o nome do investimento.');
    }
    $produto = array_key_exists($d['produto'] ?? '', INV_PRODUTOS) ? $d['produto'] : 'outro';
    $indexador = in_array($d['indexador'] ?? '', ['manual', 'cdi', 'pre', 'poupanca'], true) ? $d['indexador'] : INV_PRODUTOS[$produto][1];
    $trib = in_array($d['tributacao'] ?? '', ['ir_regressivo', 'isento', 'nenhuma'], true) ? $d['tributacao'] : INV_PRODUTOS[$produto][2];
    $pct = max(0.0, min(1000.0, parse_valor($d['percentual_cdi'] ?? 100, false)));
    $taxa = max(0.0, min(999.0, parse_valor($d['taxa_anual'] ?? 0, false)));
    if ($indexador === 'cdi' && $pct <= 0) {
        throw new InvalidArgumentException('Informe o percentual do CDI (ex.: 110).');
    }
    $liq = ($d['liquidez'] ?? 'diaria') === 'vencimento' ? 'vencimento' : 'diaria';
    $venc = parse_data($d['data_vencimento'] ?? '') ?: null;
    $inst = trim((string) ($d['instituicao'] ?? '')) ?: null;
    $tipo = inv_tipo_legado($produto);
    if ($id && pertence_ao_perfil('investimentos', $id)) {
        q('UPDATE investimentos SET nome=?, tipo=?, instituicao=?, taxa_anual=?, indexador=?, percentual_cdi=?, tributacao=?, data_vencimento=?, liquidez=? WHERE id=? AND perfil_id=?',
            [$nome, $tipo, $inst, $taxa, $indexador, $pct, $trib, $venc, $liq, $id, perfil_id()]);
        return $id;
    }
    return q_insert('INSERT INTO investimentos (perfil_id, nome, tipo, instituicao, taxa_anual, indexador, percentual_cdi, tributacao, data_vencimento, liquidez) VALUES (?,?,?,?,?,?,?,?,?,?)',
        [perfil_id(), $nome, $tipo, $inst, $taxa, $indexador, $pct, $trib, $venc, $liq]);
}
