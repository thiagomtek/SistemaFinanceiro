<?php
declare(strict_types=1);

/* =========================================================================
   CenarioService: cenario economico diario e mensal para o risco do patrimonio (RN-48).
   - Manchetes: feeds RSS publicos (Google Noticias) por tema, coletados no servidor. So titulo, fonte, data e link.
   - Leitura: o Gemini recebe SO as manchetes numeradas e os indicadores publicos do dia (nenhum dado pessoal) e devolve, por tema,
     o tom e um nivel de risco de 1 a 5, citando as manchetes pelo numero: os links vem sempre da coleta, a IA nao cria endereco.
   - Se a IA falhar, o dia fica gravado com as manchetes e os indicadores (origem 'sem_ia') e o risco usa so dados oficiais.
   - Historico: uma linha por dia (cenario_diario) e uma por mes (cenario_mensal, com o que aconteceu e a perspectiva), para comparar
     passado, presente e futuro no mes seguinte.
   ========================================================================= */

/** tema => [rotulo, consultas]. */
const CENARIO_TEMAS = [
    'juros' => ['Juros e Selic', ['Selic Copom juros']],
    'inflacao' => ['Inflação', ['inflação IPCA Brasil']],
    'fiscal' => ['Cenário fiscal', ['risco fiscal arcabouço dívida pública', 'Tesouro Direto juros longos']],
    'politica' => ['Política econômica', ['política econômica governo Congresso reforma']],
    'cambio' => ['Câmbio e dólar', ['dólar câmbio real']],
    'bolsa' => ['Bolsa e ações', ['Ibovespa bolsa de valores']],
    'imoveis' => ['Imóveis e aluguel', ['mercado imobiliário preço imóveis aluguel']],
    'internacional' => ['Cenário internacional', ['Federal Reserve juros Estados Unidos economia global']],
];
const CENARIO_MANCHETES_POR_TEMA = 8;

/** Rotulo do nivel de risco 1..5. */
function risco_nivel_rotulo(?int $n): string
{
    return [1 => 'Baixo', 2 => 'Baixo a moderado', 3 => 'Moderado', 4 => 'Elevado', 5 => 'Alto'][$n ?? 0] ?? 'Sem leitura';
}

// ------------------------------------------------------------------- manchetes

/**
 * Manchetes das ultimas 48 h por tema. Um tema que falhar fica vazio; os outros seguem.
 * @return array<string, list<array{titulo: string, fonte: string, url: string, publicada_em: ?string}>>
 */
function cenario_buscar_manchetes(): array
{
    $out = [];
    foreach (CENARIO_TEMAS as $tema => [$rotulo, $consultas]) {
        $lista = [];
        $vistos = [];
        foreach ($consultas as $q) {
            try {
                $xml = @simplexml_load_string(cenario_http_get('https://news.google.com/rss/search?q=' . rawurlencode($q . ' when:2d') . '&hl=pt-BR&gl=BR&ceid=BR:pt-419', 25));
            } catch (Throwable $t) {
                continue;
            }
            if (!$xml || !isset($xml->channel->item)) {
                continue;
            }
            foreach ($xml->channel->item as $it) {
                $fonte = trim((string) $it->source);
                $titulo = trim((string) $it->title);
                if ($fonte !== '' && substr($titulo, -strlen(' - ' . $fonte)) === ' - ' . $fonte) {
                    $titulo = trim(substr($titulo, 0, -strlen(' - ' . $fonte)));
                }
                $url = trim((string) $it->link);
                $chave = mb_strtolower(mb_substr($titulo, 0, 60));
                if ($titulo === '' || !preg_match('#^https?://#', $url) || isset($vistos[$chave])) {
                    continue;
                }
                $vistos[$chave] = true;
                $ts = strtotime((string) $it->pubDate);
                $lista[] = ['titulo' => mb_substr($titulo, 0, 300), 'fonte' => mb_substr($fonte, 0, 120), 'url' => mb_substr($url, 0, 700),
                    'publicada_em' => $ts ? date('Y-m-d H:i:s', $ts) : null];
            }
        }
        usort($lista, function ($a, $b) { return strcmp((string) $b['publicada_em'], (string) $a['publicada_em']); });
        $out[$tema] = array_slice($lista, 0, CENARIO_MANCHETES_POR_TEMA);
    }
    return $out;
}

// ----------------------------------------------------------------- leitura por IA

/** Indicadores publicos do dia (JSON gravado junto da leitura). */
function cenario_indicadores_dia(): array
{
    $r = inflacao_resumo();
    return [
        'selic' => $r['selic'], 'cdi' => $r['cdi'],
        'ipca_12m' => $r['ipca_12m']['valor'] ?? null, 'ipca_mes' => $r['ipca_mes']['valor'] ?? null, 'ipca_esperado_12m' => $r['ipca_esperado_12m'],
        'focus_selic_ano' => $r['focus']['selic_ano'], 'focus_selic_ano_seguinte' => $r['focus']['selic_ano_seguinte'],
        'focus_ipca_ano' => $r['focus']['ipca_ano'], 'focus_cambio_ano' => $r['focus']['cambio_ano'],
        'dolar' => $r['dolar']['valor'] ?? null, 'juro_real_12m' => $r['juro_real_12m'], 'juro_real_esperado' => $r['juro_real_esperado'],
    ];
}

/**
 * Pede ao Gemini a leitura do dia. Devolve null se a IA falhar (o chamador grava o dia sem a leitura).
 * @param array<string, list<array>> $manchetes
 * @return array{resumo: string, temas: array<string, array>, atencao: list<string>, modelo: string}|null
 */
function cenario_ler_com_ia(array $manchetes, array $indicadores, ?string &$erro = null): ?array
{
    $linhas = [];
    $ref = []; // numero => [tema, indice]
    $n = 0;
    foreach ($manchetes as $tema => $lista) {
        if (!$lista) {
            continue;
        }
        $linhas[] = "\n## " . $tema . ' (' . CENARIO_TEMAS[$tema][0] . ')';
        foreach ($lista as $i => $m) {
            $n++;
            $ref[$n] = [$tema, $i];
            $linhas[] = '[' . $n . '] ' . $m['titulo'] . ' (' . ($m['fonte'] ?: 'fonte n/d') . ')';
        }
    }
    if (!$ref) {
        return null;
    }
    $temas = implode('|', array_keys(CENARIO_TEMAS));
    $prompt = "Você é um analista de risco para um investidor pessoa física brasileiro, com carteira de renda fixa. Leia as manchetes de hoje (numeradas) e os indicadores oficiais "
        . "e escreva uma leitura objetiva do cenário econômico, SEM inventar fatos que não estejam nas manchetes ou nos indicadores e sem recomendar compra ou venda de ativos.\n"
        . "Indicadores oficiais de hoje (JSON): " . json_encode($indicadores, JSON_UNESCAPED_UNICODE) . "\n"
        . "Manchetes por tema:" . implode("\n", $linhas) . "\n\n"
        . "Regras: (1) 'resumo' tem 3 a 5 frases em português do Brasil. (2) Um item em 'temas' para cada tema que tenha manchetes, com 'tema' em {" . $temas . "}, "
        . "'tom' = positivo, neutro ou negativo para quem tem investimentos em CDI, 'nivel_risco' de 1 (baixo) a 5 (alto) e 'resumo' de uma a duas frases. "
        . "(3) 'destaques' lista os NÚMEROS das 1 a 3 manchetes mais relevantes de cada tema. (4) 'atencao' lista até 4 pontos curtos para acompanhar nos próximos dias "
        . "(ex.: decisão do Copom, divulgação do IPCA). Se as manchetes divergirem, diga que há divergência.";
    $esquema = [
        'type' => 'OBJECT',
        'properties' => [
            'resumo' => ['type' => 'STRING'],
            'temas' => ['type' => 'ARRAY', 'items' => ['type' => 'OBJECT', 'properties' => [
                'tema' => ['type' => 'STRING'], 'tom' => ['type' => 'STRING'], 'nivel_risco' => ['type' => 'INTEGER'], 'resumo' => ['type' => 'STRING'],
                'destaques' => ['type' => 'ARRAY', 'items' => ['type' => 'INTEGER']],
            ], 'required' => ['tema', 'tom', 'nivel_risco', 'resumo']]],
            'atencao' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
        ],
        'required' => ['resumo', 'temas'],
    ];
    try {
        $r = ia_gemini_json([['text' => $prompt]], $esquema, 'resumo', 'Não foi possível consultar a IA');
    } catch (Throwable $t) {
        $erro = $t->getMessage();
        return null;
    }
    $res = ['resumo' => mb_substr(trim((string) $r['resumo']), 0, 1500), 'temas' => [], 'atencao' => [], 'modelo' => (string) ($r['_modelo'] ?? '')];
    foreach ((array) ($r['temas'] ?? []) as $t) {
        $tema = (string) ($t['tema'] ?? '');
        if (!isset(CENARIO_TEMAS[$tema]) || isset($res['temas'][$tema])) {
            continue;
        }
        $tom = in_array($t['tom'] ?? '', ['positivo', 'neutro', 'negativo'], true) ? $t['tom'] : 'neutro';
        $dest = [];
        foreach ((array) ($t['destaques'] ?? []) as $num) {
            if (isset($ref[(int) $num]) && $ref[(int) $num][0] === $tema) {
                $dest[] = $ref[(int) $num][1];
            }
        }
        $res['temas'][$tema] = ['tom' => $tom, 'nivel_risco' => max(1, min(5, (int) ($t['nivel_risco'] ?? 3))),
            'resumo' => mb_substr(trim((string) ($t['resumo'] ?? '')), 0, 500), 'destaques' => array_values(array_unique($dest))];
    }
    foreach (array_slice((array) ($r['atencao'] ?? []), 0, 4) as $a) {
        if (is_string($a) && trim($a) !== '') {
            $res['atencao'][] = mb_substr(trim($a), 0, 200);
        }
    }
    if (!$res['temas']) {
        $erro = 'A IA devolveu a leitura sem nenhum tema válido.';
        return null;
    }
    return $res;
}

/**
 * Coleta as manchetes, pede a leitura e grava o dia (substitui o que ja existir na data).
 * @return array{data: string, origem: string, manchetes: int, temas: int, erro_ia: ?string}
 */
function cenario_gerar_dia(?string $data = null): array
{
    $data = $data ?: date('Y-m-d');
    $manchetes = cenario_buscar_manchetes();
    $total = array_sum(array_map('count', $manchetes));
    if ($total === 0) {
        throw new RuntimeException('Nenhuma manchete foi coletada (sem acesso aos feeds de notícias).');
    }
    $ind = cenario_indicadores_dia();
    $erroIa = null;
    $ia = cenario_ler_com_ia($manchetes, $ind, $erroIa);
    // uma leitura por IA ja gravada hoje nunca e trocada por uma sem IA (a IA pode falhar ao refazer)
    if (!$ia && q_val("SELECT data FROM cenario_diario WHERE data = ? AND origem = 'ia'", [$data])) {
        return ['data' => $data, 'origem' => 'mantida', 'manchetes' => $total, 'temas' => 0, 'erro_ia' => $erroIa];
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        q('DELETE FROM cenario_noticias WHERE data = ?', [$data]);
        $idsPorTema = [];
        foreach ($manchetes as $tema => $lista) {
            foreach ($lista as $i => $m) {
                $dest = $ia && in_array($i, $ia['temas'][$tema]['destaques'] ?? [], true) ? 1 : 0;
                $id = q_insert('INSERT INTO cenario_noticias (data, tema, titulo, fonte, url, url_hash, publicada_em, destaque) VALUES (?,?,?,?,?,?,?,?)',
                    [$data, $tema, $m['titulo'], $m['fonte'] ?: null, $m['url'], sha1($m['url']), $m['publicada_em'], $dest]);
                $idsPorTema[$tema][$i] = $id;
            }
        }
        $temasJson = [];
        foreach (CENARIO_TEMAS as $tema => [$rotulo]) {
            if (empty($manchetes[$tema])) {
                continue;
            }
            $t = $ia['temas'][$tema] ?? null;
            $temasJson[$tema] = ['rotulo' => $rotulo, 'tom' => $t['tom'] ?? null, 'nivel_risco' => $t['nivel_risco'] ?? null, 'resumo' => $t['resumo'] ?? null,
                'destaques' => array_map(function ($i) use ($idsPorTema, $tema) { return $idsPorTema[$tema][$i] ?? null; }, $t['destaques'] ?? []),
                'manchetes' => count($manchetes[$tema])];
        }
        $resumo = $ia ? $ia['resumo'] : 'A leitura por IA não ficou disponível hoje; as manchetes e os indicadores oficiais foram guardados e o risco usa apenas os dados oficiais.';
        q('REPLACE INTO cenario_diario (data, resumo, temas_json, indicadores_json, origem, modelo) VALUES (?,?,?,?,?,?)',
            [$data, $resumo, json_encode(['temas' => $temasJson, 'atencao' => $ia['atencao'] ?? []], JSON_UNESCAPED_UNICODE), json_encode($ind, JSON_UNESCAPED_UNICODE),
                $ia ? 'ia' : 'sem_ia', $ia['modelo'] ?? null]);
        $pdo->commit();
    } catch (Throwable $t) {
        $pdo->rollBack();
        throw $t;
    }
    return ['data' => $data, 'origem' => $ia ? 'ia' : 'sem_ia', 'manchetes' => $total, 'temas' => count($ia['temas'] ?? []), 'erro_ia' => $erroIa];
}

/** Leitura do dia mais recente ate $ate (padrao hoje), com as manchetes. */
function cenario_dia(?string $ate = null): ?array
{
    $d = q_one('SELECT * FROM cenario_diario WHERE data <= ? ORDER BY data DESC LIMIT 1', [$ate ?: date('Y-m-d')]);
    if (!$d) {
        return null;
    }
    $conteudo = json_decode((string) $d['temas_json'], true) ?: [];
    $noticias = [];
    foreach (q_all('SELECT id, tema, titulo, fonte, url, publicada_em, destaque FROM cenario_noticias WHERE data = ? ORDER BY destaque DESC, publicada_em DESC', [$d['data']]) as $n) {
        $noticias[$n['tema']][] = ['id' => (int) $n['id'], 'titulo' => $n['titulo'], 'fonte' => $n['fonte'], 'url' => $n['url'], 'publicada_em' => $n['publicada_em'], 'destaque' => (int) $n['destaque'] === 1];
    }
    return ['data' => $d['data'], 'resumo' => $d['resumo'], 'origem' => $d['origem'], 'modelo' => $d['modelo'], 'temas' => $conteudo['temas'] ?? [], 'atencao' => $conteudo['atencao'] ?? [],
        'indicadores' => json_decode((string) $d['indicadores_json'], true) ?: [], 'noticias' => $noticias];
}

// ----------------------------------------------------------------------- mensal

/**
 * Consolida um mes fechado: o que aconteceu (passado/presente) e a perspectiva para os proximos meses (futuro), a partir das leituras
 * diarias do mes e do que se esperava no mes anterior. Devolve null quando nao ha leitura diaria no mes.
 * @return array{mes: string, origem: string}|null
 */
function cenario_gerar_mensal(string $mes, bool $forcar = false): ?array
{
    if (!$forcar && q_val('SELECT mes FROM cenario_mensal WHERE mes = ?', [$mes])) {
        return null;
    }
    $dias = q_all('SELECT data, resumo, indicadores_json, origem FROM cenario_diario WHERE data >= ? AND data < ? ORDER BY data',
        [$mes . '-01', substr(add_meses($mes . '-01', 1), 0, 10)]);
    if (!$dias) {
        return null;
    }
    $primeiro = json_decode((string) $dias[0]['indicadores_json'], true) ?: [];
    $ultimo = json_decode((string) $dias[count($dias) - 1]['indicadores_json'], true) ?: [];
    $variacao = [];
    foreach (['selic' => 'Selic', 'cdi' => 'CDI', 'ipca_12m' => 'IPCA em 12 meses', 'dolar' => 'Dólar', 'juro_real_12m' => 'Juro real'] as $k => $rot) {
        if (isset($primeiro[$k], $ultimo[$k])) {
            $variacao[] = $rot . ': ' . number_format((float) $primeiro[$k], 2, ',', '.') . ' → ' . number_format((float) $ultimo[$k], 2, ',', '.');
        }
    }
    $anterior = q_one('SELECT perspectiva FROM cenario_mensal WHERE mes = ?', [substr(add_meses($mes . '-01', -1), 0, 7)]);
    $leituras = [];
    foreach (array_slice($dias, -12) as $d) { // ate 12 leituras do mes, as mais recentes
        $leituras[] = substr((string) $d['data'], 8, 2) . '/' . substr((string) $d['data'], 5, 2) . ': ' . mb_substr((string) $d['resumo'], 0, 600);
    }
    $ia = null;
    $comIa = array_filter($dias, function ($d) { return $d['origem'] === 'ia'; });
    if ($comIa) {
        $prompt = "Você é um analista de risco para um investidor pessoa física brasileiro, com carteira de renda fixa. Com base APENAS nas leituras diárias abaixo (já resumidas) e nos "
            . "indicadores, escreva o fechamento de " . mes_label($mes) . ". Não invente fatos e não recomende compra ou venda de ativos.\n"
            . "Indicadores no início e no fim do mês: " . implode('; ', $variacao) . "\n"
            . ($anterior ? "O que se esperava no mês anterior (perspectiva registrada): " . $anterior['perspectiva'] . "\n" : '')
            . "Leituras diárias:\n" . implode("\n", $leituras) . "\n\n"
            . "Responda em português do Brasil: 'resumo' (4 a 6 frases: o que aconteceu no mês e se confirmou ou não o que se esperava) e 'perspectiva' (3 a 5 frases: o que se espera para os próximos meses e o que acompanhar).";
        try {
            $ia = ia_gemini_json([['text' => $prompt]], ['type' => 'OBJECT', 'properties' => ['resumo' => ['type' => 'STRING'], 'perspectiva' => ['type' => 'STRING']], 'required' => ['resumo', 'perspectiva']],
                'resumo', 'Não foi possível consultar a IA');
        } catch (Throwable $t) {
            $ia = null;
        }
    }
    $resumo = $ia ? mb_substr(trim((string) $ia['resumo']), 0, 2000)
        : 'Fechamento de ' . mes_label($mes) . ' sem leitura por IA (' . count($dias) . ' dia(s) registrado(s)). ' . ($variacao ? 'Indicadores: ' . implode('; ', $variacao) . '.' : '');
    $persp = $ia ? mb_substr(trim((string) $ia['perspectiva']), 0, 2000) : 'Sem perspectiva por IA neste mês.';
    q('REPLACE INTO cenario_mensal (mes, resumo, perspectiva, indicadores_json, origem, modelo) VALUES (?,?,?,?,?,?)',
        [$mes, $resumo, $persp, json_encode(['inicio' => $primeiro, 'fim' => $ultimo, 'dias' => count($dias)], JSON_UNESCAPED_UNICODE), $ia ? 'ia' : 'sem_ia', $ia['_modelo'] ?? null]);
    return ['mes' => $mes, 'origem' => $ia ? 'ia' : 'sem_ia'];
}

/** Meses fechados (anteriores ao atual) com leitura diaria e ainda sem fechamento mensal. @return string[] */
function cenario_meses_pendentes(?string $hoje = null): array
{
    $atual = substr($hoje ?: date('Y-m-d'), 0, 7);
    $out = [];
    foreach (q_all('SELECT DISTINCT ' . sql_mes('data') . ' AS ym FROM cenario_diario ORDER BY 1') as $r) {
        if ($r['ym'] < $atual && !q_val('SELECT mes FROM cenario_mensal WHERE mes = ?', [$r['ym']])) {
            $out[] = $r['ym'];
        }
    }
    return $out;
}

/** Fechamentos mensais, do mais recente para o mais antigo. */
function cenario_mensais(int $n = 6): array
{
    $out = [];
    foreach (q_all('SELECT * FROM cenario_mensal ORDER BY mes DESC LIMIT ' . max(1, min(36, $n))) as $r) {
        $out[] = ['mes' => $r['mes'], 'label' => mes_label($r['mes']), 'resumo' => $r['resumo'], 'perspectiva' => $r['perspectiva'], 'origem' => $r['origem'],
            'indicadores' => json_decode((string) $r['indicadores_json'], true) ?: []];
    }
    return $out;
}

// ------------------------------------------------------------------- orquestracao

/**
 * Rotina completa: indicadores oficiais, leitura do dia, fechamentos mensais pendentes e a foto de risco de cada perfil.
 * Cada etapa e isolada: uma que falhar nao impede as outras. @return array<string, mixed>
 */
function cenario_atualizar_tudo(bool $forcar = false, ?string $hoje = null): array
{
    $hoje = $hoje ?: date('Y-m-d');
    $res = ['data' => $hoje, 'erros' => []];
    try {
        $res['inflacao'] = inflacao_atualizar($forcar);
        foreach ($res['inflacao']['erros'] as $e) {
            $res['erros'][] = $e;
        }
    } catch (Throwable $t) {
        $res['erros'][] = 'Indicadores: ' . $t->getMessage();
    }
    try {
        // so deixa de tentar quando o dia ja tem leitura por IA: um dia que ficou so com manchetes (IA fora do ar) e refeito na proxima rodada
        if ($forcar || !q_val("SELECT data FROM cenario_diario WHERE data = ? AND origem = 'ia'", [$hoje])) {
            $res['dia'] = cenario_gerar_dia($hoje);
            if (!empty($res['dia']['erro_ia'])) {
                $res['erros'][] = 'Leitura por IA: ' . $res['dia']['erro_ia'];
            }
        } else {
            $res['dia'] = ['data' => $hoje, 'origem' => 'ja_existia'];
        }
    } catch (Throwable $t) {
        $res['erros'][] = 'Leitura do dia: ' . $t->getMessage();
    }
    try {
        $res['mensal'] = [];
        foreach (cenario_meses_pendentes($hoje) as $mes) {
            $r = cenario_gerar_mensal($mes);
            if ($r) {
                $res['mensal'][] = $r;
            }
        }
    } catch (Throwable $t) {
        $res['erros'][] = 'Fechamento mensal: ' . $t->getMessage();
    }
    try {
        $res['riscos'] = risco_snapshot_todos($hoje);
    } catch (Throwable $t) {
        $res['erros'][] = 'Risco dos perfis: ' . $t->getMessage();
    }
    return $res;
}
