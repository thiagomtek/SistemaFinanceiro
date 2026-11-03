<?php
declare(strict_types=1);

/* CadastroService: Contas, cartoes, categorias do perfil e saldos por conta. */

function contas_do_perfil(bool $so_ativas = false): array
{
    return q_all('SELECT * FROM contas WHERE perfil_id = ?' . ($so_ativas ? ' AND ativa = 1' : '') . ' ORDER BY nome', [perfil_id()]);
}

function cartoes_do_perfil(bool $so_ativos = false): array
{
    return q_all('SELECT * FROM cartoes WHERE perfil_id = ?' . ($so_ativos ? ' AND ativo = 1' : '') . ' ORDER BY nome', [perfil_id()]);
}

function categorias_do_perfil(?string $tipo = null): array
{
    $sql = 'SELECT c.*, p.nome AS pai_nome FROM categorias c LEFT JOIN categorias p ON p.id = c.pai_id WHERE c.perfil_id = ?';
    $par = [perfil_id()];
    if ($tipo) {
        $sql .= ' AND c.tipo = ?';
        $par[] = $tipo;
    }
    return q_all($sql . ' ORDER BY c.tipo, COALESCE(p.nome, c.nome), c.pai_id IS NOT NULL, c.nome', $par);
}

/** Teto de itens por perfil (evita que um usuario esgote disco e CPU criando registros sem fim). */
const CADASTRO_LIMITES = ['contas' => 50, 'cartoes' => 40, 'categorias' => 300, 'investimentos' => 60];

/** @throws InvalidArgumentException quando o perfil ja tem o maximo de itens da tabela */
function limite_entidade_exigir(string $tabela, string $rotulo): void
{
    $max = CADASTRO_LIMITES[$tabela] ?? 0;
    if ($max > 0 && (int) q_val("SELECT COUNT(*) FROM $tabela WHERE perfil_id = ?", [perfil_id()]) >= $max) {
        throw new InvalidArgumentException('Limite de ' . $max . ' ' . $rotulo . ' atingido. Exclua ou inative algum antes de criar outro.');
    }
}

function pertence_ao_perfil(string $tabela, ?int $id): bool
{
    if (!$id) {
        return false;
    }
    if (!in_array($tabela, ['contas', 'cartoes', 'categorias', 'investimentos'], true)) {
        return false;
    }
    return (bool) q_val("SELECT id FROM $tabela WHERE id = ? AND perfil_id = ?", [$id, perfil_id()]);
}

/**
 * Efeito de um grupo de lancamentos (colunas tipo, conta_id, conta_destino_id, sentido_invest e total) nas contas: [conta_id => +/-].
 * Fonte unica da regra de sinal: receita e resgate de investimento creditam a conta; despesa, aplicacao e a origem da
 * transferencia debitam; o destino da transferencia credita.
 * @return array<int,float>
 */
function lancamento_efeito_contas(array $r): array
{
    $v = (float) $r['total'];
    $c = (int) $r['conta_id'];
    if ($r['tipo'] === 'receita' || ($r['sentido_invest'] ?? '') === 'resgate') {
        return [$c => $v];
    }
    if ($r['tipo'] === 'despesa') {
        return [$c => -$v];
    }
    $ef = [$c => -$v];
    if (!empty($r['conta_destino_id'])) {
        $d = (int) $r['conta_destino_id'];
        $ef[$d] = ($ef[$d] ?? 0.0) + $v;
    }
    return $ef;
}

/** Soma o efeito de lancamentos agrupados em $saldos (conta_id => saldo). */
function aplicar_efeito_contas(array &$saldos, array $linhas): void
{
    foreach ($linhas as $l) {
        foreach (lancamento_efeito_contas($l) as $conta => $delta) {
            $saldos[$conta] = ($saldos[$conta] ?? 0.0) + $delta;
        }
    }
}

/** Saldo atual (so efetivados) por conta: [conta_id => saldo]. */
function saldos_por_conta(): array
{
    $pid = perfil_id();
    $saldos = [];
    foreach (q_all('SELECT id, saldo_inicial FROM contas WHERE perfil_id = ?', [$pid]) as $c) {
        $saldos[(int) $c['id']] = (float) $c['saldo_inicial'];
    }
    aplicar_efeito_contas($saldos, q_all(
        'SELECT tipo, conta_id, conta_destino_id, sentido_invest, SUM(valor) AS total FROM lancamentos
         WHERE perfil_id = ? AND efetivado = 1 AND conta_id IS NOT NULL GROUP BY tipo, conta_id, conta_destino_id, sentido_invest',
        [$pid]
    ));
    return $saldos;
}

function saldo_total_contas(): float
{
    $ativas = array_column(contas_do_perfil(true), 'id');
    $total = 0.0;
    foreach (saldos_por_conta() as $id => $s) {
        if (in_array($id, array_map('intval', $ativas), true)) {
            $total += $s;
        }
    }
    return $total;
}

/**
 * Saldo previsto por conta (RN-02): saldo atual + lancamentos pendentes das contas + faturas de cartao em aberto
 * (debitadas da conta pagadora) com vencimento ate $ate (padrao: ultimo dia do mes corrente). Atrasados entram.
 * @return array<int,float> conta_id => saldo previsto
 */
function saldos_previstos_por_conta(?string $ate = null): array
{
    $ate = $ate ?: date('Y-m-t');
    $pid = perfil_id();
    $prev = saldos_por_conta();
    aplicar_efeito_contas($prev, q_all(
        'SELECT tipo, conta_id, conta_destino_id, sentido_invest, SUM(valor) AS total FROM lancamentos
         WHERE perfil_id = ? AND efetivado = 0 AND cartao_id IS NULL AND conta_id IS NOT NULL AND data <= ?
         GROUP BY tipo, conta_id, conta_destino_id, sentido_invest',
        [$pid, $ate]
    ));
    foreach (cartoes_do_perfil() as $cartao) {
        if (!$cartao['conta_pagamento_id']) {
            continue;
        }
        foreach (faturas_do_cartao($cartao) as $f) {
            if (!$f['paga'] && $f['vencimento'] <= $ate) {
                $c = (int) $cartao['conta_pagamento_id'];
                $prev[$c] = ($prev[$c] ?? 0) - $f['total'];
            }
        }
    }
    return $prev;
}

function saldo_total_previsto(?string $ate = null): float
{
    $ativas = array_map('intval', array_column(contas_do_perfil(true), 'id'));
    $total = 0.0;
    foreach (saldos_previstos_por_conta($ate) as $id => $s) {
        if (in_array($id, $ativas, true)) {
            $total += $s;
        }
    }
    return $total;
}

// ------------------------------------------------------ categorias de sistema

const CATEGORIAS_SISTEMA = [
    ['Reajuste', 'receita', '#0ea5e9'],
    ['Reajuste', 'despesa', '#0ea5e9'],
    ['Reajuste de fatura', 'despesa', '#f59e0b'],
];

/** Garante as categorias de sistema do perfil (nao editaveis). Usadas pelos reajustes de saldo e de fatura. */
function garantir_categorias_sistema(int $pid): void
{
    foreach (CATEGORIAS_SISTEMA as list($nome, $tipo, $cor)) {
        $id = q_val('SELECT id FROM categorias WHERE perfil_id = ? AND tipo = ? AND nome = ? AND pai_id IS NULL', [$pid, $tipo, $nome]);
        if ($id) {
            q('UPDATE categorias SET sistema = 1 WHERE id = ? AND sistema = 0', [$id]);
        } else {
            q_insert('INSERT INTO categorias (perfil_id, nome, tipo, cor, sistema) VALUES (?,?,?,?,1)', [$pid, $nome, $tipo, $cor]);
        }
    }
}

function categoria_sistema_id(string $nome, string $tipo): int
{
    garantir_categorias_sistema(perfil_id());
    return (int) q_val('SELECT id FROM categorias WHERE perfil_id = ? AND tipo = ? AND nome = ? AND pai_id IS NULL', [perfil_id(), $tipo, $nome]);
}
