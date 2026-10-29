<?php
declare(strict_types=1);

/* CartaoService: Faturas de cartao: competencia, total, pagamento e reabertura. */

/** Mes (YYYY-MM) do vencimento da fatura em que uma compra feita em $data cai. */
function fatura_mes_da_compra(array $cartao, string $data): string
{
    $fech = (int) $cartao['dia_fechamento'];
    $venc = (int) $cartao['dia_vencimento'];
    $d = new DateTimeImmutable($data);
    $mesFechamento = (int) $d->format('j') > $fech ? add_meses($d->format('Y-m-01'), 1) : $d->format('Y-m-01');
    $mesVenc = $venc > $fech ? $mesFechamento : add_meses($mesFechamento, 1);
    return substr($mesVenc, 0, 7);
}

function fatura_vencimento(array $cartao, string $ym): string
{
    $ultimo = (int) date('t', strtotime($ym . '-01'));
    return sprintf('%s-%02d', $ym, min((int) $cartao['dia_vencimento'], $ultimo));
}

/** Data de fechamento da fatura que vence em $ym (YYYY-MM). */
function fatura_fechamento(array $cartao, string $ym): string
{
    $fech = (int) $cartao['dia_fechamento'];
    $mesFech = (int) $cartao['dia_vencimento'] > $fech ? $ym : substr(add_meses($ym . '-01', -1), 0, 7);
    $ultimo = (int) date('t', strtotime($mesFech . '-01'));
    return sprintf('%s-%02d', $mesFech, min($fech, $ultimo));
}

/** Status (RN-08): paga | vencida | fechada | aberta. */
function fatura_status(array $cartao, string $ym, bool $paga, ?string $hoje = null): string
{
    $hoje = $hoje ?: date('Y-m-d');
    if ($paga) {
        return 'paga';
    }
    if ($hoje > fatura_vencimento($cartao, $ym)) {
        return 'vencida';
    }
    return $hoje > fatura_fechamento($cartao, $ym) ? 'fechada' : 'aberta';
}

/** Fatura em destaque no cartao: a mais antiga ainda nao paga e com valor. */
function fatura_corrente(array $cartao): ?array
{
    foreach (faturas_do_cartao($cartao) as $f) {
        if (!$f['paga'] && abs($f['total']) > 0.004) {
            return $f;
        }
    }
    return null;
}

function total_faturas_do_mes(string $ym): float
{
    return (float) q_val(
        'SELECT COALESCE(SUM(valor),0) FROM lancamentos WHERE perfil_id = ? AND cartao_id IS NOT NULL AND tipo = ? AND fatura_mes = ?',
        [perfil_id(), 'despesa', $ym]
    );
}

function fatura_total(int $cartao_id, string $ym): float
{
    return (float) q_val(
        'SELECT COALESCE(SUM(valor),0) FROM lancamentos WHERE perfil_id = ? AND cartao_id = ? AND fatura_mes = ? AND tipo = ?',
        [perfil_id(), $cartao_id, $ym, 'despesa']
    );
}

/**
 * Filtro SQL que deixa de fora o "Saldo restante" de um pagamento parcial de fatura. Esse item so move para a fatura seguinte
 * o que ja foi contado nas compras originais: somar de novo duplicaria a despesa em totais, categorias e medias.
 * Faturas e saldos continuam contando o item (ele e uma cobranca real da proxima fatura).
 */
function sql_sem_restante(string $alias = ''): string
{
    $c = $alias === '' ? 'ajuste' : $alias . '.ajuste';
    return "($c IS NULL OR $c <> 'restante')";
}

/** Faturas (abertas e pagas) de um cartao, da mais antiga para a mais recente. */
function faturas_do_cartao(array $cartao): array
{
    $meses = q_all(
        'SELECT fatura_mes, SUM(valor) AS total, COUNT(*) AS qtd FROM lancamentos
         WHERE perfil_id = ? AND cartao_id = ? AND fatura_mes IS NOT NULL AND tipo = ? GROUP BY fatura_mes ORDER BY fatura_mes',
        [perfil_id(), $cartao['id'], 'despesa']
    );
    $pagas = [];
    foreach (q_all('SELECT * FROM faturas WHERE cartao_id = ?', [$cartao['id']]) as $f) {
        $pagas[$f['mes']] = $f;
    }
    $out = [];
    foreach ($meses as $m) {
        $out[] = [
            'mes' => $m['fatura_mes'], 'total' => (float) $m['total'], 'qtd' => (int) $m['qtd'],
            'vencimento' => fatura_vencimento($cartao, $m['fatura_mes']),
            'fechamento' => fatura_fechamento($cartao, $m['fatura_mes']),
            'paga' => isset($pagas[$m['fatura_mes']]), 'pagamento' => $pagas[$m['fatura_mes']] ?? null,
            'parcial' => isset($pagas[$m['fatura_mes']]) && (float) $pagas[$m['fatura_mes']]['valor_pago'] < (float) $m['total'] - 0.005,
        ];
        $ult = count($out) - 1;
        $out[$ult]['status'] = fatura_status($cartao, $m['fatura_mes'], $out[$ult]['paga']);
    }
    return $out;
}

function limite_utilizado(array $cartao): float
{
    $abertas = 0.0;
    foreach (faturas_do_cartao($cartao) as $f) {
        if (!$f['paga']) {
            $abertas += $f['total'];
        }
    }
    return $abertas;
}

/**
 * Paga a fatura $ym. $valor = null paga o total. Valor MENOR (ex.: o minimo) quita a fatura e o que sobrou vai para a
 * proxima fatura como "Saldo restante" (sem juros calculados: o banco cobra os encargos na proxima fatura; ajuste-a se precisar).
 * Valor maior que o total e recusado.
 */
function pagar_fatura(int $cartao_id, string $ym, int $conta_id, string $data, $valor = null): void
{
    $cartao = q_one('SELECT * FROM cartoes WHERE id = ? AND perfil_id = ?', [$cartao_id, perfil_id()]);
    if (!$cartao || !pertence_ao_perfil('contas', $conta_id) || mes_valido($ym, '') === '') {
        throw new InvalidArgumentException('Cartão, conta ou fatura inválidos.');
    }
    if (q_val('SELECT id FROM faturas WHERE cartao_id = ? AND mes = ?', [$cartao_id, $ym])) {
        throw new InvalidArgumentException('Esta fatura já está paga.');
    }
    $total = round(fatura_total($cartao_id, $ym), 2);
    if ($total <= 0) {
        throw new InvalidArgumentException('Fatura sem valor a pagar.');
    }
    $pago = ($valor === null || $valor === '') ? $total : round(parse_valor($valor), 2);
    if ($pago <= 0) {
        throw new InvalidArgumentException('Informe um valor de pagamento maior que zero.');
    }
    if ($pago > $total + 0.005) {
        throw new InvalidArgumentException('O valor (' . brl($pago) . ') é maior que a fatura (' . brl($total) . '). Para antecipar a próxima fatura, pague-a depois que ela existir.');
    }
    $restante = round($total - $pago, 2);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $fid = q_insert('INSERT INTO faturas (cartao_id, mes, valor_pago, data_pagamento, conta_id) VALUES (?,?,?,?,?)', [$cartao_id, $ym, $pago, $data, $conta_id]);
        q_insert(
            'INSERT INTO lancamentos (perfil_id, tipo, descricao, valor, data, efetivado, conta_id, fatura_id) VALUES (?,?,?,?,?,?,?,?)',
            [perfil_id(), 'despesa', 'Fatura ' . $cartao['nome'] . ' ' . mes_label($ym) . ($restante > 0.004 ? ' (pagamento parcial)' : ''), $pago, $data, 1, $conta_id, $fid]
        );
        if ($restante > 0.004) {
            $prox = substr(add_meses($ym . '-01', 1), 0, 7);
            q_insert(
                'INSERT INTO lancamentos (perfil_id, tipo, descricao, valor, data, efetivado, cartao_id, fatura_mes, ajuste, observacao) VALUES (?,?,?,?,?,?,?,?,?,?)',
                [perfil_id(), 'despesa', 'Saldo restante da fatura ' . mes_label($ym), $restante, fatura_vencimento($cartao, $ym), 0, $cartao_id, $prox, 'restante', 'restante:' . $ym]
            );
        }
        $pdo->commit();
    } catch (Throwable $t) {
        $pdo->rollBack();
        throw $t;
    }
}

function reabrir_fatura(int $cartao_id, string $ym): void
{
    $f = q_one(
        'SELECT f.id FROM faturas f JOIN cartoes c ON c.id = f.cartao_id WHERE f.cartao_id = ? AND f.mes = ? AND c.perfil_id = ?',
        [$cartao_id, $ym, perfil_id()]
    );
    if (!$f) {
        return;
    }
    q('DELETE FROM lancamentos WHERE fatura_id = ? AND perfil_id = ?', [$f['id'], perfil_id()]);
    q("DELETE FROM lancamentos WHERE perfil_id = ? AND cartao_id = ? AND ajuste = 'restante' AND observacao = ?", [perfil_id(), $cartao_id, 'restante:' . $ym]);
    q('DELETE FROM faturas WHERE id = ?', [$f['id']]);
}

/** Rotulo e classe de badge para o status da fatura. @return array{0: string, 1: string} */
function fatura_status_visual(string $status): array
{
    $mapa = [
        'paga' => ['Paga', 'success'], 'vencida' => ['Vencida', 'danger'],
        'fechada' => ['Fechada', 'warning'], 'aberta' => ['Aberta', 'info'],
    ];
    return $mapa[$status] ?? [$status, 'secondary'];
}

/** Cartao com historico: ja tem compra com data ate hoje ou fatura paga. Nao pode ser excluido, so inativado. */
function cartao_tem_historico(int $cartaoId): bool
{
    return (bool) q_val('SELECT COUNT(*) FROM lancamentos WHERE cartao_id = ? AND perfil_id = ? AND data <= ?', [$cartaoId, perfil_id(), date('Y-m-d')])
        || (bool) q_val('SELECT COUNT(*) FROM faturas WHERE cartao_id = ?', [$cartaoId]);
}

/** Itens de fatura ainda a vencer (fatura nao paga com vencimento depois de hoje). @return array{ids: int[], total: float} */
function cartao_itens_a_vencer(int $cartaoId): array
{
    $c = q_one('SELECT * FROM cartoes WHERE id = ? AND perfil_id = ?', [$cartaoId, perfil_id()]);
    if (!$c) {
        return ['ids' => [], 'total' => 0.0];
    }
    $hoje = date('Y-m-d');
    $pagas = array_column(q_all('SELECT mes FROM faturas WHERE cartao_id = ?', [$cartaoId]), 'mes');
    $ids = [];
    $total = 0.0;
    foreach (q_all('SELECT id, valor, fatura_mes FROM lancamentos WHERE cartao_id = ? AND perfil_id = ? AND fatura_id IS NULL AND fatura_mes IS NOT NULL', [$cartaoId, perfil_id()]) as $l) {
        if (!in_array($l['fatura_mes'], $pagas, true) && fatura_vencimento($c, $l['fatura_mes']) > $hoje) {
            $ids[] = (int) $l['id'];
            $total += (float) $l['valor'];
        }
    }
    return ['ids' => $ids, 'total' => round($total, 2)];
}

/** Inativa o cartao e exclui os itens ainda a vencer. @return int itens excluidos */
function cartao_inativar(int $cartaoId): int
{
    $it = cartao_itens_a_vencer($cartaoId);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        if ($it['ids']) {
            anexos_remover_arquivos($it['ids']);
            q('DELETE FROM lancamentos WHERE perfil_id = ? AND id IN (' . implode(',', array_fill(0, count($it['ids']), '?')) . ')', array_merge([perfil_id()], $it['ids']));
        }
        q('UPDATE cartoes SET ativo = 0 WHERE id = ? AND perfil_id = ?', [$cartaoId, perfil_id()]);
        $pdo->commit();
    } catch (Throwable $t) {
        $pdo->rollBack();
        throw $t;
    }
    return count($it['ids']);
}
