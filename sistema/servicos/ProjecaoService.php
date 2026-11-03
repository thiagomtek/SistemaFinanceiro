<?php
declare(strict_types=1);

/* ProjecaoService: Projecao de saldo e patrimonio (fonte unica da regra de projecao). */

/**
 * Filtro SQL que deixa de fora lancamentos de importacoes marcadas como historico (importacoes.tipo = 'historico', RN-32).
 * O historico importado nao traz a marca de serie (salario, faturas) e contaria em dobro, na media, o que a projecao ja agenda.
 */
function sql_sem_historico(string $alias = ''): string
{
    $c = $alias === '' ? 'importacao_id' : $alias . '.importacao_id';
    return "($c IS NULL OR $c NOT IN (SELECT id FROM importacoes WHERE tipo = 'historico'))";
}

/**
 * Filtro SQL que deixa de fora o reajuste de saldo de conta (ajuste = 'saldo', RN-41): acerto de saldo nao e gasto nem renda
 * recorrente e distorceria a media de avulsos (ex.: o "saldo inicial zerado" da carga). Continua valendo em saldos e totais do mes.
 */
function sql_sem_reajuste_saldo(string $alias = ''): string
{
    $c = $alias === '' ? 'ajuste' : $alias . '.ajuste';
    return "($c IS NULL OR $c <> 'saldo')";
}

/**
 * Projecao mensal do saldo das contas e do patrimonio investido.
 * Fontes: lancamentos pendentes agendados (inclui parcelas/recorrencias), faturas de cartao em aberto
 * (no dia do vencimento) e, opcionalmente, media historica de gastos/receitas avulsos.
 *
 * Regras (RN-P1..P3):
 *  - tudo que sobra no mes (receitas - despesas - faturas) e investido no fim do mes, a $pct_aporte % do CDI
 *    (padrao 100%, cenario conservador), e esse aporte passa a render nos meses seguintes;
 *  - mes de deficit sai do saldo da conta; a sobra so e investida depois de a conta voltar a ficar positiva;
 *  - o alerta de conta negativa e o DIA em que o saldo das contas fica abaixo de zero (simulacao dia a dia),
 *    nunca uma conclusao tirada do total do mes: pode haver investimentos para cobrir com um resgate.
 * $aporte_mensal e um aporte adicional fixo (alem da sobra); 0 = so a sobra.
 */
function projetar(int $meses = 12, bool $usar_media = true, float $aporte_mensal = 0.0, ?float $cdi_proj = null, float $pct_aporte = 100.0): array
{
    $pid = perfil_id();
    $hoje = date('Y-m-d');
    $mesAtual = date('Y-m');
    $meses = max(1, min(60, $meses));
    // as ocorrencias de series fixas sao gravadas ate o horizonte pedido (a projecao precisa delas como lancamentos pendentes)
    recorrencias_estender($meses + 1);

    $lista = [];
    for ($i = 0; $i < $meses; $i++) {
        $lista[] = substr(add_meses($mesAtual . '-01', $i), 0, 7);
    }
    $ultimo = end($lista);
    $idx = array_flip($lista);
    $bucket = fn(string $ym) => $ym < $mesAtual ? $mesAtual : $ym;

    $rec = $desp = $fat = $avulsas_desp = $avulsas_rec = array_fill_keys($lista, 0.0);
    $ev = array_fill_keys($lista, []); // mes => [[data, valor com sinal]]
    $dataEv = function (string $data) use ($hoje): string {
        return $data < $hoje ? $hoje : $data; // atrasados caem hoje
    };

    // pendentes em contas (receitas e despesas); atrasadas caem no mes atual
    foreach (q_all(
        'SELECT tipo, data, valor, grupo_id, parcela_num FROM lancamentos
         WHERE perfil_id = ? AND efetivado = 0 AND cartao_id IS NULL AND tipo IN (\'receita\',\'despesa\')',
        [$pid]
    ) as $l) {
        $m = $bucket(substr($l['data'], 0, 7));
        if (!isset($idx[$m])) {
            continue;
        }
        $v = (float) $l['valor'];
        $ev[$m][] = [$dataEv($l['data']), $l['tipo'] === 'receita' ? $v : -$v];
        $avulsa = $l['grupo_id'] === null && $l['parcela_num'] === null;
        if ($l['tipo'] === 'receita') {
            $rec[$m] += $v;
            if ($avulsa) {
                $avulsas_rec[$m] += $v;
            }
        } else {
            $desp[$m] += $v;
            if ($avulsa) {
                $avulsas_desp[$m] += $v;
            }
        }
    }

    // aplicacoes (saem da conta) e resgates (entram liquidos na conta) ainda pendentes
    $tr_in = $tr_out = array_fill_keys($lista, 0.0);
    foreach (q_all(
        "SELECT data, valor, sentido_invest FROM lancamentos WHERE perfil_id = ? AND efetivado = 0 AND tipo = 'transferencia' AND investimento_id IS NOT NULL",
        [$pid]
    ) as $l) {
        $m = $bucket(substr($l['data'], 0, 7));
        if (isset($idx[$m])) {
            if ($l['sentido_invest'] === 'resgate') {
                $tr_in[$m] += (float) $l['valor'];
                $ev[$m][] = [$dataEv($l['data']), (float) $l['valor']];
            } else {
                $tr_out[$m] += (float) $l['valor'];
                $ev[$m][] = [$dataEv($l['data']), -(float) $l['valor']];
            }
        }
    }

    // faturas de cartao em aberto, no mes do vencimento
    foreach (cartoes_do_perfil() as $c) {
        foreach (faturas_do_cartao($c) as $f) {
            if ($f['paga']) {
                continue;
            }
            $m = $bucket($f['mes']);
            if (isset($idx[$m])) {
                $fat[$m] += $f['total'];
                $ev[$m][] = [$dataEv(fatura_vencimento($c, $f['mes'])), -(float) $f['total']];
            }
        }
    }
    // compras avulsas no cartao por mes da compra (evita contar duas vezes com a media). Vale desde o inicio do mes atual:
    // as compras deste mes feitas antes de hoje tambem ja estao nas faturas acima.
    foreach (q_all(
        'SELECT ' . sql_mes('data') . ' AS ym, SUM(valor) AS t FROM lancamentos
         WHERE perfil_id = ? AND cartao_id IS NOT NULL AND grupo_id IS NULL AND parcela_num IS NULL AND data >= ? AND ' . sql_sem_restante() . ' GROUP BY 1',
        [$pid, $mesAtual . '-01']
    ) as $r) {
        if (isset($idx[$r['ym']])) {
            $avulsas_desp[$r['ym']] += (float) $r['t'];
        }
    }
    // avulsos de conta ja pagos/recebidos neste mes estao no saldo: nao entram de novo na media do mes
    if ($usar_media) {
        foreach (q_all(
            "SELECT tipo, SUM(valor) AS t FROM lancamentos
             WHERE perfil_id = ? AND efetivado = 1 AND cartao_id IS NULL AND fatura_id IS NULL AND grupo_id IS NULL AND parcela_num IS NULL
               AND tipo IN ('receita','despesa') AND data >= ? AND data < ? AND " . sql_sem_restante() . ' AND ' . sql_sem_reajuste_saldo() . ' GROUP BY tipo',
            [$pid, $mesAtual . '-01', add_meses($mesAtual . '-01', 1)]
        ) as $r) {
            if ($r['tipo'] === 'receita') {
                $avulsas_rec[$mesAtual] += (float) $r['t'];
            } else {
                $avulsas_desp[$mesAtual] += (float) $r['t'];
            }
        }
    }

    // realizado neste mes em conta (ja esta no saldo de hoje): fora do fluxo que parte do saldo, mas dentro dos totais do mes mostrados na tela
    $real = ['receitas' => 0.0, 'despesas' => 0.0, 'faturas' => 0.0, 'aplicacoes' => 0.0, 'resgates' => 0.0];
    foreach (q_all(
        "SELECT tipo, CASE WHEN fatura_id IS NULL THEN 0 ELSE 1 END AS fat, SUM(valor) AS t FROM lancamentos
         WHERE perfil_id = ? AND efetivado = 1 AND cartao_id IS NULL AND tipo IN ('receita','despesa') AND data >= ? AND data < ? GROUP BY 1, 2",
        [$pid, $mesAtual . '-01', add_meses($mesAtual . '-01', 1)]
    ) as $r) {
        $real[$r['tipo'] === 'receita' ? 'receitas' : ($r['fat'] ? 'faturas' : 'despesas')] += (float) $r['t'];
    }
    foreach (q_all(
        "SELECT sentido_invest, SUM(valor) AS t FROM lancamentos
         WHERE perfil_id = ? AND efetivado = 1 AND tipo = 'transferencia' AND investimento_id IS NOT NULL AND data >= ? AND data < ? GROUP BY 1",
        [$pid, $mesAtual . '-01', add_meses($mesAtual . '-01', 1)]
    ) as $r) {
        $real[$r['sentido_invest'] === 'resgate' ? 'resgates' : 'aplicacoes'] += (float) $r['t'];
    }

    // media historica (ultimos 3 meses completos) de lancamentos avulsos
    $media_desp = $media_rec = 0.0;
    if ($usar_media) {
        $ini = add_meses($mesAtual . '-01', -3);
        $fim = $mesAtual . '-01';
        $m = q_one(
            "SELECT COALESCE(SUM(CASE WHEN tipo='despesa' THEN valor END),0) AS d, COALESCE(SUM(CASE WHEN tipo='receita' THEN valor END),0) AS r
             FROM lancamentos WHERE perfil_id = ? AND data >= ? AND data < ? AND grupo_id IS NULL AND parcela_num IS NULL
               AND fatura_id IS NULL AND tipo IN ('receita','despesa') AND (efetivado = 1 OR cartao_id IS NOT NULL) AND " . sql_sem_restante() . ' AND ' . sql_sem_historico() . ' AND ' . sql_sem_reajuste_saldo(),
            [$pid, $ini, $fim]
        );
        $media_desp = (float) $m['d'] / 3;
        $media_rec = (float) $m['r'] / 3;
    }

    // investimentos: motor de renda fixa (CDI atual ou cenario informado), bruto e liquido de IR/IOF no fim de cada mes
    indices_garantir();
    $cdiProj = $cdi_proj ?? cdi_anual_atual();
    $ativos = array_values(array_filter(investimentos_com_saldo(), function ($i) {
        return (int) $i['ativo'] === 1;
    }));
    $totInv = totais_investidos($ativos);
    $datasMes = array_map(function ($ym) {
        return date('Y-m-t', strtotime($ym . '-01'));
    }, $lista);
    $totBruto = $totLiq = array_fill_keys($datasMes, 0.0);
    $porInvest = [];
    $pesoTotal = 0.0;
    $taxaPond = 0.0;
    $temIR = false;
    foreach ($ativos as $i) {
        $serieInv = rf_serie($i, rf_movs((int) $i['id']), $datasMes, $cdiProj);
        $hojeBruto = (float) $i['saldo'];
        $linhas = [];
        foreach ($datasMes as $k => $dm) {
            $totBruto[$dm] += $serieInv[$dm]['bruto'];
            $totLiq[$dm] += $serieInv[$dm]['liquido'];
            $linhas[] = ['label' => mes_label($lista[$k]), 'bruto' => round($serieInv[$dm]['bruto'], 2), 'liquido' => round($serieInv[$dm]['liquido'], 2)];
        }
        $porInvest[] = ['id' => (int) $i['id'], 'nome' => $i['nome'], 'rotulo' => inv_rotulo_indexador($i), 'tributacao' => $i['tributacao'], 'saldo_atual' => round($hojeBruto, 2), 'serie' => $linhas];
        $pesoTotal += max(0.0, $hojeBruto);
        $taxaPond += max(0.0, $hojeBruto) * rf_taxa_anual_efetiva($i, $cdiProj);
        $temIR = $temIR || $i['tributacao'] === 'ir_regressivo';
    }
    // aportes (sobra do mes): conservador, a $pct_aporte % do CDI, com IR regressivo e IOF no resgate
    $pct_aporte = max(0.0, min(1000.0, $pct_aporte));
    $taxaAporte = rf_taxa_anual_efetiva(['indexador' => 'cdi', 'percentual_cdi' => $pct_aporte], $cdiProj);
    $fatorMes = pow(1 + max(0.0, $taxaAporte) / 100, 1 / 12);
    $coortes = []; // indice do mes => valor aportado no fim do mes
    $saldo = saldo_total_contas();
    $negativa = null;
    $serie = [];
    foreach ($lista as $k => $ym) {
        $extra_desp = $usar_media ? max(0.0, $media_desp - $avulsas_desp[$ym]) : 0.0;
        $extra_rec = $usar_media ? max(0.0, $media_rec - $avulsas_rec[$ym]) : 0.0;
        $fimMes = date('Y-m-t', strtotime($ym . '-01'));
        if ($extra_rec - $extra_desp != 0.0) {
            $ev[$ym][] = [$fimMes, $extra_rec - $extra_desp]; // media sem dia certo: no fim do mes
        }
        $entradas = $rec[$ym] + $extra_rec;
        $saidasOp = $desp[$ym] + $fat[$ym] + $extra_desp;
        $resultadoOp = $entradas - $saidasOp + $tr_in[$ym] - $tr_out[$ym];

        // simulacao dia a dia: o primeiro dia com saldo das contas abaixo de zero
        $porDia = [];
        foreach ($ev[$ym] as [$d, $v]) {
            $porDia[$d] = ($porDia[$d] ?? 0.0) + $v;
        }
        ksort($porDia);
        $cursor = $saldo;
        foreach ($porDia as $d => $v) {
            $cursor += $v;
            if ($negativa === null && $cursor < -0.005) {
                $negativa = ['data' => $d, 'saldo' => round($cursor, 2)];
            }
        }

        $fim = $saldo + $resultadoOp;
        $aporteAuto = max(0.0, min($resultadoOp, $fim)); // so investe depois de a conta voltar a ficar positiva
        $aporteExtra = $k > 0 ? $aporte_mensal : 0.0;
        $saldo = $fim - $aporteAuto - $aporteExtra;
        if ($negativa === null && $saldo < -0.005) {
            $negativa = ['data' => $fimMes, 'saldo' => round($saldo, 2)];
        }
        $aporte = $aporteAuto + $aporteExtra;
        if ($aporte > 0) {
            $coortes[$k] = $aporte;
        }
        $aportesBruto = 0.0;
        $aportesLiq = 0.0;
        foreach ($coortes as $c => $v) {
            $valor = $v * pow($fatorMes, $k - $c);
            $ganho = $valor - $v;
            $dias = 30 * ($k - $c);
            $imp = $ganho * (rf_iof_pct($dias) / 100 + (1 - rf_iof_pct($dias) / 100) * rf_aliquota_ir($dias) / 100);
            $aportesBruto += $valor;
            $aportesLiq += $valor - $imp;
        }
        $rl = $k === 0 ? $real : array_fill_keys(array_keys($real), 0.0);
        $dm = $datasMes[$k];
        $investido = $totBruto[$dm] + $aportesBruto;
        $investidoLiq = $totLiq[$dm] + $aportesLiq;
        $serie[] = [
            'mes' => $ym, 'label' => mes_label($ym),
            'receitas' => round($entradas, 2), 'despesas' => round($desp[$ym] + $extra_desp, 2), 'faturas' => round($fat[$ym], 2),
            'aplicacoes' => round($tr_out[$ym], 2), 'resgates' => round($tr_in[$ym], 2),
            // totais do mes inteiro (realizado + a realizar); no mes atual o realizado ja esta no saldo e nao entra no fluxo acima
            'receitas_mes' => round($entradas + $rl['receitas'], 2), 'despesas_mes' => round($desp[$ym] + $extra_desp + $rl['despesas'], 2),
            'faturas_mes' => round($fat[$ym] + $rl['faturas'], 2),
            'resultado_mes' => round($resultadoOp + $rl['receitas'] - $rl['despesas'] - $rl['faturas'] + $rl['resgates'] - $rl['aplicacoes'], 2),
            'realizado' => ['receitas' => round($rl['receitas'], 2), 'despesas' => round($rl['despesas'], 2), 'faturas' => round($rl['faturas'], 2)],
            'aporte' => round($aporte, 2), 'aporte_sobra' => round($aporteAuto, 2), 'resultado' => round($resultadoOp, 2), 'saldo' => round($saldo, 2),
            'investido' => round($investido, 2), 'investido_liquido' => round($investidoLiq, 2),
            'patrimonio' => round($saldo + $investido, 2), 'patrimonio_liquido' => round($saldo + $investidoLiq, 2),
        ];
    }
    $alerta = $negativa ? $negativa + ['investido_liquido' => round($totInv['liquido'], 2)] : null;
    return [
        'saldo_atual' => round(saldo_total_contas(), 2), 'investido_atual' => round($totInv['bruto'], 2), 'investido_liquido_atual' => round($totInv['liquido'], 2),
        'media_despesas' => round($media_desp, 2), 'media_receitas' => round($media_rec, 2), 'cdi_anual' => round($cdiProj, 4),
        'pct_aporte' => $pct_aporte, 'taxa_aporte' => round($taxaAporte, 4), 'alerta_negativo' => $alerta,
        'indices' => indices_resumo(), 'investimentos' => $porInvest, 'serie' => $serie,
    ];
}
