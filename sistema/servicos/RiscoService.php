<?php
declare(strict_types=1);

/* =========================================================================
   RiscoService: risco do patrimonio frente a mudancas da Selic (RN-44).
   - Cenarios: roda a mesma projecao (projetar) com o CDI deslocado em p.p., de modo que patrimonio, rendimento e aportes seguem
     exatamente a regra da projecao (fonte unica). A Selic e o CDI andam juntos: o deslocamento vale para os dois.
   - Exposicao: quanto do que esta investido reage a juros (pos-fixado em CDI), esta travado (pre-fixado), depende da Selic
     (poupanca) ou nao reage neste calculo (manual: acoes, FII, fundos e cripto lancados a mao), e quanto ha em cada instituicao.
   ========================================================================= */

const RISCO_DELTAS_SELIC = [-4.0, -2.0, 0.0, 2.0, 4.0];
const RISCO_LIMITE_FGC = 250000.0; // cobertura do FGC por CPF e por instituicao (CDB, LCI, LCA, RDB...)
const RISCO_SPREAD_SELIC_CDI = 0.10; // o CDI fica cerca de 0,10 p.p. abaixo da Selic meta

/**
 * @return array{base: array, cenarios: list<array>, sensibilidade_pp: float}
 */
function risco_cenarios_selic(int $meses = 12, bool $usar_media = true, float $aporte_mensal = 0.0, ?float $cdi_base = null, float $pct_aporte = 100.0): array
{
    $cdiBase = $cdi_base ?? cdi_anual_atual();
    $cenarios = [];
    $base = null;
    foreach (RISCO_DELTAS_SELIC as $delta) {
        $cdi = max(0.1, $cdiBase + $delta);
        $p = projetar($meses, $usar_media, $aporte_mensal, $cdi, $pct_aporte);
        $ult = $p['serie'][count($p['serie']) - 1];
        $c = [
            'delta' => $delta,
            'rotulo' => $delta == 0.0 ? 'Cenário base' : ('Selic ' . ($delta > 0 ? 'sobe ' : 'cai ') . number_format(abs($delta), 0) . ' p.p.'),
            'cdi' => round($cdi, 2),
            'selic' => round($cdi + RISCO_SPREAD_SELIC_CDI, 2),
            'patrimonio_fim' => $ult['patrimonio'],
            'patrimonio_liquido_fim' => $ult['patrimonio_liquido'],
            'investido_fim' => $ult['investido'],
            'rendimento_total' => round(array_sum(array_column($p['serie'], 'rendimento_mes')), 2),
        ];
        $cenarios[] = $c;
        if ($delta == 0.0) {
            $base = $c + ['patrimonio_hoje' => round($p['saldo_atual'] + $p['investido_atual'], 2), 'meses' => count($p['serie'])];
        }
    }
    foreach ($cenarios as &$c) {
        $c['dif_patrimonio'] = round($c['patrimonio_fim'] - $base['patrimonio_fim'], 2);
        $c['dif_pct'] = $base['patrimonio_fim'] > 0 ? round($c['dif_patrimonio'] / $base['patrimonio_fim'] * 100, 2) : 0.0;
        $c['dif_rendimento'] = round($c['rendimento_total'] - $base['rendimento_total'], 2);
    }
    unset($c);
    $baixo = $cenarios[1];
    $alto = $cenarios[3];
    $span = $alto['delta'] - $baixo['delta'];
    return ['base' => $base, 'cenarios' => $cenarios, 'sensibilidade_pp' => $span != 0.0 ? round(($alto['patrimonio_fim'] - $baixo['patrimonio_fim']) / $span, 2) : 0.0];
}

/**
 * Exposicao dos investimentos ativos: por tipo de rendimento (como reagem a Selic) e por instituicao (FGC).
 * @return array{total_investido: float, patrimonio: float, por_indexador: list<array>, por_instituicao: list<array>, limite_fgc: float}
 */
function risco_exposicao(): array
{
    $ativos = array_values(array_filter(investimentos_com_saldo(), function ($i) {
        return (int) $i['ativo'] === 1 && (float) $i['saldo'] > 0.004;
    }));
    $totInv = array_sum(array_column($ativos, 'saldo'));
    $patrimonio = $totInv + saldo_total_contas();
    $classes = [
        'cdi' => ['Pós-fixado (% do CDI)', 'Rende menos quando a Selic cai e mais quando ela sobe.'],
        'pre' => ['Pré-fixado', 'Taxa travada até o vencimento; se a Selic subir, quem já aplicou ganha menos que o mercado novo. Marcação a mercado não é calculada.'],
        'poupanca' => ['Poupança', 'Com Selic acima de 8,5% rende 0,5% ao mês fixo; abaixo, 70% da Selic. Neste cálculo segue a Selic de hoje.'],
        'manual' => ['Manual (ações, FII, fundos, cripto, outros)', 'Não reage à Selic neste cálculo: o rendimento é o que você informa. O risco de mercado desses ativos não está medido aqui.'],
    ];
    $porIdx = array_fill_keys(array_keys($classes), 0.0);
    $porInst = [];
    foreach ($ativos as $i) {
        $k = isset($classes[$i['indexador']]) ? $i['indexador'] : 'manual';
        $porIdx[$k] += (float) $i['saldo'];
        $inst = trim((string) $i['instituicao']) !== '' ? trim((string) $i['instituicao']) : 'Sem instituição';
        if (!isset($porInst[$inst])) {
            $porInst[$inst] = ['instituicao' => $inst, 'saldo' => 0.0, 'renda_fixa' => 0.0];
        }
        $porInst[$inst]['saldo'] += (float) $i['saldo'];
        if ($i['tipo'] === 'renda_fixa') {
            $porInst[$inst]['renda_fixa'] += (float) $i['saldo'];
        }
    }
    $outIdx = [];
    foreach ($classes as $k => [$rotulo, $efeito]) {
        if ($porIdx[$k] > 0.004) {
            $outIdx[] = ['chave' => $k, 'rotulo' => $rotulo, 'valor' => round($porIdx[$k], 2), 'pct' => $totInv > 0 ? round($porIdx[$k] / $totInv * 100, 1) : 0.0, 'efeito' => $efeito];
        }
    }
    $outInst = [];
    foreach ($porInst as $r) {
        $outInst[] = ['instituicao' => $r['instituicao'], 'saldo' => round($r['saldo'], 2), 'pct' => $totInv > 0 ? round($r['saldo'] / $totInv * 100, 1) : 0.0,
            'renda_fixa' => round($r['renda_fixa'], 2), 'uso_fgc_pct' => round($r['renda_fixa'] / RISCO_LIMITE_FGC * 100, 1)];
    }
    usort($outInst, function ($a, $b) { return $b['saldo'] <=> $a['saldo']; });
    return ['total_investido' => round($totInv, 2), 'patrimonio' => round($patrimonio, 2), 'por_indexador' => $outIdx, 'por_instituicao' => $outInst, 'limite_fgc' => RISCO_LIMITE_FGC];
}

/** Resumo completo para a tela de projecao. */
function risco_selic_resumo(int $meses = 12, bool $usar_media = true, float $aporte_mensal = 0.0, ?float $cdi_base = null, float $pct_aporte = 100.0): array
{
    return risco_cenarios_selic($meses, $usar_media, $aporte_mensal, $cdi_base, $pct_aporte) + ['exposicao' => risco_exposicao()];
}
