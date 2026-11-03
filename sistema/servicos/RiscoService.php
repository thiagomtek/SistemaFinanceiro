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
 * @return array{total_investido: float, patrimonio: float, por_indexador: list<array>, por_instituicao: list<array>, limite_fgc: float, usd_valor: float, usd_pct_patrimonio: float}
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
    $usd = 0.0;
    foreach ($ativos as $i) {
        if (($i['moeda'] ?? 'BRL') === 'USD') {
            $usd += (float) $i['saldo'];
        }
    }
    $outInst = [];
    foreach ($porInst as $r) {
        $outInst[] = ['instituicao' => $r['instituicao'], 'saldo' => round($r['saldo'], 2), 'pct' => $totInv > 0 ? round($r['saldo'] / $totInv * 100, 1) : 0.0,
            'renda_fixa' => round($r['renda_fixa'], 2), 'uso_fgc_pct' => round($r['renda_fixa'] / RISCO_LIMITE_FGC * 100, 1)];
    }
    usort($outInst, function ($a, $b) { return $b['saldo'] <=> $a['saldo']; });
    return ['total_investido' => round($totInv, 2), 'patrimonio' => round($patrimonio, 2), 'por_indexador' => $outIdx, 'por_instituicao' => $outInst, 'limite_fgc' => RISCO_LIMITE_FGC,
        'usd_valor' => round($usd, 2), 'usd_pct_patrimonio' => $patrimonio > 0 ? round($usd / $patrimonio * 100, 1) : 0.0];
}

/** Resumo completo para a tela de projecao. */
function risco_selic_resumo(int $meses = 12, bool $usar_media = true, float $aporte_mensal = 0.0, ?float $cdi_base = null, float $pct_aporte = 100.0): array
{
    return risco_cenarios_selic($meses, $usar_media, $aporte_mensal, $cdi_base, $pct_aporte) + ['exposicao' => risco_exposicao()];
}


// ===================================================================== risco consolidado (RN-47)

/** Nivel de risco 1..5 por faixas crescentes: devolve o numero de limites que $v alcanca, mais 1. */
function risco_faixa(float $v, array $limites): int
{
    $n = 1;
    foreach ($limites as $l) {
        if ($v >= $l) {
            $n++;
        }
    }
    return min(5, $n);
}

/** Media dos niveis de risco da IA (1..5) nos temas pedidos do dia, ou null sem leitura. */
function risco_nivel_temas(?array $dia, array $temas): ?float
{
    if (!$dia || ($dia['origem'] ?? '') !== 'ia') {
        return null;
    }
    $v = [];
    foreach ($temas as $t) {
        if (isset($dia['temas'][$t]['nivel_risco'])) {
            $v[] = (int) $dia['temas'][$t]['nivel_risco'];
        }
    }
    return $v ? array_sum($v) / count($v) : null;
}

/**
 * Sinal sobre dolarizar (comprar dolar ou investir no exterior). Cada fator soma ou tira um ponto e todos aparecem na resposta; o
 * resultado e um apoio a decisao, nao uma recomendacao: o usuario decide a parcela.
 * @return array{sinal: string, titulo: string, pontos: int, fatores: list<array>, exposicao_pct: float, como: list<string>}
 */
function risco_dolarizacao(array $resumo, float $usdPct, ?array $dia): array
{
    $f = [];
    $add = function (int $pontos, string $texto) use (&$f) {
        $f[] = ['pontos' => $pontos, 'texto' => $texto];
    };
    $dolar = $resumo['dolar']['valor'] ?? null;
    $focusCambio = $resumo['focus']['cambio_ano'] ?? null;
    if ($dolar && $focusCambio) {
        $dif = ($focusCambio / $dolar - 1) * 100;
        if ($dif > 3) {
            $add(1, 'O Focus projeta o dólar em R$ ' . number_format($focusCambio, 2, ',', '.') . ' no fim do ano, ' . number_format($dif, 1, ',', '.') . '% acima de hoje (R$ ' . number_format($dolar, 2, ',', '.') . ').');
        } elseif ($dif < -3) {
            $add(-1, 'O Focus projeta o dólar em R$ ' . number_format($focusCambio, 2, ',', '.') . ' no fim do ano, ' . number_format(abs($dif), 1, ',', '.') . '% abaixo de hoje.');
        }
    }
    $var = $resumo['dolar_var_12m'] ?? null;
    if ($var !== null) {
        if ($var < -8) {
            $add(1, 'O dólar está ' . number_format(abs($var), 1, ',', '.') . '% mais barato que há 12 meses.');
        } elseif ($var > 15) {
            $add(-1, 'O dólar já subiu ' . number_format($var, 1, ',', '.') . '% em 12 meses: comprar agora significa pagar caro.');
        }
    }
    $jr = $resumo['juro_real_esperado'] ?? ($resumo['juro_real_12m'] ?? null);
    if ($jr !== null) {
        if ($jr > 6) {
            $add(-1, 'O juro real é alto (' . number_format($jr, 1, ',', '.') . '% a.a.): o dólar comprado não rende juros, e cada real dolarizado deixa de ganhar o CDI.');
        } elseif ($jr < 3) {
            $add(1, 'O juro real está baixo (' . number_format($jr, 1, ',', '.') . '% a.a.): o que se deixa de ganhar em renda fixa para ter dólar é menor.');
        }
    }
    $ext = risco_nivel_temas($dia, ['fiscal', 'politica', 'cambio']);
    if ($ext !== null && $ext >= 3.7) {
        $add(1, 'As manchetes de hoje apontam risco elevado em fiscal, política ou câmbio (nível médio ' . number_format($ext, 1, ',', '.') . ' de 5).');
    } elseif ($ext !== null && $ext <= 2.0) {
        $add(-1, 'As manchetes de hoje apontam pouco risco em fiscal, política e câmbio (nível médio ' . number_format($ext, 1, ',', '.') . ' de 5).');
    }
    if ($usdPct < 5) {
        $add(1, 'Hoje só ' . number_format($usdPct, 1, ',', '.') . '% do seu patrimônio está em dólar: toda a riqueza depende do real.');
    } elseif ($usdPct >= 30) {
        $add(-1, 'Você já tem ' . number_format($usdPct, 1, ',', '.') . '% do patrimônio em dólar.');
    }
    $pontos = array_sum(array_column($f, 'pontos'));
    if ($pontos >= 2) {
        $sinal = 'avaliar_aumentar';
        $titulo = 'Há argumentos para aumentar a parcela em dólar';
    } elseif ($pontos <= -2) {
        $sinal = 'sem_pressa';
        $titulo = 'Não há pressa para dolarizar mais';
    } else {
        $sinal = 'manter';
        $titulo = 'Manter a exposição atual e acompanhar';
    }
    return ['sinal' => $sinal, 'titulo' => $titulo, 'pontos' => $pontos, 'fatores' => $f, 'exposicao_pct' => round($usdPct, 1),
        'como' => [
            'Comprar dólar (moeda ou conta global): acompanha o câmbio, mas não rende juros.',
            'Investir no exterior (ETFs, BDRs, fundos cambiais ou conta no exterior): rende pelo ativo e pelo câmbio, com tributação e custos próprios (confira antes).',
        ]];
}

/**
 * Dimensoes do risco do perfil atual, cada uma com nivel 1 (baixo) a 5 (alto) e o motivo com numeros. Nivel null = sem dado.
 * @return array{nivel_geral: int, dimensoes: list<array>, indicadores: array, exposicao: array, dolarizacao: array, sensibilidade_pp: float}
 */
function risco_consolidar(?string $hoje = null): array
{
    $hoje = $hoje ?: date('Y-m-d');
    $res = inflacao_resumo();
    $dia = cenario_dia($hoje);
    $cen = risco_cenarios_selic(12, true, 0.0, null, 100.0);
    $exp = risco_exposicao();
    $proj = projetar(12, true, 0.0, null, 100.0);
    $totInv = $exp['total_investido'];
    $posFix = 0.0;
    foreach ($exp['por_indexador'] as $x) {
        if ($x['chave'] === 'cdi') {
            $posFix = $x['pct'];
        }
    }
    $dims = [];

    // 1) juros: quanto o patrimonio ao fim do horizonte cai se a Selic cair 2 p.p., e o que o mercado espera para a Selic
    $queda = $cen['cenarios'][1];
    $nivel = risco_faixa(abs(min(0.0, $queda['dif_pct'])), [1.0, 2.5, 4.0, 6.0]);
    $texto = 'Se a Selic cair 2 p.p., o patrimônio ao fim de 12 meses fica ' . number_format(abs($queda['dif_pct']), 2, ',', '.') . '% (' . brl(abs($queda['dif_patrimonio'])) . ') abaixo do cenário base; '
        . number_format($posFix, 0, ',', '.') . '% do investido rende por CDI.';
    $focusSelic = $res['focus']['selic_ano_seguinte'] ?? null;
    if ($focusSelic !== null && $focusSelic < $res['selic'] - 1.0 && $posFix >= 70) {
        $nivel = min(5, $nivel + 1);
        $texto .= ' O Focus projeta Selic de ' . number_format($focusSelic, 2, ',', '.') . '% no ano seguinte (hoje ' . number_format($res['selic'], 2, ',', '.') . '%): queda esperada com carteira pós-fixada.';
    } elseif ($focusSelic !== null) {
        $texto .= ' O Focus projeta Selic de ' . number_format($focusSelic, 2, ',', '.') . '% no ano seguinte.';
    }
    $dims[] = ['chave' => 'juros', 'rotulo' => 'Juros (Selic)', 'nivel' => $totInv > 0 ? $nivel : null, 'motivo' => $totInv > 0 ? $texto : 'Sem investimentos ativos para medir.'];

    // 2) inflacao: retorno real liquido esperado da carteira
    $ativos = array_values(array_filter(investimentos_com_saldo(), function ($i) { return (int) $i['ativo'] === 1 && (float) $i['saldo'] > 0.004; }));
    $peso = $taxaPond = $ir = $rend = 0.0;
    foreach ($ativos as $i) {
        $peso += (float) $i['saldo'];
        $taxaPond += (float) $i['saldo'] * rf_taxa_anual_efetiva($i, $res['cdi']);
        $ir += (float) $i['ir'] + (float) $i['iof'];
        $rend += max(0.0, (float) $i['saldo'] - (float) $i['principal']);
    }
    $ipcaEsp = $res['ipca_esperado_12m'] ?? ($res['ipca_12m']['valor'] ?? null);
    if ($peso > 0 && $ipcaEsp !== null) {
        $brutoAno = $taxaPond / $peso;
        $aliq = $rend > 1 ? max(0.0, min(0.225, $ir / $rend)) : 0.175;
        $liqAno = $brutoAno * (1 - $aliq);
        $real = juro_real($liqAno, $ipcaEsp);
        $nivel = $real >= 4 ? 1 : ($real >= 2 ? 2 : ($real >= 0.5 ? 3 : ($real >= -1 ? 4 : 5)));
        $texto = 'Retorno esperado da carteira de ' . number_format($brutoAno, 1, ',', '.') . '% a.a. bruto (cerca de ' . number_format($liqAno, 1, ',', '.') . '% após IR) contra IPCA esperado de '
            . number_format($ipcaEsp, 2, ',', '.') . '%: juro real líquido de ' . number_format($real, 1, ',', '.') . '% ao ano.';
        $cesta = inflacao_pessoal_cesta($hoje);
        if ($cesta['confiavel'] && $cesta['ipca_cesta'] !== null && $cesta['ipca_geral'] !== null && $cesta['ipca_cesta'] - $cesta['ipca_geral'] >= 1.5) {
            $nivel = min(5, $nivel + 1);
            $texto .= ' A inflação da sua cesta (' . number_format($cesta['ipca_cesta'], 2, ',', '.') . '%) está acima do IPCA geral (' . number_format($cesta['ipca_geral'], 2, ',', '.') . '%).';
        } elseif ($cesta['gasto_total'] <= 0) {
            $texto .= ' Ainda não há gastos para montar a inflação da sua cesta.';
        } elseif (!$cesta['confiavel']) {
            $texto .= ' A inflação da sua cesta não entrou na conta: só ' . number_format($cesta['cobertura_pct'], 0, ',', '.') . '% dos seus gastos têm categoria ligada ao IPCA.';
        }
        $itens = inflacao_itens();
        $acima = count(array_filter($itens['itens'], function ($x) { return $x['acima']; }));
        if ($acima > 0) {
            $texto .= ' ' . $acima . ' item(ns) do IPCA sobem acima do índice geral em 12 meses.';
        }
        $dims[] = ['chave' => 'inflacao', 'rotulo' => 'Inflação (poder de compra)', 'nivel' => $nivel, 'motivo' => $texto];
    } else {
        $dims[] = ['chave' => 'inflacao', 'rotulo' => 'Inflação (poder de compra)', 'nivel' => null, 'motivo' => 'Sem indicadores de inflação atualizados ou sem investimentos.'];
    }

    // 3) moeda / dolarizacao
    $dol = risco_dolarizacao($res, $exp['usd_pct_patrimonio'], $dia);
    $nivel = $exp['usd_pct_patrimonio'] < 2 ? 3 : ($exp['usd_pct_patrimonio'] < 10 ? 2 : 1);
    if ($dol['pontos'] >= 2) {
        $nivel = min(5, $nivel + 1);
    } elseif ($dol['pontos'] <= -2) {
        $nivel = max(1, $nivel - 1);
    }
    $dims[] = ['chave' => 'moeda', 'rotulo' => 'Moeda (dolarização)', 'nivel' => $exp['patrimonio'] > 0 ? $nivel : null,
        'motivo' => number_format($exp['usd_pct_patrimonio'], 1, ',', '.') . '% do patrimônio em dólar. ' . $dol['titulo'] . '.'];

    // 4) concentracao e credito (FGC)
    if ($exp['por_instituicao']) {
        $maxFgc = max(array_column($exp['por_instituicao'], 'uso_fgc_pct'));
        $maxShare = max(array_column($exp['por_instituicao'], 'pct'));
        $nivel = $maxFgc >= 100 ? 5 : ($maxFgc >= 80 ? 4 : ($maxFgc >= 50 ? 3 : ($maxFgc >= 25 ? 2 : 1)));
        if ($maxShare >= 70) {
            $nivel = max($nivel, 3);
        }
        $dims[] = ['chave' => 'concentracao', 'rotulo' => 'Concentração e crédito', 'nivel' => $nivel,
            'motivo' => 'Maior instituição com ' . number_format($maxShare, 1, ',', '.') . '% do investido e ' . number_format($maxFgc, 1, ',', '.') . '% do limite de R$ 250 mil do FGC (estimativa).'];
    } else {
        $dims[] = ['chave' => 'concentracao', 'rotulo' => 'Concentração e crédito', 'nivel' => null, 'motivo' => 'Sem investimentos ativos.'];
    }

    // 5) liquidez e caixa
    $alerta = $proj['alerta_negativo'];
    $trava = 0.0;
    foreach ($ativos as $i) {
        if ($i['liquidez'] === 'vencimento') {
            $trava += (float) $i['saldo'];
        }
    }
    $travaPct = $totInv > 0 ? $trava / $totInv * 100 : 0.0;
    if ($alerta) {
        $cobre = $alerta['investido_liquido'] >= abs($alerta['saldo']);
        $nivel = $cobre ? 3 : 5;
        $texto = 'A conta corrente fica negativa em ' . data_br($alerta['data']) . ' (' . brl($alerta['saldo']) . ')' . ($cobre ? ', coberta se você resgatar investimentos de liquidez diária.' : ' e os investimentos não cobrem.');
    } else {
        $nivel = 1;
        $texto = 'A conta corrente não fica negativa nos próximos 12 meses.';
    }
    if ($travaPct >= 50) {
        $nivel = min(5, $nivel + 1);
        $texto .= ' ' . number_format($travaPct, 0, ',', '.') . '% do investido só resgata no vencimento.';
    }
    $dims[] = ['chave' => 'liquidez', 'rotulo' => 'Liquidez e caixa', 'nivel' => $nivel, 'motivo' => $texto];

    // 6) cenario externo (manchetes lidas pela IA)
    $ext = risco_nivel_temas($dia, ['fiscal', 'politica', 'cambio', 'bolsa', 'internacional']);
    if ($ext !== null) {
        $partes = [];
        foreach (['fiscal', 'politica', 'cambio', 'bolsa', 'internacional'] as $t) {
            if (isset($dia['temas'][$t]['nivel_risco'])) {
                $partes[] = $dia['temas'][$t]['rotulo'] . ' ' . $dia['temas'][$t]['nivel_risco'] . '/5';
            }
        }
        $dims[] = ['chave' => 'externo', 'rotulo' => 'Cenário externo e político', 'nivel' => (int) round($ext), 'motivo' => 'Leitura das manchetes de ' . data_br($dia['data']) . ': ' . implode(', ', $partes) . '.'];
    } else {
        $dims[] = ['chave' => 'externo', 'rotulo' => 'Cenário externo e político', 'nivel' => null, 'motivo' => 'Sem leitura de notícias por IA hoje; o risco geral usa só dados oficiais.'];
    }

    $pesos = ['juros' => 0.20, 'inflacao' => 0.20, 'moeda' => 0.15, 'concentracao' => 0.15, 'liquidez' => 0.15, 'externo' => 0.15];
    $soma = $w = 0.0;
    $max = 1;
    foreach ($dims as $d) {
        if ($d['nivel'] !== null) {
            $soma += $d['nivel'] * $pesos[$d['chave']];
            $w += $pesos[$d['chave']];
            $max = max($max, $d['nivel']);
        }
    }
    $geral = $w > 0 ? (int) round($soma / $w) : 1;
    if ($max >= 5) {
        $geral = max($geral, 4);
    }
    return ['nivel_geral' => $geral, 'dimensoes' => $dims, 'indicadores' => cenario_indicadores_dia(),
        'exposicao' => ['patrimonio' => $exp['patrimonio'], 'total_investido' => $totInv, 'por_indexador' => $exp['por_indexador'], 'usd_pct_patrimonio' => $exp['usd_pct_patrimonio']],
        'dolarizacao' => $dol, 'sensibilidade_pp' => $cen['sensibilidade_pp']];
}

/** Grava (ou refaz) a foto de risco do dia do perfil atual. */
function risco_snapshot_salvar(?string $hoje = null): array
{
    $hoje = $hoje ?: date('Y-m-d');
    $r = risco_consolidar($hoje);
    q('REPLACE INTO risco_snapshots (perfil_id, data, nivel_geral, dimensoes_json, indicadores_json, exposicao_json) VALUES (?,?,?,?,?,?)',
        [perfil_id(), $hoje, $r['nivel_geral'], json_encode($r['dimensoes'], JSON_UNESCAPED_UNICODE), json_encode($r['indicadores'], JSON_UNESCAPED_UNICODE),
            json_encode(['exposicao' => $r['exposicao'], 'dolarizacao' => ['sinal' => $r['dolarizacao']['sinal'], 'pontos' => $r['dolarizacao']['pontos']], 'sensibilidade_pp' => $r['sensibilidade_pp']], JSON_UNESCAPED_UNICODE)]);
    return ['perfil_id' => perfil_id(), 'nivel_geral' => $r['nivel_geral']];
}

/** Foto de risco de cada perfil de usuario ativo (para a rotina diaria). */
function risco_snapshot_todos(?string $hoje = null): array
{
    $guarda = $_SESSION;
    $out = [];
    foreach (q_all('SELECT p.id AS pid, p.usuario_id AS uid FROM perfis p INNER JOIN usuarios u ON u.id = p.usuario_id WHERE u.ativo = 1 ORDER BY p.id') as $r) {
        $_SESSION = ['usuario_id' => (int) $r['uid'], 'perfil_id' => (int) $r['pid']];
        try {
            $out[] = risco_snapshot_salvar($hoje);
        } catch (Throwable $t) {
            $out[] = ['perfil_id' => (int) $r['pid'], 'erro' => $t->getMessage()];
        }
    }
    $_SESSION = $guarda;
    return $out;
}

/**
 * Historico do risco do perfil atual: a ultima foto de cada mes (e a mais recente), do mais novo para o mais antigo.
 * @return list<array{data: string, nivel_geral: int, rotulo: string, dimensoes: array, indicadores: array}>
 */
function risco_historico(int $n = 12): array
{
    $out = [];
    $vistos = [];
    foreach (q_all('SELECT * FROM risco_snapshots WHERE perfil_id = ? ORDER BY data DESC', [perfil_id()]) as $r) {
        $m = substr((string) $r['data'], 0, 7);
        if (isset($vistos[$m])) {
            continue;
        }
        $vistos[$m] = true;
        $out[] = ['data' => $r['data'], 'nivel_geral' => (int) $r['nivel_geral'], 'rotulo' => risco_nivel_rotulo((int) $r['nivel_geral']),
            'dimensoes' => json_decode((string) $r['dimensoes_json'], true) ?: [], 'indicadores' => json_decode((string) $r['indicadores_json'], true) ?: []];
        if (count($out) >= $n) {
            break;
        }
    }
    return $out;
}
