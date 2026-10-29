<?php
declare(strict_types=1);

/* DashboardService: Consultas agregadas do dashboard. Despesas excluem o pagamento da fatura (as compras do cartao ja contam)
   e o "Saldo restante" de pagamento parcial (ver sql_sem_restante em CartaoService). */

function resumo_mes(string $ym): array
{
    $ini = $ym . '-01';
    $fim = add_meses($ini, 1);
    $r = q_one(
        "SELECT COALESCE(SUM(CASE WHEN tipo='receita' THEN valor END),0) AS rec,
                COALESCE(SUM(CASE WHEN tipo='receita' AND efetivado=1 THEN valor END),0) AS rec_ef,
                COALESCE(SUM(CASE WHEN tipo='despesa' AND fatura_id IS NULL THEN valor END),0) AS desp,
                COALESCE(SUM(CASE WHEN tipo='despesa' AND fatura_id IS NULL AND (efetivado=1 OR cartao_id IS NOT NULL) THEN valor END),0) AS desp_ef
         FROM lancamentos WHERE perfil_id = ? AND data >= ? AND data < ? AND " . sql_sem_restante(),
        [perfil_id(), $ini, $fim]
    );
    return array_map('floatval', $r);
}

function despesas_por_categoria(string $ym): array
{
    $ini = $ym . '-01';
    return q_all(
        "SELECT COALESCE(p.nome, c.nome, 'Sem categoria') AS nome, COALESCE(p.cor, c.cor, '#94a3b8') AS cor, SUM(l.valor) AS total
         FROM lancamentos l
         LEFT JOIN categorias c ON c.id = l.categoria_id
         LEFT JOIN categorias p ON p.id = c.pai_id
         WHERE l.perfil_id = ? AND l.tipo = 'despesa' AND l.fatura_id IS NULL AND l.data >= ? AND l.data < ? AND " . sql_sem_restante('l') . "
         GROUP BY 1, 2 ORDER BY total DESC",
        [perfil_id(), $ini, add_meses($ini, 1)]
    );
}

function receitas_despesas_meses(int $passados = 6, int $futuros = 0, ?string $base = null): array
{
    $base = mes_valido($base, '') !== '' ? $base . '-01' : date('Y-m-01');
    $ini = add_meses($base, -$passados);
    $fim = add_meses($base, $futuros + 1);
    $mapa = [];
    for ($d = $ini; $d < $fim; $d = add_meses($d, 1)) {
        $mapa[substr($d, 0, 7)] = ['rec' => 0.0, 'desp' => 0.0];
    }
    foreach (q_all(
        'SELECT ' . sql_mes('data') . " AS ym, tipo, SUM(valor) AS t FROM lancamentos
         WHERE perfil_id = ? AND tipo IN ('receita','despesa') AND fatura_id IS NULL AND data >= ? AND data < ? AND " . sql_sem_restante() . ' GROUP BY 1, 2',
        [perfil_id(), $ini, $fim]
    ) as $r) {
        if (isset($mapa[$r['ym']])) {
            $mapa[$r['ym']][$r['tipo'] === 'receita' ? 'rec' : 'desp'] = (float) $r['t'];
        }
    }
    return $mapa;
}
