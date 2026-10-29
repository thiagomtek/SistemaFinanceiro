<?php
// Auditoria de integridade (somente leitura): docker exec fin_app php /var/www/html/tools/auditoria/auditoria_integridade.php
require __DIR__ . '/../../sistema/bootstrap.php';
$_SESSION['usuario_id'] = 1;
garantir_perfil();
$pid = perfil_id();
$ok = function (string $t, $v, string $obs = '') { echo sprintf("%-6s %-70s %s\n", ($v == 0 ? 'OK' : 'ATENCAO'), $t, $v == 0 ? '' : $v . ' ' . $obs); };
$c = function (string $sql, array $p = []) { return (int) q_val($sql, $p); };

echo "== lancamentos\n";
$ok('valor <= 0', $c('SELECT COUNT(*) FROM lancamentos WHERE perfil_id = ? AND valor <= 0 AND ajuste IS NULL', [$pid]));
$ok('valor negativo fora de credito de cartao/reajuste', $c("SELECT COUNT(*) FROM lancamentos WHERE perfil_id = ? AND valor < 0 AND cartao_id IS NULL", [$pid]));
$ok('despesa/receita sem conta nem cartao', $c("SELECT COUNT(*) FROM lancamentos WHERE perfil_id = ? AND tipo IN ('receita','despesa') AND conta_id IS NULL AND cartao_id IS NULL", [$pid]));
$ok('transferencia sem destino e sem investimento', $c("SELECT COUNT(*) FROM lancamentos WHERE perfil_id = ? AND tipo='transferencia' AND conta_destino_id IS NULL AND investimento_id IS NULL", [$pid]));
$ok('transferencia com origem = destino', $c("SELECT COUNT(*) FROM lancamentos WHERE perfil_id = ? AND tipo='transferencia' AND conta_id = conta_destino_id", [$pid]));
$ok('item de cartao sem fatura_mes', $c("SELECT COUNT(*) FROM lancamentos WHERE perfil_id = ? AND cartao_id IS NOT NULL AND fatura_mes IS NULL AND fatura_id IS NULL", [$pid]));
$ok('item de cartao marcado como efetivado', $c("SELECT COUNT(*) FROM lancamentos WHERE perfil_id = ? AND cartao_id IS NOT NULL AND efetivado = 1", [$pid]));
$ok('conta E cartao ao mesmo tempo', $c("SELECT COUNT(*) FROM lancamentos WHERE perfil_id = ? AND cartao_id IS NOT NULL AND conta_id IS NOT NULL", [$pid]));
$ok('lancamento apontando para conta inexistente', $c("SELECT COUNT(*) FROM lancamentos l WHERE l.perfil_id = ? AND l.conta_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM contas c WHERE c.id = l.conta_id)", [$pid]));
$ok('lancamento em conta inativa', $c("SELECT COUNT(*) FROM lancamentos l JOIN contas c ON c.id = l.conta_id WHERE l.perfil_id = ? AND c.ativa = 0", [$pid]));
$ok('lancamento em cartao inativo (itens ainda a vencer)', $c("SELECT COUNT(*) FROM lancamentos l JOIN cartoes k ON k.id = l.cartao_id WHERE l.perfil_id = ? AND k.ativo = 0 AND l.fatura_id IS NULL", [$pid]));
$ok('categoria de tipo diferente do lancamento', $c("SELECT COUNT(*) FROM lancamentos l JOIN categorias c ON c.id = l.categoria_id WHERE l.perfil_id = ? AND l.tipo IN ('receita','despesa') AND c.tipo <> l.tipo", [$pid]));
$ok('hash duplicado', $c("SELECT COUNT(*) FROM (SELECT hash_unico FROM lancamentos WHERE perfil_id = ? AND hash_unico IS NOT NULL GROUP BY hash_unico HAVING COUNT(*) > 1) t", [$pid]));
$ok('parcela_num maior que parcela_total', $c("SELECT COUNT(*) FROM lancamentos WHERE perfil_id = ? AND parcela_num > parcela_total", [$pid]));
$ok('mesma serie (grupo) com valores/descricoes diferentes (fixas)', $c("SELECT COUNT(*) FROM (SELECT grupo_id FROM lancamentos WHERE perfil_id = ? AND recorrencia = 'fixa' GROUP BY grupo_id HAVING COUNT(DISTINCT valor) > 1 OR COUNT(DISTINCT descricao) > 1) t", [$pid]));
$ok('pendentes (nao cartao) com data passada', $c("SELECT COUNT(*) FROM lancamentos WHERE perfil_id = ? AND efetivado = 0 AND cartao_id IS NULL AND fatura_id IS NULL AND tipo IN ('receita','despesa') AND data < ?", [$pid, date('Y-m-d')]), '(devem ser confirmados ou ajustados)');

echo "== faturas\n";
$ok('fatura paga sem lancamento de pagamento', $c("SELECT COUNT(*) FROM faturas f WHERE NOT EXISTS (SELECT 1 FROM lancamentos l WHERE l.fatura_id = f.id)"));
$ok('lancamento de pagamento sem fatura', $c("SELECT COUNT(*) FROM lancamentos l WHERE l.perfil_id = ? AND l.fatura_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM faturas f WHERE f.id = l.fatura_id)", [$pid]));
$ok('pagamento com valor diferente do valor_pago', $c("SELECT COUNT(*) FROM faturas f JOIN lancamentos l ON l.fatura_id = f.id WHERE ABS(l.valor - f.valor_pago) > 0.005"));
$ok('fatura duplicada (cartao+mes)', $c("SELECT COUNT(*) FROM (SELECT cartao_id, mes FROM faturas GROUP BY cartao_id, mes HAVING COUNT(*) > 1) t"));
echo "-- faturas pagas: total dos itens x valor pago\n";
foreach (q_all("SELECT f.cartao_id, f.mes, f.valor_pago, k.nome FROM faturas f JOIN cartoes k ON k.id = f.cartao_id WHERE k.perfil_id = ?", [$pid]) as $f) {
    $t = fatura_total((int) $f['cartao_id'], $f['mes']);
    printf("   %-18s %s itens=%.2f pago=%.2f %s\n", $f['nome'], $f['mes'], $t, $f['valor_pago'], abs($t - $f['valor_pago']) < 0.01 ? 'ok' : 'DIFERE (' . round($t - $f['valor_pago'], 2) . ')');
}

echo "== cartoes: fechamento/vencimento\n";
foreach (cartoes_do_perfil() as $k) {
    printf("   %-18s fecha dia %2d vence dia %2d ativo=%d  ex.: compra 1 dia antes do fechamento -> fatura %s; no dia seguinte -> %s\n", $k['nome'], $k['dia_fechamento'], $k['dia_vencimento'], $k['ativo'],
        fatura_mes_da_compra($k, date('Y-m-') . sprintf('%02d', max(1, (int) $k['dia_fechamento'] - 1))), fatura_mes_da_compra($k, date('Y-m-') . sprintf('%02d', min(28, (int) $k['dia_fechamento'] + 1))));
}

echo "== contas\n";
$s = saldos_por_conta();
foreach (contas_do_perfil() as $ct) {
    $ef = (float) q_val("SELECT COUNT(*) FROM lancamentos WHERE (conta_id = ? OR conta_destino_id = ?)", [$ct['id'], $ct['id']]);
    printf("   conta %-10s ativa=%d saldo_inicial=%.2f saldo_atual=%.2f lancamentos=%d\n", $ct['nome'], $ct['ativa'], $ct['saldo_inicial'], $s[$ct['id']] ?? 0, $ef);
}

echo "== investimentos\n";
foreach (investimentos_com_saldo() as $i) {
    $mv = q_all('SELECT tipo, SUM(valor) AS t FROM investimento_movs WHERE investimento_id = ? GROUP BY tipo', [$i['id']]);
    $m = array_column($mv, 't', 'tipo');
    printf("   %-22s bruto=%.2f aportes=%.2f resgates(bruto)=%.2f reajustes=%.2f ativo=%d\n", $i['nome'], $i['saldo'], $m['aporte'] ?? 0, $m['resgate'] ?? 0, $m['reajuste'] ?? 0, $i['ativo']);
}
$ok('movimento de investimento ligado a lancamento inexistente', $c("SELECT COUNT(*) FROM investimento_movs m WHERE m.lancamento_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM lancamentos l WHERE l.id = m.lancamento_id)"));
$ok('transferencia de investimento sem movimento correspondente', $c("SELECT COUNT(*) FROM lancamentos l WHERE l.perfil_id = ? AND l.investimento_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM investimento_movs m WHERE m.lancamento_id = l.id)", [$pid]));
$ok('indices (CDI) mais antigos que 5 dias', (strtotime('today') - strtotime((string) q_val("SELECT MAX(data) FROM indices WHERE serie = '12'"))) / 86400 > 5 ? 1 : 0);

echo "== projecao x dashboard (mesmos numeros?)\n";
$p = projetar(12, true, 0.0);
printf("   saldo atual contas: %.2f | projecao.saldo_atual: %.2f | investido hoje %.2f | projecao.investido_atual %.2f\n", saldo_total_contas(), $p['saldo_atual'], total_investido(), $p['investido_atual']);
printf("   primeiro mes da projecao: resultado=%.2f sobra investida=%.2f saldo=%.2f\n", $p['serie'][0]['resultado'], $p['serie'][0]['aporte'], $p['serie'][0]['saldo']);
echo "   alerta: " . json_encode($p['alerta_negativo']) . "\n";
