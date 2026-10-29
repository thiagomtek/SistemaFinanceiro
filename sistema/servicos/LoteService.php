<?php
declare(strict_types=1);

/* LoteService: acoes em lote sobre lancamentos e saldo previsto do dia (tela de transacoes). */


/**
 * Aplica uma operacao a varios lancamentos do perfil. Retorna quantos foram alterados.
 * ops: efetivar | pendente | categoria (valor = id ou vazio) | conta (valor = id) | excluir
 * Pagamentos de fatura nunca sao alterados em lote; itens de cartao nao tem situacao nem conta.
 */
function lote_aplicar(array $ids, string $op, $valor = null): int
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) {
        throw new InvalidArgumentException('Selecione ao menos um lançamento.');
    }
    $pid = perfil_id();
    $marcas = implode(',', array_fill(0, count($ids), '?'));
    $base = [$pid];
    $n = 0;
    switch ($op) {
        case 'efetivar':
        case 'pendente':
            $n = q("UPDATE lancamentos SET efetivado = ? WHERE perfil_id = ? AND id IN ($marcas) AND cartao_id IS NULL AND fatura_id IS NULL", array_merge([$op === 'efetivar' ? 1 : 0], $base, $ids))->rowCount();
            break;
        case 'categoria':
            $cat = (int) $valor ?: null;
            $tipoCat = $cat ? q_val('SELECT tipo FROM categorias WHERE id = ? AND perfil_id = ?', [$cat, $pid]) : null;
            if ($cat && !$tipoCat) {
                throw new InvalidArgumentException('Categoria inválida.');
            }
            // categoria de receita so vai para receitas e a de despesa so para despesas (como no cadastro do lancamento)
            $n = q("UPDATE lancamentos SET categoria_id = ? WHERE perfil_id = ? AND id IN ($marcas) AND tipo <> 'transferencia' AND fatura_id IS NULL"
                . ($tipoCat ? ' AND tipo = ?' : ''), array_merge([$cat], $base, $ids, $tipoCat ? [$tipoCat] : []))->rowCount();
            break;
        case 'conta':
            $conta = (int) $valor;
            if (!pertence_ao_perfil('contas', $conta)) {
                throw new InvalidArgumentException('Conta inválida.');
            }
            // transferencias: so quando a nova conta difere do destino
            $n = q("UPDATE lancamentos SET conta_id = ? WHERE perfil_id = ? AND id IN ($marcas) AND cartao_id IS NULL AND fatura_id IS NULL AND (conta_destino_id IS NULL OR conta_destino_id <> ?)", array_merge([$conta], $base, $ids, [$conta]))->rowCount();
            break;
        case 'excluir':
            $alvo = array_column(q_all("SELECT id FROM lancamentos WHERE perfil_id = ? AND id IN ($marcas) AND fatura_id IS NULL", array_merge($base, $ids)), 'id');
            anexos_remover_arquivos($alvo);
            $fimSeries = series_fixas_fim($alvo);
            $n = $alvo ? q("DELETE FROM lancamentos WHERE perfil_id = ? AND id IN ($marcas) AND fatura_id IS NULL", array_merge($base, $ids))->rowCount() : 0;
            series_fixas_encerrar_se_cortadas($fimSeries); // sem isso as ocorrencias do fim da serie voltariam sozinhas
            break;
        default:
            throw new InvalidArgumentException('Ação em lote inválida.');
    }
    return $n;
}

// ------------------------------------------------------------ saldo previsto do dia

/**
 * Saldo previsto ao final de cada dia com movimento no periodo [$ini, $fim] (RN-03): saldo inicial das contas
 * + todos os lancamentos (efetivados ou pendentes) ate o dia + faturas de cartao em aberto no vencimento.
 * $contaId limita a uma conta; senao soma as contas ativas.
 * @return array<string,float> data => saldo
 */
function saldo_previsto_por_dia(string $ini, string $fim, ?int $contaId = null): array
{
    $pid = perfil_id();
    $conjunto = $contaId ? [$contaId] : array_map('intval', array_column(contas_do_perfil(true), 'id'));
    if (!$conjunto) {
        return [];
    }
    $em = array_flip($conjunto);
    $saldo = 0.0;
    foreach (contas_do_perfil() as $c) {
        if (isset($em[(int) $c['id']])) {
            $saldo += (float) $c['saldo_inicial'];
        }
    }
    // so as contas do conjunto: a regra de sinal vem de lancamento_efeito_contas (CadastroService)
    $delta = function (array $r) use ($em): float {
        return (float) array_sum(array_intersect_key(lancamento_efeito_contas($r), $em));
    };
    foreach (q_all(
        'SELECT tipo, conta_id, conta_destino_id, sentido_invest, SUM(valor) AS total FROM lancamentos
         WHERE perfil_id = ? AND cartao_id IS NULL AND conta_id IS NOT NULL AND data < ? GROUP BY tipo, conta_id, conta_destino_id, sentido_invest',
        [$pid, $ini]
    ) as $r) {
        $saldo += $delta($r);
    }
    $faturas = [];
    foreach (cartoes_do_perfil() as $cartao) {
        $pag = (int) $cartao['conta_pagamento_id'];
        if (!$pag || !isset($em[$pag])) {
            continue;
        }
        foreach (faturas_do_cartao($cartao) as $f) {
            if ($f['paga']) {
                continue;
            }
            if ($f['vencimento'] < $ini) {
                $saldo -= $f['total'];
            } elseif ($f['vencimento'] <= $fim) {
                $faturas[$f['vencimento']] = ($faturas[$f['vencimento']] ?? 0.0) + $f['total'];
            }
        }
    }
    $mov = [];
    foreach (q_all(
        'SELECT data, tipo, conta_id, conta_destino_id, sentido_invest, SUM(valor) AS total FROM lancamentos
         WHERE perfil_id = ? AND cartao_id IS NULL AND conta_id IS NOT NULL AND data >= ? AND data <= ?
         GROUP BY data, tipo, conta_id, conta_destino_id, sentido_invest',
        [$pid, $ini, $fim]
    ) as $r) {
        $mov[$r['data']] = ($mov[$r['data']] ?? 0.0) + $delta($r);
    }
    foreach ($faturas as $d => $v) {
        $mov[$d] = ($mov[$d] ?? 0.0) - $v;
    }
    ksort($mov);
    $out = [];
    foreach ($mov as $d => $v) {
        $saldo += $v;
        $out[$d] = round($saldo, 2);
    }
    return $out;
}

/**
 * Saldo dos investimentos ativos em cada data ($datas, AAAA-MM-DD): bruto (como o banco mostra) e liquido de IR/IOF
 * (se resgatasse tudo no dia). Datas futuras usam o CDI atual e as aplicacoes/resgates ja agendados.
 * @return array<string, array{bruto: float, liquido: float}>
 */
function saldo_investimentos_por_dia(array $datas): array
{
    $datas = array_values(array_unique($datas));
    $out = [];
    foreach ($datas as $d) {
        $out[$d] = ['bruto' => 0.0, 'liquido' => 0.0];
    }
    if (!$datas) {
        return $out;
    }
    indices_garantir();
    foreach (q_all('SELECT * FROM investimentos WHERE perfil_id = ? AND ativo = 1', [perfil_id()]) as $inv) {
        foreach (rf_serie($inv, rf_movs((int) $inv['id']), $datas) as $d => $x) {
            $out[$d]['bruto'] += $x['bruto'];
            $out[$d]['liquido'] += $x['liquido'];
        }
    }
    foreach ($out as $d => $x) {
        $out[$d] = ['bruto' => round($x['bruto'], 2), 'liquido' => round($x['liquido'], 2)];
    }
    return $out;
}
