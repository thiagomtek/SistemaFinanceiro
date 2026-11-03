<?php
declare(strict_types=1);

/* =========================================================================
   IndiceService: indices economicos (CDI, Selic) do Banco Central e calendario de dias uteis.

   Series SGS: 12 = CDI diario (% ao dia, base de rendimento dos investimentos "% do CDI"),
               4389 = CDI anualizado base 252 (% a.a., usado nas projecoes),
               432 = Selic meta (% a.a., usada na poupanca).
   Os valores ficam na tabela `indices`; a atualizacao e automatica (preguicosa, no maximo a cada 4 h) ou manual.
   ========================================================================= */

const IDX_CDI_DIA = '12';
const IDX_CDI_ANUAL = '4389';
const IDX_SELIC = '432';
const IDX_INTERVALO_TENTATIVA_S = 4 * 3600;
const IDX_INTERVALO_FALTA_S = 600; // com historico faltando, tenta de novo no maximo a cada 10 min (nunca a cada pagina)
const IDX_DATA_MINIMA = '1995-01-01'; // antes disso a serie do CDI do BCB nao e usada: datas mais antigas nao disparam busca

// ------------------------------------------------------------------ calendario

/** Pascoa (algoritmo de Meeus/Jones/Butcher) como timestamp UTC ao meio-dia. */
function pascoa(int $ano): string
{
    $a = $ano % 19;
    $b = intdiv($ano, 100);
    $c = $ano % 100;
    $d = intdiv($b, 4);
    $e = $b % 4;
    $f = intdiv($b + 8, 25);
    $g = intdiv($b - $f + 1, 3);
    $h = (19 * $a + $b - $d - $g + 15) % 30;
    $i = intdiv($c, 4);
    $k = $c % 4;
    $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
    $m = intdiv($a + 11 * $h + 22 * $l, 451);
    $mes = intdiv($h + $l - 7 * $m + 114, 31);
    $dia = (($h + $l - 7 * $m + 114) % 31) + 1;
    return sprintf('%04d-%02d-%02d', $ano, $mes, $dia);
}

/** Feriados nacionais que fecham o mercado financeiro (inclui carnaval, sexta-feira santa e Corpus Christi). */
function feriados_nacionais(int $ano): array
{
    static $cache = [];
    if (isset($cache[$ano])) {
        return $cache[$ano];
    }
    $p = pascoa($ano);
    $mov = fn(int $dias) => date('Y-m-d', strtotime(($dias >= 0 ? '+' : '') . $dias . ' day', strtotime($p)));
    $lista = ["$ano-01-01", "$ano-04-21", "$ano-05-01", "$ano-09-07", "$ano-10-12", "$ano-11-02", "$ano-11-15", "$ano-12-25",
        $mov(-48), $mov(-47), $mov(-2), $mov(60)];
    if ($ano >= 2024) {
        $lista[] = "$ano-11-20"; // Consciencia Negra, feriado nacional desde 2024
    }
    return $cache[$ano] = array_flip($lista);
}

function eh_dia_util(string $iso): bool
{
    $dow = (int) date('N', strtotime($iso));
    return $dow < 6 && !isset(feriados_nacionais((int) substr($iso, 0, 4))[$iso]);
}

/** Dias uteis no intervalo [$de, $ate) (o dia de inicio conta, o dia final nao). */
function dias_uteis(string $de, string $ate): int
{
    $n = 0;
    for ($d = $de; $d < $ate; $d = add_dias($d, 1)) {
        if (eh_dia_util($d)) {
            $n++;
        }
    }
    return $n;
}

function ultimo_dia_util_ate(string $iso): string
{
    while (!eh_dia_util($iso)) {
        $iso = add_dias($iso, -1);
    }
    return $iso;
}

// -------------------------------------------------------------- Banco Central

/** @return array<int, array{0: string, 1: float}> pares [AAAA-MM-DD, valor] */
function bcb_buscar(string $serie, string $ini, string $fim, bool $rapido = false): array
{
    if (isset($GLOBALS['FIN_BCB_MOCK']) && is_callable($GLOBALS['FIN_BCB_MOCK'])) {
        return ($GLOBALS['FIN_BCB_MOCK'])($serie, $ini, $fim);
    }
    $url = 'https://api.bcb.gov.br/dados/serie/bcdata.sgs.' . $serie . '/dados?formato=json&dataInicial=' . date('d/m/Y', strtotime($ini)) . '&dataFinal=' . date('d/m/Y', strtotime($fim));
    $json = null;
    $http = 0;
    // $rapido: atualizacao automatica dentro de uma pagina; uma tentativa curta, para a tela nao ficar presa se o BCB estiver fora do ar
    $tentativas = $rapido ? 1 : 3;
    for ($t = 0; $t < $tentativas && !is_array($json); $t++) {
        if ($t > 0) {
            sleep(1);
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $rapido ? 6 : 25, CURLOPT_CONNECTTIMEOUT => $rapido ? 3 : 8, CURLOPT_HTTPHEADER => ['Accept: application/json']]);
        $resp = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $json = $resp !== false && $http === 200 ? json_decode((string) $resp, true) : null;
    }
    if (!is_array($json)) {
        throw new RuntimeException('Banco Central indisponível (série ' . $serie . ', HTTP ' . $http . ').');
    }
    $out = [];
    foreach ($json as $r) {
        $d = parse_data($r['data'] ?? '');
        if ($d && isset($r['valor']) && is_numeric($r['valor'])) {
            $out[] = [$d, (float) $r['valor']];
        }
    }
    return $out;
}

function indices_gravar(string $serie, array $linhas): int
{
    $n = 0;
    $pdo = db();
    $pdo->beginTransaction();
    try {
        foreach ($linhas as [$data, $valor]) {
            q('REPLACE INTO indices (serie, data, valor) VALUES (?,?,?)', [$serie, $data, $valor]);
            $n++;
        }
        $pdo->commit();
    } catch (Throwable $t) {
        $pdo->rollBack();
        throw $t;
    }
    return $n;
}

function parametro_get(string $chave): ?string
{
    $v = q_val('SELECT valor FROM parametros WHERE chave = ?', [$chave]);
    return $v === null ? null : (string) $v;
}

function parametro_set(string $chave, string $valor): void
{
    q('REPLACE INTO parametros (chave, valor) VALUES (?,?)', [$chave, $valor]);
}

function indices_ultimo(string $serie): ?array
{
    return q_one('SELECT data, valor FROM indices WHERE serie = ? ORDER BY data DESC LIMIT 1', [$serie]);
}

/** Data mais antiga em que algum investimento indexado ao CDI recebeu aporte (inicio do historico necessario). */
function indices_inicio_necessario(): string
{
    $min = q_val("SELECT MIN(m.data) FROM investimento_movs m JOIN investimentos i ON i.id = m.investimento_id WHERE i.indexador = 'cdi'");
    return max($min ? (string) $min : date('Y-m-d', strtotime('-40 day')), IDX_DATA_MINIMA);
}

/**
 * Atualiza CDI diario, CDI anualizado e Selic a partir do Banco Central.
 * @return array{atualizado: bool, mensagem: string, cdi_anual: ?float, ultima_data: ?string}
 */
function indices_atualizar(bool $forcar = false): array
{
    $agora = time();
    $ultimaTent = (int) (parametro_get('idx_tentativa') ?? 0);
    $inicio = indices_inicio_necessario();
    $minCdi = q_val('SELECT MIN(data) FROM indices WHERE serie = ?', [IDX_CDI_DIA]);
    $maxCdi = q_val('SELECT MAX(data) FROM indices WHERE serie = ?', [IDX_CDI_DIA]);
    $faltaHistorico = !$minCdi || $minCdi > date('Y-m-d', strtotime($inicio . ' +7 day'));
    $esperado = ultimo_dia_util_ate(date('Y-m-d', strtotime('-1 day')));
    $defasado = !$maxCdi || $maxCdi < $esperado;
    if (!$forcar && !$faltaHistorico && !$defasado) {
        return ['atualizado' => false, 'mensagem' => 'Índices em dia.', 'cdi_anual' => cdi_anual_atual(), 'ultima_data' => (string) $maxCdi];
    }
    // mesmo com o historico faltando, respeita o intervalo: antes, uma data antiga demais fazia cada pagina consultar o BCB (ate 3 x 25 s)
    if (!$forcar && ($agora - $ultimaTent) < ($faltaHistorico ? IDX_INTERVALO_FALTA_S : IDX_INTERVALO_TENTATIVA_S)) {
        return ['atualizado' => false, 'mensagem' => 'Última tentativa recente; aguardando para tentar de novo.', 'cdi_anual' => cdi_anual_atual(), 'ultima_data' => (string) $maxCdi];
    }
    parametro_set('idx_tentativa', (string) $agora);
    $hoje = date('Y-m-d');
    $rapido = !$forcar && !$faltaHistorico; // so busca o que faltou desde a ultima data: janela pequena
    try {
        $desde = $faltaHistorico || $forcar && !$maxCdi ? $inicio : date('Y-m-d', strtotime(($maxCdi ?: $inicio) . ' -5 day'));
        $desde = min($desde, $hoje);
        // a API limita janelas de series diarias a 10 anos
        $ini = $desde;
        while ($ini <= $hoje) {
            $fimJanela = min($hoje, date('Y-m-d', strtotime($ini . ' +9 year')));
            indices_gravar(IDX_CDI_DIA, bcb_buscar(IDX_CDI_DIA, $ini, $fimJanela, $rapido));
            $ini = date('Y-m-d', strtotime($fimJanela . ' +1 day'));
        }
        $recente = date('Y-m-d', strtotime('-60 day'));
        indices_gravar(IDX_CDI_ANUAL, bcb_buscar(IDX_CDI_ANUAL, $recente, $hoje, $rapido));
        indices_gravar(IDX_SELIC, bcb_buscar(IDX_SELIC, $recente, $hoje, $rapido));
        parametro_set('idx_atualizado_em', date('Y-m-d H:i:s'));
        $u = indices_ultimo(IDX_CDI_DIA);
        return ['atualizado' => true, 'mensagem' => 'Índices atualizados com o Banco Central.', 'cdi_anual' => cdi_anual_atual(), 'ultima_data' => $u['data'] ?? null];
    } catch (Throwable $e) {
        return ['atualizado' => false, 'mensagem' => 'Não foi possível atualizar agora: ' . $e->getMessage(), 'cdi_anual' => cdi_anual_atual(), 'ultima_data' => (string) $maxCdi];
    }
}

/** Atualizacao automatica discreta: chamada pelas telas que usam CDI. Nunca lanca excecao. */
function indices_garantir(): void
{
    static $feito = false;
    if ($feito) {
        return;
    }
    $feito = true;
    try {
        indices_atualizar(false);
    } catch (Throwable $e) {
        // sem internet ou banco indisponivel: segue com o que ja esta salvo
    }
}

/** Garante o CDI diario no banco desde $desde (para posicoes iniciais antigas). Nunca lanca excecao. */
function indices_garantir_desde(string $desde): void
{
    $desde = max($desde, IDX_DATA_MINIMA);
    $min = q_val('SELECT MIN(data) FROM indices WHERE serie = ?', [IDX_CDI_DIA]);
    if ($min && $min <= date('Y-m-d', strtotime($desde . ' +7 day'))) {
        return;
    }
    if (time() - (int) (parametro_get('idx_desde_tentativa') ?? 0) < IDX_INTERVALO_FALTA_S) {
        return;
    }
    parametro_set('idx_desde_tentativa', (string) time());
    try {
        $hoje = date('Y-m-d');
        $ini = $desde;
        while ($ini <= $hoje) {
            $fim = min($hoje, date('Y-m-d', strtotime($ini . ' +9 year')));
            indices_gravar(IDX_CDI_DIA, bcb_buscar(IDX_CDI_DIA, $ini, $fim));
            $ini = date('Y-m-d', strtotime($fim . ' +1 day'));
        }
    } catch (Throwable $e) {
        // sem internet: segue com o que ja esta salvo
    }
}

// -------------------------------------------------------------------- leituras

/** CDI anualizado (base 252) mais recente, em % a.a. */
function cdi_anual_atual(): float
{
    $u = indices_ultimo(IDX_CDI_ANUAL);
    if ($u) {
        return (float) $u['valor'];
    }
    $d = indices_ultimo(IDX_CDI_DIA);
    return $d ? round((pow(1 + (float) $d['valor'] / 100, 252) - 1) * 100, 2) : 10.0;
}

function selic_atual(): float
{
    $u = indices_ultimo(IDX_SELIC);
    return $u ? (float) $u['valor'] : 10.0;
}

/** Taxa diaria equivalente (em % ao dia util) de um CDI anual (% a.a., base 252). */
function cdi_dia_de_anual(float $anual): float
{
    return (pow(1 + $anual / 100, 1 / 252) - 1) * 100;
}

/** @return array<string,float> data => CDI diario (% ao dia) */
function cdi_diario_tabela(string $ini, string $fim): array
{
    $out = [];
    foreach (q_all('SELECT data, valor FROM indices WHERE serie = ? AND data >= ? AND data <= ?', [IDX_CDI_DIA, $ini, $fim]) as $r) {
        $out[$r['data']] = (float) $r['valor'];
    }
    return $out;
}

function indices_resumo(): array
{
    $d = indices_ultimo(IDX_CDI_DIA);
    return [
        'cdi_anual' => cdi_anual_atual(), 'cdi_dia' => $d ? (float) $d['valor'] : null, 'data' => $d['data'] ?? null,
        'selic' => selic_atual(), 'atualizado_em' => parametro_get('idx_atualizado_em'),
    ];
}
