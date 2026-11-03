<?php
declare(strict_types=1);

/* =========================================================================
   AlocacaoService: sugestoes por classe de ativo (RN-49). Apoio a decisao, nao recomendacao.
   - Compara o que o usuario tem em cada classe (pos-fixado, renda fixa de prazo, acoes, imobiliario, exterior, outros) com uma
     faixa de referencia que ele mesmo pode editar (a politica de alocacao dele), e soma fatores do momento (juros, inflacao, Tesouro,
     noticias). Todos os fatores aparecem com o motivo; nenhum produto ou papel e indicado, so a classe.
   - A reserva de emergencia vem antes: com reserva abaixo do alvo, nenhuma sugestao de aumentar risco e feita.
   - Imovel para aluguel: calcula o retorno minimo que o aluguel precisa ter para compensar a renda fixa (nao ha preco de imovel).
   ========================================================================= */

const ALOC_CLASSES = [
    'pos' => ['Pós-fixado e caixa', 'CDB, RDB, cofrinhos, Tesouro Selic e poupança: acompanham o CDI e dão liquidez.'],
    'prazo' => ['Renda fixa de prazo', 'Prefixados e títulos atrelados à inflação (IPCA+): travam uma taxa por anos e oscilam de preço antes do vencimento.'],
    'acoes' => ['Ações', 'Participação em empresas: maior retorno esperado no longo prazo e oscilação alta.'],
    'imobiliario' => ['Imobiliário', 'Fundos imobiliários (e imóveis para aluguel): renda de aluguel e valorização.'],
    'exterior' => ['Exterior e dólar', 'Dólar, ativos no exterior e investimentos atrelados ao dólar.'],
    'outros' => ['Outros (fundos, previdência, cripto)', 'Multimercado, previdência, criptomoedas e o que não cabe nas classes acima.'],
];

/** Faixas de referencia (% do total investido) por tolerancia a risco: [minimo, maximo]. Pontos de partida, editaveis pelo usuario. */
const ALOC_FAIXAS = [
    'conservador' => ['pos' => [50, 80], 'prazo' => [10, 35], 'acoes' => [0, 10], 'imobiliario' => [0, 10], 'exterior' => [0, 10], 'outros' => [0, 5]],
    'moderado' => ['pos' => [25, 50], 'prazo' => [15, 35], 'acoes' => [10, 25], 'imobiliario' => [5, 15], 'exterior' => [10, 20], 'outros' => [0, 10]],
    'arrojado' => ['pos' => [10, 30], 'prazo' => [10, 25], 'acoes' => [20, 40], 'imobiliario' => [5, 20], 'exterior' => [15, 30], 'outros' => [0, 15]],
];
const ALOC_RESERVA_PADRAO = 6; // meses de despesa em liquidez diaria
const ALOC_ALIQUOTA_IR_LONGO = 15.0; // IR da renda fixa depois de 2 anos, para o retorno minimo do aluguel

// ----------------------------------------------------------------------- perfil

/** Perfil de alocacao do usuario (guardado em `parametros`, por perfil). */
function alocacao_perfil(): array
{
    $j = json_decode((string) parametro_get('inv_perfil_' . perfil_id()), true) ?: [];
    $tol = isset(ALOC_FAIXAS[$j['tolerancia'] ?? '']) ? $j['tolerancia'] : 'moderado';
    $faixas = ALOC_FAIXAS[$tol];
    $editadas = [];
    foreach ((array) ($j['faixas'] ?? []) as $k => $f) {
        if (isset($faixas[$k]) && is_array($f) && count($f) === 2) {
            $faixas[$k] = [(float) $f[0], (float) $f[1]];
            $editadas[] = $k;
        }
    }
    $reserva = (int) ($j['reserva_meses'] ?? ALOC_RESERVA_PADRAO);
    return ['tolerancia' => $tol, 'reserva_meses' => max(1, min(36, $reserva)), 'faixas' => $faixas, 'faixas_editadas' => $editadas, 'personalizado' => $j !== []];
}

/**
 * Salva o perfil. $faixas: classe => [min, max] (em %). Valida limites, minimo <= maximo e soma dos minimos <= 100.
 * @throws InvalidArgumentException
 */
function alocacao_perfil_salvar(string $tolerancia, int $reservaMeses, array $faixas): array
{
    if (!isset(ALOC_FAIXAS[$tolerancia])) {
        throw new InvalidArgumentException('Escolha uma tolerância a risco válida.');
    }
    if ($reservaMeses < 1 || $reservaMeses > 36) {
        throw new InvalidArgumentException('A reserva de emergência deve ficar entre 1 e 36 meses de despesas.');
    }
    $padrao = ALOC_FAIXAS[$tolerancia];
    $final = [];
    $somaMin = 0.0;
    foreach (ALOC_CLASSES as $k => [$rotulo]) {
        $f = $faixas[$k] ?? $padrao[$k];
        $min = (float) $f[0];
        $max = (float) $f[1];
        if ($min < 0 || $max > 100 || $min > $max) {
            throw new InvalidArgumentException('Faixa inválida em "' . $rotulo . '": use valores de 0 a 100 e o mínimo não pode passar do máximo.');
        }
        $somaMin += $min;
        if ($min != $padrao[$k][0] || $max != $padrao[$k][1]) {
            $final[$k] = [$min, $max];
        }
    }
    if ($somaMin > 100.0001) {
        throw new InvalidArgumentException('A soma dos mínimos das faixas passa de 100%.');
    }
    parametro_set('inv_perfil_' . perfil_id(), json_encode(['tolerancia' => $tolerancia, 'reserva_meses' => $reservaMeses, 'faixas' => $final]));
    return alocacao_perfil();
}

// ------------------------------------------------------------------ situacao atual

/** Classe de alocacao de um investimento (exposicao ao dolar manda sobre o tipo). */
function alocacao_classe(array $i): string
{
    if (($i['moeda'] ?? 'BRL') === 'USD') {
        return 'exterior';
    }
    switch ($i['tipo']) {
        case 'acoes':
            return 'acoes';
        case 'fii':
            return 'imobiliario';
        case 'renda_fixa':
            return $i['indexador'] === 'pre' ? 'prazo' : 'pos';
        default:
            return 'outros';
    }
}

/**
 * Meses de despesa cobertos por dinheiro de liquidez diaria (contas + investimentos ativos de liquidez diaria em renda fixa),
 * contra a media mensal de despesas dos ultimos 6 meses completos (sem pagamento de fatura, que ja esta nas compras, e sem reajustes).
 * @return array{meses: ?float, liquido: float, media_despesas: float}
 */
function alocacao_reserva(?string $hoje = null): array
{
    $hoje = $hoje ?: date('Y-m-d');
    $ini = date('Y-m-01', strtotime($hoje . ' -6 month'));
    $fim = date('Y-m-01', strtotime($hoje));
    $total = (float) q_val(
        "SELECT COALESCE(SUM(valor),0) FROM lancamentos WHERE perfil_id = ? AND tipo = 'despesa' AND data >= ? AND data < ? AND fatura_id IS NULL AND ajuste IS NULL
           AND (efetivado = 1 OR cartao_id IS NOT NULL) AND " . sql_sem_restante() . ' AND ' . sql_sem_historico(),
        [perfil_id(), $ini, $fim]
    );
    $media = $total / 6;
    $liquido = max(0.0, saldo_total_contas());
    foreach (investimentos_com_saldo() as $i) {
        if ((int) $i['ativo'] === 1 && $i['tipo'] === 'renda_fixa' && $i['liquidez'] === 'diaria' && $i['indexador'] !== 'pre') {
            $liquido += (float) $i['saldo'];
        }
    }
    return ['meses' => $media > 0 ? round($liquido / $media, 1) : null, 'liquido' => round($liquido, 2), 'media_despesas' => round($media, 2)];
}

/** Retorno minimo do aluguel (% a.a. do valor do imovel) para o imovel empatar com a renda fixa liquida, supondo valorizacao igual a inflacao esperada. */
function alocacao_imovel_minimo(array $resumo): ?array
{
    $ipca = $resumo['ipca_esperado_12m'] ?? null;
    if ($ipca === null || empty($resumo['cdi'])) {
        return null;
    }
    $cdiLiq = $resumo['cdi'] * (1 - ALOC_ALIQUOTA_IR_LONGO / 100);
    $minimo = $cdiLiq - $ipca;
    return ['cdi_liquido' => round($cdiLiq, 2), 'valorizacao' => round($ipca, 2), 'aluguel_minimo_ano' => round($minimo, 2), 'aluguel_minimo_mes' => round($minimo / 12, 2)];
}

// ----------------------------------------------------------------------- sinais

/** @return array{sinal: string, rotulo: string} */
function alocacao_sinal(float $pct, array $faixa, int $pontos, bool $reservaBaixa, string $classe): array
{
    $arriscada = !in_array($classe, ['pos'], true);
    if ($reservaBaixa && $arriscada && $pct < $faixa[1]) {
        return ['sinal' => 'priorizar_reserva', 'rotulo' => 'Antes, reforçar a reserva de emergência'];
    }
    if ($pct > $faixa[1] + 0.05) {
        return ['sinal' => 'reduzir', 'rotulo' => 'Acima da faixa: avaliar reduzir'];
    }
    if ($pct < $faixa[0] - 0.05) {
        return $pontos >= 0 ? ['sinal' => 'aumentar', 'rotulo' => 'Abaixo da faixa: avaliar aumentar'] : ['sinal' => 'aguardar', 'rotulo' => 'Abaixo da faixa, mas o momento pede cautela'];
    }
    if ($pontos >= 2) {
        return ['sinal' => 'aumentar', 'rotulo' => 'Dentro da faixa, momento favorável: pode ir ao topo'];
    }
    if ($pontos <= -2) {
        return ['sinal' => 'aguardar', 'rotulo' => 'Dentro da faixa, momento pede cautela'];
    }
    return ['sinal' => 'manter', 'rotulo' => 'Dentro da faixa: manter'];
}

/**
 * Sugestoes por classe de ativo para o perfil atual.
 * @return array{perfil: array, total_investido: float, reserva: array, classes: list<array>, tesouro: array, imovel: ?array, indicadores: array}
 */
function alocacao_sugestoes(?string $hoje = null): array
{
    $hoje = $hoje ?: date('Y-m-d');
    $perfil = alocacao_perfil();
    $res = inflacao_resumo();
    $dia = cenario_dia($hoje);
    $reserva = alocacao_reserva($hoje);
    $reservaBaixa = $reserva['meses'] !== null && $reserva['meses'] < $perfil['reserva_meses'];
    $tes = $res['tesouro'];
    $risco = risco_exposicao();
    $valor = array_fill_keys(array_keys(ALOC_CLASSES), 0.0);
    foreach (investimentos_com_saldo() as $i) {
        if ((int) $i['ativo'] === 1 && (float) $i['saldo'] > 0.004) {
            $valor[alocacao_classe($i)] += (float) $i['saldo'];
        }
    }
    $total = array_sum($valor);
    $selic = $res['selic'];
    $selicQueda = ($res['focus']['selic_ano_seguinte'] ?? null) !== null && $res['focus']['selic_ano_seguinte'] < $selic - 1.0;
    $jr = $res['juro_real_esperado'] ?? $res['juro_real_12m'];
    $nv = function (string $tema) use ($dia): ?int {
        return risco_nivel_temas($dia, [$tema]) !== null ? (int) round(risco_nivel_temas($dia, [$tema])) : null;
    };
    $dolar = risco_dolarizacao($res, $risco['usd_pct_patrimonio'], $dia);
    $itens = inflacao_itens();
    $aluguel = null;
    foreach ($itens['itens'] as $x) {
        if ($x['codigo'] === 7448) {
            $aluguel = $x;
        }
    }

    $fatores = array_fill_keys(array_keys(ALOC_CLASSES), []);
    $add = function (string $classe, int $pontos, string $texto) use (&$fatores) {
        $fatores[$classe][] = ['pontos' => $pontos, 'texto' => $texto];
    };
    $f1 = function ($v) { return number_format((float) $v, 1, ',', '.'); };

    // pos-fixado
    if ($selicQueda) {
        $add('pos', -1, 'O Focus projeta a Selic em ' . $f1($res['focus']['selic_ano_seguinte']) . '% no ano seguinte (hoje ' . $f1($selic) . '%): o pós-fixado tende a render menos.');
    }
    if ($reservaBaixa) {
        $add('pos', 2, 'A reserva de emergência cobre ' . $f1($reserva['meses']) . ' meses de despesas, abaixo do alvo de ' . $perfil['reserva_meses'] . ': a prioridade é liquidez.');
    }
    if ($jr !== null && $jr > 6) {
        $add('pos', 1, 'O juro real está alto (' . $f1($jr) . '% a.a.): esperar em renda fixa pós-fixada ainda paga bem acima da inflação.');
    }
    // renda fixa de prazo
    if ($selicQueda) {
        $add('prazo', 1, 'Com a Selic esperada em queda, travar uma taxa por mais tempo protege o rendimento.');
    }
    if (!empty($tes['ipca_6']) && $tes['ipca_6']['taxa'] >= 6.0) {
        $add('prazo', 1, 'O Tesouro IPCA+ de ' . substr((string) $tes['ipca_6']['vencimento'], 0, 4) . ' paga ' . $f1($tes['ipca_6']['taxa']) . '% ao ano acima da inflação.');
    }
    $fiscal = $nv('fiscal');
    if ($fiscal !== null && $fiscal >= 4) {
        $add('prazo', -1, 'As manchetes de hoje apontam risco fiscal alto (' . $fiscal . '/5): juros longos podem subir e o preço dos títulos de prazo cair antes do vencimento.');
    }
    if ($reservaBaixa) {
        $add('prazo', -2, 'Com a reserva abaixo do alvo, travar dinheiro por anos não é prioridade.');
    }
    // acoes
    if ($jr !== null && $jr >= 4) {
        $add('acoes', -1, 'A renda fixa paga juro real líquido alto, o que torna o risco das ações menos compensado.');
    }
    if ($selicQueda) {
        $add('acoes', 1, 'Selic em queda esperada costuma melhorar o ambiente para ações.');
    }
    $bolsa = $nv('bolsa');
    if ($bolsa !== null && $bolsa >= 4) {
        $add('acoes', -1, 'As manchetes de bolsa apontam risco alto hoje (' . $bolsa . '/5).');
    } elseif ($bolsa !== null && $bolsa <= 2) {
        $add('acoes', 1, 'As manchetes de bolsa apontam pouco risco hoje (' . $bolsa . '/5).');
    }
    if ($reservaBaixa) {
        $add('acoes', -2, 'Com a reserva abaixo do alvo, não faz sentido aumentar o risco.');
    }
    // imobiliario
    if ($selicQueda) {
        $add('imobiliario', 1, 'Selic em queda esperada favorece fundos imobiliários e imóveis.');
    }
    if ($jr !== null && $jr > 6) {
        $add('imobiliario', -1, 'O juro real alto torna a renda fixa uma concorrente forte do aluguel.');
    }
    if ($aluguel && $aluguel['spread'] > 0.5) {
        $add('imobiliario', 1, 'O aluguel residencial subiu ' . $f1($aluguel['var_12m']) . '% em 12 meses, acima do IPCA geral.');
    }
    $imv = $nv('imoveis');
    if ($imv !== null && $imv >= 4) {
        $add('imobiliario', -1, 'As manchetes sobre imóveis apontam risco alto hoje (' . $imv . '/5).');
    }
    if ($reservaBaixa) {
        $add('imobiliario', -2, 'Com a reserva abaixo do alvo, evite imobilizar dinheiro.');
    }
    // exterior (mesmos fatores da dolarizacao)
    foreach ($dolar['fatores'] as $f) {
        $add('exterior', (int) $f['pontos'], $f['texto']);
    }
    if ($reservaBaixa) {
        $add('exterior', -2, 'Com a reserva abaixo do alvo, a prioridade não é aumentar risco.');
    }
    // outros
    $ext = risco_nivel_temas($dia, ['bolsa', 'cambio']);
    if ($ext !== null && $ext >= 3.7) {
        $add('outros', -1, 'Bolsa e câmbio com risco alto nas manchetes: ativos voláteis pedem cautela.');
    }
    if ($reservaBaixa) {
        $add('outros', -2, 'Com a reserva abaixo do alvo, evite ativos voláteis.');
    }

    $classes = [];
    foreach (ALOC_CLASSES as $k => [$rotulo, $descricao]) {
        $pct = $total > 0 ? $valor[$k] / $total * 100 : 0.0;
        $pontos = array_sum(array_column($fatores[$k], 'pontos'));
        $s = alocacao_sinal($pct, $perfil['faixas'][$k], $pontos, $reservaBaixa, $k);
        $classes[] = ['chave' => $k, 'rotulo' => $rotulo, 'descricao' => $descricao, 'valor' => round($valor[$k], 2), 'pct' => round($pct, 1),
            'faixa' => [(float) $perfil['faixas'][$k][0], (float) $perfil['faixas'][$k][1]], 'pontos' => $pontos, 'fatores' => $fatores[$k],
            'sinal' => $s['sinal'], 'indicacao' => $s['rotulo']];
    }
    return ['perfil' => $perfil, 'padroes' => ALOC_FAIXAS, 'total_investido' => round($total, 2), 'reserva' => $reserva + ['alvo' => $perfil['reserva_meses'], 'baixa' => $reservaBaixa],
        'classes' => $classes, 'tesouro' => $tes, 'imovel' => alocacao_imovel_minimo($res),
        'indicadores' => ['selic' => $selic, 'selic_queda_esperada' => $selicQueda, 'juro_real' => $jr, 'ipca_esperado' => $res['ipca_esperado_12m']]];
}
