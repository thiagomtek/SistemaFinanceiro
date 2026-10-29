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
