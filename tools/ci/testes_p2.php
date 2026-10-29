<?php
declare(strict_types=1);

/**
 * Testes das funcoes P2: ignorar transacao (funcao retirada depois; fica so o historico), periodo da repeticao, lote, filtros salvos, saldo previsto do dia e anexos.
 * Uso: php tools/ci/testes_p2.php
 */

putenv('DB_DRIVER=sqlite');
putenv('DB_SQLITE=:memory:');
putenv('UPLOAD_DIR=' . sys_get_temp_dir() . '/fin_teste_uploads_' . getmypid());

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
function igual($a, $b, string $msg): void
{
    ok(abs((float) $a - (float) $b) < 0.005, $msg . " (esperado $b, obtido $a)");
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

$uid = q_insert('INSERT INTO usuarios (nome, email, senha_hash) VALUES (?,?,?)', ['T', 't@t', 'x']);
$_SESSION['usuario_id'] = $uid;
garantir_perfil();
$a = q_insert('INSERT INTO contas (perfil_id, nome, tipo, saldo_inicial) VALUES (?,?,?,?)', [perfil_id(), 'A', 'corrente', 1000]);
$b = q_insert('INSERT INTO contas (perfil_id, nome, tipo, saldo_inicial) VALUES (?,?,?,?)', [perfil_id(), 'B', 'corrente', 0]);
$cartao = q_insert('INSERT INTO cartoes (perfil_id, nome, conta_pagamento_id, limite, dia_fechamento, dia_vencimento) VALUES (?,?,?,?,?,?)', [perfil_id(), 'C', $a, 5000, 15, 28]);
$mes = date('Y-m');
$d = function (int $dia) use ($mes) {
    return sprintf('%s-%02d', $mes, $dia);
};

echo "== observacao e tudo e considerado\n";
criar_lancamento(['tipo' => 'despesa', 'descricao' => 'Normal', 'valor' => '100', 'data' => $d(5), 'efetivado' => 1, 'conta_id' => $a]);
criar_lancamento(['tipo' => 'despesa', 'descricao' => 'Com obs', 'valor' => '300', 'data' => $d(6), 'efetivado' => 1, 'conta_id' => $a, 'observacao' => 'acerto entre contas']);
igual(saldos_por_conta()[$a], 600, 'saldo atual considera todas as transações');
$rm = resumo_mes($mes);
igual($rm['desp'], 400, 'despesas do mês consideram todas');
$ob = q_one("SELECT * FROM lancamentos WHERE descricao = 'Com obs'");
ok($ob['observacao'] === 'acerto entre contas', 'observação gravada');
criar_lancamento(['tipo' => 'despesa', 'descricao' => 'Compra cartão', 'valor' => '200', 'data' => $d(2), 'cartao_id' => $cartao, 'fatura_mes' => $mes]);
igual(fatura_total($cartao, $mes), 200, 'item de cartão entra na fatura');
q("DELETE FROM lancamentos WHERE descricao = 'Com obs'");
igual(saldos_por_conta()[$a], 900, 'saldo volta ao remover os itens de teste');

echo "== periodicidade da repeticao\n";
criar_lancamento(['tipo' => 'receita', 'descricao' => 'Semanal', 'valor' => '10', 'data' => '2026-01-01', 'conta_id' => $a, 'repeticao' => 'fixa', 'qtd' => 3, 'periodo' => 'semana']);
$ds = array_column(q_all("SELECT data FROM lancamentos WHERE descricao = 'Semanal' ORDER BY data"), 'data');
ok($ds === ['2026-01-01', '2026-01-08', '2026-01-15'], 'semanal: 7 em 7 dias');
criar_lancamento(['tipo' => 'receita', 'descricao' => 'Diario', 'valor' => '10', 'data' => '2026-01-30', 'conta_id' => $a, 'repeticao' => 'fixa', 'qtd' => 3, 'periodo' => 'dia']);
$ds = array_column(q_all("SELECT data FROM lancamentos WHERE descricao = 'Diario' ORDER BY data"), 'data');
ok($ds === ['2026-01-30', '2026-01-31', '2026-02-01'], 'diário atravessa o mês');
criar_lancamento(['tipo' => 'receita', 'descricao' => 'Anual', 'valor' => '10', 'data' => '2024-02-29', 'conta_id' => $a, 'repeticao' => 'fixa', 'qtd' => 2, 'periodo' => 'ano']);
$ds = array_column(q_all("SELECT data FROM lancamentos WHERE descricao = 'Anual' ORDER BY data"), 'data');
ok($ds === ['2024-02-29', '2025-02-28'], 'anual limita 29/02 ao último dia do mês');

echo "== fixa sem fim e exclusao de series\n";
$hojeF = date('Y-m-d');
$ids = criar_lancamento_ids(['tipo' => 'despesa', 'descricao' => 'Assinatura', 'valor' => '40', 'data' => $hojeF, 'conta_id' => $a, 'repeticao' => 'fixa', 'periodo' => 'mes']);
$limiteF = add_meses($hojeF, RECORRENCIA_HORIZONTE_MESES);
ok(count($ids) >= RECORRENCIA_HORIZONTE_MESES && count($ids) <= RECORRENCIA_HORIZONTE_MESES + 1, 'fixa sem quantidade gera ocorrências até o horizonte (' . count($ids) . ')');
$gF = q_val('SELECT grupo_id FROM lancamentos WHERE id = ?', [$ids[0]]);
ok(q_val("SELECT recorrencia FROM lancamentos WHERE id = ?", [$ids[0]]) === 'fixa' && q_val("SELECT MAX(data) FROM lancamentos WHERE grupo_id = ?", [$gF]) <= $limiteF, 'série marcada como fixa');
q('DELETE FROM lancamentos WHERE grupo_id = ? AND data > ?', [$gF, add_meses($hojeF, 6)]);
$cria = recorrencias_estender();
ok($cria > 0 && (int) q_val('SELECT COUNT(*) FROM lancamentos WHERE grupo_id = ?', [$gF]) === count($ids), 'com o tempo o sistema gera as próximas ocorrências, sem duplicar (' . $cria . ' novas)');
igual(recorrencias_estender(), 0, 'nada a criar quando já está no horizonte', 0);
$linhasF = q_all('SELECT id, data FROM lancamentos WHERE grupo_id = ? ORDER BY data', [$gF]);
foreach ([0, 1, 2] as $i) { q('UPDATE lancamentos SET efetivado = 1 WHERE id = ?', [$linhasF[$i]['id']]); }
igual(excluir_lancamento((int) $linhasF[5]['id'], 'um'), 1, 'excluir somente esta');
excluir_lancamento((int) $linhasF[1]['id'], 'futuras');
ok(q_val('SELECT COUNT(*) FROM lancamentos WHERE grupo_id = ? AND id = ?', [$gF, $linhasF[2]['id']]) == 1, 'esta + futuras não confirmadas: a futura já confirmada fica');
ok(q_val('SELECT COUNT(*) FROM lancamentos WHERE grupo_id = ? AND id = ?', [$gF, $linhasF[0]['id']]) == 1 && q_val('SELECT COUNT(*) FROM lancamentos WHERE grupo_id = ? AND id = ?', [$gF, $linhasF[1]['id']]) == 0, 'a anterior fica e a escolhida (mesmo confirmada) é excluída');
igual(q_val('SELECT COUNT(*) FROM lancamentos WHERE grupo_id = ?', [$gF]), 2, 'sobram a anterior e a futura confirmada');
igual(q_val("SELECT COUNT(*) FROM lancamentos WHERE grupo_id = ? AND recorrencia = 'fixa'", [$gF]), 0, 'a série fixa terminou: não é mais estendida');
igual(recorrencias_estender(), 0, 'e não volta a ser gerada', 0);
$id2 = criar_lancamento_ids(['tipo' => 'despesa', 'descricao' => 'Parcelada', 'valor' => '300', 'data' => $hojeF, 'conta_id' => $a, 'repeticao' => 'parcelado', 'qtd' => 6]);
q('UPDATE lancamentos SET efetivado = 1 WHERE id IN (?, ?)', [$id2[0], $id2[1]]);
igual(excluir_lancamento($id2[3], 'todas'), 6, 'todas: apaga a série inteira, inclusive as confirmadas');
$id3 = criar_lancamento_ids(['tipo' => 'despesa', 'descricao' => 'Parcelada B', 'valor' => '300', 'data' => $hojeF, 'conta_id' => $a, 'repeticao' => 'parcelado', 'qtd' => 6]);
q('UPDATE lancamentos SET efetivado = 1 WHERE id IN (?, ?)', [$id3[0], $id3[1]]);
igual(excluir_lancamento($id3[2], 'futuras'), 4, 'parcelada: esta e as próximas (4), as confirmadas ficam');
q("DELETE FROM lancamentos WHERE descricao LIKE 'Parcelada B%' OR descricao LIKE 'Assinatura%'");
igual(saldos_por_conta()[$a], 900, 'saldo volta ao remover os itens de teste');

echo "== lote\n";
$ids = array_column(q_all("SELECT id FROM lancamentos WHERE descricao = 'Semanal' ORDER BY id"), 'id');
igual(lote_aplicar($ids, 'efetivar'), 3, 'efetivar em lote');
igual(q_val("SELECT COUNT(*) FROM lancamentos WHERE descricao = 'Semanal' AND efetivado = 1"), 3, 'todos efetivados');
lote_aplicar($ids, 'pendente');
igual(q_val("SELECT COUNT(*) FROM lancamentos WHERE descricao = 'Semanal' AND efetivado = 1"), 0, 'voltam a pendentes');
$catRec = (int) q_val("SELECT id FROM categorias WHERE perfil_id = ? AND tipo = 'receita' AND sistema = 0 LIMIT 1", [perfil_id()]);
igual(lote_aplicar($ids, 'categoria', $catRec), 3, 'categoria em lote');
igual(lote_aplicar($ids, 'conta', $b), 3, 'conta em lote');
igual(q_val("SELECT COUNT(*) FROM lancamentos WHERE descricao = 'Semanal' AND conta_id = ?", [$b]), 3, 'conta alterada');
$itemCartao = (int) q_val("SELECT id FROM lancamentos WHERE descricao = 'Compra cartão'");
igual(lote_aplicar([$itemCartao], 'efetivar'), 0, 'item de cartão não aceita efetivar');
igual(lote_aplicar([$itemCartao], 'conta', $b), 0, 'item de cartão não troca de conta');
criar_lancamento(['tipo' => 'despesa', 'descricao' => 'Fatura base', 'valor' => '50', 'data' => $d(3), 'cartao_id' => $cartao, 'fatura_mes' => '2027-02']);
pagar_fatura($cartao, '2027-02', $a, $d(10));
$pag = (int) q_val('SELECT id FROM lancamentos WHERE fatura_id IS NOT NULL');
igual(lote_aplicar([$pag], 'excluir'), 0, 'pagamento de fatura nunca é excluído em lote');
lanca(function () { lote_aplicar([], 'efetivar'); }, 'sem seleção é recusado');
lanca(function () use ($ids) { lote_aplicar($ids, 'foo'); }, 'ação inválida é recusada');
lanca(function () use ($ids) { lote_aplicar($ids, 'conta', 99999); }, 'conta de outro perfil é recusada');
igual(lote_aplicar($ids, 'excluir'), 3, 'exclusão em lote');

echo "== saldo previsto do dia (RN-03)\n";
q('DELETE FROM lancamentos');
q('DELETE FROM faturas');
q('UPDATE contas SET saldo_inicial = 1000 WHERE id = ?', [$a]);
q('UPDATE contas SET saldo_inicial = 500 WHERE id = ?', [$b]);
criar_lancamento(['tipo' => 'despesa', 'descricao' => 'Antes', 'valor' => '100', 'data' => $d(1), 'efetivado' => 1, 'conta_id' => $a]);
criar_lancamento(['tipo' => 'receita', 'descricao' => 'Dia 10', 'valor' => '400', 'data' => $d(10), 'conta_id' => $a]);
criar_lancamento(['tipo' => 'despesa', 'descricao' => 'Dia 10b', 'valor' => '50', 'data' => $d(10), 'conta_id' => $a]);
criar_lancamento(['tipo' => 'transferencia', 'descricao' => 'Transf', 'valor' => '200', 'data' => $d(12), 'conta_id' => $a, 'conta_destino_id' => $b]);
criar_lancamento(['tipo' => 'despesa', 'descricao' => 'Compra', 'valor' => '300', 'data' => $d(4), 'cartao_id' => $cartao, 'fatura_mes' => $mes]);
$sd = saldo_previsto_por_dia($mes . '-05', date('Y-m-t'), $a);
igual($sd[$d(10)], 1000 - 100 + 400 - 50, 'conta A: saldo inicial + movimentos até o dia 10');
igual($sd[$d(12)], 1000 - 100 + 400 - 50 - 200, 'transferência de saída reduz a conta A');
igual($sd[$d(28)], 1000 - 100 + 400 - 50 - 200 - 300, 'fatura em aberto debita a conta pagadora no vencimento');
$sdTodas = saldo_previsto_por_dia($mes . '-05', date('Y-m-t'));
igual($sdTodas[$d(12)], (1000 - 100 + 400 - 50) + 500, 'todas as contas: a transferência entre contas não altera o total');

echo "== anexos\n";
$lid = (int) q_val("SELECT id FROM lancamentos WHERE descricao = 'Compra'");
$tmp = tempnam(sys_get_temp_dir(), 'ax');
file_put_contents($tmp, 'conteudo');
$aid = anexar_arquivo($lid, ['name' => 'comprovante.pdf', 'tmp_name' => $tmp, 'size' => 8, 'error' => UPLOAD_ERR_OK]);
$dados = anexo_dados($aid);
ok($dados && $dados['mime'] === 'application/pdf' && is_file(anexos_dir() . '/' . $dados['arquivo']), 'anexo gravado em disco com tipo definido pela extensão');
ok(count(anexos_de_lancamentos([$lid])[$lid]) === 1, 'anexo listado no lançamento');
lanca(function () use ($lid, $tmp) { anexar_arquivo($lid, ['name' => 'virus.exe', 'tmp_name' => $tmp, 'size' => 8, 'error' => UPLOAD_ERR_OK]); }, 'extensão não permitida é recusada');
lanca(function () use ($lid, $tmp) { anexar_arquivo($lid, ['name' => 'grande.pdf', 'tmp_name' => $tmp, 'size' => 6 * 1024 * 1024, 'error' => UPLOAD_ERR_OK]); }, 'arquivo acima de 5 MB é recusado');
lanca(function () use ($tmp) { anexar_arquivo(999999, ['name' => 'a.pdf', 'tmp_name' => $tmp, 'size' => 8, 'error' => UPLOAD_ERR_OK]); }, 'lançamento inexistente é recusado');
$arquivo = anexos_dir() . '/' . $dados['arquivo'];
excluir_lancamento($lid);
ok(!is_file($arquivo), 'excluir o lançamento apaga o arquivo do anexo');
igual(q_val('SELECT COUNT(*) FROM anexos'), 0, 'linha do anexo removida em cascata');
unlink($tmp);

echo "\n" . ($falhas ? "$falhas FALHA(S)\n" : "TODOS OS TESTES PASSARAM\n");
exit($falhas ? 1 : 0);
