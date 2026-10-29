<?php
declare(strict_types=1);

/**
 * Testes de regra de negocio contra SQLite em memoria (sem MySQL).
 * Uso: php tools/ci/testes_dominio.php [caminho-da-planilha.xlsx]
 */

putenv('DB_DRIVER=sqlite');
putenv('DB_SQLITE=:memory:');

require __DIR__ . '/../../sistema/bootstrap.php';

require __DIR__ . '/sqlite_schema.php';
$sql = schema_sqlite();
db()->exec($sql);

$falhas = 0;
function ok(bool $cond, string $msg): void
{
    global $falhas;
    echo ($cond ? '  [ok] ' : '  [FALHA] ') . $msg . "\n";
    if (!$cond) {
        $falhas++;
    }
}
function igual($a, $b, string $msg): void
{
    ok(abs((float) $a - (float) $b) < 0.005, $msg . " (esperado $b, obtido $a)");
}

$uid = q_insert('INSERT INTO usuarios (nome, email, senha_hash) VALUES (?,?,?)', ['T', 't@t', 'x']);
$_SESSION['usuario_id'] = $uid;
garantir_perfil();
echo "== cadastros\n";
ok(count(categorias_do_perfil('despesa')) >= 5, 'categorias padrão criadas');

$itau = q_insert('INSERT INTO contas (perfil_id, nome, tipo, saldo_inicial) VALUES (?,?,?,?)', [perfil_id(), 'Itaú', 'corrente', 1000]);
$nu = q_insert('INSERT INTO contas (perfil_id, nome, tipo, saldo_inicial) VALUES (?,?,?,?)', [perfil_id(), 'Nubank', 'corrente', 0]);
$cartao = q_insert('INSERT INTO cartoes (perfil_id, nome, conta_pagamento_id, limite, dia_fechamento, dia_vencimento) VALUES (?,?,?,?,?,?)', [perfil_id(), 'Visa', $itau, 5000, 25, 5]);
$cartaoRow = q_one('SELECT * FROM cartoes WHERE id = ?', [$cartao]);

echo "== fatura do cartao (fecha 25, vence 5)\n";
ok(fatura_mes_da_compra($cartaoRow, '2026-03-10') === '2026-04', 'compra antes do fechamento vence no mês seguinte');
ok(fatura_mes_da_compra($cartaoRow, '2026-03-26') === '2026-05', 'compra após fechamento vai para a fatura seguinte');
$c2 = ['dia_fechamento' => 5, 'dia_vencimento' => 15];
ok(fatura_mes_da_compra($c2, '2026-03-04') === '2026-03', 'vencimento depois do fechamento fica no mesmo mês');

echo "== lancamentos\n";
igual(criar_lancamento(['tipo' => 'receita', 'descricao' => 'Salário', 'valor' => '5.000,00', 'data' => '05/11/2026', 'efetivado' => 1, 'conta_id' => $itau]), 1, 'receita criada');
igual(saldo_total_contas(), 6000, 'saldo = inicial + receita efetivada');
criar_lancamento(['tipo' => 'despesa', 'descricao' => 'Notebook', 'valor' => '1000', 'data' => '2026-11-01', 'conta_id' => $itau, 'repeticao' => 'parcelado', 'qtd' => 3]);
igual(q_val("SELECT COUNT(*) FROM lancamentos WHERE descricao LIKE 'Notebook (%/3)'"), 3, '3 parcelas geradas');
igual(q_val("SELECT SUM(valor) FROM lancamentos WHERE descricao LIKE 'Notebook%'"), 1000, 'parcelas somam o total');
igual(q_val("SELECT valor FROM lancamentos WHERE descricao = 'Notebook (1/3)'"), 333.34, 'resto de centavos vai na 1ª parcela');
criar_lancamento(['tipo' => 'transferencia', 'descricao' => 'Reserva', 'valor' => '200', 'data' => '2026-11-02', 'efetivado' => 1, 'conta_id' => $itau, 'conta_destino_id' => $nu]);
igual(saldo_total_contas(), 6000, 'transferência não altera saldo total');
igual(saldos_por_conta()[$nu], 200, 'transferência credita conta destino');
try {
    criar_lancamento(['tipo' => 'despesa', 'descricao' => 'x', 'valor' => 10, 'data' => '2026-11-01', 'conta_id' => 99999]);
    ok(false, 'conta de outro perfil deve ser rejeitada');
} catch (InvalidArgumentException $e) {
    ok(true, 'conta inexistente/de outro perfil rejeitada');
}

echo "== cartao e pagamento de fatura\n";
criar_lancamento(['tipo' => 'despesa', 'descricao' => 'Mercado', 'valor' => '300', 'data' => '2026-11-10', 'cartao_id' => $cartao]);
criar_lancamento(['tipo' => 'despesa', 'descricao' => 'Farmácia', 'valor' => '100', 'data' => '2026-11-12', 'cartao_id' => $cartao]);
igual(fatura_total($cartao, '2026-12'), 400, 'fatura de dez soma compras de nov');
igual(saldo_total_contas(), 6000, 'compra no cartão não mexe no saldo');
igual(limite_utilizado($cartaoRow), 400, 'limite utilizado');
pagar_fatura($cartao, '2026-12', $itau, '2026-12-05');
igual(saldos_por_conta()[$itau], 6000 - 200 - 400, 'pagamento debita a conta');
igual(limite_utilizado($cartaoRow), 0, 'limite liberado após pagar');
reabrir_fatura($cartao, '2026-12');
igual(saldos_por_conta()[$itau], 6000 - 200, 'reabrir fatura devolve o saldo');

echo "== importacao da planilha modelo\n";
$arq = $argv[1] ?? __DIR__ . '/../../RELATORIO_TRANSACOES_47bb7992-f61e-478b-8a22-db3050e67e53.xlsx';
if (is_file($arq)) {
    $r = importar_ler_arquivo($arq, basename($arq));
    ok(count($r['linhas']) > 50, 'linhas lidas: ' . count($r['linhas']));
    $tipos = array_count_values(array_column($r['linhas'], 'tipo'));
    echo '  tipos: ' . json_encode($tipos) . "\n";
    ok(isset($tipos['transferencia']), 'aba de transferências lida');
    $an = importar_analisar($r['linhas']);
    echo '  contas no arquivo: ' . implode(', ', array_map(fn($c) => $c['nome'] . ($c['sugestao'] ? '✓' : '?'), $an['contas'])) . "\n";
    $mapC = [];
    foreach ($an['contas'] as $k => $c) {
        $mapC[$k] = $c['sugestao'] ?: 'novo';
    }
    $mapK = [];
    foreach ($an['categorias'] as $tipo => $lista) {
        foreach ($lista as $k => $c) {
            $mapK["$tipo|$k"] = $c['sugestao'] ?: 'novo';
        }
    }
    $antes = (int) q_val('SELECT COUNT(*) FROM lancamentos');
    $st = importar_aplicar($r['linhas'], $mapC, $mapK, basename($arq));
    echo '  resultado: ' . json_encode($st) . "\n";
    ok($st['importados'] > 50, 'lançamentos importados');
    igual((int) q_val('SELECT COUNT(*) FROM lancamentos') - $antes, $st['importados'], 'contagem no banco confere');
    $st2 = importar_aplicar($r['linhas'], $mapC, $mapK, basename($arq));
    igual($st2['importados'], 0, 'reimportar não duplica');
    ok($st2['duplicados'] === $st['importados'], 'reimportação detecta duplicados');
    ok((int) q_val('SELECT COUNT(*) FROM lancamentos WHERE parcela_total IS NOT NULL AND importacao_id IS NOT NULL') > 5, 'parcelas "(n/m)" reconhecidas');
}

echo "== projecao\n";
$p = projetar(12, true);
ok(count($p['serie']) === 12, '12 meses projetados');
ok($p['serie'][0]['mes'] === date('Y-m'), 'começa no mês atual');
echo '  saldo atual ' . $p['saldo_atual'] . ' | final ' . end($p['serie'])['saldo'] . ' | média desp ' . $p['media_despesas'] . "\n";
$p2 = projetar(6, false, 500);
ok($p2['serie'][5]['investido'] > 0, 'aporte mensal acumula no patrimônio investido');

echo "== dashboard\n";
$rm = resumo_mes('2026-11');
ok($rm['rec'] > 0, 'resumo do mês com receitas');
ok(count(despesas_por_categoria('2026-11')) > 0, 'despesas por categoria');

echo "\n" . ($falhas ? "$falhas FALHA(S)\n" : "TODOS OS TESTES PASSARAM\n");
exit($falhas ? 1 : 0);
