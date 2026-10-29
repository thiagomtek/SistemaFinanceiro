<?php
declare(strict_types=1);

/**
 * Testes da projecao: a sobra do mes e investida (e rende), mes de deficit sai da conta, e o alerta de conta negativa
 * aponta o DIA exato (nunca o mes inteiro). Nao usa internet. Uso: php tools/ci/testes_projecao.php
 */

putenv('DB_DRIVER=sqlite');
putenv('DB_SQLITE=:memory:');

require __DIR__ . '/../../sistema/bootstrap.php';
require __DIR__ . '/sqlite_schema.php';
db()->exec(schema_sqlite());

$falhas = 0;
function ok(bool $cond, string $msg): void
{
    global $falhas;
    echo ($cond ? '  [ok] ' : '  [FALHA] ') . $msg . "\n";
    if (!$cond) {
        $falhas++;
    }
}
function igual($a, $b, string $msg, float $tol = 0.01): void
{
    ok(abs((float) $a - (float) $b) <= $tol, $msg . " (esperado $b, obtido $a)");
}

$GLOBALS['FIN_BCB_MOCK'] = function (string $serie, string $ini, string $fim): array {
    $out = [];
    for ($t = strtotime($ini); $t <= strtotime($fim); $t += 86400) {
        $d = date('Y-m-d', $t);
        if ($serie === IDX_CDI_DIA && eh_dia_util($d)) {
            $out[] = [$d, 0.05];
        } elseif ($serie === IDX_CDI_ANUAL && eh_dia_util($d)) {
            $out[] = [$d, 13.65];
        } elseif ($serie === IDX_SELIC) {
            $out[] = [$d, 13.75];
        }
    }
    return $out;
};

$uid = q_insert('INSERT INTO usuarios (nome, email, senha_hash) VALUES (?,?,?)', ['T', 't@t', 'x']);
$_SESSION['usuario_id'] = $uid;
garantir_perfil();
$conta = q_insert('INSERT INTO contas (perfil_id, nome, tipo, saldo_inicial) VALUES (?,?,?,?)', [perfil_id(), 'Itaú', 'corrente', 100]);
$dia = function (int $mes, int $d): string { // mes 1 = proximo mes
    return date('Y-m', strtotime('first day of +' . $mes . ' month')) . sprintf('-%02d', $d);
};
$lanca = function (string $tipo, string $desc, float $v, string $data) use ($conta): int {
    return criar_lancamento_ids(['tipo' => $tipo, 'descricao' => $desc, 'valor' => $v, 'data' => $data, 'efetivado' => 0, 'conta_id' => $conta])[0];
};

echo "== a sobra do mes e investida e rende; a conta nao acumula\n";
$lanca('receita', 'Salário', 5000, $dia(1, 10));
$idDesp = $lanca('despesa', 'Aluguel', 2000, $dia(1, 3));
$p = projetar(4, false, 0.0);
$m1 = $p['serie'][1];
igual($m1['resultado'], 3000, 'resultado do mês: 5.000 − 2.000');
igual($m1['aporte_sobra'], 3000, 'a sobra do mês é investida');
igual($m1['saldo'], 100, 'o saldo da conta continua o mesmo (a sobra foi investida)');
igual($m1['investido'], 3000, 'investido = sobra, no fim do mês do aporte');
$fatorMes = pow(1 + $p['taxa_aporte'] / 100, 1 / 12);
igual($p['serie'][2]['investido'], 3000 * $fatorMes, 'um mês depois o aporte rendeu a 100% do CDI', 0.02);
igual($p['serie'][3]['investido'], 3000 * $fatorMes * $fatorMes, 'e continua rendendo no mês seguinte', 0.05);
ok($p['serie'][3]['investido_liquido'] < $p['serie'][3]['investido'], 'líquido de IR/IOF menor que o bruto');
igual($p['taxa_aporte'], rf_taxa_anual_efetiva(['indexador' => 'cdi', 'percentual_cdi' => 100], 13.65), 'taxa do aporte = 100% do CDI de hoje', 0.001);
$p110 = projetar(4, false, 0.0, null, 110.0);
ok($p110['serie'][3]['investido'] > $p['serie'][3]['investido'], 'aportes a 110% do CDI rendem mais que a 100%');

echo "== alerta de conta negativa: so no dia exato\n";
ok($p['alerta_negativo'] !== null && $p['alerta_negativo']['data'] === $dia(1, 3), 'aluguel no dia 3, salário só no dia 10: a conta fica negativa EXATAMENTE no dia 3');
igual($p['alerta_negativo']['saldo'], 100 - 2000, 'saldo previsto no dia');
ok(abs($p['serie'][1]['saldo'] - 100) < 0.01 && $p['serie'][1]['resultado'] > 0, 'mesmo com o mês fechando positivo (o total do mês não mostra o problema)');
q('UPDATE lancamentos SET data = ? WHERE id = ?', [$dia(1, 12), $idDesp]);
$p2 = projetar(4, false, 0.0);
ok($p2['alerta_negativo'] === null, 'com o salário (dia 10) antes do aluguel (dia 12) não há alerta');
igual($p2['serie'][1]['saldo'], 100, 'e o saldo da conta segue igual');

echo "== mes de deficit sai da conta; a sobra so e investida depois de a conta voltar a ficar positiva\n";
$lanca('despesa', 'Viagem', 4000, $dia(2, 20));
$p3 = projetar(5, false, 0.0);
igual($p3['serie'][2]['resultado'], -4000, 'mês de deficit');
igual($p3['serie'][2]['aporte_sobra'], 0, 'não investe em mês de deficit');
igual($p3['serie'][2]['saldo'], 100 - 4000, 'a conta absorve o deficit (fica negativa)');
ok($p3['alerta_negativo'] !== null && $p3['alerta_negativo']['data'] === $dia(2, 20), 'alerta no dia 20 do mês seguinte');
ok($p3['alerta_negativo']['investido_liquido'] >= 0, 'o alerta informa quanto há em investimentos (líquido)');
igual($p3['serie'][3]['saldo'], -3900, 'sem entradas, o saldo negativo segue');
$lanca('receita', 'Bônus', 6000, $dia(3, 5));
$p4 = projetar(5, false, 0.0);
igual($p4['serie'][3]['aporte_sobra'], 2100, 'o bônus primeiro cobre o negativo (−3.900) e só o que sobra (2.100) é investido');
igual($p4['serie'][3]['saldo'], 0, 'conta volta a zero');

echo "== aporte extra fixo (opcional)\n";
$p5 = projetar(3, false, 500.0);
igual($p5['serie'][1]['aporte'], 3000 + 500, 'aporte total = sobra + extra');
igual($p5['serie'][1]['saldo'], 100 - 500, 'o extra sai da conta');
ok($p5['serie'][0]['aporte'] >= 0, 'mês atual sem extra');

echo "== RN-32: importacao marcada como historico fica fora da media; as demais continuam entrando\n";
$mesPassado = date('Y-m-01', strtotime('first day of -1 month'));
$impNormal = q_insert('INSERT INTO importacoes (perfil_id, arquivo, tipo) VALUES (?,?,?)', [perfil_id(), 'planilha.xlsx', 'planilha']);
$impHist = q_insert('INSERT INTO importacoes (perfil_id, arquivo, tipo) VALUES (?,?,?)', [perfil_id(), 'historico.xlsx', 'historico']);
$avulso = function (string $tipo, float $v, ?int $imp) use ($conta, $mesPassado): void {
    q('INSERT INTO lancamentos (perfil_id, tipo, descricao, valor, data, efetivado, conta_id, importacao_id) VALUES (?,?,?,?,?,?,?,?)',
        [perfil_id(), $tipo, 'Avulso', $v, $mesPassado, 1, $conta, $imp]);
};
$avulso('despesa', 3000, null);
$avulso('despesa', 6000, $impHist);
$avulso('receita', 9000, $impHist);
$avulso('receita', 1500, $impNormal);
$pm = projetar(3, true);
igual($pm['media_despesas'], 3000 / 3, 'média de despesas: só os 3.000 avulsos (os 6.000 do histórico ficam de fora) ÷ 3 meses');
igual($pm['media_receitas'], 1500 / 3, 'média de receitas: a importação comum entra, os 9.000 do histórico não');

echo "\n" . ($falhas ? "$falhas FALHA(S)\n" : "TODOS OS TESTES PASSARAM\n");
exit($falhas ? 1 : 0);
