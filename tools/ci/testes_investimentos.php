<?php
declare(strict_types=1);

/**
 * Testes do motor de investimentos: dias uteis, CDI do Banco Central (com mock), lotes, IR regressivo, IOF, PEPS,
 * reajuste, aplicacao/resgate ligados a transferencias entre contas e investimentos, e projecao.
 * Uso: php tools/ci/testes_investimentos.php
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
function lanca(callable $f, string $msg): void
{
    try {
        $f();
        ok(false, $msg);
    } catch (InvalidArgumentException $e) {
        ok(true, $msg);
    }
}

// CDI de mentira: 0,05% ao dia util (~13,4% a.a.), CDI anualizado 13,65 e Selic 13,75
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
$fator = function (int $du, float $pct, float $tdi = 0.05): float {
    return pow(1 + $tdi / 100 * $pct / 100, $du);
};

echo "== calendario de dias uteis\n";
ok(pascoa(2026) === '2026-04-05', 'Páscoa de 2026');
ok(!eh_dia_util('2026-02-16') && !eh_dia_util('2026-02-17'), 'carnaval 2026 não é dia útil');
ok(!eh_dia_util('2026-04-03') && !eh_dia_util('2026-06-04'), 'sexta-feira santa e Corpus Christi');
ok(!eh_dia_util('2026-11-20') && !eh_dia_util('2026-10-12') && !eh_dia_util('2026-12-25'), 'feriados fixos (inclui Consciência Negra desde 2024)');
ok(eh_dia_util('2023-11-20'), '20/11 de 2023 ainda era dia útil');
igual(dias_uteis('2026-12-24', '2026-12-29'), 2, 'dias úteis entre 24 e 29/12/2026 (24 e 28)', 0);
igual(dias_uteis('2026-01-05', '2026-01-12'), 5, 'semana cheia: 5 dias úteis', 0);
ok(ultimo_dia_util_ate('2026-10-25') === '2026-10-23', 'último dia útil até domingo 25/10 é sexta 23/10');

echo "== taxas de IR e IOF\n";
igual(rf_aliquota_ir(180), 22.5, 'IR até 180 dias');
igual(rf_aliquota_ir(181), 20, 'IR de 181 a 360');
igual(rf_aliquota_ir(361), 17.5, 'IR de 361 a 720');
igual(rf_aliquota_ir(721), 15, 'IR acima de 720');
igual(rf_iof_pct(1), 96, 'IOF no 1º dia');
igual(rf_iof_pct(10), 66, 'IOF no 10º dia');
igual(rf_iof_pct(29), 3, 'IOF no 29º dia');
igual(rf_iof_pct(30), 0, 'IOF zera no 30º dia');

$uid = q_insert('INSERT INTO usuarios (nome, email, senha_hash) VALUES (?,?,?)', ['T', 't@t', 'x']);
$_SESSION['usuario_id'] = $uid;
garantir_perfil();
$conta = q_insert('INSERT INTO contas (perfil_id, nome, tipo, saldo_inicial) VALUES (?,?,?,?)', [perfil_id(), 'BV', 'corrente', 50000]);
$conta2 = q_insert('INSERT INTO contas (perfil_id, nome, tipo, saldo_inicial) VALUES (?,?,?,?)', [perfil_id(), 'Itaú', 'corrente', 0]);

echo "== indices do Banco Central (mock)\n";
$cdb110 = investimento_salvar(['nome' => 'CDB BV 110%', 'produto' => 'cdb', 'instituicao' => 'BV', 'percentual_cdi' => '110']);
q_insert("INSERT INTO investimento_movs (investimento_id, data, tipo, valor) VALUES (?,?,?,?)", [$cdb110, '2026-01-05', 'aporte', 10000]);
$r = indices_atualizar(true);
ok($r['atualizado'] && abs($r['cdi_anual'] - 13.65) < 0.001, 'índices gravados; CDI anual atual 13,65');
igual(selic_atual(), 13.75, 'Selic meta gravada');
$n1 = (int) q_val('SELECT COUNT(*) FROM indices');
indices_atualizar(true);
igual((int) q_val('SELECT COUNT(*) FROM indices'), $n1, 'atualizar de novo não duplica linhas', 0);
$cdiDia = cdi_diario_tabela('2026-01-05', '2026-01-09');
igual(count($cdiDia), 5, 'CDI diário só nos dias úteis', 0);
$GLOBALS['FIN_BCB_MOCK'] = function () { throw new RuntimeException('sem internet'); };
$r2 = indices_atualizar(true);
ok(!$r2['atualizado'] && strpos($r2['mensagem'], 'sem internet') !== false, 'falha de rede não derruba o sistema e é informada');
unset($GLOBALS['FIN_BCB_MOCK']);
$GLOBALS['FIN_BCB_MOCK'] = function (string $serie, string $ini, string $fim): array {
    $out = [];
    for ($t = strtotime($ini); $t <= strtotime($fim); $t += 86400) {
        $d = date('Y-m-d', $t);
        if (($serie === IDX_CDI_DIA || $serie === IDX_CDI_ANUAL) && eh_dia_util($d)) {
            $out[] = [$d, $serie === IDX_CDI_DIA ? 0.05 : 13.65];
        } elseif ($serie === IDX_SELIC) {
            $out[] = [$d, 13.75];
        }
    }
    return $out;
};

echo "== rendimento: % do CDI nos dias uteis\n";
$inv = q_one('SELECT * FROM investimentos WHERE id = ?', [$cdb110]);
$movs = rf_movs($cdb110);
$s = rf_simular($inv, $movs, '2026-02-05');
$du = dias_uteis('2026-01-05', '2026-02-05');
igual($s['saldo_bruto'], 10000 * $fator($du, 110), 'saldo bruto após 1 mês = 10.000 x (1 + 0,05% x 110%)^dias úteis');
$s100 = rf_simular(array_merge($inv, ['percentual_cdi' => 100]), $movs, '2026-02-05');
ok($s['saldo_bruto'] > $s100['saldo_bruto'], '110% do CDI rende mais que 100%');
$s130 = rf_simular(array_merge($inv, ['percentual_cdi' => 130]), $movs, '2026-02-05');
ok($s130['saldo_bruto'] > $s['saldo_bruto'], '130% do CDI rende mais que 110%');
igual(rf_simular($inv, $movs, '2026-01-05')['saldo_bruto'], 10000, 'no dia da aplicação o saldo ainda é o valor aplicado');

echo "== imposto no resgate (IR regressivo + IOF)\n";
$bruto100 = rf_simular($inv, $movs, '2026-04-15'); // 100 dias corridos
$ganho = $bruto100['saldo_bruto'] - 10000;
igual($bruto100['liquidacao']['ir'], $ganho * 0.225, 'IR de 22,5% até 180 dias sobre o ganho');
igual($bruto100['liquidacao']['iof'], 0, 'sem IOF depois de 30 dias');
igual($bruto100['liquidacao']['liquido'], $bruto100['saldo_bruto'] - $ganho * 0.225, 'líquido = bruto - IR');
$b10 = rf_simular($inv, $movs, '2026-01-15'); // 10 dias
$g10 = $b10['saldo_bruto'] - 10000;
igual($b10['liquidacao']['iof'], $g10 * 0.66, 'IOF de 66% do ganho no 10º dia');
igual($b10['liquidacao']['ir'], ($g10 - $g10 * 0.66) * 0.225, 'IR incide sobre o ganho depois do IOF');
$b200 = rf_simular($inv, $movs, '2026-07-24');
igual($b200['liquidacao']['ir'], ($b200['saldo_bruto'] - 10000) * 0.20, 'IR de 20% de 181 a 360 dias');
$b800 = rf_simular($inv, $movs, '2028-03-15');
igual($b800['liquidacao']['ir'], ($b800['saldo_bruto'] - 10000) * 0.15, 'IR de 15% acima de 720 dias');

echo "== LCI isenta\n";
$lci = investimento_salvar(['nome' => 'LCI', 'produto' => 'lci', 'percentual_cdi' => '95']);
q_insert("INSERT INTO investimento_movs (investimento_id, data, tipo, valor) VALUES (?,?,?,?)", [$lci, '2026-01-05', 'aporte', 5000]);
$sl = rf_simular(q_one('SELECT * FROM investimentos WHERE id = ?', [$lci]), rf_movs($lci), '2026-04-15');
igual($sl['liquidacao']['ir'] + $sl['liquidacao']['iof'], 0, 'LCI: sem IR nem IOF');
igual($sl['liquidacao']['liquido'], $sl['saldo_bruto'], 'LCI: líquido = bruto');

echo "== lotes e PEPS\n";
$cdb2 = investimento_salvar(['nome' => 'CDB 2 lotes', 'produto' => 'cdb', 'percentual_cdi' => '100']);
q_insert("INSERT INTO investimento_movs (investimento_id, data, tipo, valor) VALUES (?,?,?,?)", [$cdb2, '2026-01-05', 'aporte', 1000]);
q_insert("INSERT INTO investimento_movs (investimento_id, data, tipo, valor) VALUES (?,?,?,?)", [$cdb2, '2026-06-15', 'aporte', 1000]);
$i2 = q_one('SELECT * FROM investimentos WHERE id = ?', [$cdb2]);
$e = rf_simular($i2, rf_movs($cdb2), '2026-10-20');
igual(count($e['lotes']), 2, 'dois lotes, cada um com a sua data');
$v1 = 1000 * $fator(dias_uteis('2026-01-05', '2026-10-20'), 100);
$v2 = 1000 * $fator(dias_uteis('2026-06-15', '2026-10-20'), 100);
igual($e['saldo_bruto'], $v1 + $v2, 'saldo = soma dos lotes');
$liq1 = ($v1 - 1000) * (1 - rf_aliquota_ir(rf_dias_corridos('2026-01-05', '2026-10-20')) / 100);
$liq2 = ($v2 - 1000) * (1 - rf_aliquota_ir(rf_dias_corridos('2026-06-15', '2026-10-20')) / 100);
igual($e['liquidacao']['liquido'], $v1 + $v2 - ($v1 - 1000) * rf_aliquota_ir(rf_dias_corridos('2026-01-05', '2026-10-20')) / 100 - ($v2 - 1000) * rf_aliquota_ir(rf_dias_corridos('2026-06-15', '2026-10-20')) / 100, 'cada lote paga o IR da sua faixa (lote antigo 20%, novo 22,5%)');
// resgate parcial consome o lote mais antigo primeiro
q_insert("INSERT INTO investimento_movs (investimento_id, data, tipo, valor) VALUES (?,?,?,?)", [$cdb2, '2026-10-20', 'resgate', $v1 + 100]);
$ap = rf_simular($i2, rf_movs($cdb2), '2026-10-20');
igual($ap['saldo_bruto'], $v2 - 100, 'depois do resgate sobra só o lote novo (menos 100)');
igual($ap['lotes'][0]['principal'], 1000 * (1 - 100 / $v2), 'principal do lote novo reduzido proporcionalmente');
igual($ap['ir_resgatado'], ($v1 - 1000) * rf_aliquota_ir(rf_dias_corridos('2026-01-05', '2026-10-20')) / 100 + (100 - 1000 * 100 / $v2) * 0.225, 'IR do resgate: lote antigo inteiro + parte do novo');

echo "== reajuste do saldo do investimento\n";
$cdb3 = investimento_salvar(['nome' => 'CDB reajuste', 'produto' => 'cdb', 'percentual_cdi' => '120']);
q_insert("INSERT INTO investimento_movs (investimento_id, data, tipo, valor) VALUES (?,?,?,?)", [$cdb3, '2026-01-05', 'aporte', 2000]);
$calc = rf_simular(q_one('SELECT * FROM investimentos WHERE id = ?', [$cdb3]), rf_movs($cdb3), '2026-03-02')['saldo_bruto'];
$rj = rf_reajustar($cdb3, number_format($calc + 3.50, 2, ',', ''), '2026-03-02');
igual($rj['diferenca'], 3.50, 'diferença entre o saldo real e o calculado');
igual(rf_simular(q_one('SELECT * FROM investimentos WHERE id = ?', [$cdb3]), rf_movs($cdb3), '2026-03-02')['saldo_bruto'], round($calc, 2) + 3.50, 'saldo passa a ser o informado', 0.011);
$depois = rf_simular(q_one('SELECT * FROM investimentos WHERE id = ?', [$cdb3]), rf_movs($cdb3), '2026-04-01')['saldo_bruto'];
$esperado = (round($calc, 2) + 3.50) * $fator(dias_uteis('2026-03-02', '2026-04-01'), 120);
igual($depois, $esperado, 'depois do reajuste o saldo ajustado continua rendendo', 0.02);
lanca(function () use ($cdb3, $calc) { rf_reajustar($cdb3, number_format($calc + 3.50, 2, ',', ''), '2026-03-02'); }, 'reajustar para o mesmo saldo é recusado');

echo "== reajuste informando o saldo LIQUIDO\n";
$cdb4 = investimento_salvar(['nome' => 'CDB líquido', 'produto' => 'cdb', 'percentual_cdi' => '110']);
q_insert("INSERT INTO investimento_movs (investimento_id, data, tipo, valor) VALUES (?,?,?,?)", [$cdb4, '2026-01-05', 'aporte', 10000]);
$i4 = q_one('SELECT * FROM investimentos WHERE id = ?', [$cdb4]);
$s4 = rf_simular($i4, rf_movs($cdb4), '2026-03-02');
$liqAlvo = round($s4['liquidacao']['liquido'] + 100, 2);
$rj4 = rf_reajustar($cdb4, number_format($liqAlvo, 2, ',', ''), '2026-03-02', '', 'liquido');
$s4b = rf_simular($i4, rf_movs($cdb4), '2026-03-02');
igual($s4b['liquidacao']['liquido'], $liqAlvo, 'depois do reajuste o líquido (após IR/IOF) é o informado', 0.02);
ok($rj4['diferenca'] > 100, 'o ajuste no bruto é maior que o do líquido, porque parte do ganho vai para o imposto (' . $rj4['diferenca'] . ')');
igual($s4b['saldo_bruto'], $s4['saldo_bruto'] + $rj4['diferenca'], 'o ajuste fica registrado sobre o saldo bruto', 0.011);
lanca(function () use ($cdb4) { rf_reajustar($cdb4, '100', '2026-03-02', '', 'xyz'); }, 'base inválida é recusada');
$contaInv = q_insert('INSERT INTO contas (perfil_id, nome, tipo) VALUES (?,?,?)', [perfil_id(), 'Conta tipo investimento', 'investimento']);
lanca(function () use ($contaInv, $cdb4) { rf_aplicar($contaInv, $cdb4, 100, '2026-03-02'); }, 'aplicação não aceita conta do tipo investimento');
lanca(function () use ($contaInv, $cdb4) { rf_resgatar($cdb4, $contaInv, 100, '2026-03-02'); }, 'resgate não credita conta do tipo investimento');

echo "== pré-fixado, poupança e manual\n";
$pre = investimento_salvar(['nome' => 'Pré 12%', 'produto' => 'tesouro', 'indexador' => 'pre', 'taxa_anual' => '12']);
q_insert("INSERT INTO investimento_movs (investimento_id, data, tipo, valor) VALUES (?,?,?,?)", [$pre, '2026-01-05', 'aporte', 1000]);
$ip = q_one('SELECT * FROM investimentos WHERE id = ?', [$pre]);
$duAno = dias_uteis('2026-01-05', '2027-01-05');
igual(rf_simular($ip, rf_movs($pre), '2027-01-05')['saldo_bruto'], 1000 * pow(1.12, $duAno / 252), 'pré-fixado: (1 + taxa)^(dias úteis / 252)');
$pou = investimento_salvar(['nome' => 'Poupança', 'produto' => 'poupanca']);
q_insert("INSERT INTO investimento_movs (investimento_id, data, tipo, valor) VALUES (?,?,?,?)", [$pou, '2026-01-05', 'aporte', 1000]);
igual(rf_simular(q_one('SELECT * FROM investimentos WHERE id = ?', [$pou]), rf_movs($pou), '2026-02-04')['saldo_bruto'], 1005, 'poupança com Selic > 8,5%: 0,5% ao mês', 0.01);
$man = investimento_salvar(['nome' => 'Ações', 'produto' => 'acoes']);
q_insert("INSERT INTO investimento_movs (investimento_id, data, tipo, valor) VALUES (?,?,?,?)", [$man, '2026-01-05', 'aporte', 1000]);
q_insert("INSERT INTO investimento_movs (investimento_id, data, tipo, valor) VALUES (?,?,?,?)", [$man, '2026-02-05', 'rendimento', 80]);
igual(rf_simular(q_one('SELECT * FROM investimentos WHERE id = ?', [$man]), rf_movs($man), '2026-03-01')['saldo_bruto'], 1080, 'manual: aporte + rendimento informado');

echo "== aplicacao e resgate ligados as contas\n";
$antes = saldos_por_conta()[$conta];
$lid = rf_aplicar($conta, $cdb110, '5.000,00', '2026-02-02');
igual(saldos_por_conta()[$conta], $antes - 5000, 'aplicação debita a conta de origem');
$l = q_one('SELECT * FROM lancamentos WHERE id = ?', [$lid]);
ok($l['tipo'] === 'transferencia' && (int) $l['investimento_id'] === $cdb110 && $l['sentido_invest'] === 'aplicacao', 'aplicação é uma transferência ligada ao investimento');
igual(q_val("SELECT COUNT(*) FROM investimento_movs WHERE lancamento_id = ? AND tipo = 'aporte'", [$lid]), 1, 'cria o aporte (lote) no investimento', 0);
$totalAntes = total_investido();
$p = rf_previa_resgate($cdb110, '2.000,00', '2026-04-15');
igual($p['bruto'], 2000, 'prévia: valor bruto do resgate');
ok($p['ir'] > 0 && $p['liquido'] < $p['bruto'] && abs($p['bruto'] - $p['ir'] - $p['iof'] - $p['liquido']) < 0.011, 'prévia: líquido = bruto - IR - IOF');
$saldoConta = saldos_por_conta()[$conta2];
$res = rf_resgatar($cdb110, $conta2, '2.000,00', '2026-04-15');
igual(saldos_por_conta()[$conta2], $saldoConta + $res['liquido'], 'resgate credita o valor líquido na conta de destino');
$mv = q_one("SELECT * FROM investimento_movs WHERE tipo = 'resgate' AND lancamento_id = ?", [$res['lancamento_id']]);
igual($mv['valor'], 2000, 'movimento guarda o bruto');
igual($mv['ir'], $res['ir'], 'movimento guarda o IR retido');
lanca(function () use ($cdb110, $conta2) { rf_resgatar($cdb110, $conta2, '999999', '2026-04-15'); }, 'resgate maior que o saldo é recusado');
$total = rf_previa_resgate($cdb110, null, '2026-05-04');
ok($total['total'] && abs($total['bruto'] - $total['saldo_bruto']) < 0.011, 'resgate total usa todo o saldo bruto');
lanca(function () use ($conta2) { rf_aplicar($conta2, 99999, '10', '2026-02-02'); }, 'investimento de outro perfil é recusado');
// previsto da conta enxerga aplicacao/resgate pendentes
$lidFut = rf_aplicar($conta, $cdb110, '1000', '2026-10-31', false);
$prev = saldos_previstos_por_conta('2026-12-31');
$atual = saldos_por_conta();
igual($prev[$conta], $atual[$conta] - 1000, 'aplicação pendente reduz o saldo previsto da conta');
// excluir a transferencia remove o aporte
excluir_lancamento($lidFut);
igual(q_val('SELECT COUNT(*) FROM investimento_movs WHERE lancamento_id = ?', [$lidFut]), 0, 'excluir a transferência remove o aporte ligado', 0);
lanca(function () use ($lid) { atualizar_lancamento($lid, ['tipo' => 'despesa', 'descricao' => 'x', 'valor' => 1, 'data' => '2026-02-02', 'conta_id' => 1]); }, 'aplicação não pode ser editada como lançamento comum');

echo "== transferencia entre contas bancarias\n";
$a0 = saldos_por_conta();
criar_lancamento(['tipo' => 'transferencia', 'descricao' => 'BV -> Itaú', 'valor' => '1500', 'data' => '2026-03-03', 'efetivado' => 1, 'conta_id' => $conta, 'conta_destino_id' => $conta2]);
$a1 = saldos_por_conta();
igual($a1[$conta], $a0[$conta] - 1500, 'origem debitada');
igual($a1[$conta2], $a0[$conta2] + 1500, 'destino creditado');

echo "== projecao\n";
$proj = projetar(6, false, 0.0);
ok(isset($proj['investimentos']) && count($proj['investimentos']) >= 3, 'projeção traz cada investimento');
$ult = end($proj['serie']);
ok($ult['investido_liquido'] < $ult['investido'], 'líquido de impostos menor que o bruto');
ok($proj['serie'][5]['investido'] > $proj['serie'][0]['investido'], 'investimentos crescem com o CDI');
$c14 = projetar(6, false, 0.0, 14.0);
$c10 = projetar(6, false, 0.0, 10.0);
ok(end($c14['serie'])['investido'] > end($c10['serie'])['investido'], 'cenário de CDI maior rende mais');
igual($c14['cdi_anual'], 14, 'cenário informado aparece na resposta', 0.001);

echo "== resgate pelo valor LIQUIDO na conta (imposto sai do proprio investimento)\n";
$cl = q_insert('INSERT INTO contas (perfil_id, nome, tipo, saldo_inicial) VALUES (?,?,?,?)', [perfil_id(), 'Conta liquido', 'corrente', 0]);
$cdb5 = investimento_salvar(['nome' => 'CDB resgate liquido', 'produto' => 'cdb', 'percentual_cdi' => '120']);
q_insert("INSERT INTO investimento_movs (investimento_id, data, tipo, valor) VALUES (?,?,?,?)", [$cdb5, '2026-04-01', 'aporte', 10000]);
$dR = date('Y-m-d');
$pv = rf_previa_resgate_liquido($cdb5, 30, $dR);
igual($pv['liquido'], 30, 'prévia: líquido = o pedido');
igual($pv['bruto'] - $pv['ir'] - $pv['iof'], 30, 'bruto − IR − IOF = líquido', 0.011);
ok($pv['bruto'] > 30 && $pv['ir'] > 0 && !$pv['editado'], 'o imposto sai do investimento (bruto maior que 30)');
$r1 = rf_resgatar_liquido($cdb5, $cl, 30, $dR);
igual(saldos_por_conta()[$cl], 30, 'a conta recebe exatamente R$ 30,00');
$m5 = q_one("SELECT * FROM investimento_movs WHERE investimento_id = ? AND tipo = 'resgate' ORDER BY id DESC LIMIT 1", [$cdb5]);
igual($m5['valor'], $r1['bruto'], 'o investimento é debitado do bruto calculado');
igual((float) $m5['ir'] + (float) $m5['iof'] + 30, (float) $m5['valor'], 'bruto = 30 + IR + IOF', 0.011);
$r2 = rf_resgatar_liquido($cdb5, $cl, 30, $dR, true, '', '5,00', '1,00');
igual($r2['bruto'], 36, 'IR/IOF informados à mão: bruto = 30 + 5 + 1');
ok($r2['editado'] && abs($r2['ir'] - 5) < 0.001 && abs($r2['iof'] - 1) < 0.001, 'impostos informados são os gravados');
igual(saldos_por_conta()[$cl], 60, 'a conta continua recebendo 30 exatos');
$mm = q_one("SELECT * FROM investimento_movs WHERE investimento_id = ? AND tipo = 'resgate' ORDER BY id DESC LIMIT 1", [$cdb5]);
igual($mm['valor'], 36, 'movimento do investimento com o bruto maior');
$rt = rf_previa_resgate_liquido($cdb5, null, $dR, '50,00', null);
igual($rt['bruto'] - $rt['ir'] - $rt['iof'], $rt['liquido'], 'resgate total com IR editado: líquido = bruto − impostos', 0.011);
igual($rt['ir'], 50, 'IR editado no resgate total');
lanca(function () use ($cdb5, $dR) { rf_previa_resgate_liquido($cdb5, 30, $dR, '999999', '0'); }, 'impostos que estouram o saldo são recusados');
lanca(function () use ($cdb5, $dR) { rf_previa_resgate_liquido($cdb5, 0, $dR); }, 'valor zero é recusado');

echo "== saldo dos investimentos por dia (saldo previsto do dia)\n";
$hojeD = date('Y-m-d');
$futuro = date('Y-m-d', strtotime('+60 day'));
$sd = saldo_investimentos_por_dia([$hojeD, $futuro, $hojeD]);
ok(count($sd) === 2, 'uma entrada por data, sem repetir');
igual($sd[$hojeD]['bruto'], total_investido(), 'hoje: bruto = total investido do sistema', 0.05);
igual($sd[$hojeD]['liquido'], total_investido(true), 'hoje: líquido = total líquido do sistema', 0.05);
ok($sd[$futuro]['bruto'] > $sd[$hojeD]['bruto'], 'saldo futuro maior (CDI)');
ok($sd[$futuro]['liquido'] <= $sd[$futuro]['bruto'], 'líquido nunca maior que o bruto');
ok(saldo_investimentos_por_dia([]) === [], 'sem datas, sem resultado');

echo "== rendimento do periodo (mes/ano): bruto e liquido, sem projecao\n";
$G = function (string $a, string $b): float { return pow(1.12, dias_uteis($a, $b) / 252); }; // pre-fixado 12% a.a.
$movInv = function (int $id, string $d, string $t, float $v): void {
    q_insert('INSERT INTO investimento_movs (investimento_id, data, tipo, valor) VALUES (?,?,?,?)', [$id, $d, $t, $v]);
};
$de = '2026-03-01';
$ate = '2026-03-31';
$p1 = investimento_salvar(['nome' => 'Per Pré 12', 'produto' => 'tesouro', 'indexador' => 'pre', 'taxa_anual' => '12']);
$movInv($p1, '2026-01-05', 'aporte', 1000);
$inv1 = q_one('SELECT * FROM investimentos WHERE id = ?', [$p1]);
$r = rf_rendimento_periodo($inv1, rf_movs($p1), $de, $ate);
$esperado = 1000 * ($G('2026-01-05', $ate) - $G('2026-01-05', '2026-02-28'));
igual($r['bruto'], $esperado, 'bruto do mês = saldo final - saldo da véspera (sem aportes nem resgates)');
igual($r['liquido'], $esperado * 0.775, 'líquido = bruto menos 22,5% de IR (lote de 54 a 85 dias, sem IOF)');
ok($r['liquido'] < $r['bruto'], 'líquido menor que o bruto com IR');

// aporte e resgate no meio do periodo nao sao rendimento
$p2 = investimento_salvar(['nome' => 'Per Pré fluxo', 'produto' => 'tesouro', 'indexador' => 'pre', 'taxa_anual' => '12']);
$movInv($p2, '2026-01-05', 'aporte', 1000);
$movInv($p2, '2026-03-10', 'aporte', 500);
$inv2 = q_one('SELECT * FROM investimentos WHERE id = ?', [$p2]);
rf_resgatar($p2, 0, 200, '2026-03-20');
$r2 = rf_rendimento_periodo($inv2, rf_movs($p2), $de, $ate);
$saldoFim = (1000 * $G('2026-01-05', '2026-03-20') - 200) * $G('2026-03-20', $ate) + 500 * $G('2026-03-10', $ate);
$esperado2 = $saldoFim - 1000 * $G('2026-01-05', '2026-02-28') - 500 + 200;
igual($r2['bruto'], $esperado2, 'aporte de 500 e resgate de 200 no mês não entram no rendimento bruto');
$g2 = 500 * ($G('2026-03-10', $ate) - 1); // ganho do lote novo (21 dias): IOF de 30% e depois IR de 22,5%
igual($r2['liquido'], ($r2['bruto'] - $g2) * 0.775 + $g2 * 0.70 * 0.775, 'líquido com resgate: 77,5% do ganho do lote antigo (IR pago no resgate e estimado) + lote de 21 dias com IOF de 30% e IR');
igual($r2['aportes'], 500, 'aportes do período informados');
igual($r2['resgates'], 200, 'resgates brutos do período informados');
// o IR/IOF gravado no resgate (corrigido pelo usuario) e o que vale como imposto pago
q('UPDATE investimento_movs SET ir = ir + 1 WHERE investimento_id = ? AND tipo = ?', [$p2, 'resgate']);
$r2b = rf_rendimento_periodo($inv2, rf_movs($p2), $de, $ate);
igual($r2b['liquido'], $r2['liquido'] - 1, 'IR a mais cobrado pelo banco no resgate reduz o líquido exatamente em R$ 1,00');
igual($r2b['bruto'], $r2['bruto'], 'e não muda o bruto');

// movimentos futuros (agendados) nao entram: nada e projetado
$antesFut = rf_rendimento_periodo($inv2, rf_movs($p2), $de, $ate);
$movInv($p2, date('Y-m-d', strtotime('+20 day')), 'aporte', 700);
$movInv($p2, date('Y-m-d', strtotime('+10 day')), 'rendimento', 999);
$movInv($p2, '2026-04-15', 'aporte', 300);
$depFut = rf_rendimento_periodo($inv2, rf_movs($p2), $de, $ate);
igual($depFut['bruto'], $antesFut['bruto'], 'movimentos depois do fim do período não mudam o bruto');
igual($depFut['liquido'], $antesFut['liquido'], 'nem o líquido');

// periodo comeca antes do primeiro aporte
$p3 = investimento_salvar(['nome' => 'Per Pré tardio', 'produto' => 'tesouro', 'indexador' => 'pre', 'taxa_anual' => '12']);
$movInv($p3, '2026-03-10', 'aporte', 1000);
$r3 = rf_rendimento_periodo(q_one('SELECT * FROM investimentos WHERE id = ?', [$p3]), rf_movs($p3), $de, $ate);
igual($r3['bruto'], 1000 * ($G('2026-03-10', $ate) - 1), 'investimento que começou no mês: rendimento = saldo final - aplicado');

// isento: liquido = bruto; manual: reajuste negativo e rendimento informado entram
$p4 = investimento_salvar(['nome' => 'Per LCI pré', 'produto' => 'lci', 'indexador' => 'pre', 'taxa_anual' => '12']);
$movInv($p4, '2026-01-05', 'aporte', 1000);
$r4 = rf_rendimento_periodo(q_one('SELECT * FROM investimentos WHERE id = ?', [$p4]), rf_movs($p4), $de, $ate);
igual($r4['liquido'], $r4['bruto'], 'LCI isenta: líquido = bruto');
ok($r4['bruto'] > 0, 'LCI rendeu no mês');
$p5 = investimento_salvar(['nome' => 'Per Manual', 'produto' => 'acoes']);
$movInv($p5, '2026-01-05', 'aporte', 1000);
$movInv($p5, '2026-03-10', 'reajuste', -50);
$movInv($p5, '2026-03-12', 'rendimento', 20);
$r5 = rf_rendimento_periodo(q_one('SELECT * FROM investimentos WHERE id = ?', [$p5]), rf_movs($p5), $de, $ate);
igual($r5['bruto'], -30, 'manual: reajuste de -50 e rendimento de +20 dão -30 (pode ser negativo)');
igual($r5['liquido'], -30, 'manual sem imposto: líquido = bruto');
$r5ano = rf_rendimento_periodo(q_one('SELECT * FROM investimentos WHERE id = ?', [$p5]), rf_movs($p5), '2026-01-01', $ate);
igual($r5ano['bruto'], -30, 'ano: o aporte de janeiro não conta como rendimento');
$r6 = rf_rendimento_periodo($inv1, [], $de, $ate);
ok($r6['bruto'] == 0.0 && $r6['liquido'] == 0.0, 'sem movimentos, rendimento zero');

// total da carteira (inclui inativos) = soma dos investimentos
q('UPDATE investimentos SET ativo = 0 WHERE id = ?', [$p3]);
$tot = investimentos_rendimento_periodos($ate);
ok($tot['mes']['de'] === '2026-03-01' && $tot['ano']['de'] === '2026-01-01' && $tot['mes']['ate'] === $ate, 'mês e ano começam no dia 1 e vão até a data de hoje');
$somaMes = $somaAnoL = 0.0;
foreach (q_all('SELECT * FROM investimentos WHERE perfil_id = ?', [perfil_id()]) as $iv) {
    $somaMes += rf_rendimento_periodo($iv, rf_movs((int) $iv['id']), '2026-03-01', $ate)['bruto'];
    $somaAnoL += rf_rendimento_periodo($iv, rf_movs((int) $iv['id']), '2026-01-01', $ate)['liquido'];
}
igual($tot['mes']['bruto'], $somaMes, 'KPI do mês = soma dos investimentos (inclusive o inativo)');
igual($tot['ano']['liquido'], $somaAnoL, 'KPI líquido do ano = soma dos investimentos');

echo "\n" . ($falhas ? "$falhas FALHA(S)\n" : "TODOS OS TESTES PASSARAM\n");
exit($falhas ? 1 : 0);
