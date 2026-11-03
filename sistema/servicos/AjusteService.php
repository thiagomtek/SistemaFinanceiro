<?php
declare(strict_types=1);

/* AjusteService: reajuste de saldo de conta e reajuste de fatura de cartao (regras RN-04, RN-05, RN-11, RN-12). */

/**
 * Reajusta o saldo de uma conta para $novo_saldo.
 * modo "transacao": cria receita/despesa efetivada de ajuste (categoria Reajuste) com a diferenca.
 * modo "saldo_inicial": altera o saldo inicial da conta pela diferenca (nao cria transacao).
 * @return array{diferenca: float, modo: string, anterior: float}
 */
function reajustar_saldo(int $conta_id, $novo_saldo, string $modo = 'transacao', string $descricao = '', ?string $data = null): array
{
    if (!pertence_ao_perfil('contas', $conta_id)) {
        throw new InvalidArgumentException('Conta inválida.');
    }
    if (!in_array($modo, ['transacao', 'saldo_inicial'], true)) {
        throw new InvalidArgumentException('Modo de reajuste inválido.');
    }
    $data = $data ? parse_data($data) : date('Y-m-d');
    if (!$data) {
        throw new InvalidArgumentException('Data inválida.');
    }
    $novo = round(parse_valor($novo_saldo), 2);
    $atual = round(saldos_por_conta()[$conta_id] ?? 0.0, 2);
    $dif = round($novo - $atual, 2);
    if (abs($dif) < 0.005) {
        throw new InvalidArgumentException('O saldo informado já é o saldo atual da conta.');
    }
    if ($modo === 'saldo_inicial') {
        q('UPDATE contas SET saldo_inicial = saldo_inicial + ? WHERE id = ? AND perfil_id = ?', [$dif, $conta_id, perfil_id()]);
    } else {
        $tipo = $dif > 0 ? 'receita' : 'despesa';
        q_insert(
            'INSERT INTO lancamentos (perfil_id, tipo, descricao, valor, data, efetivado, conta_id, categoria_id, ajuste)
             VALUES (?,?,?,?,?,?,?,?,?)',
            [perfil_id(), $tipo, trim($descricao) !== '' ? mb_substr(trim($descricao), 0, 160) : 'Reajuste*', abs($dif), $data, 1, $conta_id,
                categoria_sistema_id('Reajuste', $tipo), 'saldo']
        );
    }
    return ['diferenca' => $dif, 'modo' => $modo, 'anterior' => $atual];
}

/**
 * Ajusta a fatura $ym do cartao para $valor_final criando/atualizando UM item "Reajuste de fatura" (RN-11).
 * Pode ficar negativo quando o valor e menor que a soma dos demais itens (RN-12: permitido, com aviso).
 * @return array{ajuste: float, outros: float, negativo: bool}
 */
function ajustar_fatura(int $cartao_id, string $ym, $valor_final): array
{
    $cartao = q_one('SELECT * FROM cartoes WHERE id = ? AND perfil_id = ?', [$cartao_id, perfil_id()]);
    if (!$cartao || mes_valido($ym, '') === '') {
        throw new InvalidArgumentException('Cartão ou fatura inválidos.');
    }
    if (q_val('SELECT id FROM faturas WHERE cartao_id = ? AND mes = ?', [$cartao_id, $ym])) {
        throw new InvalidArgumentException('Esta fatura já está paga. Reabra-a para ajustar.');
    }
    $final = round(parse_valor($valor_final), 2);
    if ($final < 0) {
        throw new InvalidArgumentException('O valor da fatura não pode ser negativo.');
    }
    $outros = round((float) q_val(
        "SELECT COALESCE(SUM(valor),0) FROM lancamentos WHERE perfil_id = ? AND cartao_id = ? AND fatura_mes = ? AND tipo = 'despesa'
         AND (ajuste IS NULL OR ajuste <> 'fatura')",
        [perfil_id(), $cartao_id, $ym]
    ), 2);
    $ajuste = round($final - $outros, 2);
    $existente = q_val("SELECT id FROM lancamentos WHERE perfil_id = ? AND cartao_id = ? AND fatura_mes = ? AND ajuste = 'fatura' ORDER BY id LIMIT 1", [perfil_id(), $cartao_id, $ym]);

    if (abs($ajuste) < 0.005) {
        if ($existente) {
            q('DELETE FROM lancamentos WHERE id = ? AND perfil_id = ?', [$existente, perfil_id()]);
        }
        return ['ajuste' => 0.0, 'outros' => $outros, 'negativo' => false];
    }
    if ($existente) {
        q('UPDATE lancamentos SET valor = ? WHERE id = ? AND perfil_id = ?', [$ajuste, $existente, perfil_id()]);
    } else {
        q_insert(
            'INSERT INTO lancamentos (perfil_id, tipo, descricao, valor, data, efetivado, cartao_id, fatura_mes, categoria_id, ajuste)
             VALUES (?,?,?,?,?,?,?,?,?,?)',
            [perfil_id(), 'despesa', 'Reajuste de fatura', $ajuste, fatura_vencimento($cartao, $ym), 0, $cartao_id, $ym,
                categoria_sistema_id('Reajuste de fatura', 'despesa'), 'fatura']
        );
    }
    return ['ajuste' => $ajuste, 'outros' => $outros, 'negativo' => $ajuste < 0];
}

/**
 * Remove o "Reajuste de fatura" manual das faturas nao pagas que vencem depois de $ym. Quando chega a fatura real do banco, o
 * reajuste que fazia a projecao dos meses seguintes perde o sentido: o que vale e o que o banco informa (RN-39).
 * @return array{qtd: int, total: float}
 */
function remover_reajustes_futuros_fatura(int $cartao_id, string $ym): array
{
    $pagas = array_column(q_all('SELECT mes FROM faturas WHERE cartao_id = ?', [$cartao_id]), 'mes');
    $ids = [];
    $total = 0.0;
    foreach (q_all("SELECT id, valor, fatura_mes FROM lancamentos WHERE perfil_id = ? AND cartao_id = ? AND ajuste = 'fatura' AND fatura_mes > ?", [perfil_id(), $cartao_id, $ym]) as $r) {
        if (!in_array($r['fatura_mes'], $pagas, true)) {
            $ids[] = (int) $r['id'];
            $total += (float) $r['valor'];
        }
    }
    foreach (array_chunk($ids, 400) as $lote) {
        q('DELETE FROM lancamentos WHERE perfil_id = ? AND id IN (' . implode(',', array_fill(0, count($lote), '?')) . ')', array_merge([perfil_id()], $lote));
    }
    return ['qtd' => count($ids), 'total' => round($total, 2)];
}
