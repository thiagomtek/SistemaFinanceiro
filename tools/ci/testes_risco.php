<?php
declare(strict_types=1);

/**
 * Testes do risco do patrimonio frente a Selic (RN-44): os cenarios usam a mesma projecao deslocando o CDI, o pre-fixado e o
 * manual nao reagem, o pos-fixado reage no sentido certo, o cenario base e igual a projecao e a exposicao fecha com o total.
 * Nao usa internet. Uso: php tools/ci/testes_risco.php
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
$conta = q_insert('INSERT INTO contas (perfil_id, nome, tipo, saldo_inicial) VALUES (?,?,?,?)', [perfil_id(), 'Itaú', 'corrente', 1000]);
$novoInv = function (string $nome, string $tipo, string $inst, string $idx, float $pct, float $taxa) {
    return q_insert('INSERT INTO investimentos (perfil_id, nome, tipo, instituicao, indexador, percentual_cdi, taxa_anual, tributacao) VALUES (?,?,?,?,?,?,?,?)',
        [perfil_id(), $nome, $tipo, $inst, $idx, $pct, $taxa, 'nenhuma']);
};
$antigo = date('Y-m-d', strtotime('-60 day'));

echo "== pre-fixado e manual nao reagem a Selic\n";
$pre = $novoInv('CDB pré', 'renda_fixa', 'Banco A', 'pre', 100, 12.0);
$man = $novoInv('Ações', 'acoes', 'Corretora', 'manual', 100, 0.0);
rf_aplicar(0, $pre, 50000, $antigo, true);
rf_aplicar(0, $man, 20000, $antigo, true);
$r0 = risco_cenarios_selic(12, false);
ok(count($r0['cenarios']) === 5, 'cinco cenários: Selic −4, −2, base, +2 e +4 p.p.');
foreach ($r0['cenarios'] as $c) {
    igual($c['dif_patrimonio'], 0, 'sem pós-fixado, ' . $c['rotulo'] . ' não muda o patrimônio');
}
igual($r0['sensibilidade_pp'], 0, 'sensibilidade por p.p. zerada');

echo "== pos-fixado reage no sentido certo e o cenario base e a propria projecao\n";
$cdb = $novoInv('CDB DI', 'renda_fixa', 'Banco B', 'cdi', 100, 0);
rf_aplicar(0, $cdb, 100000, $antigo, true);
$r = risco_cenarios_selic(12, false);
$c = $r['cenarios'];
ok($c[0]['patrimonio_fim'] < $c[1]['patrimonio_fim'] && $c[1]['patrimonio_fim'] < $c[2]['patrimonio_fim'] && $c[2]['patrimonio_fim'] < $c[3]['patrimonio_fim'] && $c[3]['patrimonio_fim'] < $c[4]['patrimonio_fim'],
    'quanto maior a Selic, maior o patrimônio ao fim (pós-fixado)');
ok($c[1]['dif_patrimonio'] < 0 && $c[3]['dif_patrimonio'] > 0, 'Selic cai: patrimônio abaixo do base; Selic sobe: acima');
igual($c[2]['dif_patrimonio'], 0, 'cenário base: diferença zero');
$proj = projetar(12, false, 0.0, null, 100.0);
$ult = $proj['serie'][count($proj['serie']) - 1];
igual($c[2]['patrimonio_fim'], $ult['patrimonio'], 'cenário base = patrimônio ao fim da projeção');
igual($r['base']['patrimonio_hoje'], $proj['saldo_atual'] + $proj['investido_atual'], 'patrimônio de hoje = contas + investimentos');
ok($r['sensibilidade_pp'] > 0, 'sensibilidade por p.p. positiva');
igual($r['sensibilidade_pp'], ($c[3]['patrimonio_fim'] - $c[1]['patrimonio_fim']) / 4, 'sensibilidade = (Selic +2 − Selic −2) ÷ 4 p.p.');
igual($c[0]['selic'] - $c[0]['cdi'], 0.10, 'Selic = CDI + 0,10 p.p.');
$fixo = $c[3]['patrimonio_fim'] - $c[1]['patrimonio_fim'];
// 100.000 a 100% do CDI por 12 meses: 4 p.p. de CDI (de -2 a +2) valem cerca de 4% do saldo antes de imposto (sem IR nos testes)
ok($fixo > 3500 && $fixo < 4500, 'ordem de grandeza: 4 p.p. de Selic sobre R$ 100 mil pós-fixados ≈ R$ 4 mil em 12 meses (obtido ' . round($fixo, 2) . ')');

echo "== exposicao\n";
$e = risco_exposicao();
igual($e['total_investido'], 50000 + 20000 + 100000 + 0, 'total investido (bruto de hoje, com o rendimento do pós-fixado)', 3000);
igual(array_sum(array_column($e['por_indexador'], 'valor')), $e['total_investido'], 'a soma por tipo de rendimento fecha com o total');
igual(array_sum(array_column($e['por_instituicao'], 'saldo')), $e['total_investido'], 'a soma por instituição fecha com o total');
igual(array_sum(array_column($e['por_indexador'], 'pct')), 100, 'percentuais por tipo somam 100%', 0.2);
$chaves = array_column($e['por_indexador'], 'chave');
ok(in_array('cdi', $chaves, true) && in_array('pre', $chaves, true) && in_array('manual', $chaves, true), 'aparecem pós-fixado, pré-fixado e manual');
$bancoB = array_values(array_filter($e['por_instituicao'], function ($r) { return $r['instituicao'] === 'Banco B'; }))[0];
igual($bancoB['uso_fgc_pct'], $bancoB['renda_fixa'] / 250000 * 100, 'uso do FGC = renda fixa na instituição ÷ R$ 250 mil', 0.1);
$acoes = array_values(array_filter($e['por_instituicao'], function ($r) { return $r['instituicao'] === 'Corretora'; }))[0];
igual($acoes['renda_fixa'], 0, 'ações não entram na conta do FGC');
q('UPDATE investimentos SET ativo = 0 WHERE id = ?', [$cdb]);
$e2 = risco_exposicao();
ok(!in_array('cdi', array_column($e2['por_indexador'], 'chave'), true), 'investimento inativo sai da exposição');

echo "\n" . ($falhas ? "$falhas FALHA(S)\n" : "TODOS OS TESTES PASSARAM\n");
exit($falhas ? 1 : 0);
