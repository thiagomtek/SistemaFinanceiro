<?php
declare(strict_types=1);

/* =========================================================================
   InflacaoService: inflacao real e percebida como base do risco (RN-45, RN-46).
   - Inflacao real: IPCA e INPC mensais, IPCA em 12 meses e dolar (Banco Central), expectativas do Focus (BCB/Olinda) e o IPCA por
     grupo e por item (IBGE/SIDRA, tabela 7060). Tudo vai para a tabela global `indices` (serie 'I<codigo>' para o IBGE e
     'F<indicador><ano>' para o Focus), sem dado pessoal.
   - Juro real = (1 + juro nominal) / (1 + inflacao) - 1, em % a.a.
   - Inflacao percebida: itens que sobem acima do IPCA (carro, aluguel, plano de saude...), e a inflacao do bolso do usuario:
     reajustes dos proprios gastos recorrentes e o IPCA da cesta de categorias dele.
   ========================================================================= */

const IDX_IPCA_MES = '433';
const IDX_IPCA_12M = '13522';
const IDX_INPC_MES = '188';
const IDX_DOLAR = '1';
const INFLACAO_INTERVALO_S = 6 * 3600; // nao busca de novo antes disso, a menos que seja forcado
const IBGE_IPCA_GERAL = 7169;

/** Itens do IPCA (codigo SIDRA => [rotulo, tipo]); 'grupo' ou 'item'. */
const IBGE_IPCA_ITENS = [
    7169 => ['IPCA geral', 'geral'],
    7170 => ['Alimentação e bebidas', 'grupo'], 7445 => ['Habitação', 'grupo'], 7486 => ['Artigos de residência', 'grupo'],
    7558 => ['Vestuário', 'grupo'], 7625 => ['Transportes', 'grupo'], 7660 => ['Saúde e cuidados pessoais', 'grupo'],
    7712 => ['Despesas pessoais', 'grupo'], 7766 => ['Educação', 'grupo'], 7786 => ['Comunicação', 'grupo'],
    7641 => ['Automóvel novo', 'item'], 107654 => ['Automóvel usado', 'item'], 5102011 => ['Conserto de automóvel', 'item'],
    7656 => ['Combustíveis (veículos)', 'item'], 7643 => ['Seguro de veículo', 'item'], 7634 => ['Passagem aérea', 'item'],
    7448 => ['Aluguel residencial', 'item'], 7449 => ['Condomínio', 'item'], 7451 => ['Taxa de água e esgoto', 'item'],
    7485 => ['Energia elétrica residencial', 'item'], 7696 => ['Plano de saúde', 'item'],
];

/** Categorias do usuario (nome sem acento, minusculo) => grupo do IPCA usado como peso da cesta pessoal. */
const INFLACAO_CATEGORIA_GRUPO = [
    'alimentacao' => 7170, 'mercado' => 7170, 'supermercado' => 7170, 'restaurante' => 7170,
    'casa' => 7445, 'moradia' => 7445, 'aluguel' => 7445, 'condominio' => 7445,
    'transporte' => 7625, 'carro' => 7625, 'combustivel' => 7625, 'veiculo' => 7625,
    'saude' => 7660, 'educacao' => 7766, 'vestuario' => 7558, 'roupas' => 7558,
    'lazer' => 7712, 'eletronicos' => 7486, 'comunicacao' => 7786, 'internet' => 7786,
];

/** GET HTTP simples. Em teste, $GLOBALS['FIN_HTTP_MOCK'] (callable(string $url): ?string) substitui a rede. */
function cenario_http_get(string $url, int $timeout = 25): string
{
    if (isset($GLOBALS['FIN_HTTP_MOCK']) && is_callable($GLOBALS['FIN_HTTP_MOCK'])) {
        $r = ($GLOBALS['FIN_HTTP_MOCK'])($url);
        if ($r === null) {
            throw new RuntimeException('Sem resposta de ' . (parse_url($url, PHP_URL_HOST) ?: 'servidor') . '.');
        }
        return (string) $r;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3, CURLOPT_USERAGENT => 'SistemaFinanceiro/1.0 (uso pessoal)', CURLOPT_HTTPHEADER => ['Accept: application/json, application/xml, text/xml']]);
    $resp = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($resp === false || $http !== 200) {
        throw new RuntimeException((parse_url($url, PHP_URL_HOST) ?: 'Servidor') . ' indisponível (HTTP ' . $http . ').');
    }
    return (string) $resp;
}

// ------------------------------------------------------------------ atualizacao

/** IPCA/INPC/dolar do Banco Central. @return int linhas gravadas */
function inflacao_atualizar_bcb(): int
{
    $n = 0;
    $hoje = date('Y-m-d');
    foreach ([IDX_IPCA_MES => '-26 month', IDX_IPCA_12M => '-26 month', IDX_INPC_MES => '-26 month', IDX_DOLAR => '-400 day'] as $serie => $janela) {
        $serie = (string) $serie; // chave numerica de array vira int
        $n += indices_gravar($serie, bcb_buscar($serie, date('Y-m-d', strtotime($janela)), $hoje));
    }
    return $n;
}

/** IPCA geral, grupos e itens (variacao em 12 meses) do IBGE. @return int linhas gravadas */
function inflacao_atualizar_ibge(): int
{
    $url = 'https://apisidra.ibge.gov.br/values/t/7060/n1/all/v/2265/p/last%206/c315/' . implode(',', array_keys(IBGE_IPCA_ITENS));
    $json = json_decode(cenario_http_get($url, 40), true);
    if (!is_array($json) || count($json) < 2) {
        throw new RuntimeException('Resposta inesperada do IBGE.');
    }
    $porSerie = [];
    foreach (array_slice($json, 1) as $r) { // D3C = periodo (AAAAMM), D4C = categoria do IPCA, V = variacao em 12 meses (%)
        $per = (string) ($r['D3C'] ?? '');
        $cod = (int) ($r['D4C'] ?? 0);
        $v = $r['V'] ?? null;
        if (strlen($per) !== 6 || !isset(IBGE_IPCA_ITENS[$cod]) || !is_numeric($v)) {
            continue;
        }
        $porSerie['I' . $cod][] = [substr($per, 0, 4) . '-' . substr($per, 4, 2) . '-01', (float) $v];
    }
    $n = 0;
    foreach ($porSerie as $serie => $linhas) {
        $n += indices_gravar($serie, $linhas);
    }
    if ($n === 0) {
        throw new RuntimeException('O IBGE não devolveu valores do IPCA.');
    }
    return $n;
}

/** Expectativas do boletim Focus (mediana anual) do IPCA, da Selic e do cambio. @return int linhas gravadas */
function inflacao_atualizar_focus(): int
{
    $n = 0;
    $desde = date('Y-m-d', strtotime('-20 day'));
    foreach (['IPCA' => 'IPCA', 'Selic' => 'SELI', 'Câmbio' => 'CAMB'] as $ind => $cod) {
        $url = 'https://olinda.bcb.gov.br/olinda/servico/Expectativas/versao/v1/odata/ExpectativasMercadoAnuais?$top=200&$orderby=Data%20desc&$format=json'
            . '&$select=Indicador,Data,DataReferencia,Mediana&$filter=' . rawurlencode("Indicador eq '" . $ind . "' and baseCalculo eq 0 and Data ge '" . $desde . "'");
        $json = json_decode(cenario_http_get($url, 40), true);
        $porSerie = [];
        foreach (($json['value'] ?? []) as $r) {
            $ano = (int) ($r['DataReferencia'] ?? 0);
            if ($ano < (int) date('Y') || $ano > (int) date('Y') + 3 || !isset($r['Mediana']) || !is_numeric($r['Mediana']) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($r['Data'] ?? ''))) {
                continue;
            }
            $porSerie['F' . $cod . substr((string) $ano, 2)][] = [$r['Data'], (float) $r['Mediana']];
        }
        foreach ($porSerie as $serie => $linhas) {
            $n += indices_gravar($serie, $linhas);
        }
    }
    return $n;
}

/**
 * Atualiza as fontes de inflacao, cada uma isolada (uma fora do ar nao impede as outras).
 * @return array{atualizado: bool, fontes: array<string, string>, erros: string[]}
 */
function inflacao_atualizar(bool $forcar = false): array
{
    $ult = parametro_get('infl_atualizado_em');
    if (!$forcar && $ult && time() - strtotime($ult) < INFLACAO_INTERVALO_S) {
        return ['atualizado' => false, 'fontes' => [], 'erros' => []];
    }
    $fontes = [];
    $erros = [];
    $cdiSelic = function () use ($forcar) { // CDI e Selic alimentam o juro real: a rotina diaria nao pode depender de alguem abrir uma tela
        $r = indices_atualizar($forcar);
        if (!$r['atualizado'] && strpos($r['mensagem'], 'Não foi possível') === 0) {
            throw new RuntimeException($r['mensagem']);
        }
        return $r['mensagem'];
    };
    foreach (['Banco Central (CDI e Selic)' => $cdiSelic, 'Banco Central (IPCA, INPC, dólar)' => 'inflacao_atualizar_bcb', 'IBGE (IPCA por grupo e item)' => 'inflacao_atualizar_ibge', 'Focus (expectativas)' => 'inflacao_atualizar_focus'] as $nome => $fn) {
        try {
            $v = $fn();
            $fontes[$nome] = is_int($v) ? $v . ' valores' : (string) $v;
        } catch (Throwable $t) {
            $erros[] = $nome . ': ' . $t->getMessage();
        }
    }
    if ($fontes) {
        parametro_set('infl_atualizado_em', date('Y-m-d H:i:s'));
    }
    return ['atualizado' => (bool) $fontes, 'fontes' => $fontes, 'erros' => $erros];
}

// -------------------------------------------------------------------- leituras

/** @return array{data: string, valor: float}|null */
function inflacao_ultimo(string $serie): ?array
{
    $u = indices_ultimo($serie);
    return $u ? ['data' => (string) $u['data'], 'valor' => (float) $u['valor']] : null;
}

function focus_valor(string $codigo, int $ano): ?float
{
    $u = indices_ultimo('F' . $codigo . substr((string) $ano, 2));
    return $u ? (float) $u['valor'] : null;
}

/** Variacao do dolar (%) entre o ultimo valor e o de $dias dias antes (o mais proximo anterior, ate 10 dias de folga). */
function dolar_variacao(int $dias = 365): ?float
{
    $u = inflacao_ultimo(IDX_DOLAR);
    if (!$u) {
        return null;
    }
    $alvo = date('Y-m-d', strtotime($u['data'] . ' -' . $dias . ' day'));
    $a = q_one('SELECT data, valor FROM indices WHERE serie = ? AND data <= ? AND data >= ? ORDER BY data DESC LIMIT 1', [IDX_DOLAR, $alvo, date('Y-m-d', strtotime($alvo . ' -10 day'))]);
    return $a && (float) $a['valor'] > 0 ? round(($u['valor'] / (float) $a['valor'] - 1) * 100, 2) : null;
}

/** Juro real (% a.a.) de um juro nominal e uma inflacao (% a.a.). */
function juro_real(float $nominalAnual, float $inflacaoAnual): float
{
    return round(((1 + $nominalAnual / 100) / (1 + $inflacaoAnual / 100) - 1) * 100, 2);
}

/** IPCA esperado para os proximos 12 meses: media ponderada do Focus do ano corrente e do seguinte (peso = meses que faltam no ano). */
function ipca_esperado_12m(?string $hoje = null): ?float
{
    $t = strtotime($hoje ?: date('Y-m-d'));
    $a0 = focus_valor('IPCA', (int) date('Y', $t));
    $a1 = focus_valor('IPCA', (int) date('Y', $t) + 1);
    if ($a0 === null || $a1 === null) {
        return $a0 ?? $a1;
    }
    $w = (13 - (int) date('n', $t)) / 12;
    return round($w * $a0 + (1 - $w) * $a1, 2);
}

/** Painel de indicadores (so numeros publicos). */
function inflacao_resumo(): array
{
    $ano = (int) date('Y');
    $cdi = cdi_anual_atual();
    $ipca12 = inflacao_ultimo(IDX_IPCA_12M);
    $esp = ipca_esperado_12m();
    $dolar = inflacao_ultimo(IDX_DOLAR);
    return [
        'ipca_mes' => inflacao_ultimo(IDX_IPCA_MES), 'ipca_12m' => $ipca12, 'inpc_mes' => inflacao_ultimo(IDX_INPC_MES),
        'ipca_esperado_12m' => $esp,
        'focus' => [
            'ipca_ano' => focus_valor('IPCA', $ano), 'ipca_ano_seguinte' => focus_valor('IPCA', $ano + 1),
            'selic_ano' => focus_valor('SELI', $ano), 'selic_ano_seguinte' => focus_valor('SELI', $ano + 1),
            'cambio_ano' => focus_valor('CAMB', $ano), 'cambio_ano_seguinte' => focus_valor('CAMB', $ano + 1),
        ],
        'dolar' => $dolar, 'dolar_var_12m' => dolar_variacao(365), 'cdi' => $cdi, 'selic' => selic_atual(),
        'juro_real_12m' => $ipca12 ? juro_real($cdi, $ipca12['valor']) : null,
        'juro_real_esperado' => $esp !== null ? juro_real($cdi, $esp) : null,
        'atualizado_em' => parametro_get('infl_atualizado_em'),
    ];
}

// ------------------------------------------------------------ inflacao percebida

/**
 * IPCA em 12 meses por grupo e item, do mais acima do IPCA geral para o mais abaixo.
 * @return array{geral: ?array, mes: ?string, itens: list<array>}
 */
function inflacao_itens(): array
{
    $geral = inflacao_ultimo('I' . IBGE_IPCA_GERAL);
    $itens = [];
    foreach (IBGE_IPCA_ITENS as $cod => [$rotulo, $tipo]) {
        if ($tipo === 'geral') {
            continue;
        }
        $u = inflacao_ultimo('I' . $cod);
        if (!$u || !$geral || $u['data'] !== $geral['data']) {
            continue;
        }
        $itens[] = ['codigo' => $cod, 'rotulo' => $rotulo, 'tipo' => $tipo, 'var_12m' => $u['valor'],
            'spread' => round($u['valor'] - $geral['valor'], 2), 'acima' => $u['valor'] - $geral['valor'] > 0.5];
    }
    usort($itens, function ($a, $b) { return $b['spread'] <=> $a['spread']; });
    return ['geral' => $geral, 'mes' => $geral['data'] ?? null, 'itens' => $itens];
}

/**
 * Reajuste dos gastos recorrentes (despesas fixas e de mesma serie) do usuario: valor da primeira e da ultima ocorrencia ja
 * ocorrida. A variacao em % ao ano so e calculada com pelo menos 6 meses entre as duas.
 * @return list<array{descricao: string, desde: string, valor_inicial: float, valor_atual: float, var_pct: float, meses: int, anualizada: ?float}>
 */
function inflacao_pessoal_recorrentes(?string $hoje = null): array
{
    $hoje = $hoje ?: date('Y-m-d');
    $ini = date('Y-m-d', strtotime($hoje . ' -24 month'));
    $porGrupo = [];
    foreach (q_all(
        "SELECT grupo_id, descricao, valor, data FROM lancamentos
         WHERE perfil_id = ? AND tipo = 'despesa' AND grupo_id IS NOT NULL AND parcela_num IS NULL AND recorrencia IS NOT NULL
           AND data >= ? AND data <= ? AND (ajuste IS NULL) AND cartao_id IS NULL ORDER BY data",
        [perfil_id(), $ini, $hoje]
    ) as $r) {
        $porGrupo[$r['grupo_id']][] = $r;
    }
    $out = [];
    foreach ($porGrupo as $linhas) {
        if (count($linhas) < 2) {
            continue;
        }
        $a = $linhas[0];
        $b = $linhas[count($linhas) - 1];
        if ((float) $a['valor'] <= 0 || abs((float) $b['valor'] - (float) $a['valor']) < 0.005) {
            continue;
        }
        $meses = (int) round((strtotime($b['data']) - strtotime($a['data'])) / (86400 * 30.44));
        $var = ((float) $b['valor'] / (float) $a['valor'] - 1) * 100;
        $out[] = ['descricao' => (string) $b['descricao'], 'desde' => (string) $a['data'], 'valor_inicial' => round((float) $a['valor'], 2),
            'valor_atual' => round((float) $b['valor'], 2), 'var_pct' => round($var, 2), 'meses' => $meses,
            'anualizada' => $meses >= 6 ? round((pow((float) $b['valor'] / (float) $a['valor'], 12 / $meses) - 1) * 100, 2) : null];
    }
    usort($out, function ($a, $b) { return $b['var_pct'] <=> $a['var_pct']; });
    return $out;
}

/** Nome de categoria sem acento e minusculo, para casar com INFLACAO_CATEGORIA_GRUPO. */
function inflacao_normalizar(string $nome): string
{
    $s = mb_strtolower(trim($nome), 'UTF-8');
    return strtr($s, ['á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ç' => 'c']);
}

/**
 * IPCA da cesta do usuario: variacao em 12 meses de cada grupo do IPCA ponderada pelo que ele gastou nas categorias ligadas a esse
 * grupo (ultimos 12 meses). Categorias sem ligacao (ex.: "Outros") ficam fora; a cobertura mostra quanto do gasto entrou no calculo.
 * @return array{ipca_cesta: ?float, ipca_geral: ?float, cobertura_pct: float, confiavel: bool, grupos: list<array>, gasto_total: float}
 */
function inflacao_pessoal_cesta(?string $hoje = null): array
{
    $hoje = $hoje ?: date('Y-m-d');
    $ini = date('Y-m-d', strtotime($hoje . ' -12 month'));
    $porGrupo = [];
    $total = 0.0;
    foreach (q_all(
        "SELECT c.nome AS categoria, SUM(l.valor) AS t FROM lancamentos l LEFT JOIN categorias c ON c.id = l.categoria_id
         WHERE l.perfil_id = ? AND l.tipo = 'despesa' AND l.data > ? AND l.data <= ? AND l.fatura_id IS NULL AND l.ajuste IS NULL GROUP BY c.nome",
        [perfil_id(), $ini, $hoje]
    ) as $r) {
        $total += (float) $r['t'];
        $g = INFLACAO_CATEGORIA_GRUPO[inflacao_normalizar((string) $r['categoria'])] ?? null;
        if ($g !== null) {
            $porGrupo[$g] = ($porGrupo[$g] ?? 0.0) + (float) $r['t'];
        }
    }
    $geral = inflacao_ultimo('I' . IBGE_IPCA_GERAL);
    $mapeado = array_sum($porGrupo);
    $grupos = [];
    $soma = 0.0;
    $peso = 0.0;
    foreach ($porGrupo as $g => $valor) {
        $u = inflacao_ultimo('I' . $g);
        if (!$u) {
            continue;
        }
        $soma += $valor * $u['valor'];
        $peso += $valor;
        $grupos[] = ['codigo' => $g, 'rotulo' => IBGE_IPCA_ITENS[$g][0] ?? (string) $g, 'gasto' => round($valor, 2), 'var_12m' => $u['valor']];
    }
    usort($grupos, function ($a, $b) { return $b['gasto'] <=> $a['gasto']; });
    $cobertura = $total > 0 ? round($mapeado / $total * 100, 1) : 0.0;
    return ['ipca_cesta' => $peso > 0 ? round($soma / $peso, 2) : null, 'ipca_geral' => $geral['valor'] ?? null, 'cobertura_pct' => $cobertura,
        'confiavel' => $cobertura >= 50.0, 'grupos' => $grupos, 'gasto_total' => round($total, 2)];
}

/** Tudo da inflacao percebida para a tela. */
function inflacao_percebida(): array
{
    return ['itens' => inflacao_itens(), 'recorrentes' => inflacao_pessoal_recorrentes(), 'cesta' => inflacao_pessoal_cesta()];
}
